<?php
/**
 * Tests for the AI review of the import that need no WordPress: the payload
 * sent per submission, the OpenAI request and answer, the verdict store and
 * how a verdict combines with the rules.
 */

require __DIR__ . '/wp-stubs.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want;
	$ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) {
		echo "     got:  " . var_export( $got, true ) . "\n";
		echo "     want: " . var_export( $want, true ) . "\n";
	}
}

/**
 * A Responses API answer whose message carries the given content parts.
 *
 * @param array $parts Content parts.
 * @return array Decoded answer.
 */
function answer_with( array $parts ) {
	return array(
		'id'     => 'resp_1',
		'status' => 'completed',
		'output' => array(
			array( 'type' => 'reasoning', 'id' => 'rs_1', 'summary' => array() ),
			array( 'type' => 'message', 'role' => 'assistant', 'content' => $parts ),
		),
	);
}

/**
 * A Responses API answer whose text is the given results, JSON encoded.
 *
 * @param array $results Results.
 * @return array Decoded answer.
 */
function answer_results( array $results ) {
	return answer_with( array( array( 'type' => 'output_text', 'text' => json_encode( array( 'results' => $results ) ) ) ) );
}

echo "=== Payload ===\n";

$row    = array( 'id' => 41, 'ip' => '192.0.2.9', 'user_agent' => 'Mozilla/5.0 Tajny', 'created_at' => '2025-01-01 10:00:00' );
$values = array(
	'firstName'   => 'Jan',
	'lastName'    => 'Novák',
	'companyName' => 'Novák s.r.o.',
	'email'       => 'jan.novak@example.cz',
	'phone'       => '+420 603 123 456',
	'topic'       => 'Poptávka',
	'message'     => 'Dobrý den, poptávám nabídku na novou střechu.',
);
$notes  = array( 'Rozpočet' => '200 000 Kč', 'Termín' => 'jaro' );

$prepared = Raynet_Import_Ai::prepare( $row, $values, $notes );
check( 'payload: id', $prepared['id'], 41 );
check( 'payload: jméno', $prepared['first_name'], 'Jan' );
check( 'payload: příjmení', $prepared['last_name'], 'Novák' );
check( 'payload: firma', $prepared['company'], 'Novák s.r.o.' );
check( 'payload: předmět', $prepared['topic'], 'Poptávka' );
check( 'payload: zpráva', $prepared['message'], 'Dobrý den, poptávám nabídku na novou střechu.' );
check( 'payload: doména e-mailu', $prepared['email_domain'], 'example.cz' );
check( 'payload: země předvolby', $prepared['phone_country'], 'Česko' );
check( 'payload: poznámky', $prepared['notes'], array( array( 'label' => 'Rozpočet', 'value' => '200 000 Kč' ), array( 'label' => 'Termín', 'value' => 'jaro' ) ) );
check( 'payload: jen vyjmenované klíče', array_keys( $prepared ), array( 'id', 'first_name', 'last_name', 'company', 'topic', 'message', 'email_domain', 'phone_country', 'notes' ) );

$encoded_payload = json_encode( $prepared, JSON_UNESCAPED_UNICODE );
check( 'payload: bez e-mailu', strpos( $encoded_payload, 'jan.novak' ), false );
check( 'payload: bez telefonu', strpos( $encoded_payload, '603' ), false );
check( 'payload: bez IP', strpos( $encoded_payload, '192.0.2.9' ), false );
check( 'payload: bez prohlížeče', strpos( $encoded_payload, 'Tajny' ), false );

$long_notes = array();
for ( $i = 1; $i <= 14; $i++ ) {
	$long_notes[ 'Pole ' . $i ] = str_repeat( 'ž', 400 );
}
$long = Raynet_Import_Ai::prepare( $row, array_merge( $values, array( 'message' => str_repeat( 'á', 2000 ) ) ), $long_notes );
check( 'zpráva oříznutá na 1500 znaků', mb_strlen( $long['message'] ), 1500 );
check( 'nejvýš 10 poznámek', count( $long['notes'] ), 10 );
check( 'poznámka oříznutá na 300 znaků', mb_strlen( $long['notes'][0]['value'] ), 300 );
check( 'prázdná poznámka vynechána', count( Raynet_Import_Ai::prepare( $row, $values, array( 'Prázdné' => '  ' ) )['notes'] ), 0 );

check( 'chybějící hodnoty jsou prázdné', Raynet_Import_Ai::prepare( array( 'id' => 5 ), array(), array() )['first_name'], '' );
check( 'bez telefonu: bez předvolby', Raynet_Import_Ai::prepare( array( 'id' => 5 ), array(), array() )['phone_country'], 'bez předvolby' );

echo "\n=== Maskování kontaktů v textu ===\n";

$masked = Raynet_Import_Ai::prepare(
	$row,
	array_merge(
		$values,
		array(
			'message'     => 'volejte 603 123 456 nebo pište na jan@example.cz',
			'topic'       => 'Objednávka 20 kusů v roce 2026',
			'companyName' => 'Firma (kontakt +421 905-123-456)',
			'firstName'   => 'Jan petr.svoboda@firma.example.com',
			'lastName'    => 'Tel. (603) 123.456',
		)
	),
	array( 'Kontakt' => 'Pište na obchod@strechy-novak.cz', 'Počet' => '20 kusů' )
);
check( 'zpráva: telefon i e-mail zamaskované', $masked['message'], 'volejte [telefon] nebo pište na [e-mail]' );
check( 'předmět: krátké číslo a rok zůstanou', $masked['topic'], 'Objednávka 20 kusů v roce 2026' );
check( 'firma: telefon s předvolbou a pomlčkami', $masked['company'], 'Firma (kontakt [telefon])' );
check( 'jméno: e-mail', $masked['first_name'], 'Jan [e-mail]' );
check( 'příjmení: telefon se závorkou a tečkou', false !== strpos( $masked['last_name'], '[telefon]' ) && false === strpos( $masked['last_name'], '123' ), true );
check( 'poznámka: e-mail', $masked['notes'][0]['value'], 'Pište na [e-mail]' );
check( 'poznámka: krátké číslo zůstane', $masked['notes'][1]['value'], '20 kusů' );
check( 'sedm číslic je telefon', Raynet_Import_Ai::mask( 'kód 1234567' ), 'kód [telefon]' );
check( 'šest číslic zůstane', Raynet_Import_Ai::mask( 'cena 200 000 Kč' ), 'cena 200 000 Kč' );
check( 'nezlomitelná mezera', Raynet_Import_Ai::mask( "tel. 603\u{00A0}123\u{00A0}456" ), 'tel. [telefon]' );
check( 'úzká nezlomitelná mezera', Raynet_Import_Ai::mask( "tel. 603\u{202F}123\u{202F}456" ), 'tel. [telefon]' );
check( 'lomítko', Raynet_Import_Ai::mask( 'tel. 602/123 456' ), 'tel. [telefon]' );
check( 'lomítko s nulou', Raynet_Import_Ai::mask( 'tel. 0602/123456' ), 'tel. [telefon]' );
check( 'pomlčky s mezerami', Raynet_Import_Ai::mask( 'tel. 603 - 123 - 456' ), 'tel. [telefon]' );
check( 'tečky', Raynet_Import_Ai::mask( 'tel. 603.123.456' ), 'tel. [telefon]' );
check( 'závorky', Raynet_Import_Ai::mask( 'tel. (+420) 603 123 456' ), 'tel. [telefon]' );
check( '20 kusů zůstane', Raynet_Import_Ai::mask( 'chci 20 kusů' ), 'chci 20 kusů' );
check( 'rok zůstane', Raynet_Import_Ai::mask( 'v roce 2026' ), 'v roce 2026' );
check( 'e-mail s (at)', Raynet_Import_Ai::mask( 'pište jan (at) example.cz' ), 'pište [e-mail]' );
check( 'e-mail s [at]', Raynet_Import_Ai::mask( 'pište jan.novak [at] example.cz' ), 'pište [e-mail]' );
check( 'e-mail s mezerami kolem @', Raynet_Import_Ai::mask( 'pište jan @ example.cz' ), 'pište [e-mail]' );
check( 'e-mail s (AT) velkými', Raynet_Import_Ai::mask( 'pište jan(AT)example.cz' ), 'pište [e-mail]' );
check( 'slovo at bez závorek zůstane', Raynet_Import_Ai::mask( 'look at example.cz' ), 'look at example.cz' );

echo "\n=== Předvolba a doména ===\n";

check( '+420 je Česko', Raynet_Import_Ai::phone_country( '+420 603 123 456' ), 'Česko' );
check( '00421 je Slovensko', Raynet_Import_Ai::phone_country( '00421 905 123 456' ), 'Slovensko' );
check( '+44 je Spojené království', Raynet_Import_Ai::phone_country( '+44 20 7946 0958' ), 'Spojené království' );
check( 'neznámá předvolba jako číslo', Raynet_Import_Ai::phone_country( '+9999 123 456' ), '+9999' );
check( 'neznámá předvolba bez mezer nejvýš 4 číslice', Raynet_Import_Ai::phone_country( '+9999123456' ), '+9999' );
check( 'bez předvolby', Raynet_Import_Ai::phone_country( '603123456' ), 'bez předvolby' );
check( 'prázdný telefon', Raynet_Import_Ai::phone_country( '' ), 'bez předvolby' );
check( '+1 je USA / Kanada', Raynet_Import_Ai::phone_country( '+1 202 555 0100' ), 'USA / Kanada' );
check( '(+420) je Česko', Raynet_Import_Ai::phone_country( '(+420) 603 123 456' ), 'Česko' );
check( '(00421) je Slovensko', Raynet_Import_Ai::phone_country( '(00421) 905 123 456' ), 'Slovensko' );

check( 'doména malými písmeny', Raynet_Import_Ai::email_domain( ' Jan@Example.CZ ' ), 'example.cz' );
check( 'bez zavináče žádná doména', Raynet_Import_Ai::email_domain( 'neplatny' ), '' );
check( 'prázdná doména', Raynet_Import_Ai::email_domain( 'jan@' ), '' );

echo "\n=== Otisk ===\n";

$hash = Raynet_Import_Ai::hash( $prepared );
check( 'otisk je řetězec', is_string( $hash ) && strlen( $hash ) >= 32, true );
check( 'otisk nezávisí na pořadí klíčů', Raynet_Import_Ai::hash( array_reverse( $prepared, true ) ), $hash );
$changed            = $prepared;
$changed['message'] = 'Jiná zpráva';
check( 'otisk se mění se zprávou', Raynet_Import_Ai::hash( $changed ) !== $hash, true );
// The hash is over what is sent: two messages that differ only in the
// masked number send the same text, so they share a hash.
$hash_a = Raynet_Import_Ai::hash( Raynet_Import_Ai::prepare( $row, array_merge( $values, array( 'message' => 'volejte 603 123 456' ) ), array() ) );
$hash_b = Raynet_Import_Ai::hash( Raynet_Import_Ai::prepare( $row, array_merge( $values, array( 'message' => 'volejte 777 888 999' ) ), array() ) );
check( 'otisk je z maskovaných dat', $hash_a, $hash_b );
// A new model or business text is a different question to the model.
$GLOBALS['wp_options']['raynet_lead_openai_model'] = 'gpt-jiny';
check( 'otisk se mění s modelem', Raynet_Import_Ai::hash( $prepared ) !== $hash, true );
unset( $GLOBALS['wp_options']['raynet_lead_openai_model'] );
$GLOBALS['wp_options']['raynet_lead_openai_business'] = 'Nově prodáváme okna.';
check( 'otisk se mění s popisem firmy', Raynet_Import_Ai::hash( $prepared ) !== $hash, true );
unset( $GLOBALS['wp_options']['raynet_lead_openai_business'] );
check( 'otisk se vrátí s nastavením', Raynet_Import_Ai::hash( $prepared ), $hash );

echo "\n=== Požadavek ===\n";

$site = array( 'name' => 'Střechy Novák', 'url' => 'https://strechy.example', 'business' => 'Pokrývačské práce a klempířství.' );
$GLOBALS['wp_options']['raynet_lead_openai_model'] = 'gpt-test-mini';
$body    = Raynet_OpenAI_Client::request_body( array( $prepared ), $site );
$encoded = json_encode( $body, JSON_UNESCAPED_UNICODE );

check( 'model z nastavení', $body['model'], 'gpt-test-mini' );
check( 'odpovědi se u OpenAI neukládají', $body['store'], false );
check( 'vstup: systém a uživatel', array_column( $body['input'], 'role' ), array( 'system', 'user' ) );
check( 'formát: json_schema', $body['text']['format']['type'], 'json_schema' );
check( 'formát: název', $body['text']['format']['name'], 'import_review' );
check( 'formát: strict', $body['text']['format']['strict'], true );
check(
	'schéma podle plánu',
	$body['text']['format']['schema'],
	array(
		'type'                 => 'object',
		'properties'           => array(
			'results' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'verdict' => array( 'type' => 'string', 'enum' => array( 'import', 'reject' ) ),
						'reason'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'id', 'verdict', 'reason' ),
					'additionalProperties' => false,
				),
			),
		),
		'required'             => array( 'results' ),
		'additionalProperties' => false,
	)
);
check( 'tělo bez e-mailu', strpos( $encoded, 'jan.novak@example.cz' ), false );
check( 'tělo bez telefonu', strpos( $encoded, '+420 603 123 456' ), false );

// Every spelling of the same number, in the phone field and in the text:
// none of its digits may reach the body, in any grouping.
$phone_spellings = array( '+420 603 123 456', '+420603123456', '00420 603 123 456', '603 123 456', '603-123-456', '603/123 456', "603\u{00A0}123\u{00A0}456", "603\u{202F}123\u{202F}456", '(+420) 603 123 456', '603.123.456' );
$phone_items     = array();
foreach ( $phone_spellings as $n => $spelling ) {
	$phone_items[] = Raynet_Import_Ai::prepare(
		array( 'id' => 100 + $n ),
		array( 'phone' => $spelling, 'message' => 'Volejte ' . $spelling . ' kdykoli.', 'topic' => $spelling, 'companyName' => 'Tel ' . $spelling ),
		array( 'Telefon' => $spelling )
	);
}
$phone_body = json_encode( Raynet_OpenAI_Client::request_body( $phone_items, $site ), JSON_UNESCAPED_UNICODE );
check( 'tělo: žádné číslice telefonu v žádném zápisu', strpos( (string) preg_replace( '/\D/', '', $phone_body ), '603123456' ), false );
foreach ( $phone_spellings as $spelling ) {
	check( 'tělo bez zápisu ' . $spelling, strpos( $phone_body, $spelling ), false );
}
check( 'tělo: všude země', substr_count( $phone_body, 'Česko' ) >= 4, true );
check( 'tělo nese doménu', false !== strpos( $encoded, 'example.cz' ), true );
check( 'tělo nese zemi', false !== strpos( $encoded, 'Česko' ), true );
check( 'pokyn zná web', false !== strpos( $body['input'][0]['content'], 'Střechy Novák' ) && false !== strpos( $body['input'][0]['content'], 'https://strechy.example' ), true );
check( 'pokyn zná činnost firmy', false !== strpos( $body['input'][0]['content'], 'Pokrývačské práce' ), true );
check( 'pokyn varuje před pokyny v textu', false !== stripos( $body['input'][0]['content'], 'untrusted' ), true );
$user_json = json_decode( $body['input'][1]['content'], true );
check( 'uživatelská zpráva je JSON dávka', is_array( $user_json ) && isset( $user_json['submissions'][0]['id'] ) ? $user_json['submissions'][0]['id'] : null, 41 );
unset( $GLOBALS['wp_options']['raynet_lead_openai_model'] );
check( 'výchozí model', Raynet_OpenAI_Client::model(), 'gpt-6-luna' );

echo "\n=== Čtení odpovědi ===\n";

$ids = array( 41, 42 );
check(
	'platná odpověď',
	Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'Skutečná poptávka střechy.' ), array( 'id' => 42, 'verdict' => 'reject', 'reason' => 'Nabídka SEO služeb.' ) ) ), $ids ),
	array(
		41 => array( 'verdict' => 'import', 'reason' => 'Skutečná poptávka střechy.' ),
		42 => array( 'verdict' => 'reject', 'reason' => 'Nabídka SEO služeb.' ),
	)
);
check( 'odmítnutí modelu: nic', Raynet_OpenAI_Client::parse( answer_with( array( array( 'type' => 'refusal', 'refusal' => 'I cannot help.' ) ) ), $ids ), array() );
check( 'odmítnutí modelu: bez textu', Raynet_OpenAI_Client::answer_text( answer_with( array( array( 'type' => 'refusal', 'refusal' => 'I cannot help.' ) ) ) ), null );
check( 'text, který není JSON: nic', Raynet_OpenAI_Client::parse( answer_with( array( array( 'type' => 'output_text', 'text' => 'Všechno importujte.' ) ) ), $ids ), array() );
check( 'odpověď bez výstupu: nic', Raynet_OpenAI_Client::parse( array( 'output' => 'x' ), $ids ), array() );
check(
	'cizí id vynecháno',
	array_keys( Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'OK' ), array( 'id' => 999, 'verdict' => 'import', 'reason' => 'Podstrčené' ) ) ), $ids ) ),
	array( 41 )
);
check(
	'verdikt maybe vynechán',
	Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'maybe', 'reason' => 'Nevím' ) ) ), $ids ),
	array()
);
check(
	'id jako text vynecháno',
	Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => '41', 'verdict' => 'import', 'reason' => 'OK' ) ) ), $ids ),
	array()
);
check(
	'chybějící důvod vynechán',
	Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'import' ) ) ), $ids ),
	array()
);
$long_reason = Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'reject', 'reason' => str_repeat( 'ř', 200 ) ) ) ), $ids );
check( 'dlouhý důvod oříznut na 160 znaků', mb_strlen( $long_reason[41]['reason'] ), 160 );
$tagged = Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'reject', 'reason' => '<script>alert(1)</script>Odkaz na kasino' ) ) ), $ids );
check( 'důvod jako prostý text', false !== strpos( $tagged[41]['reason'], '<' ), false );
check(
	'zdvojené id: platí první',
	Raynet_OpenAI_Client::parse( answer_results( array( array( 'id' => 41, 'verdict' => 'reject', 'reason' => 'První' ), array( 'id' => 41, 'verdict' => 'import', 'reason' => 'Druhý' ) ) ), $ids ),
	array( 41 => array( 'verdict' => 'reject', 'reason' => 'První' ) )
);

echo "\n=== Klíč ===\n";

check( 'bez klíče není nastaveno', Raynet_OpenAI_Client::configured(), false );
check( 'bez klíče prázdná nápověda', Raynet_OpenAI_Client::key_hint(), '' );
check( 'bez klíče prázdný klíč', Raynet_OpenAI_Client::key(), '' );
$GLOBALS['wp_options']['raynet_lead_openai_key'] = 'sk-test-0123456789abcd';
check( 'klíč z volby', Raynet_OpenAI_Client::key(), 'sk-test-0123456789abcd' );
check( 'nápověda: poslední 4 znaky', Raynet_OpenAI_Client::key_hint(), '…abcd' );
check( 'nastaveno', Raynet_OpenAI_Client::configured(), true );
check( 'není z konstanty', Raynet_OpenAI_Client::from_constant(), false );

$GLOBALS['wp_options']['raynet_lead_openai_key'] = 'sk-ulozeny-0000';
check( 'uložení: prázdné pole klíč nechá', Raynet_OpenAI_Client::sanitize_key_option( array( 'value' => '  ', 'delete' => '' ) ), 'sk-ulozeny-0000' );
check( 'uložení: nový klíč', Raynet_OpenAI_Client::sanitize_key_option( array( 'value' => ' sk-novy-1111 ' ) ), 'sk-novy-1111' );
check( 'uložení: chybějící pole klíč nechá', Raynet_OpenAI_Client::sanitize_key_option( null ), 'sk-ulozeny-0000' );
check( 'uložení: smazání', Raynet_OpenAI_Client::sanitize_key_option( array( 'value' => '', 'delete' => '1' ) ), '' );
check( 'uložení: nový klíč přebije smazání', Raynet_OpenAI_Client::sanitize_key_option( array( 'value' => 'sk-novy-1111', 'delete' => '1' ) ), 'sk-novy-1111' );
check( 'uložení: prázdný řetězec klíč nesmaže', Raynet_OpenAI_Client::sanitize_key_option( '' ), 'sk-ulozeny-0000' );
check( 'uložení: řetězec z mezer klíč nesmaže', Raynet_OpenAI_Client::sanitize_key_option( '   ' ), 'sk-ulozeny-0000' );
check( 'uložení: řetězec je nový klíč', Raynet_OpenAI_Client::sanitize_key_option( 'sk-cli-2222' ), 'sk-cli-2222' );
check( 'model: prázdný je výchozí', Raynet_OpenAI_Client::sanitize_model_option( '  ' ), 'gpt-6-luna' );
check( 'model: text', Raynet_OpenAI_Client::sanitize_model_option( ' gpt-6-luna-mini <b> ' ), 'gpt-6-luna-mini' );
check( 'popis firmy nejvýš 1000 znaků', mb_strlen( Raynet_OpenAI_Client::sanitize_business_option( str_repeat( 'é', 1200 ) ) ), 1000 );

echo "\n=== Volání ===\n";

$GLOBALS['wp_options']['raynet_lead_openai_key'] = 'sk-test-0123456789abcd';
$GLOBALS['wp_requests']      = array();
$GLOBALS['wp_request_queue'] = array( array( 'code' => 200, 'body' => json_encode( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'Poptávka.' ), array( 'id' => 7, 'verdict' => 'reject', 'reason' => 'Cizí' ) ) ) ) ) );
$review = Raynet_OpenAI_Client::review( array( $prepared ), $site );
check( 'review: jen platné řádky', $review, array( 41 => array( 'verdict' => 'import', 'reason' => 'Poptávka.' ) ) );
$sent = isset( $GLOBALS['wp_requests'][0] ) ? $GLOBALS['wp_requests'][0] : array( 'url' => '', 'args' => array() );
check( 'review: adresa', $sent['url'], 'https://api.openai.com/v1/responses' );
check( 'review: metoda POST', isset( $sent['args']['method'] ) ? $sent['args']['method'] : '', 'POST' );
check( 'review: timeout 30 s', isset( $sent['args']['timeout'] ) ? $sent['args']['timeout'] : 0, 30 );
check( 'review: Bearer', isset( $sent['args']['headers']['Authorization'] ) ? $sent['args']['headers']['Authorization'] : '', 'Bearer sk-test-0123456789abcd' );
check( 'review: tělo bez e-mailu', strpos( (string) ( isset( $sent['args']['body'] ) ? $sent['args']['body'] : '' ), 'jan.novak' ), false );

$error_for = function ( $response ) use ( $prepared, $site ) {
	$GLOBALS['wp_request_queue'] = array( $response );
	return Raynet_OpenAI_Client::review( array( $prepared ), $site );
};

$e401 = $error_for( array( 'code' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'Incorrect API key provided: sk-test-0123456789abcd.' ) ) ) ) );
check( '401: kód', is_wp_error( $e401 ) ? $e401->get_error_code() : '', 'raynet_ai_unauthorized' );
check( '401: bez klíče ve zprávě', is_wp_error( $e401 ) && false === strpos( $e401->get_error_message() . var_export( $e401->get_error_data(), true ), '0123456789abcd' ), true );
$e429 = $error_for( array( 'code' => 429, 'body' => json_encode( array( 'error' => array( 'message' => 'Rate limit reached for sk-test-0123456789abcd' ) ) ) ) );
check( '429: kód', is_wp_error( $e429 ) ? $e429->get_error_code() : '', 'raynet_ai_rate_limited' );
check( '429: bez klíče ve zprávě', is_wp_error( $e429 ) && false === strpos( $e429->get_error_message(), '0123456789abcd' ), true );
$e500 = $error_for( array( 'code' => 500, 'body' => json_encode( array( 'error' => array( 'message' => str_repeat( 'x', 500 ) . ' sk-test-0123456789abcd' ) ) ) ) );
check( '500: kód', is_wp_error( $e500 ) ? $e500->get_error_code() : '', 'raynet_ai_http' );
check( '500: text od OpenAI oříznut', is_wp_error( $e500 ) && mb_strlen( $e500->get_error_message() ) < 400 && false !== strpos( $e500->get_error_message(), 'xxx' ), true );
check( '500: stav v datech', is_wp_error( $e500 ) ? $e500->get_error_data() : null, array( 'status' => 500 ) );
$e_bearer = $error_for( array( 'code' => 400, 'body' => json_encode( array( 'error' => array( 'message' => 'Bad header Bearer sk-test-0123456789abcd here' ) ) ) ) );
check( '400: klíč vymazán ze zprávy', is_wp_error( $e_bearer ) && false === strpos( $e_bearer->get_error_message(), 'sk-test-0123456789abcd' ), true );
$e_transport = $error_for( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
check( 'časový limit: kód', is_wp_error( $e_transport ) ? $e_transport->get_error_code() : '', 'raynet_ai_http' );
$e_refusal = $error_for( array( 'code' => 200, 'body' => json_encode( answer_with( array( array( 'type' => 'refusal', 'refusal' => 'No.' ) ) ) ) ) );
check( 'odmítnutí: kód', is_wp_error( $e_refusal ) ? $e_refusal->get_error_code() : '', 'raynet_ai_bad_answer' );
$e_text = $error_for( array( 'code' => 200, 'body' => json_encode( answer_with( array( array( 'type' => 'output_text', 'text' => 'nope' ) ) ) ) ) );
check( 'ne-JSON: kód', is_wp_error( $e_text ) ? $e_text->get_error_code() : '', 'raynet_ai_bad_answer' );
$e_html = $error_for( array( 'code' => 200, 'body' => '<html>proxy</html>' ) );
check( 'HTML místo JSON: kód', is_wp_error( $e_html ) ? $e_html->get_error_code() : '', 'raynet_ai_bad_answer' );

check( 'review: bez přesměrování', isset( $sent['args']['redirection'] ) ? $sent['args']['redirection'] : null, 0 );

$e_empty = $error_for( array( 'code' => 200, 'body' => json_encode( answer_results( array() ) ) ) );
check( 'prázdné výsledky: kód', is_wp_error( $e_empty ) ? $e_empty->get_error_code() : '', 'raynet_ai_bad_answer' );
$e_foreign = $error_for( array( 'code' => 200, 'body' => json_encode( answer_results( array( array( 'id' => 999, 'verdict' => 'import', 'reason' => 'Cizí' ) ) ) ) ) );
check( 'jen cizí id: kód', is_wp_error( $e_foreign ) ? $e_foreign->get_error_code() : '', 'raynet_ai_bad_answer' );
$e_200_error = $error_for( array( 'code' => 200, 'body' => json_encode( array( 'status' => 'failed', 'error' => array( 'code' => 'server_error', 'message' => 'Upstream broke for sk-test-0123456789abcd' ), 'output' => array() ) ) ) );
check( '200 s chybou: kód', is_wp_error( $e_200_error ) ? $e_200_error->get_error_code() : '', 'raynet_ai_http' );
check( '200 s chybou: text OpenAI bez klíče', is_wp_error( $e_200_error ) && false !== strpos( $e_200_error->get_error_message(), 'Upstream broke' ) && false === strpos( $e_200_error->get_error_message(), '0123456789abcd' ), true );
$e_incomplete = $error_for( array( 'code' => 200, 'body' => json_encode( array( 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'error' => null, 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => '{"results":[{"id":41,' ) ) ) ) ) ) ) );
check( 'neúplná odpověď: kód', is_wp_error( $e_incomplete ) ? $e_incomplete->get_error_code() : '', 'raynet_ai_bad_answer' );
check( 'neúplná odpověď: vlastní hláška', is_wp_error( $e_incomplete ) && false !== strpos( $e_incomplete->get_error_message(), 'neúplná' ) && false !== strpos( $e_incomplete->get_error_message(), 'max_output_tokens' ), true );
$ok_with_null_error = $error_for( array( 'code' => 200, 'body' => json_encode( array_merge( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'OK' ) ) ), array( 'error' => null ) ) ) ) );
check( 'error: null není chyba', is_array( $ok_with_null_error ) ? array_keys( $ok_with_null_error ) : $ok_with_null_error, array( 41 ) );

// The key goes wherever the endpoint filter points, so only https counts.
$GLOBALS['wp_test_filters']['raynet_import_ai_endpoint'] = function () {
	return 'http://proxy.example/v1/responses';
};
$GLOBALS['wp_requests'] = array();
$error_for( array( 'code' => 200, 'body' => json_encode( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'OK' ) ) ) ) ) );
check( 'adresa bez https: výchozí', isset( $GLOBALS['wp_requests'][0]['url'] ) ? $GLOBALS['wp_requests'][0]['url'] : '', 'https://api.openai.com/v1/responses' );
$GLOBALS['wp_test_filters']['raynet_import_ai_endpoint'] = function () {
	return 'https://proxy.example/v1/responses';
};
$GLOBALS['wp_requests'] = array();
$error_for( array( 'code' => 200, 'body' => json_encode( answer_results( array( array( 'id' => 41, 'verdict' => 'import', 'reason' => 'OK' ) ) ) ) ) );
check( 'adresa s https: použita', isset( $GLOBALS['wp_requests'][0]['url'] ) ? $GLOBALS['wp_requests'][0]['url'] : '', 'https://proxy.example/v1/responses' );
unset( $GLOBALS['wp_test_filters']['raynet_import_ai_endpoint'] );

// The key never travels as a function argument, where a stack trace would
// print it.
$client_source = (string) file_get_contents( dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/class-raynet-openai-client.php' );
check( 'klíč není parametrem funkce', (bool) preg_match( '/function\s+\w+\s*\([^)]*\$key\b/', $client_source ), false );

unset( $GLOBALS['wp_options']['raynet_lead_openai_key'] );
$GLOBALS['wp_requests'] = array();
$no_key = Raynet_OpenAI_Client::review( array( $prepared ), $site );
check( 'bez klíče: kód', is_wp_error( $no_key ) ? $no_key->get_error_code() : '', 'raynet_ai_no_key' );
check( 'bez klíče: žádný požadavek', count( $GLOBALS['wp_requests'] ), 0 );

echo "\n=== Úložiště verdiktů ===\n";

check( 'prefix', Raynet_Import_Ai::OPTION_PREFIX, 'raynet_import_ai_' );
$GLOBALS['wp_options']['raynet_lead_openai_model'] = 'gpt-test-mini';
Raynet_Import_Ai::set( 41, array( 'verdict' => 'reject', 'reason' => 'Nabídka SEO.' ), $hash );
$stored = Raynet_Import_Ai::get( 41, $hash );
check( 'uložený verdikt', is_array( $stored ) ? array( $stored['verdict'], $stored['reason'], $stored['model'], $stored['hash'] ) : null, array( 'reject', 'Nabídka SEO.', 'gpt-test-mini', $hash ) );
check( 'uložený čas', is_array( $stored ) && is_int( $stored['time'] ) && $stored['time'] > 0, true );
check( 'starý otisk: nic', Raynet_Import_Ai::get( 41, 'jiny-otisk' ), null );
check( 'neznámé odeslání: nic', Raynet_Import_Ai::get( 43, $hash ), null );
$GLOBALS['wp_options']['raynet_import_ai_44'] = array( 'verdict' => 'maybe', 'reason' => 'x', 'model' => 'm', 'hash' => $hash, 'time' => 1 );
check( 'poškozený verdikt: nic', Raynet_Import_Ai::get( 44, $hash ), null );
Raynet_Import_Ai::set( 45, array( 'verdict' => 'import', 'reason' => str_repeat( 'a', 300 ) ), $hash );
check( 'uložený důvod nejvýš 160', mb_strlen( Raynet_Import_Ai::get( 45, $hash )['reason'] ), 160 );
check( 'zapomenout: počet', Raynet_Import_Ai::forget( array( 41, 45, 46 ) ), 2 );
check( 'zapomenuto', Raynet_Import_Ai::get( 41, $hash ), null );
check( 'cizí verdikt zůstal', isset( $GLOBALS['wp_options']['raynet_import_ai_44'] ), true );
unset( $GLOBALS['wp_options']['raynet_import_ai_44'], $GLOBALS['wp_options']['raynet_lead_openai_model'] );
check( 'velikost dávky', Raynet_Import_Ai::batch_size(), 20 );

echo "\n=== Spojení s pravidly ===\n";

$ok_rules      = array( 'verdict' => 'ok', 'reasons' => array() );
$suspect_rules = array( 'verdict' => 'suspect', 'reasons' => array( array( 'id' => 'odd_name', 'strength' => 'medium', 'count' => 0 ) ) );
$strong_rules  = array( 'verdict' => 'spam', 'reasons' => array( array( 'id' => 'links_many', 'strength' => 'strong', 'count' => 0 ) ) );
$reject        = array( 'verdict' => 'reject', 'reason' => 'Nesouvisí s činností firmy.' );
$import        = array( 'verdict' => 'import', 'reason' => 'Skutečná poptávka.' );

$m = Raynet_Import_Ai::merge( $ok_rules, $reject );
check( 'zamítnutí nad OK: spam', $m['verdict'], 'spam' );
check( 'zamítnutí nad OK: důvod ai_reject', $m['reasons'], array( array( 'id' => 'ai_reject', 'strength' => 'strong', 'count' => 0 ) ) );
check( 'zamítnutí nad OK: ai', $m['ai'], $reject );

$m = Raynet_Import_Ai::merge( $suspect_rules, $import );
check( 'doporučení nad podezřelým: ok', $m['verdict'], 'ok' );
check( 'doporučení nad podezřelým: důvody pravidel zůstanou', array_column( $m['reasons'], 'id' ), array( 'odd_name' ) );
check( 'doporučení nad podezřelým: ai', $m['ai'], $import );

$m = Raynet_Import_Ai::merge( $strong_rules, $import );
check( 'doporučení nad silným signálem: zůstane spam', $m['verdict'], 'spam' );
check( 'doporučení nad silným signálem: ai je vidět', $m['ai'], $import );

$m = Raynet_Import_Ai::merge( $suspect_rules, null );
check( 'bez AI: beze změny', array( $m['verdict'], $m['reasons'] ), array( $suspect_rules['verdict'], $suspect_rules['reasons'] ) );
check( 'bez AI: ai je null', array_key_exists( 'ai', $m ) && null === $m['ai'], true );
check( 'poškozený AI verdikt se nepoužije', Raynet_Import_Ai::merge( $ok_rules, array( 'verdict' => 'maybe', 'reason' => '' ) )['verdict'], 'ok' );

// A full classify() result: the tab and preselection follow the new verdict,
// but never for a row that is sent, blocked or has an attempt record.
$classified = array( 'state' => 'new', 'verdict' => 'ok', 'reasons' => array(), 'tab' => 'review', 'preselect' => true, 'block' => '', 'number_warning' => false );
$m = Raynet_Import_Ai::merge( $classified, $reject );
check( 'zamítnutý řádek přejde na Spam', array( $m['tab'], $m['preselect'] ), array( 'spam', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $classified, array( 'verdict' => 'suspect', 'preselect' => false ) ), $import );
check( 'doporučený nový řádek se předvybere', array( $m['tab'], $m['preselect'] ), array( 'review', true ) );
$m = Raynet_Import_Ai::merge( array_merge( $classified, array( 'state' => 'failed', 'verdict' => 'suspect', 'preselect' => false ) ), $import );
check( 'řádek s pokusem se nepředvybere', $m['preselect'], false );
$m = Raynet_Import_Ai::merge( array_merge( $classified, array( 'state' => 'sent', 'tab' => 'sent', 'preselect' => false ) ), $reject );
check( 'odeslaný řádek zůstane na Odesláno', array( $m['tab'], $m['preselect'] ), array( 'sent', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $classified, array( 'tab' => 'blocked', 'block' => 'x', 'preselect' => false ) ), $import );
check( 'blokovaný řádek zůstane blokovaný', array( $m['tab'], $m['preselect'] ), array( 'blocked', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $classified, array( 'verdict' => 'spam', 'tab' => 'spam', 'preselect' => false, 'reasons' => $strong_rules['reasons'] ) ), $import );
check( 'silný signál: zůstane na Spamu', array( $m['tab'], $m['preselect'] ), array( 'spam', false ) );

echo "\n=== Síla důvodů bez uvedené síly ===\n";

check( 'síla: links_many je silný', Raynet_Submission_Spam_Score::strength_of( 'links_many' ), 'strong' );
check( 'síla: keywords je střední', Raynet_Submission_Spam_Score::strength_of( 'keywords' ), 'medium' );
check( 'síla: one_word je slabý', Raynet_Submission_Spam_Score::strength_of( 'one_word' ), 'weak' );
check( 'síla: neznámé id', Raynet_Submission_Spam_Score::strength_of( 'cizi_id' ), '' );

// Every reason score() can give has the strength strength_of() says, so
// the two cannot drift apart.
$spammy = Raynet_Submission_Spam_Score::score(
	array(
		'values'            => array( 'firstName' => 'Xqzt', 'lastName' => 'Xqzt', 'email' => 'a@mailinator.com', 'phone' => '111', 'message' => 'Buy SEO http://a.example http://b.example <a href="x">x</a> bit.ly/x', 'topic' => 'casino' ),
		'texts'             => array(),
		'ip'                => '192.0.2.1',
		'user_agent'        => 'curl/8.0',
		'has_message_field' => true,
		'duplicate'         => true,
	),
	array( 'ip_counts' => array( '192.0.2.1' => 9 ) ),
	array()
);
$drift = array();
foreach ( $spammy['reasons'] as $reason ) {
	if ( Raynet_Submission_Spam_Score::strength_of( $reason['id'] ) !== $reason['strength'] ) {
		$drift[] = $reason['id'];
	}
}
check( 'síla: score() a strength_of() se shodují', array( count( $spammy['reasons'] ) > 5, $drift ), array( true, array() ) );

check( 'silný: s uvedenou silou', Raynet_Import_Ai::has_strong( array( array( 'id' => 'keywords', 'strength' => 'strong', 'count' => 0 ) ) ), true );
check( 'silný: jen id a počet, silné id', Raynet_Import_Ai::has_strong( array( array( 'id' => 'links_many', 'count' => 0 ) ) ), true );
check( 'silný: jen id a počet, střední id', Raynet_Import_Ai::has_strong( array( array( 'id' => 'keywords', 'count' => 0 ) ) ), false );
check( 'silný: prázdná síla podle id', Raynet_Import_Ai::has_strong( array( array( 'id' => 'same_ip', 'strength' => '', 'count' => 3 ) ) ), false );
check( 'silný: neplatná síla podle id', Raynet_Import_Ai::has_strong( array( array( 'id' => 'markup', 'strength' => 'huge', 'count' => 0 ) ) ), true );
check( 'silný: prostý text', Raynet_Import_Ai::has_strong( array( 'vlastni_signal' ) ), true );
check( 'silný: neznámé id', Raynet_Import_Ai::has_strong( array( array( 'id' => 'cizi_id', 'count' => 0 ) ) ), true );
check( 'silný: bez id', Raynet_Import_Ai::has_strong( array( array( 'count' => 0 ) ) ), true );
check( 'silný: číslo', Raynet_Import_Ai::has_strong( array( 5 ) ), true );
check( 'silný: žádné důvody', Raynet_Import_Ai::has_strong( array() ), false );

$cache_shaped = array( 'state' => 'new', 'verdict' => 'spam', 'reasons' => array( array( 'id' => 'links_many', 'count' => 0 ) ), 'tab' => 'spam', 'preselect' => false, 'block' => '', 'number_warning' => false );
$m            = Raynet_Import_Ai::merge( $cache_shaped, $import );
check( 'C1: důvod bez síly, AI doporučí: zůstane spam', array( $m['verdict'], $m['tab'], $m['preselect'] ), array( 'spam', 'spam', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $cache_shaped, array( 'reasons' => array( 'vlastni_<b>signal</b>' ) ) ), $import );
check( 'C1: prostý text, AI doporučí: zůstane spam', array( $m['verdict'], $m['preselect'] ), array( 'spam', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $cache_shaped, array( 'reasons' => array( array( 'id' => 'cizi_id', 'count' => 0 ) ) ) ), $import );
check( 'C1: neznámé id, AI doporučí: zůstane spam', array( $m['verdict'], $m['preselect'] ), array( 'spam', false ) );
$m = Raynet_Import_Ai::merge( array_merge( $cache_shaped, array( 'verdict' => 'suspect', 'tab' => 'review', 'reasons' => array( array( 'id' => 'keywords', 'count' => 0 ) ) ) ), $import );
check( 'C1: střední id bez síly, AI doporučí: ok', array( $m['verdict'], $m['preselect'] ), array( 'ok', true ) );

// Through a real cached index entry: packed, stored, read back.
$pack   = new ReflectionMethod( 'Raynet_Elementor_Submissions', 'pack' );
$unpack = new ReflectionMethod( 'Raynet_Elementor_Submissions', 'unpack' );
$pack->setAccessible( true );
$unpack->setAccessible( true );
$cached_entry = $unpack->invoke(
	null,
	$pack->invoke(
		null,
		array( 'state' => 'new', 'verdict' => 'spam', 'reasons' => array( array( 'id' => 'links_many', 'strength' => 'strong', 'count' => 0 ), array( 'id' => 'cizi_id', 'count' => 0 ), 'vlastni signal' ), 'tab' => 'spam', 'preselect' => false, 'block' => '', 'number_warning' => false, 'ai' => null )
	)
);
$m = Raynet_Import_Ai::merge( $cached_entry, $import );
check( 'C1: záznam z mezipaměti, AI doporučí: zůstane spam', array( $m['verdict'], $m['tab'], $m['preselect'] ), array( 'spam', 'spam', false ) );
$cached_medium = $unpack->invoke( null, $pack->invoke( null, array_merge( $cached_entry, array( 'verdict' => 'suspect', 'tab' => 'review', 'reasons' => array( array( 'id' => 'keywords', 'count' => 0 ) ) ) ) ) );
$m             = Raynet_Import_Ai::merge( $cached_medium, $import );
check( 'záznam z mezipaměti se středním důvodem, AI doporučí: ok', array( $m['verdict'], $m['tab'], $m['preselect'] ), array( 'ok', 'review', true ) );

echo "\n=== Opakované spojení ===\n";

foreach ( array( 'reject' => $reject, 'import' => $import, 'null' => null ) as $ai_name => $ai_verdict ) {
	foreach ( array( 'ok' => $classified, 'silný' => array_merge( $classified, array( 'verdict' => 'spam', 'tab' => 'spam', 'preselect' => false, 'reasons' => $strong_rules['reasons'] ) ), 'mezipaměť' => $cached_entry ) as $base_name => $base ) {
		$once  = Raynet_Import_Ai::merge( $base, $ai_verdict );
		$twice = Raynet_Import_Ai::merge( $once, $ai_verdict );
		check( 'dvakrát jako jednou: ' . $ai_name . ' nad ' . $base_name, $twice, $once );
	}
}
check( 'zamítnutí dvakrát: jeden ai_reject', count( array_keys( array_column( Raynet_Import_Ai::merge( Raynet_Import_Ai::merge( $ok_rules, $reject ), $reject )['reasons'], 'id' ), 'ai_reject', true ) ), 1 );

echo "\n=== Značka „neposouzeno“ ===\n";

$none_hash = 'otisk-none';
Raynet_Import_Ai::set( 61, array( 'verdict' => 'none', 'reason' => 'nic' ), $none_hash );
check( 'značka: get() ji vrátí', Raynet_Import_Ai::get( 61, $none_hash )['verdict'], 'none' );
check( 'značka: bez důvodu', Raynet_Import_Ai::get( 61, $none_hash )['reason'], '' );
check( 'značka: jiný otisk nic', Raynet_Import_Ai::get( 61, 'jiny' ), null );
$m = Raynet_Import_Ai::merge( $classified, array( 'verdict' => 'none', 'reason' => '' ) );
check( 'značka: pravidla platí beze změny', array( $m['verdict'], $m['reasons'], $m['tab'], $m['preselect'] ), array( 'ok', array(), 'review', true ) );
check( 'značka: ai nese none', $m['ai'], array( 'verdict' => 'none', 'reason' => '' ) );
check( 'vynechání poprvé: ještě bez značky', Raynet_Import_Ai::note_missing( 62, $none_hash ), false );
check( 'vynechání poprvé: není verdikt', Raynet_Import_Ai::get( 62, $none_hash ), null );
check( 'vynechání podruhé: značka', Raynet_Import_Ai::note_missing( 62, $none_hash ), true );
check( 'vynechání podruhé: get() vrátí none', Raynet_Import_Ai::get( 62, $none_hash )['verdict'], 'none' );
check( 'vynechání s jiným otiskem začne znovu', array( Raynet_Import_Ai::note_missing( 63, 'a' ), Raynet_Import_Ai::note_missing( 63, 'b' ) ), array( false, false ) );
check( 'zapomenout smaže i značky a vynechání', Raynet_Import_Ai::forget( array( 61, 62, 63 ) ), 3 );
check( 'fronta: označený řádek se nepošle', Raynet_Elementor_Submissions::ai_candidates( array( 5 => array( 'state' => 'new', 'tab' => 'review', 'verdict' => 'ok', 'reasons' => array(), 'ai' => 'none' ) ) ), array() );

echo "\n=== Co odejde z uloženého odeslání ===\n";

$ai_form = array(
	Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' => array(
		array( 'remote_id' => 'email', 'local_id' => 'email' ),
		array( 'remote_id' => 'firstName', 'local_id' => 'name' ),
		array( 'remote_id' => 'phone', 'local_id' => 'tel' ),
		array( 'remote_id' => 'message', 'local_id' => 'msg' ),
	),
	Raynet_Elementor_Forms::NOTICE_KEY => array( 'odkud' ),
	'form_fields'                      => array(
		array( 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'E-mail' ),
		array( 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Jméno' ),
		array( 'custom_id' => 'tel', 'field_type' => 'tel', 'field_label' => 'Telefon' ),
		array( 'custom_id' => 'msg', 'field_type' => 'textarea', 'field_label' => 'Zpráva' ),
		array( 'custom_id' => 'odkud', 'field_type' => 'text', 'field_label' => 'Odkud o nás víte' ),
	),
);
$ai_row  = array(
	'id'         => 77,
	'created_at' => '2025-03-12 14:10:00',
	'referer'    => 'https://example.test/kontakt/',
	'ip'         => '192.0.2.10',
	'user_agent' => 'Mozilla/5.0',
	'state'      => Raynet_Elementor_Submissions::STATE_NEW,
	'log'        => '',
	'values'     => array(
		'email' => 'jana.novakova@firma.cz',
		'name'  => 'Jana',
		'tel'   => '+420 603 123 456',
		'msg'   => 'Poptávám střechu, volejte 777 888 999.',
		'odkud' => 'Google',
	),
);
$ai_payload = Raynet_Elementor_Submissions::ai_payload( $ai_row, $ai_form );
$ai_encoded = wp_json_encode( $ai_payload );
check( 'payload: id odeslání', $ai_payload['id'], 77 );
check( 'payload: jméno', $ai_payload['first_name'], 'Jana' );
check( 'payload: doména e-mailu', $ai_payload['email_domain'], 'firma.cz' );
check( 'payload: země telefonu', $ai_payload['phone_country'], 'Česko' );
check( 'payload: pole do poznámky', $ai_payload['notes'], array( array( 'label' => 'Odkud o nás víte', 'value' => 'Google' ) ) );
check( 'payload: bez e-mailu', false !== strpos( $ai_encoded, 'jana.novakova' ), false );
check( 'payload: bez telefonu', false !== strpos( $ai_encoded, '603 123' ) || false !== strpos( $ai_encoded, '777 888' ), false );
check( 'payload: bez IP', false !== strpos( $ai_encoded, '192.0.2.10' ), false );
check( 'payload: otisk jako z prepare()', Raynet_Import_Ai::hash( $ai_payload ), Raynet_Import_Ai::hash( Raynet_Import_Ai::prepare( $ai_row, array( 'firstName' => 'Jana', 'lastName' => '', 'companyName' => '', 'topic' => '', 'message' => 'Poptávám střechu, volejte 777 888 999.', 'email' => 'jana.novakova@firma.cz', 'phone' => '+420 603 123 456' ), array( 'Odkud o nás víte' => 'Google' ) ) ) );

echo "\n=== Které řádky odejdou k posouzení ===\n";

$entry = function ( $tab, $verdict, $state, $ai = null, array $reasons = array() ) {
	return array( 'state' => $state, 'verdict' => $verdict, 'reasons' => $reasons, 'tab' => $tab, 'preselect' => false, 'block' => '', 'number_warning' => false, 'ai' => $ai );
};
// Newest first, as index() gives it.
$ai_index = array(
	90 => $entry( 'review', 'ok', 'new' ),
	80 => $entry( 'spam', 'spam', 'new', null, array( array( 'id' => 'keywords', 'strength' => 'medium', 'count' => 0 ), array( 'id' => 'same_ip', 'strength' => 'medium', 'count' => 3 ) ) ),
	70 => $entry( 'spam', 'spam', 'new', null, array( array( 'id' => 'links_many', 'strength' => 'strong', 'count' => 0 ) ) ),
	60 => $entry( 'review', 'ok', 'new', 'import' ),
	50 => $entry( 'spam', 'spam', 'new', 'reject', array( array( 'id' => 'ai_reject', 'strength' => 'strong', 'count' => 0 ) ) ),
	40 => $entry( 'sent', 'ok', 'sent' ),
	30 => $entry( 'blocked', 'ok', 'new' ),
	20 => $entry( 'review', 'suspect', 'failed' ),
	10 => $entry( 'review', 'suspect', 'new' ),
);
check( 'fronta: nové bez verdiktu, nejstarší první, spam bez silného signálu ano', Raynet_Elementor_Submissions::ai_candidates( $ai_index ), array( 10, 80, 90 ) );
check( 'fronta: za kurzorem', Raynet_Elementor_Submissions::ai_candidates( $ai_index, 10 ), array( 80, 90 ) );
check( 'fronta: za koncem nic', Raynet_Elementor_Submissions::ai_candidates( $ai_index, 90 ), array() );
check( 'fronta: prázdný index', Raynet_Elementor_Submissions::ai_candidates( array() ), array() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
