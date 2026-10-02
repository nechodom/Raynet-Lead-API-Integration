<?php
/**
 * Tests for choosing a lead's owner from the RAYNET users.
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
function client() {
	return new Raynet_Lead_Api_Client( array(
		'base_url' => 'https://app.raynet.cz/api/v2/', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
	) );
}
function capture( callable $fn ) {
	ob_start();
	$fn();
	return ob_get_clean();
}

$fixture = json_encode( array(
	'success'    => true,
	'totalCount' => 5,
	'data'       => array(
		array( 'id' => 8, 'username' => 'petr@firma.cz', 'person' => array( 'id' => 9, 'fullName' => 'Petr Svoboda' ), 'userRole' => 'USER' ),
		array( 'id' => 10, 'username' => 'jana@firma.cz', 'person' => array( 'id' => 11, 'fullName' => 'Jana Nováková' ), 'userRole' => 'ADMIN' ),
		array( 'id' => 12, 'username' => 'api@firma.cz', 'person' => null, 'userRole' => 'API' ),
		array( 'id' => 13, 'username' => 'byvaly@firma.cz', 'person' => array( 'id' => 14, 'fullName' => 'Bývalý Kolega' ), 'rowInfo.rowAccess' => 'INVALID' ),
		array( 'id' => 15, 'username' => 'adam@firma.cz', 'person' => array( 'id' => 16, 'fullName' => '<b>Adam</b> Černý' ) ),
	),
) );

// ---------- Reading the users ----------
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => $fixture );
$users = client()->get_users();
$req   = end( $GLOBALS['wp_requests'] );

check( 'volá seznam uživatelů', $req['url'], 'https://app.raynet.cz/api/v2/userAccount/?limit=1000&offset=0' );
check( 'klíčem je kontaktní osoba, ne účet', array_keys( $users ), array( 16, 11, 9 ) );
check( 'jméno a login zvlášť', $users[11], array( 'name' => 'Jana Nováková', 'login' => 'jana@firma.cz' ) );
check( 'bez značek z RAYNETu', $users[16]['name'], 'Adam Černý' );
check( 'účet bez osoby vynechán', isset( $users[12] ) || isset( $users[13] ), false );
check( 'zneplatněný záznam vynechán', isset( $users[14] ), false );
check( 'seřazeno podle jména bez diakritiky', array_column( $users, 'name' ), array( 'Adam Černý', 'Jana Nováková', 'Petr Svoboda' ) );

// More than a thousand accounts: read page by page.
$page_one = array();
for ( $i = 1; $i <= 1000; $i++ ) {
	$page_one[] = array( 'id' => 1000 + $i, 'username' => "u$i@firma.cz", 'person' => array( 'id' => 5000 + $i, 'fullName' => "Uživatel $i" ) );
}
$GLOBALS['wp_request_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'totalCount' => 1001, 'data' => $page_one ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'totalCount' => 1001, 'data' => array( array( 'id' => 9999, 'username' => 'posledni@firma.cz', 'person' => array( 'id' => 9998, 'fullName' => 'Poslední' ) ) ) ) ) ),
);
$before_pages = count( $GLOBALS['wp_requests'] );
$many         = client()->get_users();
check( 'načte i druhou stránku', count( $many ), 1001 );
check( 'druhá stránka od offsetu 1000', end( $GLOBALS['wp_requests'] )['url'], 'https://app.raynet.cz/api/v2/userAccount/?limit=1000&offset=1000' );
check( 'dva požadavky', count( $GLOBALS['wp_requests'] ) - $before_pages, 2 );

// A full page without totalCount may still be followed by more.
$GLOBALS['wp_request_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'data' => $page_one ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'data' => array( array( 'id' => 9999, 'username' => 'posledni@firma.cz', 'person' => array( 'id' => 9998, 'fullName' => 'Poslední' ) ) ) ) ) ),
);
$before_pages = count( $GLOBALS['wp_requests'] );
check( 'bez totalCount čte dál', count( client()->get_users() ), 1001 );
check( 'bez totalCount dva požadavky', count( $GLOBALS['wp_requests'] ) - $before_pages, 2 );

// Exactly a thousand, with the total given: no needless second request.
$GLOBALS['wp_request_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'totalCount' => 1000, 'data' => $page_one ) ) ),
);
$before_pages = count( $GLOBALS['wp_requests'] );
check( 'přesně tisíc', count( client()->get_users() ), 1000 );
check( 'přesně tisíc jedním požadavkem', count( $GLOBALS['wp_requests'] ) - $before_pages, 1 );

// Exactly a thousand without the total: one more request finds the end.
$GLOBALS['wp_request_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'data' => $page_one ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'data' => array() ) ) ),
);
$before_pages = count( $GLOBALS['wp_requests'] );
check( 'tisíc bez totalCount', count( client()->get_users() ), 1000 );
check( 'prázdná stránka ukončí čtení', count( $GLOBALS['wp_requests'] ) - $before_pages, 2 );
$GLOBALS['wp_request_queue'] = array();

$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => '<html>údržba</html>' );
check( 'odpověď bez dat je chyba', is_wp_error( client()->get_users() ), true );

// A key that may create leads but not list users: say so, not "create leads".
$GLOBALS['wp_next_response'] = array( 'code' => 403, 'body' => '{}' );
$forbidden = client()->get_users();
check( '403 je chyba', is_wp_error( $forbidden ), true );
check( '403 mluví o vypisování uživatelů', is_wp_error( $forbidden ) && false !== strpos( $forbidden->get_error_message(), 'vypisovat uživatele (403)' ), true );
check( '403 nemluví o leadech', is_wp_error( $forbidden ) && false !== strpos( $forbidden->get_error_message(), 'zakládat leady' ), false );
check( '403 nese stav', is_wp_error( $forbidden ) ? $forbidden->get_error_data()['status'] : 0, 403 );

// ---------- Keeping the list ----------
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
) ) );
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => $fixture );
check( 'načtení vrátí počet', Raynet_Lead_Users::refresh( client() ), 3 );
check( 'seznam uložen', Raynet_Lead_Users::all()[9], 'Petr Svoboda (petr@firma.cz)' );
check( 'jen jména pro Elementor', Raynet_Lead_Users::names(), array( 16 => 'Adam Černý', 11 => 'Jana Nováková', 9 => 'Petr Svoboda' ) );

// The list belongs to one connection.
$connected = get_option( Raynet_Lead_Settings::OPTION );
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array_merge( $connected, array( 'instance_name' => 'jina-instance', 'api_key' => '' ) ) ) );
check( 'po změně instance se seznam nenabízí', Raynet_Lead_Users::all(), array() );
check( 'a bude se znovu načítat', Raynet_Lead_Users::state()['fetched_at'], 0 );
update_option( Raynet_Lead_Settings::OPTION, $connected );
check( 'po návratu původní seznam platí', count( Raynet_Lead_Users::all() ), 3 );

$GLOBALS['wp_next_response'] = array( 'code' => 401, 'body' => '{}' );
check( 'selhání vrátí chybu', is_wp_error( Raynet_Lead_Users::refresh( client() ) ), true );
check( 'selhání seznam nesmaže', count( Raynet_Lead_Users::all() ), 3 );
check( 'selhání si pamatuje důvod', false !== strpos( Raynet_Lead_Users::state()['error'], '401' ), true );

$before = count( $GLOBALS['wp_requests'] );
Raynet_Lead_Users::maybe_refresh();
check( 'po selhání se hned nezkouší znovu', count( $GLOBALS['wp_requests'] ), $before );

$state               = get_option( Raynet_Lead_Users::OPTION );
$state['failed_at']  = 0;
$state['fetched_at'] = time() - 60;
update_option( Raynet_Lead_Users::OPTION, $state );
Raynet_Lead_Users::maybe_refresh();
check( 'čerstvý seznam se nestahuje', count( $GLOBALS['wp_requests'] ), $before );

$state['fetched_at'] = time() - Raynet_Lead_Users::MAX_AGE - 1;
update_option( Raynet_Lead_Users::OPTION, $state );
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => $fixture );
Raynet_Lead_Users::maybe_refresh();
check( 'starý seznam se obnoví', count( $GLOBALS['wp_requests'] ), $before + 1 );

// ---------- The picker ----------
$options = Raynet_Lead_Users::options( 'Zdědit' );
check( 'první volba je prázdná', array_slice( $options, 0, 1, true ), array( '' => 'Zdědit' ) );
check( 'hodnoty jsou ID osob', array_map( 'strval', array_keys( $options ) ), array( '', '16', '11', '9' ) );

$kept = Raynet_Lead_Users::options( 'Zdědit', 77 );
check( 'dřívější vlastník mimo seznam zůstane', isset( $kept['77'] ) && false !== strpos( $kept['77'], '77' ), true );

$html = capture( function () {
	Raynet_Lead_Users::field( 'raynet[owner]', 'raynet-owner', 11, 'Neposílat' );
} );
check( 'výběr místo čísla', false !== strpos( $html, '<select id="raynet-owner" name="raynet[owner]"' ), true );
check( 'vybraný vlastník', (bool) preg_match( '/value="11" selected/', $html ), true );
check( 'jméno v nabídce', false !== strpos( $html, 'Jana Nováková (jana@firma.cz)' ), true );

$unknown = capture( function () {
	Raynet_Lead_Users::field( 'raynet[owner]', 'raynet-owner', 77, 'Neposílat' );
} );
check( 'neznámý vlastník zůstane vybraný', (bool) preg_match( '/value="77" selected/', $unknown ), true );

$none = capture( function () {
	Raynet_Lead_Users::field( 'raynet[owner]', 'raynet-owner', 0, 'Neposílat' );
} );
check( 'bez vlastníka vybraná prázdná volba', (bool) preg_match( '/value="" selected/', $none ), true );

// Same names: the login tells them apart, in Elementor too.
$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array(
	array( 'id' => 1, 'username' => 'jan.novak@firma.cz', 'person' => array( 'id' => 2, 'fullName' => 'Jan Novák' ) ),
	array( 'id' => 3, 'username' => 'jan.novak2@firma.cz', 'person' => array( 'id' => 4, 'fullName' => 'Jan Novák' ) ),
	array( 'id' => 5, 'username' => 'eva@firma.cz', 'person' => array( 'id' => 6, 'fullName' => 'Eva Malá' ) ),
) ) ) );
Raynet_Lead_Users::refresh( client() );
$same_names = Raynet_Lead_Users::names();
ksort( $same_names );
check( 'stejná jména rozliší login', $same_names, array( 2 => 'Jan Novák (jan.novak@firma.cz)', 4 => 'Jan Novák (jan.novak2@firma.cz)', 6 => 'Eva Malá' ) );
check( 'možnosti pro Elementor bez loginů', Raynet_Lead_Users::options( 'Zdědit', 0, true )[6], 'Eva Malá' );

update_option( Raynet_Lead_Users::OPTION, array() );
$fallback = capture( function () {
	Raynet_Lead_Users::field( 'raynet[owner]', 'raynet-owner', 42, 'Neposílat' );
} );
check( 'bez seznamu číselné pole', false !== strpos( $fallback, 'type="number"' ), true );
check( 'číselné pole drží hodnotu', false !== strpos( $fallback, 'value="42"' ), true );
check( 'nenačtený seznam řekne proč', false !== strpos( $fallback, 'zatím není načtený' ), true );

$GLOBALS['wp_next_response'] = array( 'code' => 401, 'body' => '{}' );
Raynet_Lead_Users::refresh( client() );
$failed_field = capture( function () {
	Raynet_Lead_Users::field( 'raynet[owner]', 'raynet-owner', 0, 'Neposílat' );
} );
check( 'chyba načtení je u pole vidět', false !== strpos( $failed_field, 'nepodařilo načíst' ) && false !== strpos( $failed_field, '401' ), true );

// Custom fields belong to one connection too; a list from before the
// fingerprint stays trusted so live forms keep working after the update.
update_option( Raynet_Lead_Fields::OPTION, array( 'fields' => array( 'Stare_x1' => array( 'label' => 'Staré', 'type' => 'STRING', 'enum' => array(), 'group' => '' ) ), 'fetched_at' => time() ) );
check( 'vlastní pole bez otisku platí', isset( Raynet_Lead_Fields::custom()['Stare_x1'] ), true );
update_option( Raynet_Lead_Fields::OPTION, array( 'connection' => 'jina', 'fields' => array( 'Cizi_x1' => array( 'label' => 'Cizí', 'type' => 'STRING', 'enum' => array(), 'group' => '' ) ), 'fetched_at' => time() ) );
check( 'vlastní pole z jiné instance neplatí', Raynet_Lead_Fields::custom(), array() );

// The chosen owner reaches the lead as the contact person id.
check( 'uložení výběru dá číslo', Raynet_Lead_Settings::sanitize( array( 'owner' => '11' ) )['owner'], 11 );
check( 'prázdná volba dá nulu', Raynet_Lead_Settings::sanitize( array( 'owner' => '' ) )['owner'], 0 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
