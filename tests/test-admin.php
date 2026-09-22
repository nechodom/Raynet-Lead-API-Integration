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
function plugin_basename( $file ) { return 'raynet-lead-api-integration/raynet-lead-api-integration.php'; }
function add_menu_page( ...$a ) { return 'toplevel_page_raynet-lead-integration'; }
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

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
