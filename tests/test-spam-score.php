<?php
/**
 * Tests for spam scoring of stored submissions: which signals fire on which
 * values, which ordinary Czech values never trip them, and how signals add
 * up to a verdict.
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
 * Sorted signal ids of a scoring result.
 */
function ids( $result ) {
	$ids = array_map(
		function ( $reason ) {
			return $reason['id'];
		},
		$result['reasons']
	);
	sort( $ids );
	return $ids;
}

/**
 * A clean submission with the given changes to its values and keys.
 */
function submission( array $values = array(), array $keys = array() ) {
	return array_merge(
		array(
			'values'            => array_merge(
				array(
					'firstName'   => 'Jana',
					'lastName'    => 'Nováková',
					'companyName' => '',
					'email'       => 'jana@firma.cz',
					'phone'       => '777 123 456',
					'topic'       => '',
					'message'     => 'Dobrý den, poptávám nabídku na rekonstrukci koupelny.',
				),
				$values
			),
			'texts'             => array(),
			'ip'                => '203.0.113.7',
			'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
			'has_message_field' => true,
			'duplicate'         => false,
		),
		$keys
	);
}

/**
 * Scores a submission with the default config unless one is given.
 */
function score( array $submission, array $stats = array(), array $config = array() ) {
	$stats = array_merge( array( 'text_senders' => array(), 'ip_counts' => array() ), $stats );
	return Raynet_Submission_Spam_Score::score( $submission, $stats, array_merge( Raynet_Submission_Spam_Score::default_config(), $config ) );
}

$S = 'Raynet_Submission_Spam_Score';

// Constants and config.
check( 'konstanty verdiktu', array( $S::VERDICT_OK, $S::VERDICT_SUSPECT, $S::VERDICT_SPAM ), array( 'ok', 'suspect', 'spam' ) );
check( 'konstanty síly', array( $S::STRONG, $S::MEDIUM, $S::WEAK ), array( 'strong', 'medium', 'weak' ) );
$config = $S::default_config();
check(
	'výchozí prahy',
	array( $config['strong_to_spam'], $config['medium_to_spam'], $config['medium_to_suspect'], $config['weak_to_suspect'], $config['medium_weak_to_spam'], $config['same_text_senders'], $config['same_ip_count'], $config['min_message_length'], $config['min_phone_digits'], $config['home_phone_prefixes'] ),
	array( 1, 2, 1, 1, 2, 3, 5, 10, 9, array() )
);
check( 'výchozí klíčová slova', array_values( array_diff( array( 'seo', 'backlink', 'casino', 'crypto', 'bitcoin', 'viagra', 'loan', 'guest post', 'ranking', 'traffic' ), $config['keywords'] ) ), array() );
check( 'výchozí jednorázové domény', array_values( array_diff( array( 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'yopmail.com', 'trashmail.com' ), $config['disposable_domains'] ) ), array() );
check( 'povolená písma a vypnuté signály', array( $config['allowed_scripts'], $config['disabled'] ), array( array( 'Latin' ), array() ) );

// text_key.
check( 'text_key prázdného textu', $S::text_key( "  \n " ), '' );
check( 'text_key nehledí na velikost a mezery', $S::text_key( "Ahoj  SVĚTE\n dneska" ), $S::text_key( 'ahoj světe dneska' ) );
check( 'text_key různých textů se liší', $S::text_key( 'ahoj' ) === $S::text_key( 'nazdar' ), false );

// Clean baseline.
$clean = score( submission() );
check( 'čisté odeslání je ok', $clean['verdict'], 'ok' );
check( 'čisté odeslání nemá důvody', $clean['reasons'], array() );

// link_short.
$r = score( submission( array( 'firstName' => 'Visit http://x.ru' ) ) );
check( 'odkaz ve jméně -> link_short', in_array( 'link_short', ids( $r ), true ), true );
check( 'odkaz ve jméně -> spam', $r['verdict'], 'spam' );
check( 'tvar důvodu', $r['reasons'][0], array( 'id' => 'link_short', 'strength' => 'strong', 'count' => 0 ) );

// markup.
$r = score( submission( array( 'message' => 'Dobrý den, <a href="https://x.ru">klikněte</a> zde prosím.' ) ) );
check( 'HTML ve zprávě -> markup', in_array( 'markup', ids( $r ), true ), true );
$r = score( submission( array(), array( 'texts' => array( 'Hello [url=x.ru]cheap[/url]' ) ) ) );
check( 'BBCode v ostatních textech -> markup', in_array( 'markup', ids( $r ), true ), true );

// foreign_script.
$r = score( submission( array( 'message' => 'Здравствуйте, предлагаем продвижение сайта' ) ) );
check( 'azbuka -> foreign_script', ids( $r ), array( 'foreign_script' ) );
check( 'azbuka -> spam', $r['verdict'], 'spam' );
$r = score( submission( array( 'message' => 'Здравствуйте, предлагаем продвижение сайта' ) ), array(), array( 'disabled' => array( 'foreign_script' ) ) );
check( 'vypnutý foreign_script -> ok', array( $r['verdict'], $r['reasons'] ), array( 'ok', array() ) );
$r = score( submission( array( 'message' => 'Příliš žluťoučký kůň úpěl ďábelské ódy.' ) ) );
check( 'česká diakritika foreign_script nespustí', ids( $r ), array() );

// same_text.
$key = $S::text_key( submission()['values']['message'] );
$r   = score( submission(), array( 'text_senders' => array( $key => 3 ) ) );
check( '3 odesílatelé stejného textu -> same_text', $r['reasons'], array( array( 'id' => 'same_text', 'strength' => 'strong', 'count' => 3 ) ) );
$r = score( submission(), array( 'text_senders' => array( $key => 2 ) ) );
check( '2 odesílatelé stejného textu nic', $r['reasons'], array() );
check( 'základní zpráva má aspoň 40 znaků', mb_strlen( submission()['values']['message'] ) >= 40, true );
check( 'výchozí minimální délka stejného textu', $S::default_config()['same_text_min_length'], 40 );
// Three real people may send the same short stock line.
$short     = 'Prosím o zavolání. ' . '!';
$short_key = $S::text_key( $short );
check( 'krátká zpráva má 20 znaků', mb_strlen( $short ), 20 );
$r = score( submission( array( 'message' => $short ) ), array( 'text_senders' => array( $short_key => 3 ) ) );
check( 'krátký stejný text od 3 lidí bez same_text', in_array( 'same_text', ids( $r ), true ), false );
$r = score( submission( array( 'message' => $short ) ), array( 'text_senders' => array( $short_key => 3 ) ), array( 'same_text_min_length' => 0 ) );
check( 'same_text_min_length 0 -> same_text', in_array( 'same_text', ids( $r ), true ), true );
// Counted like min_message_length: trimmed, in characters.
$edge = str_repeat( 'č', 40 );
$r    = score( submission( array( 'message' => '  ' . $edge . '  ' ) ), array( 'text_senders' => array( $S::text_key( $edge ) => 3 ) ) );
check( 'přesně 40 znaků (bez mezer okolo) -> same_text', in_array( 'same_text', ids( $r ), true ), true );
$r = score( submission( array( 'message' => '  ' . mb_substr( $edge, 1 ) . '  ' ) ), array( 'text_senders' => array( $S::text_key( mb_substr( $edge, 1 ) ) => 3 ) ) );
check( '39 znaků -> bez same_text', in_array( 'same_text', ids( $r ), true ), false );

// links_many / link_one.
$r = score( submission( array( 'message' => 'Podívejte se na https://a.example a www.b.example prosím.' ) ) );
check( 'dva odkazy -> links_many bez link_one', ids( $r ), array( 'links_many' ) );
$r = score( submission( array( 'message' => 'Naše stránky jsou http://www.firma.cz, ozvěte se.' ) ) );
check( 'jeden odkaz (http://www.) -> link_one', ids( $r ), array( 'link_one' ) );
check( 'jeden odkaz -> suspect', $r['verdict'], 'suspect' );

// keywords.
$r = score( submission( array( 'message' => 'We offer the best SEO backlinks for your site.' ) ) );
check( 'SEO -> keywords', ids( $r ), array( 'keywords' ) );
$r = score( submission( array( 'message' => 'Poptávám seolit pro naši dílnu, děkuji.' ) ) );
check( 'seolit keywords nespustí', ids( $r ), array() );
$r = score( submission( array( 'topic' => 'Guest   Post' ) ) );
check( 'fráze přes více mezer v předmětu -> keywords', ids( $r ), array( 'keywords' ) );

// same_ip.
$r = score( submission(), array( 'ip_counts' => array( '203.0.113.7' => 5 ) ) );
check( '5 odeslání z IP -> same_ip', $r['reasons'], array( array( 'id' => 'same_ip', 'strength' => 'medium', 'count' => 5 ) ) );
$r = score( submission(), array( 'ip_counts' => array( '203.0.113.7' => 4 ) ) );
check( '4 odeslání z IP nic', $r['reasons'], array() );
$r = score( submission( array(), array( 'ip' => '' ) ), array( 'ip_counts' => array( '' => 9 ) ) );
check( 'prázdná IP nic', $r['reasons'], array() );

// odd_name.
$r = score( submission( array( 'firstName' => 'Robertbok', 'lastName' => 'Robertbok' ) ) );
check( 'shodné jméno a příjmení -> odd_name', ids( $r ), array( 'odd_name' ) );
$r = score( submission( array( 'firstName' => 'J4na' ) ) );
check( 'číslice ve jméně -> odd_name', ids( $r ), array( 'odd_name' ) );
$r = score( submission( array( 'firstName' => 'xQzTrWpL' ) ) );
check( 'shluk souhlásek a střídání velikosti -> odd_name', ids( $r ), array( 'odd_name' ) );
foreach ( array( 'Křšťan', 'Nguyen', 'Ng', 'Vlk', 'Smrž', 'McDonald', 'Anna-Marie' ) as $name ) {
	$r = score( submission( array( 'firstName' => $name ) ) );
	check( "jméno $name odd_name nespustí", ids( $r ), array() );
}
$r = score( submission( array( 'lastName' => 'NOVÁKOVÁ' ) ) );
check( 'příjmení velkými písmeny odd_name nespustí', ids( $r ), array() );
$r = score( submission( array( 'firstName' => 'Thi Lan', 'lastName' => 'Nguyen' ) ) );
check( 'vietnamské jméno odd_name nespustí', ids( $r ), array() );

// bot_agent.
$r = score( submission( array(), array( 'user_agent' => 'python-requests/2.31' ) ) );
check( 'python agent -> bot_agent', ids( $r ), array( 'bot_agent' ) );
$r = score( submission( array(), array( 'user_agent' => '' ) ) );
check( 'prázdný agent -> bot_agent', ids( $r ), array( 'bot_agent' ) );
$r = score( submission( array(), array( 'user_agent' => null ) ) );
check( 'neukládaný agent nic', ids( $r ), array() );
$r = score( submission( array(), array( 'user_agent' => 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36' ) ) );
check( 'telefon CUBOT bot_agent nespustí', ids( $r ), array() );

// bad_phone.
foreach ( array( '123', '111111111', '123456', '123456789', '987654321', '0123456789' ) as $phone ) {
	$r = score( submission( array( 'phone' => $phone ) ) );
	check( "telefon $phone -> bad_phone", ids( $r ), array( 'bad_phone' ) );
}
foreach ( array( '', '+420 777 123 456', '123456780', '1234567891' ) as $phone ) {
	$r = score( submission( array( 'phone' => $phone ) ) );
	check( "telefon '$phone' nic", ids( $r ), array() );
}
$r = score( submission( array( 'phone' => '123' ) ) );
check( 'bad_phone je středně silný', $r['reasons'][0]['strength'], 'medium' );
check( 'samotné bad_phone -> suspect', $r['verdict'], 'suspect' );

// disposable_email.
$r = score( submission( array( 'email' => 'x@mailinator.com' ) ) );
check( 'jednorázová doména -> disposable_email', ids( $r ), array( 'disposable_email' ) );
$r = score( submission( array( 'email' => 'X@Mail.YOPMAIL.com' ) ) );
check( 'subdoména a velká písmena -> disposable_email', ids( $r ), array( 'disposable_email' ) );

// short_message.
$r = score( submission( array( 'message' => 'ahoj' ) ) );
check( 'krátká zpráva -> short_message (a one_word)', ids( $r ), array( 'one_word', 'short_message' ) );
check( 'short_message je středně silný', $r['reasons'][0], array( 'id' => 'short_message', 'strength' => 'medium', 'count' => 0 ) );
$r = score( submission( array( 'message' => 'ahoj' ), array( 'has_message_field' => false ) ) );
check( 'bez pole zprávy nic', ids( $r ), array() );
$r = score( submission( array( 'message' => 'Žluťoučký' ) ) );
check( 'délka se počítá ve znacích, ne v bajtech', ids( $r ), array( 'one_word', 'short_message' ) );
$r = score( submission( array( 'message' => 'Prosím zavolat' ) ) );
check( 'krátká, ale 14 znaků: nic', ids( $r ), array() );

// message_is_name.
foreach ( array( 'Jana Nováková', 'jana   NOVÁKOVÁ!', 'Nováková, Jana.', 'Jana', 'jana@firma.cz', ' Jana@Firma.cz ', '777 123 456', '777123456' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' -> message_is_name", in_array( 'message_is_name', ids( $r ), true ), true );
}
check( 'message_is_name je středně silný', score( submission( array( 'message' => 'Jana Nováková' ) ) )['reasons'][0]['strength'], 'medium' );
foreach ( array( '', 'Jana Nováková, prosím o zavolání', 'Dobrý den Jano' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' message_is_name nespustí", in_array( 'message_is_name', ids( $r ), true ), false );
}
$r = score( submission( array( 'firstName' => '', 'lastName' => '', 'email' => '', 'phone' => '', 'message' => '' ) ) );
check( 'prázdná zpráva i jméno: message_is_name nespustí', in_array( 'message_is_name', ids( $r ), true ), false );

// no_words.
foreach ( array( '199999', '999999 ❤❤❤❤', '???', 'ab 12345678' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' -> no_words", in_array( 'no_words', ids( $r ), true ), true );
	check( "zpráva '$text' bez one_word", in_array( 'one_word', ids( $r ), true ), false );
}
check( 'no_words je středně silný', score( submission( array( 'message' => '??? ??? ??? ???' ) ) )['reasons'], array( array( 'id' => 'no_words', 'strength' => 'medium', 'count' => 0 ) ) );
foreach ( array( 'Ano', 'Volejte 777 123 456', '' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' no_words nespustí", in_array( 'no_words', ids( $r ), true ), false );
}
// A message without words is short too: only no_words counts, so it alone
// is Suspect, not Spam.
foreach ( array( 'ok', '2 ks', '120 m2', '199999' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text': no_words bez short_message, podezřelé", array( in_array( 'no_words', ids( $r ), true ), in_array( 'short_message', ids( $r ), true ), $r['verdict'] ), array( true, false, 'suspect' ) );
}

// Every signal score() knows has its strength in STRENGTHS, and nothing
// else is there: the list itself is compared, whatever fires.
$signal_list = new ReflectionMethod( $S, 'signal_list' );
check( 'seznam signálů = STRENGTHS', array_keys( $signal_list->invoke( null, submission( array() ), array(), $S::default_config() ) ), array_keys( $S::STRENGTHS ) );

// gibberish.
foreach ( array( 'Mggkgmegvkfv', 'ahoj Hgkrtmnb', 'xqwzt' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' -> gibberish", in_array( 'gibberish', ids( $r ), true ), true );
}
check( 'gibberish je středně silný', score( submission( array( 'message' => 'Mggkgmegvkfv Mggkgmegvkfv' ) ) )['reasons'], array( array( 'id' => 'gibberish', 'strength' => 'medium', 'count' => 0 ) ) );
// r and l count as vowels for the share, and URL scraps are not words:
// "Wtssdres" (one vowel and an r in eight letters) no longer fires.
foreach ( array( 'https', 'https://example.cz', 'www', 'strengths', 'Your strengths', 'štvrťrok', 'Wtssdres.', 'čtvrtek', 'zmrzlina', 'scvrkl', 'čtvrthodina', 'smrt', 'prst', 'Brno', 'krk', 'vlk', 'Strč prst skrz krk', 'Prosím o zavolání', 'Potřebuji cenovou nabídku', 'Dobrý den', 'Hello, please call me', 'Ppotttřebuju vššechhno', 'Rajashthan', 'Mggkgmegvkfv a ještě pár dalších slov' ) as $text ) {
	$r = score( submission( array( 'message' => $text ) ) );
	check( "zpráva '$text' gibberish nespustí", in_array( 'gibberish', ids( $r ), true ), false );
}

// one_word.
$r = score( submission( array( 'message' => 'Poptávka-rekonstrukce' ) ) );
check( 'jedno dlouhé slovo -> one_word', $r['reasons'], array( array( 'id' => 'one_word', 'strength' => 'weak', 'count' => 0 ) ) );
check( 'samotné one_word -> suspect', $r['verdict'], 'suspect' );
$r = score( submission( array( 'message' => '   ' ) ) );
check( 'mezery: one_word ani no_words', array( in_array( 'one_word', ids( $r ), true ), in_array( 'no_words', ids( $r ), true ) ), array( false, false ) );

// home_phone.
$home = array( 'home_phone_prefixes' => array( '+420', '+421' ) );
foreach ( array( '+44 20 7946 0958', '0044 20 7946 0958', '+93730067643' ) as $phone ) {
	$r = score( submission( array( 'phone' => $phone ) ), array(), $home );
	check( "telefon $phone mimo předvolby -> home_phone", ids( $r ), array( 'home_phone' ) );
	$r = score( submission( array( 'phone' => $phone ) ) );
	check( "telefon $phone bez nastavených předvoleb nic", ids( $r ), array() );
}
check( 'home_phone je středně silný', score( submission( array( 'phone' => '+44 20 7946 0958' ) ), array(), $home )['reasons'][0]['strength'], 'medium' );
foreach ( array( '+420 777 123 456', '00421 905 123 456', '777 123 456', '' ) as $phone ) {
	$r = score( submission( array( 'phone' => $phone ) ), array(), $home );
	check( "telefon '$phone' home_phone nespustí", in_array( 'home_phone', ids( $r ), true ), false );
}
$r = score( submission( array( 'phone' => '+44 20 7946 0958' ) ), array(), $home + array( 'disabled' => array( 'home_phone' ) ) );
check( 'vypnutý home_phone nic', ids( $r ), array() );

// New signals can be switched off like the old ones.
$r = score( submission( array( 'message' => 'Wtssdres.' ) ), array(), array( 'disabled' => array( 'short_message', 'gibberish', 'one_word' ) ) );
check( 'vypnuté nové signály -> ok', array( $r['verdict'], $r['reasons'] ), array( 'ok', array() ) );
$r = score( submission( array( 'message' => 'Jana Nováková' ) ), array(), array( 'disabled' => array( 'message_is_name' ) ) );
check( 'vypnutý message_is_name -> ok', $r['verdict'], 'ok' );
$r = score( submission( array( 'message' => '??? ??? ???' ) ), array(), array( 'disabled' => array( 'no_words' ) ) );
check( 'vypnutý no_words -> ok', $r['verdict'], 'ok' );

// duplicate.
$r = score( submission( array(), array( 'duplicate' => true ) ) );
check( 'duplicita -> duplicate', $r['reasons'], array( array( 'id' => 'duplicate', 'strength' => 'medium', 'count' => 0 ) ) );
check( 'duplicita -> aspoň suspect', $r['verdict'] !== 'ok', true );

// Thresholds.
$r = score( submission( array( 'message' => 'Get better ranking at https://seo.example today' ) ) );
check( 'link_one + keywords', ids( $r ), array( 'keywords', 'link_one' ) );
check( 'link_one + keywords -> spam', $r['verdict'], 'spam' );
$r = score( submission( array( 'phone' => '123', 'message' => 'ahoj' ) ) );
check( 'bad_phone + short_message -> spam', $r['verdict'], 'spam' );
$M = array( 'id' => 'x', 'strength' => 'medium', 'count' => 0 );
$W = array( 'id' => 'y', 'strength' => 'weak', 'count' => 0 );
check( 'jediný slabý důvod -> suspect', $S::verdict( array( $W ), array() ), 'suspect' );
check( 'jeden střední + jeden slabý -> suspect', $S::verdict( array( $M, $W ), array() ), 'suspect' );
check( 'jeden střední + dva slabé -> spam', $S::verdict( array( $M, $W, $W ), array() ), 'spam' );
check( 'tři slabé bez středního -> suspect', $S::verdict( array( $W, $W, $W ), array() ), 'suspect' );
check( 'medium_weak_to_spam 0 vypne', $S::verdict( array( $M, $W, $W, $W ), array( 'medium_weak_to_spam' => 0 ) ), 'suspect' );
check( 'staré weak_to_suspect 2 z filtru dál platí', $S::verdict( array( $W ), array( 'weak_to_suspect' => 2 ) ), 'ok' );

// Order and partial config.
$r = score( submission( array( 'phone' => '123', 'firstName' => 'Visit http://x.ru' ), array( 'duplicate' => true ) ) );
check( 'důvody v pořadí signálů', array_column( $r['reasons'], 'id' ), array( 'link_short', 'bad_phone', 'duplicate' ) );
$r = $S::score( submission( array( 'message' => 'ahoj' ) ), array(), array( 'disabled' => array( 'short_message' ) ) );
check( 'neúplná konfigurace a statistiky doplní výchozí', array( $r['verdict'], ids( $r ) ), array( 'suspect', array( 'one_word' ) ) );
check( 'verdict bez důvodů', $S::verdict( array(), $S::default_config() ), 'ok' );
check( 'verdict s vlastním prahem', $S::verdict( array( array( 'id' => 'link_short', 'strength' => 'strong', 'count' => 0 ) ), array( 'strong_to_spam' => 2 ) ), 'ok' );

// Rows from a real site: name | e-mail | phone | message => verdict.
$rows = array(
	array( 'Musalimkhan Musalimkhan', 'musahibkhan10@gmail.com', '8005564803', 'Mggkgmegvkfv', 'spam' ),
	array( 'Savan Damor', 'damorsavan90@gmail.com', '919265133608', 'Savan Damor', 'not ok' ),
	array( 'Ghulam Hazrat Sarwari Saf', 'ghulam@example.com', '+93730067643', 'Hgu', 'not ok' ),
	// gibberish counts r and l as vowels now: short_message + one_word.
	array( 'Manuek', 'manuek@example.com', '972753307', 'Wtssdres.', 'not ok' ),
	array( '061 9907530', 'aljeria@example.com', '+252619907530', 'Aljeria', 'spam' ),
	array( 'Bantibhargav', 'b7446807@gmail.com', '9610998312', 'Rajashthan', 'not ok' ),
	array( '11065551392', 'x11065551392@example.com', '11065551392', '999999 ❤❤❤❤', 'spam' ),
	// no_words no longer comes with short_message: no_words alone.
	array( 'Erer Retr', 'erer@example.com', '11065551392', '199999', 'not ok' ),
	array( 'buca lumbala', 'buca@example.com', '939993476', 'Cahilo09', 'not ok' ),
	array( 'Kehkasha', 'nwnnwnnwnw8@gmail.com', '123456', 'Mujhe I phone chahiye', 'not ok' ),
	array( 'Voloju yashwanth', 'yashu@example.com', '6300791290', 'Yashu@123', 'not ok' ),
	array( 'Dominik Horváth', 'hdominik8624@gmail.com', '797857057', 'Ppotttřebuju vššechhno', 'ok' ),
	array( 'Priya', 'priya@example.com', '8639271793', 'Will I get the money or not', 'ok' ),
	array( 'Jan Novák', 'jan.novak@example.cz', '+420 603 123 456', 'Dobrý den, prosím o cenovou nabídku na rekonstrukci koupelny.', 'ok' ),
	array( 'John Smith', 'john@example.com', '+44 20 7946 0958', 'Hello, we would like a quote for 20 units.', 'ok' ),
);
foreach ( $rows as $i => $row ) {
	list( $name, $email, $phone, $message, $want ) = $row;
	$split = explode( ' ', $name, 2 );
	$r     = score(
		submission(
			array(
				'firstName' => $split[0],
				'lastName'  => isset( $split[1] ) ? $split[1] : '',
				'email'     => $email,
				'phone'     => $phone,
				'message'   => $message,
			)
		)
	);
	$got = 'not ok' === $want && 'ok' !== $r['verdict'] ? 'not ok' : $r['verdict'];
	check( sprintf( 'řádek %d (%s): %s [%s]', $i + 1, $message, $want, implode( ', ', array_column( $r['reasons'], 'id' ) ) ), $got, $want );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
