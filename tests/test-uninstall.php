<?php
/**
 * Tests for uninstall.php: data goes only when the site asked for it, and
 * then all of it.
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
 * Records the queries uninstall.php runs.
 */
class Raynet_Test_Wpdb {
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
	public $queries  = array();
	public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
}

define( 'WP_UNINSTALL_PLUGIN', 'raynet-lead-api-integration/raynet-lead-api-integration.php' );

$plugin_options = array(
	'raynet_lead_version'             => '2.9.0',
	'raynet_lead_last_error'          => array( 'message' => 'x' ),
	'raynet_lead_migrated_v2'         => 1,
	'raynet_lead_migrated_forms'      => 1,
	'raynet_lead_default_form'        => 5,
	'raynet_lead_elementor_templates' => array( 'poptavka' => array() ),
	'raynet_lead_custom_fields'       => array( 'connection' => 'abc', 'fields' => array() ),
	'raynet_lead_users'               => array( 'connection' => 'abc', 'people' => array( 11 => array( 'name' => 'Jana', 'login' => 'jana@firma.cz' ) ) ),
	// Legacy 1.x options.
	'raynet_username'                 => 'legacy',
	'raynet_api_key'                  => 'legacy',
	'raynet_instance_name'            => 'legacy',
	'raynet_api_url'                  => 'legacy',
	'raynet_custom_note'              => 'legacy',
);

/**
 * Runs uninstall.php against a fresh copy of the site's data.
 *
 * @param bool $delete The site's "delete data on uninstall" choice.
 * @return Raynet_Test_Wpdb The queries it ran.
 */
function uninstall( $delete ) {
	global $plugin_options;

	$GLOBALS['wp_options']         = $plugin_options + array(
		'raynet_lead_settings' => array( 'delete_on_uninstall' => $delete ),
		'blogname'             => 'Web',
	);
	$GLOBALS['wp_site_transients'] = array( 'raynet_lead_latest_release' => array( 'tag' => 'v2.9.0' ) );
	$GLOBALS['wp_posts']           = array(
		1 => array( 'post_type' => 'raynet_form' ),
		2 => array( 'post_type' => 'page' ),
	);
	$GLOBALS['wpdb']               = new Raynet_Test_Wpdb();

	include dirname( __DIR__ ) . '/raynet-lead-api-integration/uninstall.php';

	return $GLOBALS['wpdb'];
}

// Kept: the default, and what an update looks like.
$kept = uninstall( false );
check( 'bez volby zůstane nastavení', isset( $GLOBALS['wp_options']['raynet_lead_settings'] ), true );
check( 'bez volby zůstanou uživatelé', isset( $GLOBALS['wp_options']['raynet_lead_users'] ), true );
check( 'bez volby zůstanou vlastní pole', isset( $GLOBALS['wp_options']['raynet_lead_custom_fields'] ), true );
check( 'bez volby zůstanou formuláře', isset( $GLOBALS['wp_posts'][1] ), true );
check( 'bez volby žádný dotaz', $kept->queries, array() );

// Deleted: every option of the plugin, nothing else.
$deleted = uninstall( true );
check( 'smaže nastavení', isset( $GLOBALS['wp_options']['raynet_lead_settings'] ), false );
check( 'smaže seznam uživatelů', isset( $GLOBALS['wp_options']['raynet_lead_users'] ), false );
check( 'smaže seznam vlastních polí', isset( $GLOBALS['wp_options']['raynet_lead_custom_fields'] ), false );
check( 'nezůstane žádná volba pluginu', array_keys( $GLOBALS['wp_options'] ), array( 'blogname' ) );
check( 'smaže uloženou verzi z GitHubu', isset( $GLOBALS['wp_site_transients']['raynet_lead_latest_release'] ), false );
check( 'smaže formuláře', isset( $GLOBALS['wp_posts'][1] ), false );
check( 'nechá jiné příspěvky', isset( $GLOBALS['wp_posts'][2] ), true );
check( 'smaže zálohy rozvržení', (bool) preg_grep( "/DELETE FROM wp_postmeta WHERE meta_key = '_raynet_elementor_backup'/", $deleted->queries ), true );
check( 'smaže omezovače odesílání', (bool) preg_grep( '/raynet\\\\_lead\\\\_throttle\\\\_%/', $deleted->queries ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
