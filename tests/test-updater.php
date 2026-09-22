<?php
/**
 * Updates from GitHub releases: what gets offered, and what must not be.
 */

require __DIR__ . '/wp-stubs.php';

function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n"; }
}

function release_body( $tag, $assets = null ) {
	if ( null === $assets ) {
		$assets = array(
			array( 'browser_download_url' => 'https://github.com/nechodom/Raynet-Lead-API-Integration/releases/download/' . $tag . '/raynet-lead-api-integration.zip' ),
		);
	}

	return wp_json_encode( array(
		'tag_name'     => $tag,
		'published_at' => '2026-09-22T15:29:14Z',
		'body'         => 'Popis změn.',
		'assets'       => $assets,
	) );
}

function queue( $body, $code = 200 ) {
	$GLOBALS['wp_http_queue'] = array( array( 'code' => $code, 'body' => $body ) );
}

function reset_all( $enabled = 1 ) {
	$GLOBALS['wp_site_transients'] = array();
	$GLOBALS['wp_requests']        = array();
	$GLOBALS['wp_http_queue']      = array();
	$GLOBALS['wp_options']         = array();
	update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
		'region' => 'cz', 'updates_enabled' => $enabled ? '1' : '',
	) ) );
}

function blank_transient() {
	$t = new stdClass();
	$t->response  = array();
	$t->no_update = array();
	return $t;
}

$plugin  = 'raynet-lead-api-integration/raynet-lead-api-integration.php';
$updater = new Raynet_Lead_Updater();

// RAYNET_LEAD_VERSION is 2.0.0 in the stubs; a newer tag must be offered.
check( 'stub version', RAYNET_LEAD_VERSION, '2.0.0' );

// ---------- A newer release is offered ----------
reset_all();
queue( release_body( 'v2.5.0' ) );
$t = $updater->inject_update( blank_transient() );
check( 'novější verze nabídnuta',   isset( $t->response[ $plugin ] ), true );
check( 'verze bez v',               $t->response[ $plugin ]->new_version, '2.5.0' );
check( 'balíček je asset z GitHubu', str_contains( $t->response[ $plugin ]->package, 'releases/download/v2.5.0/' ), true );
check( 'slug odpovídá složce',      $t->response[ $plugin ]->slug, 'raynet-lead-api-integration' );
check( 'basename vyplněn',          $t->response[ $plugin ]->plugin, $plugin );

// ---------- Same version: no update, but WordPress must still know the plugin ----------
reset_all();
queue( release_body( 'v2.0.0' ) );
$t = $updater->inject_update( blank_transient() );
check( 'stejná verze se nenabízí',     isset( $t->response[ $plugin ] ), false );
check( 'zapsáno do no_update',         isset( $t->no_update[ $plugin ] ), true );
check( 'no_update nenese balíček',     $t->no_update[ $plugin ]->package, '' );

// ---------- Older release ----------
reset_all();
queue( release_body( 'v1.9.0' ) );
$t = $updater->inject_update( blank_transient() );
check( 'starší verze se nenabízí', isset( $t->response[ $plugin ] ), false );

// ---------- Switched off ----------
reset_all( 0 );
queue( release_body( 'v9.0.0' ) );
$t = $updater->inject_update( blank_transient() );
check( 'vypnuté aktualizace nenabízí nic', isset( $t->response[ $plugin ] ), false );
check( 'vypnuté aktualizace nevolají API', count( $GLOBALS['wp_requests'] ), 0 );

// ---------- An asset served from somewhere else is refused ----------
reset_all();
queue( release_body( 'v3.0.0', array(
	array( 'browser_download_url' => 'https://evil.example.com/raynet.zip' ),
) ) );
$t = $updater->inject_update( blank_transient() );
check( 'cizí host odmítnut', isset( $t->response[ $plugin ] ), false );

// ---------- No zip attached: the source zipball would land in the wrong folder ----------
reset_all();
queue( release_body( 'v3.0.0', array(
	array( 'browser_download_url' => 'https://github.com/nechodom/Raynet-Lead-API-Integration/releases/download/v3.0.0/notes.txt' ),
) ) );
$t = $updater->inject_update( blank_transient() );
check( 'release bez zip se nenabízí', isset( $t->response[ $plugin ] ), false );

// ---------- Network and API failures ----------
reset_all();
queue( '', 500 );
$t = $updater->inject_update( blank_transient() );
check( 'chyba API nic nenabídne', isset( $t->response[ $plugin ] ), false );
check( 'transient nepadá',        is_object( $t ), true );

reset_all();
queue( 'není to json' );
$t = $updater->inject_update( blank_transient() );
check( 'nevalidní JSON nic nenabídne', isset( $t->response[ $plugin ] ), false );

reset_all();
queue( wp_json_encode( array( 'message' => 'API rate limit exceeded' ) ), 403 );
$t = $updater->inject_update( blank_transient() );
check( 'překročený limit nic nenabídne', isset( $t->response[ $plugin ] ), false );

// A non-object transient is handed straight back; WordPress does this early on.
check( 'null transient projde beze změny', $updater->inject_update( null ), null );

// ---------- The lookup is cached ----------
reset_all();
queue( release_body( 'v2.5.0' ) );
$updater->inject_update( blank_transient() );
$after_first = count( $GLOBALS['wp_requests'] );
$updater->inject_update( blank_transient() );
check( 'první volání sáhlo na API', $after_first, 1 );
check( 'druhé volání použilo cache', count( $GLOBALS['wp_requests'] ), 1 );

$updater->flush();
queue( release_body( 'v2.6.0' ) );
$t = $updater->inject_update( blank_transient() );
check( 'po vyprázdnění cache se ptá znovu', $t->response[ $plugin ]->new_version, '2.6.0' );

// ---------- Details dialog ----------
reset_all();
queue( release_body( 'v2.5.0' ) );
$info = $updater->details( false, 'plugin_information', (object) array( 'slug' => 'raynet-lead-api-integration' ) );
check( 'detaily vrácené',        is_object( $info ), true );
check( 'detaily nesou verzi',    $info->version, '2.5.0' );
check( 'changelog z release',    str_contains( $info->sections['changelog'], 'Popis změn.' ), true );

$other = $updater->details( false, 'plugin_information', (object) array( 'slug' => 'jiny-plugin' ) );
check( 'cizí plugin neobsloužen', $other, false );
check( 'jiná akce neobsloužena',  $updater->details( false, 'query_plugins', (object) array( 'slug' => 'raynet-lead-api-integration' ) ), false );

// Release notes are escaped, not trusted as markup.
reset_all();
queue( wp_json_encode( array(
	'tag_name' => 'v2.5.0',
	'body'     => '<script>alert(1)</script>',
	'assets'   => array( array( 'browser_download_url' => 'https://github.com/nechodom/Raynet-Lead-API-Integration/releases/download/v2.5.0/x.zip' ) ),
) ) );
$info = $updater->details( false, 'plugin_information', (object) array( 'slug' => 'raynet-lead-api-integration' ) );
check( 'poznámky z GitHubu escapované', str_contains( $info->sections['changelog'], '<script>' ), false );

// ---------- Status for the settings screen ----------
reset_all();
queue( release_body( 'v2.5.0' ) );
$updater->fetch_latest();
$status = $updater->status();
check( 'status hlásí nainstalovanou', $status['current'], '2.0.0' );
check( 'status hlásí poslední',       $status['latest'], '2.5.0' );
check( 'status hlásí dostupnost',     $status['available'], true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
