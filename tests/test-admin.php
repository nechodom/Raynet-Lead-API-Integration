<?php
/**
 * Smoke tests for the settings screen: it must render without fatals,
 * cover every setting, and never print the stored API key.
 */

require __DIR__ . '/wp-stubs.php';

function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function current_user_can( $cap ) { return true; }
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />'; }
function submit_button( $text = null ) { echo '<button type="submit">Uložit</button>'; }
function selected( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? " selected='selected'" : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? " checked='checked'" : ''; if ( $echo ) { echo $r; } return $r; }
function wp_date( $format, $ts = null ) { return date( $format, $ts ); }
$GLOBALS['wp_menu_pages']    = array();
$GLOBALS['wp_submenu_pages'] = array();
function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
	$GLOBALS['wp_menu_pages'][] = array( 'slug' => $slug, 'cap' => $cap, 'title' => $menu_title );
	return 'toplevel_page_' . $slug;
}
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '' ) {
	$GLOBALS['wp_submenu_pages'][] = array( 'parent' => $parent, 'slug' => $slug, 'cap' => $cap, 'title' => $menu_title );
	return $parent . '_page_' . $slug;
}
function register_setting( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_localize_script( ...$a ) {}

require_once dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/class-raynet-admin.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got: " . var_export( $got, true ) . "\n"; }
}

update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'sk', 'username' => 'u@e.cz', 'api_key' => 'SUPERSECRETKEY',
	'instance_name' => 'inst', 'priority' => 'CRITICAL', 'honeypot_enabled' => '1',
	'notice_prefix' => 'Z webu', 'category' => '5',
) ) );
update_option( 'raynet_lead_last_error', array( 'message' => 'RAYNET API vrátilo chybu 401', 'time' => time() ) );

$admin = new Raynet_Lead_Admin();

ob_start();
$admin->render_page();
$html = ob_get_clean();

check( 'page renders something', strlen( $html ) > 2000, true );
check( 'API KEY IS NEVER PRINTED', str_contains( $html, 'SUPERSECRETKEY' ), false );
check( 'api key field is a password input', (bool) preg_match( '/type="password"[^>]*name="raynet_lead_settings\[api_key\]"/', $html ), true );
check( 'last error surfaced', str_contains( $html, 'chybu 401' ), true );
check( 'shortcode documented', str_contains( $html, '[raynet_lead_form]' ), true );
check( 'saved region preselected', (bool) preg_match( '/value="sk"[^>]*selected/', $html ), true );
check( 'saved priority preselected', (bool) preg_match( '/value="CRITICAL"[^>]*selected/', $html ), true );
check( 'checkbox state restored', (bool) preg_match( '/\[honeypot_enabled\][^>]*checked/', $html ), true );

// Every setting must have a matching input, or it silently resets to its default on save.
preg_match_all( '/name="raynet_lead_settings\[([a-z_0-9]+)\]"/', $html, $m );
$in_form = array_unique( $m[1] );

// Consent moved onto the form in 2.1. Its keys stay in the option so the form
// migration can still read them, but the settings page no longer edits them.
$retired  = array( 'consent_enabled', 'consent_label' );
$expected = array_values( array_diff( array_keys( Raynet_Lead_Settings::defaults() ), $retired ) );

check( 'every live setting has a form field', array_values( array_diff( $expected, $in_form ) ), array() );
check( 'no stray fields', array_values( array_diff( $in_form, $expected ) ), array() );
check( 'retired settings are not editable', array_values( array_intersect( $retired, $in_form ) ), array() );
check( 'settings page points at the forms', str_contains( $html, 'post_type=raynet_form' ), true );

// A round trip through the form must not lose or alter anything.
$before = Raynet_Lead_Settings::all();
$posted = $before;
$posted['api_key'] = '';                       // Password field always posts empty.
unset( $posted['delete_on_uninstall'] );       // Unchecked checkboxes are not posted at all.
$after = Raynet_Lead_Settings::sanitize( $posted );
check( 'resave keeps the api key', $after['api_key'], 'SUPERSECRETKEY' );
unset( $before['api_key'], $after['api_key'] );
check( 'resave is lossless', $after, $before );

// Unconfigured installs get a warning instead of silence.
$GLOBALS['wp_options'] = array();
ob_start();
$admin->render_page();
$blank = ob_get_clean();
check( 'warns when unconfigured', str_contains( $blank, 'Připojení zatím není kompletní' ), true );

check( 'settings link added', (bool) preg_match( '/page=raynet-lead-integration/', $admin->add_settings_link( array() )[0] ), true );

// ---------- Menu wiring ----------
//
// Regression guard for 2.2.1: add_menu_page() does not register a submenu for
// its own page. With only the forms list under it, wp-admin/includes/menu.php
// (lines 107-135 in WP 7.0) rewrites the parent menu's slug to that first
// child, and the settings page both disappears from the menu and answers
// "Sorry, you are not allowed to access this page."
$GLOBALS['wp_menu_pages']    = array();
$GLOBALS['wp_submenu_pages'] = array();
$admin->add_menu();

check( 'nadřazená položka vznikla', count( $GLOBALS['wp_menu_pages'] ), 1 );
check( 'nadřazená má slug stránky', $GLOBALS['wp_menu_pages'][0]['slug'], Raynet_Lead_Admin::PAGE );

$own = array_values( array_filter( $GLOBALS['wp_submenu_pages'], function ( $s ) {
	return $s['parent'] === Raynet_Lead_Admin::PAGE && $s['slug'] === Raynet_Lead_Admin::PAGE;
} ) );

check( 'stránka je i vlastním podmenu', count( $own ), 1 );
check( 'podmenu se jmenuje Nastavení', $own[0]['title'], 'Nastavení' );
check( 'podmenu chce stejné oprávnění', $own[0]['cap'], 'manage_options' );

// Replay the core rule: after the forms list is prepended by
// _add_post_type_submenus(), some child must still carry the parent's slug,
// otherwise the settings page is unreachable.
$children = array_merge(
	array( array( 'parent' => Raynet_Lead_Admin::PAGE, 'slug' => 'edit.php?post_type=raynet_form' ) ),
	$GLOBALS['wp_submenu_pages']
);
$slugs = array_column( $children, 'slug' );
check( 'stránka přežije přepis rodiče', in_array( Raynet_Lead_Admin::PAGE, $slugs, true ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
