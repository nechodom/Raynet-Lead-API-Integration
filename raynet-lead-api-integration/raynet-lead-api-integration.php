<?php
/**
 * Plugin Name:       Raynet Lead API Integration
 * Plugin URI:        https://github.com/nechodom/Raynet-Lead-API-Integration
 * Description:       Builder formulářů, který odesílá poptávky do RAYNET CRM jako Leady přes REST API v2. Přihlašovací údaje nikdy neopustí server.
 * Version:           2.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Matěj Kevin Nechodom
 * Author URI:        https://github.com/nechodom
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       raynet-lead-api-integration
 * Domain Path:       /languages
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

define( 'RAYNET_LEAD_VERSION', '2.1.0' );
define( 'RAYNET_LEAD_FILE', __FILE__ );
define( 'RAYNET_LEAD_PATH', plugin_dir_path( __FILE__ ) );
define( 'RAYNET_LEAD_URL', plugin_dir_url( __FILE__ ) );

require_once RAYNET_LEAD_PATH . 'includes/class-raynet-settings.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-definition.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-post-type.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-renderer.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-api-client.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-lead-form.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-admin.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-builder-admin.php';

/**
 * Boots the plugin once all plugins are loaded.
 *
 * @return void
 */
function raynet_lead_bootstrap() {
	load_plugin_textdomain(
		'raynet-lead-api-integration',
		false,
		dirname( plugin_basename( RAYNET_LEAD_FILE ) ) . '/languages'
	);

	Raynet_Lead_Settings::maybe_migrate();

	Raynet_Lead_Form_Post_Type::register();
	Raynet_Lead_Form_Post_Type::maybe_migrate();

	( new Raynet_Lead_Form() )->register();

	if ( is_admin() ) {
		( new Raynet_Lead_Admin() )->register();
		( new Raynet_Lead_Form_Builder_Admin() )->register();
	}
}
add_action( 'plugins_loaded', 'raynet_lead_bootstrap' );

/**
 * Stores the installed version on activation so migrations can run.
 *
 * @return void
 */
function raynet_lead_activate() {
	Raynet_Lead_Settings::maybe_migrate();
	Raynet_Lead_Form_Post_Type::register();
	Raynet_Lead_Form_Post_Type::maybe_migrate();
	flush_rewrite_rules();
	update_option( 'raynet_lead_version', RAYNET_LEAD_VERSION );
}
register_activation_hook( __FILE__, 'raynet_lead_activate' );
