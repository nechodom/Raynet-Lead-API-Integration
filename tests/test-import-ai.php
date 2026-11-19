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

echo "\n=== Předvolba a doména ===\n";

check( '+420 je Česko', Raynet_Import_Ai::phone_country( '+420 603 123 456' ), 'Česko' );
check( '00421 je Slovensko', Raynet_Import_Ai::phone_country( '00421 905 123 456' ), 'Slovensko' );
check( '+44 je Spojené království', Raynet_Import_Ai::phone_country( '+44 20 7946 0958' ), 'Spojené království' );
check( 'neznámá předvolba jako číslo', Raynet_Import_Ai::phone_country( '+9999 123 456' ), '+9999' );
check( 'neznámá předvolba bez mezer nejvýš 4 číslice', Raynet_Import_Ai::phone_country( '+9999123456' ), '+9999' );
check( 'bez předvolby', Raynet_Import_Ai::phone_country( '603123456' ), 'bez předvolby' );
check( 'prázdný telefon', Raynet_Import_Ai::phone_country( '' ), 'bez předvolby' );
check( '+1 je USA / Kanada', Raynet_Import_Ai::phone_country( '+1 202 555 0100' ), 'USA / Kanada' );

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
check( 'uložení: smazání', Raynet_OpenAI_Client::sanitize_key_option( array( 'value' => 'sk-novy-1111', 'delete' => '1' ) ), '' );
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

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
