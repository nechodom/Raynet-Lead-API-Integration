<?php
/**
 * Tests for every RAYNET attribute a form can map to: the standard ones beyond
 * the builder's list, custom fields fetched from RAYNET, and how bulk-apply
 * treats what a form already has.
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
function call_private( $obj, $method, array $args ) {
	$r = new ReflectionMethod( $obj, $method );
	return $r->invokeArgs( $obj, $args );
}
function mapped_to( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		if ( '' !== $r['local_id'] ) { $out[ $r['remote_id'] ] = $r['local_id']; }
	}
	return $out;
}
function client() {
	return new Raynet_Lead_Api_Client( array(
		'base_url' => 'https://app.raynet.cz/api/v2/', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
	) );
}

// ---------- Custom field configuration from RAYNET ----------
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array(
	'success' => true,
	'data'    => array(
		'Company' => array( array( 'name' => 'Firemni_x1', 'label' => 'Jen u firem', 'dataType' => 'STRING' ) ),
		'Lead'    => array(
			array( 'name' => 'Pocet_zam_a1b2c', 'label' => 'Počet zaměstnanců', 'dataType' => 'BIG_DECIMAL', 'groupName' => 'Firma' ),
			array( 'name' => 'Velikost_d3e4f', 'label' => 'Velikost zakázky', 'dataType' => 'ENUMERATION', 'enumeration' => array( 'Malá', 'Velká', '' ) ),
			array( 'name' => 'VIP_b91d1', 'label' => 'VIP', 'dataType' => 'BOOLEAN' ),
			array( 'name' => 'Termin_g5h6', 'label' => 'Termín realizace', 'dataType' => 'DATE' ),
			array( 'name' => 'Schuzka_i7', 'label' => 'Schůzka', 'dataType' => 'DATETIME' ),
			array( 'name' => 'Cas_j8', 'label' => 'Čas volání', 'dataType' => 'TIME' ),
			array( 'name' => 'Pozn_k9', 'label' => 'Poznámka pro obchodníka', 'dataType' => 'TEXT' ),
			array( 'name' => 'Priloha_l0', 'label' => 'Příloha', 'dataType' => 'FILE' ),
			array( 'name' => 'Skore_m1', 'label' => 'Skóre', 'dataType' => 'STRING', 'readOnly' => true ),
			array( 'name' => 'Spatne"}jmeno', 'label' => 'Jméno s nesmysly', 'dataType' => 'STRING' ),
			array( 'label' => 'Bez jména', 'dataType' => 'STRING' ),
		),
	),
) ) );

$count = Raynet_Lead_Fields::refresh( client() );
$req   = end( $GLOBALS['wp_requests'] );

check( 'volá konfiguraci vlastních polí', $req['url'], 'https://app.raynet.cz/api/v2/customField/config/' );
check( 'volá se GET', $req['args']['method'], 'GET' );
check( 'načte jen pole leadu, bez souboru a jen pro čtení', $count, 8 );

$custom = Raynet_Lead_Fields::custom();
check( 'pole firmy se nebere', isset( $custom['Firemni_x1'] ), false );
check( 'soubor vynechán', isset( $custom['Priloha_l0'] ), false );
check( 'jen pro čtení vynecháno', isset( $custom['Skore_m1'] ), false );
check( 'jméno pole očištěno', isset( $custom['Spatnejmeno'] ), true );
check( 'popisek zachován', $custom['Pocet_zam_a1b2c']['label'], 'Počet zaměstnanců' );
check( 'typ zachován', $custom['Velikost_d3e4f']['type'], 'ENUMERATION' );
check( 'prázdná položka číselníku zahozena', $custom['Velikost_d3e4f']['enum'], array( 'Malá', 'Velká' ) );
check( 'skupina zachována', $custom['Pocet_zam_a1b2c']['group'], 'Firma' );
check( 'čas načtení uložen', Raynet_Lead_Fields::state()['fetched_at'] > 0, true );

// A failed fetch keeps what was there and remembers why.
$GLOBALS['wp_next_response'] = array( 'code' => 401, 'body' => '{}' );
$failed = Raynet_Lead_Fields::refresh( client() );
check( 'selhání vrátí chybu', is_wp_error( $failed ), true );
check( 'selhání nesmaže pole', count( Raynet_Lead_Fields::custom() ), 8 );
check( 'selhání si pamatuje důvod', false !== strpos( Raynet_Lead_Fields::state()['error'], '401' ), true );
check( 'selhání si pamatuje čas', Raynet_Lead_Fields::state()['failed_at'] > 0, true );

// maybe_refresh() must not retry straight after a failure: 20 bad logins lock
// the site's IP out of RAYNET for an hour.
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
) ) );
$before = count( $GLOBALS['wp_requests'] );
Raynet_Lead_Fields::maybe_refresh();
check( 'po selhání se hned nezkouší znovu', count( $GLOBALS['wp_requests'] ), $before );

$state              = get_option( Raynet_Lead_Fields::OPTION );
$state['failed_at'] = 0;
$state['fetched_at'] = time() - 60;
update_option( Raynet_Lead_Fields::OPTION, $state );
Raynet_Lead_Fields::maybe_refresh();
check( 'čerstvá konfigurace se nestahuje', count( $GLOBALS['wp_requests'] ), $before );

$state['fetched_at'] = time() - Raynet_Lead_Fields::MAX_AGE - 1;
update_option( Raynet_Lead_Fields::OPTION, $state );
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'Lead' => array() ) ) ) );
Raynet_Lead_Fields::maybe_refresh();
check( 'stará konfigurace se obnoví', count( $GLOBALS['wp_requests'] ), $before + 1 );
check( 'prázdná instance nemá vlastní pole', Raynet_Lead_Fields::custom(), array() );

// Put the fixture back for the rest of the file.
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'Lead' => array(
	array( 'name' => 'Pocet_zam_a1b2c', 'label' => 'Počet zaměstnanců', 'dataType' => 'BIG_DECIMAL' ),
	array( 'name' => 'Velikost_d3e4f', 'label' => 'Velikost zakázky', 'dataType' => 'ENUMERATION', 'enumeration' => array( 'Malá', 'Velká' ) ),
	array( 'name' => 'VIP_b91d1', 'label' => 'VIP', 'dataType' => 'BOOLEAN' ),
	array( 'name' => 'Termin_g5h6', 'label' => 'Termín realizace', 'dataType' => 'DATE' ),
	array( 'name' => 'Schuzka_i7', 'label' => 'Schůzka', 'dataType' => 'DATETIME' ),
	array( 'name' => 'Cas_j8', 'label' => 'Čas volání', 'dataType' => 'TIME' ),
	array( 'name' => 'Pozn_k9', 'label' => 'Poznámka pro obchodníka', 'dataType' => 'TEXT' ),
	array( 'name' => 'Kod_n2', 'label' => 'Kód akce', 'dataType' => 'STRING' ),
) ) ) ) );
Raynet_Lead_Fields::refresh( client() );

// ---------- Mapping rows ----------
$rows = Raynet_Lead_Fields::mapping_rows();
$ids  = array_column( $rows, 'id' );

check( 'nabízí celé jméno', in_array( 'fullName', $ids, true ), true );
check( 'nenabízí souhlas', in_array( 'consent', $ids, true ), false );
check( 'nabízí IČO', in_array( 'regNumber', $ids, true ), true );
check( 'nabízí druhý e-mail', in_array( 'email2', $ids, true ), true );
check( 'nabízí vlastní pole s prefixem', in_array( 'cf:Pocet_zam_a1b2c', $ids, true ), true );
check( 'vlastní pole jsou poslední', end( $rows )['group'], 'custom' );
check( 'vlastní pole je v popisku označené', false !== strpos( $rows[ array_search( 'cf:VIP_b91d1', $ids, true ) ]['label'], 'vlastní pole' ), true );
check( 'id jsou unikátní', count( $ids ), count( array_unique( $ids ) ) );

check( 'custom_name z id', Raynet_Lead_Fields::custom_name( 'cf:VIP_b91d1' ), 'VIP_b91d1' );
check( 'custom_name očistí', Raynet_Lead_Fields::custom_name( 'cf:A"b}c' ), 'Abc' );
check( 'standardní id není vlastní', Raynet_Lead_Fields::custom_name( 'email' ), '' );

// ---------- Coercion ----------
function coerced( $id, $raw ) {
	$r = Raynet_Lead_Fields::coerce( $id, $raw );
	return 'ok' === $r['status'] ? $r['value'] : $r['status'];
}
function coerced_typed( $id, $raw, $type ) {
	$r = Raynet_Lead_Fields::coerce( $id, $raw, $type );
	return 'ok' === $r['status'] ? $r['value'] : $r['status'];
}

check( 'číslo s mezerou a čárkou', coerced( 'cf:Pocet_zam_a1b2c', '1 234,50' ), 1234.5 );
check( 'číslo s nezlomitelnou mezerou', coerced( 'cf:Pocet_zam_a1b2c', "12\xc2\xa0000" ), 12000 );
check( 'anglický zápis', coerced( 'cf:Pocet_zam_a1b2c', '1,234.50' ), 1234.5 );
check( 'evropský s tečkami', coerced( 'cf:Pocet_zam_a1b2c', '1.234,5' ), 1234.5 );
check( 'celé číslo je int', coerced( 'cf:Pocet_zam_a1b2c', '42' ), 42 );
check( 'měna a procenta se odříznou', coerced( 'cf:Pocet_zam_a1b2c', '15 %' ), 15 );
check( 'záporné číslo', coerced( 'cf:Pocet_zam_a1b2c', '-3,5' ), -3.5 );
check( 'nesmysl není číslo', coerced( 'cf:Pocet_zam_a1b2c', 'hodně' ), 'invalid' );
check( 'prázdné se neposílá', coerced( 'cf:Pocet_zam_a1b2c', '  ' ), 'empty' );

check( 'číselník přesně', coerced( 'cf:Velikost_d3e4f', 'Velká' ), 'Velká' );
check( 'číselník bez diakritiky a velikosti', coerced( 'cf:Velikost_d3e4f', 'velka' ), 'Velká' );
check( 'mimo číselník', coerced( 'cf:Velikost_d3e4f', 'Obrovská' ), 'invalid' );

check( 'ano/ne: zaškrtnuto', coerced( 'cf:VIP_b91d1', 'on' ), true );
check( 'ano/ne: text možnosti', coerced( 'cf:VIP_b91d1', 'Ano, jsem VIP' ), true );
check( 'ano/ne: ne', coerced( 'cf:VIP_b91d1', 'Ne' ), false );
check( 'ano/ne: prázdné se neposílá', coerced( 'cf:VIP_b91d1', '' ), 'empty' );

check( 'datum ISO', coerced( 'cf:Termin_g5h6', '2026-10-01' ), '2026-10-01' );
check( 'datum česky', coerced( 'cf:Termin_g5h6', '1. 10. 2026' ), '2026-10-01' );
check( 'datum s lomítky', coerced( 'cf:Termin_g5h6', '01/10/2026' ), '2026-10-01' );
check( 'neexistující datum', coerced( 'cf:Termin_g5h6', '31. 2. 2026' ), 'invalid' );
check( 'datum a čas', coerced( 'cf:Schuzka_i7', '1.10.2026 9:05' ), '2026-10-01 09:05' );
check( 'datum a čas ISO', coerced( 'cf:Schuzka_i7', '2026-10-01T14:30' ), '2026-10-01 14:30' );
check( 'datum bez času', coerced( 'cf:Schuzka_i7', '2026-10-01' ), '2026-10-01 00:00' );
check( 'čas', coerced( 'cf:Cas_j8', '9:30' ), '09:30' );
check( 'čas s tečkou', coerced( 'cf:Cas_j8', '14.45' ), '14:45' );
check( 'nesmyslný čas', coerced( 'cf:Cas_j8', '25:00' ), 'invalid' );

check( 'dlouhý text drží řádky', coerced( 'cf:Pozn_k9', "a\nb" ), "a\nb" );
check( 'text bez HTML', coerced( 'cf:Kod_n2', '<b>X1</b>' ), 'X1' );
check( 'více hodnot se spojí', coerced( 'cf:Kod_n2', array( 'A', 'B' ) ), 'A, B' );

check( 'neznámé vlastní pole jde do poznámky', coerced( 'cf:Smazane_z9', 'něco' ), 'invalid' );
check( 'neznámé prázdné vlastní pole nic', coerced( 'cf:Smazane_z9', '' ), 'empty' );

check( 'IČO jako text', coerced( 'regNumber', ' 12345678 ' ), '12345678' );
check( 'druhý e-mail platný', coerced( 'email2', 'b@example.cz' ), 'b@example.cz' );
check( 'druhý e-mail neplatný', coerced( 'email2', 'nic' ), 'invalid' );
check( 'země kódem', coerced( 'country', 'sk' ), 'SK' );
check( 'země názvem', coerced( 'country', 'Česká republika' ), 'CZ' );
check( 'neznámá země', coerced( 'country', 'Atlantida' ), 'invalid' );
check( 'souhlas zaškrtnut = posílat', coerced( 'marketingConsent', 'on' ), false );
check( 'souhlas nezaškrtnut = neposílat', coerced( 'marketingConsent', '' ), true );

// ---------- Findings of the 2.5.0 review ----------

// Yes, no, and "cannot tell". A consent read wrongly is a GDPR problem.
function optin( $raw, $type ) {
	return Raynet_Lead_Fields::coerce( 'marketingConsent', $raw, $type );
}
check( 'nesouhlasím je odmítnutí', optin( 'Nesouhlasím', 'radio' )['value'], true );
check( 'ne, děkuji je odmítnutí', optin( 'Ne, děkuji', 'select' )['value'], true );
check( 'nechci je odmítnutí', optin( 'Nechci', 'radio' )['value'], true );
check( 'no thanks je odmítnutí', optin( 'No, thanks', 'radio' )['value'], true );
check( 'souhlasím je souhlas', optin( 'Souhlasím', 'radio' )['value'], false );
check( 'ano, chci je souhlas', optin( 'Ano, chci', 'select' )['value'], false );
check( 'zaškrtnutý souhlas je souhlas', optin( 'on', 'acceptance' )['value'], false );
check( 'zaškrtnuté zaškrtávátko s textem je souhlas', optin( 'Přeji si dostávat novinky', 'checkbox' )['value'], false );
check( 'newsletter není „ne“', optin( 'Newsletter', 'checkbox' )['value'], false );
check( 'nejasná odpověď není souhlas', optin( 'Možná později', 'radio' )['value'], true );
check( 'nejasná odpověď jde do poznámky', optin( 'Možná později', 'radio' )['note'], true );
check( 'jasná odpověď do poznámky nejde', optin( 'Souhlasím', 'radio' )['note'], false );

check( 'ano/ne: nesouhlas v radiu', coerced_typed( 'cf:VIP_b91d1', 'Nesouhlasím', 'radio' ), false );
check( 'ano/ne: nejasné v radiu jde do poznámky', coerced_typed( 'cf:VIP_b91d1', 'Nevím', 'radio' ), 'invalid' );
check( 'ano/ne: zaškrtnuté s textem', coerced_typed( 'cf:VIP_b91d1', 'Jsem VIP', 'checkbox' ), true );

// Numbers that cannot be read for certain are refused, not guessed.
check( '50 tis. se nehádá', coerced( 'cf:Pocet_zam_a1b2c', '50 tis.' ), 'invalid' );
check( 'rozpětí se nehádá', coerced( 'cf:Pocet_zam_a1b2c', 'od 10 do 20' ), 'invalid' );
check( '25.000 je nejednoznačné', coerced( 'cf:Pocet_zam_a1b2c', '25.000 Kč' ), 'invalid' );
check( '1,500 je nejednoznačné', coerced( 'cf:Pocet_zam_a1b2c', '1,500' ), 'invalid' );
check( '0,125 je desetinné', coerced( 'cf:Pocet_zam_a1b2c', '0,125' ), 0.125 );
check( 'opakovaná tečka jsou tisíce', coerced( 'cf:Pocet_zam_a1b2c', '1.234.567' ), 1234567 );
check( 'koruny s pomlčkou', coerced( 'cf:Pocet_zam_a1b2c', '1 499,- Kč' ), 1499 );
check( 'eura', coerced( 'cf:Pocet_zam_a1b2c', '€ 12,5' ), 12.5 );

// A time out of range is not a time.
check( 'hodina 29 neprojde', coerced( 'cf:Schuzka_i7', '1.2.2025 29:00' ), 'invalid' );
check( 'minuta 75 neprojde', coerced( 'cf:Schuzka_i7', '1.2.2025 9:75' ), 'invalid' );
check( 'čas s tečkou v datu', coerced( 'cf:Schuzka_i7', '1.2.2025 9.30' ), '2025-02-01 09:30' );

// Countries.
check( 'ČR je Česko', coerced( 'country', 'ČR' ), 'CZ' );
check( 'SR je Slovensko', coerced( 'country', 'SR' ), 'SK' );
check( 'UK je GB', coerced( 'country', 'UK' ), 'GB' );
check( 'neexistující kód neprojde', coerced( 'country', 'XX' ), 'invalid' );
check( 'platný kód projde', coerced( 'country', 'de' ), 'DE' );

// Enumeration items and labels are kept as RAYNET has them; labels reach
// Elementor escaped, because its mapping control prints them as markup.
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'Lead' => array(
	array( 'name' => 'Velikost_x1', 'label' => 'Velikost <b>firmy</b> & obrat', 'dataType' => 'ENUMERATION', 'enumeration' => array( '< 10 zaměstnanců', '10–50' ) ),
) ) ) ) );
Raynet_Lead_Fields::refresh( client() );
$stored_enum = Raynet_Lead_Fields::custom();
check( 'položka číselníku beze změny', $stored_enum['Velikost_x1']['enum'][0], '< 10 zaměstnanců' );
check( 'popisek bez značek', $stored_enum['Velikost_x1']['label'], 'Velikost firmy & obrat' );
check( 'sanitizovaná hodnota najde položku', coerced( 'cf:Velikost_x1', '&lt; 10 zaměstnanců' ), '< 10 zaměstnanců' );
$escaped_rows = array_column( Raynet_Lead_Fields::mapping_rows(), 'label', 'id' );
check( 'popisek pro Elementor escapovaný', $escaped_rows['cf:Velikost_x1'], 'Velikost firmy &amp; obrat (vlastní pole)' );

// A 200 without data is not "no custom fields".
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => '<html>Údržba</html>' );
check( 'odpověď bez dat je chyba', is_wp_error( Raynet_Lead_Fields::refresh( client() ) ), true );
check( 'a uložená pole zůstanou', isset( Raynet_Lead_Fields::custom()['Velikost_x1'] ), true );

// Back to the fixture the rest of the file expects.
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( 'Lead' => array(
	array( 'name' => 'Pocet_zam_a1b2c', 'label' => 'Počet zaměstnanců', 'dataType' => 'BIG_DECIMAL' ),
	array( 'name' => 'Velikost_d3e4f', 'label' => 'Velikost zakázky', 'dataType' => 'ENUMERATION', 'enumeration' => array( 'Malá', 'Velká' ) ),
	array( 'name' => 'VIP_b91d1', 'label' => 'VIP', 'dataType' => 'BOOLEAN' ),
	array( 'name' => 'Termin_g5h6', 'label' => 'Termín realizace', 'dataType' => 'DATE' ),
	array( 'name' => 'Schuzka_i7', 'label' => 'Schůzka', 'dataType' => 'DATETIME' ),
	array( 'name' => 'Cas_j8', 'label' => 'Čas volání', 'dataType' => 'TIME' ),
	array( 'name' => 'Pozn_k9', 'label' => 'Poznámka pro obchodníka', 'dataType' => 'TEXT' ),
	array( 'name' => 'Kod_n2', 'label' => 'Kód akce', 'dataType' => 'STRING' ),
) ) ) ) );
Raynet_Lead_Fields::refresh( client() );

// ---------- Payload ----------
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst', 'priority' => 'DEFAULT', 'lead_person' => '1',
) ) );

$form     = new Raynet_Lead_Form();
$settings = Raynet_Lead_Settings::all();
$values   = $form->collect_values( array( 'firstName' => 'Jan', 'lastName' => 'Novák', 'email' => 'jan@example.cz' ) );

$payload = call_private( $form, 'build_payload', array( $values, $settings, array(
	'raynet_attributes'    => array(
		'regNumber'                    => '12345678',
		'contactInfo.email2'           => 'b@example.cz',
		'contactInfo.doNotSendMM'      => false,
		'address.country'              => 'CZ',
		'socialNetworkContact.linkedin' => 'in/jan',
		'priority.evil'                => 'x',
		'contactInfo.a.b'              => 'x',
		'contactInfo.email3'           => '',
		'owner'                        => 7,
		'notice'                       => 'přepsáno',
	),
	'raynet_custom_fields' => array( 'Pocet_zam_a1b2c' => 12, 'VIP_b91d1' => true ),
) ) );

check( 'IČO na nejvyšší úrovni', $payload['regNumber'], '12345678' );
check( 'IČO znamená firmu', $payload['leadPerson'], false );
check( 'druhý e-mail vedle prvního', $payload['contactInfo'], array( 'email' => 'jan@example.cz', 'email2' => 'b@example.cz', 'doNotSendMM' => false ) );
check( 'země v adrese', $payload['address'], array( 'country' => 'CZ' ) );
check( 'sociální sítě', $payload['socialNetworkContact'], array( 'linkedin' => 'in/jan' ) );
check( 'cesta do cizího objektu se zahodí', is_array( $payload['priority'] ), false );
check( 'hlubší cesta se zahodí', isset( $payload['contactInfo']['a'] ), false );
check( 'prázdná hodnota se neposílá', isset( $payload['contactInfo']['email3'] ), false );
check( 'neznámá cesta nenastaví vlastníka', isset( $payload['owner'] ), false );
check( 'neznámá cesta nepřepíše poznámku', false !== strpos( (string) ( $payload['notice'] ?? '' ), 'přepsáno' ), false );
check( 'vlastní pole s typy', $payload['customFields'], array( 'Pocet_zam_a1b2c' => 12, 'VIP_b91d1' => true ) );

$plain = call_private( $form, 'build_payload', array( $values, $settings, array() ) );
check( 'bez vlastních polí žádný klíč', isset( $plain['customFields'] ), false );
check( 'bez IČO zůstává fyzická osoba', $plain['leadPerson'], true );

// The fallback e-mail carries every mapped value, the consent the right way round.
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst', 'fallback_email' => 'zaloha@example.cz',
) ) );
$GLOBALS['wp_mails']         = array();
$GLOBALS['wp_next_response'] = array( 'code' => 500, 'body' => '{}' );
$failed_lead = $form->submit_lead( $values, Raynet_Lead_Settings::all(), array(
	'raynet_attributes'    => array( 'regNumber' => '12345678', 'contactInfo.doNotSendMM' => false ),
	'raynet_custom_fields' => array( 'Pocet_zam_a1b2c' => 12, 'VIP_b91d1' => true ),
	'raynet_extras'        => array( 'Velikost zakázky' => 'Obrovská' ),
) );
$mail_body = isset( $GLOBALS['wp_mails'][0] ) ? $GLOBALS['wp_mails'][0]['body'] : '';
check( 'selhání vrátí chybu', is_wp_error( $failed_lead ), true );
check( 'záložní e-mail má IČO', false !== strpos( $mail_body, 'IČO: 12345678' ), true );
check( 'záložní e-mail má vlastní pole s popiskem', false !== strpos( $mail_body, 'Počet zaměstnanců: 12' ), true );
check( 'záložní e-mail má ano/ne slovem', false !== strpos( $mail_body, 'VIP: ano' ), true );
check( 'záložní e-mail má poznámkové hodnoty', false !== strpos( $mail_body, 'Velikost zakázky: Obrovská' ), true );
check( 'souhlas v e-mailu správně otočený', false !== strpos( $mail_body, 'Souhlas s marketingovými sděleními: ano' ), true );

// ---------- Guessing the mapping ----------
$guess = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'ico', 'type' => 'text', 'label' => 'IČO' ),
	array( 'id' => 'dph', 'type' => 'text', 'label' => 'IČ DPH' ),
	array( 'id' => 'fir', 'type' => 'text', 'label' => 'Název firmy' ),
	array( 'id' => 'zam', 'type' => 'number', 'label' => 'Počet zaměstnanců' ),
	array( 'id' => 'obc', 'type' => 'text', 'label' => 'Poznámka pro obchodníka' ),
	array( 'id' => 'web', 'type' => 'url', 'label' => 'Adresa vašeho webu' ),
	array( 'id' => 'e', 'type' => 'email', 'label' => 'E-mail' ),
	array( 'id' => 'e2', 'type' => 'email', 'label' => 'Potvrzení e-mailu' ),
) ) );

check( 'IČO je IČO, ne firma', $guess['regNumber'], 'ico' );
check( 'IČ DPH je daňové číslo', $guess['taxNumber'], 'dph' );
check( 'firma zůstává firmou', $guess['companyName'], 'fir' );
check( 'vlastní pole podle přesného popisku', $guess['cf:Pocet_zam_a1b2c'], 'zam' );
check( 'přesná shoda s vlastním polem před obecným odhadem', $guess['cf:Pozn_k9'], 'obc' );
check( 'zpráva tím pádem volná', isset( $guess['message'] ), false );
check( 'adresa webu je web', $guess['www'], 'web' );
check( 'adresa webu není ulice', isset( $guess['street'] ), false );
check( 'potvrzení e-mailu se nehádá jako druhý e-mail', isset( $guess['email2'] ), false );

// What someone mapped by hand is kept; the guess fills only the gaps.
$kept = mapped_to( Raynet_Elementor_Forms::auto_map(
	array(
		array( 'id' => 'a', 'type' => 'text', 'label' => 'Jméno' ),
		array( 'id' => 'b', 'type' => 'text', 'label' => 'Kontakt' ),
		array( 'id' => 'c', 'type' => 'email', 'label' => 'E-mail' ),
		array( 'id' => 'd', 'type' => 'textarea', 'label' => 'Zpráva' ),
	),
	array( 'phone' => 'b', 'message' => 'c' )
) );
check( 'ruční telefon zůstal', $kept['phone'], 'b' );
check( 'ruční mapování zůstalo, i když se odhad liší', $kept['message'], 'c' );
check( 'ručně použité pole se znovu nehádá', isset( $kept['email'] ), false );
check( 'mezera se doplní', $kept['firstName'], 'a' );
check( 'volné pole se nepřiřadí k obsazenému atributu', in_array( 'd', $kept, true ), false );

$half = mapped_to( Raynet_Elementor_Forms::auto_map(
	array( array( 'id' => 'n', 'type' => 'text', 'label' => 'Jméno a příjmení' ) ),
	array( 'lastName' => 'x' )
) );
check( 'ruční půlka zakáže celé jméno', isset( $half['fullName'] ), false );

$whole = mapped_to( Raynet_Elementor_Forms::auto_map(
	array( array( 'id' => 'p', 'type' => 'text', 'label' => 'Příjmení' ) ),
	array( 'fullName' => 'x' )
) );
check( 'ruční celé jméno zakáže půlky', isset( $whole['lastName'] ), false );

$orphan = Raynet_Elementor_Forms::auto_map( array(), array( 'cf:Nenactene_q1' => 'f' ) );
check( 'nenačtené vlastní pole si mapování ponechá', mapped_to( $orphan )['cf:Nenactene_q1'], 'f' );

// ---------- configure() and the form's existing state ----------
$fresh = Raynet_Elementor_Forms::configure(
	array( 'form_fields' => array( array( 'custom_id' => 'e', 'field_type' => 'email', 'field_label' => 'E-mail' ) ) ),
	array(),
	true
);
check( 'nedotčené akce: e-mail zůstane', $fresh['submit_actions'], array( 'email', 'raynet_crm' ) );

$none = Raynet_Elementor_Forms::configure( array( 'submit_actions' => array() ), array(), false );
check( 'výslovně žádné akce: jen RAYNET', $none['submit_actions'], array( 'raynet_crm' ) );

$redirect = Raynet_Elementor_Forms::configure( array( 'submit_actions' => array( 'redirect' ) ), array(), false );
check( 'vlastní akce zůstanou', $redirect['submit_actions'], array( 'redirect', 'raynet_crm' ) );

$manual = array(
	'submit_actions'        => array( 'email', 'raynet_crm' ),
	'form_fields'           => array(
		array( 'custom_id' => 'mail', 'field_type' => 'email', 'field_label' => 'E-mail' ),
		array( 'custom_id' => 'tel', 'field_type' => 'text', 'field_label' => 'Kde vás zastihneme' ),
		array( 'custom_id' => 'msg', 'field_type' => 'textarea', 'field_label' => 'Zpráva' ),
	),
	'raynet_crm_fields_map' => array(
		array( 'remote_id' => 'email', 'local_id' => 'mail' ),
		array( 'remote_id' => 'phone', 'local_id' => 'tel' ),
		array( 'remote_id' => 'cf:Kod_n2', 'local_id' => 'smazane' ),
		array( 'remote_id' => 'nesmysl', 'local_id' => 'msg' ),
	),
);
$reapplied = mapped_to( Raynet_Elementor_Forms::configure( $manual, array(), true )['raynet_crm_fields_map'] );
check( 'opakované nasazení nechá ruční telefon', $reapplied['phone'], 'tel' );
check( 'a doplní zprávu', $reapplied['message'], 'msg' );
check( 'mapování na smazané pole se zahodí', isset( $reapplied['cf:Kod_n2'] ), false );
check( 'neznámý atribut se zahodí', isset( $reapplied['nesmysl'] ), false );

$untouched = Raynet_Elementor_Forms::configure( $manual, array(), false );
check( 'bez odhadu se mapování nedotkne', $untouched['raynet_crm_fields_map'], $manual['raynet_crm_fields_map'] );

check( 'kontakt namapován', Raynet_Elementor_Forms::maps_contact( $manual ), true );
check( 'bez kontaktu', Raynet_Elementor_Forms::maps_contact( array(
	'form_fields'           => array( array( 'custom_id' => 'n', 'field_type' => 'text', 'field_label' => 'Jméno' ) ),
	'raynet_crm_fields_map' => array( array( 'remote_id' => 'firstName', 'local_id' => 'n' ) ),
) ), false );
check( 'kontakt na smazané pole se nepočítá', Raynet_Elementor_Forms::maps_contact( array(
	'form_fields'           => array(),
	'raynet_crm_fields_map' => array( array( 'remote_id' => 'email', 'local_id' => 'pryc' ) ),
) ), false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
