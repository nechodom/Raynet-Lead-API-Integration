<?php
/**
 * Plugin Name:       Raynet Lead API Integration
 * Plugin URI:        https://github.com/nechodom/Raynet-Lead-API-Integration
 * Update URI:        https://github.com/nechodom/Raynet-Lead-API-Integration
 * Description:       Builder formulářů, který odesílá poptávky do RAYNET CRM jako Leady přes REST API v2. Přihlašovací údaje nikdy neopustí server.
 * Version:           2.2.4
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

defined( 'RAYNET_LEAD_VERSION' ) || define( 'RAYNET_LEAD_VERSION', '2.2.4' );
defined( 'RAYNET_LEAD_FILE' ) || define( 'RAYNET_LEAD_FILE', __FILE__ );
defined( 'RAYNET_LEAD_PATH' ) || define( 'RAYNET_LEAD_PATH', plugin_dir_path( __FILE__ ) );
defined( 'RAYNET_LEAD_URL' ) || define( 'RAYNET_LEAD_URL', plugin_dir_url( __FILE__ ) );

require_once RAYNET_LEAD_PATH . 'includes/class-raynet-settings.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-definition.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-post-type.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-renderer.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-api-client.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-lead-form.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-admin.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-builder-admin.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-updater.php';

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

	( new Raynet_Lead_Form() )->register();
	( new Raynet_Lead_Updater() )->register();

	if ( is_admin() ) {
		( new Raynet_Lead_Admin() )->register();
		( new Raynet_Lead_Form_Builder_Admin() )->register();
	}
}
add_action( 'plugins_loaded', 'raynet_lead_bootstrap' );

/*
 * The post type and the one-time form migration both wait for `init`.
 *
 * register_post_type() must not run before `init`, and creating a post earlier
 * is fatal: WordPress builds $wp_rewrite only after `plugins_loaded`, and
 * wp_insert_post() reaches for it through get_permalink().
 */
add_action( 'init', array( 'Raynet_Lead_Form_Post_Type', 'register' ), 5 );
add_action( 'init', array( 'Raynet_Lead_Form_Post_Type', 'maybe_migrate' ), 20 );

/**
 * Stores the installed version on activation so migrations can run.
 *
 * @return void
 */
function raynet_lead_activate() {
	Raynet_Lead_Settings::maybe_migrate();

	// Activation runs inside a fully booted admin request, so $wp_rewrite
	// exists here. The post type still has to be registered for this request
	// before a form can be inserted.
	Raynet_Lead_Form_Post_Type::register();
	Raynet_Lead_Form_Post_Type::maybe_migrate();

	flush_rewrite_rules();
	update_option( 'raynet_lead_version', RAYNET_LEAD_VERSION );
}
register_activation_hook( __FILE__, 'raynet_lead_activate' );
