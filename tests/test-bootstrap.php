<?php
/**
 * Hook timing.
 *
 * Regression guard for a fatal shipped in 2.1.0: the plugin registered its post
 * type and ran the form migration on `plugins_loaded`. WordPress builds
 * $wp_rewrite only after that hook, and wp_insert_post() reaches for it through
 * get_permalink(), so every request on the site died with
 * "Call to a member function get_extra_permastruct() on null".
 */

require __DIR__ . '/wp-stubs.php';

require_once RAYNET_LEAD_PATH . 'raynet-lead-api-integration.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n"; }
}

function hooks_for( $hook ) {
	return array_values( array_filter( $GLOBALS['wp_hooks'], function ( $h ) use ( $hook ) {
		return $h['hook'] === $hook;
	} ) );
}

function hook_has( $hook, $needle ) {
	foreach ( hooks_for( $hook ) as $h ) {
		$cb = $h['cb'];

		if ( is_array( $cb ) && is_string( $cb[0] ) && $cb[1] === $needle ) {
			return true;
		}

		if ( is_string( $cb ) && $cb === $needle ) {
			return true;
		}
	}

	return false;
}

check( 'bootstrap na plugins_loaded', hook_has( 'plugins_loaded', 'raynet_lead_bootstrap' ), true );
check( 'typ obsahu se registruje na init', hook_has( 'init', 'register' ), true );
check( 'migrace formulářů běží na init', hook_has( 'init', 'maybe_migrate' ), true );

// The migration must run after the post type exists.
$prio = array();
foreach ( hooks_for( 'init' ) as $h ) {
	if ( is_array( $h['cb'] ) ) { $prio[ $h['cb'][1] ] = $h['prio']; }
}
check( 'registrace předchází migraci', $prio['register'] < $prio['maybe_migrate'], true );

// The heart of it: bootstrap must touch neither the post type nor the database.
$GLOBALS['wp_posts']      = array();
$GLOBALS['wp_post_types'] = array();

raynet_lead_bootstrap();

check( 'bootstrap neregistruje typ obsahu', count( $GLOBALS['wp_post_types'] ), 0 );
check( 'bootstrap nezakládá příspěvek',     count( $GLOBALS['wp_posts'] ), 0 );

// And the init callbacks, in their real order, do the work.
Raynet_Lead_Form_Post_Type::register();
check( 'init registruje raynet_form', $GLOBALS['wp_post_types'], array( 'raynet_form' ) );

Raynet_Lead_Form_Post_Type::maybe_migrate();
check( 'init založí výchozí formulář', Raynet_Lead_Form_Post_Type::default_id() > 0, true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
