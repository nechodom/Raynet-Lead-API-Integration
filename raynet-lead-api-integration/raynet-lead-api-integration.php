<?php
/**
 * Plugin Name:       Raynet Lead API Integration
 * Plugin URI:        https://github.com/nechodom/Raynet-Lead-API-Integration
 * Update URI:        https://github.com/nechodom/Raynet-Lead-API-Integration
 * Description:       Builder formulářů, který odesílá poptávky do RAYNET CRM jako Leady přes REST API v2. Přihlašovací údaje nikdy neopustí server.
 * Version:           2.7.0
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

defined( 'RAYNET_LEAD_VERSION' ) || define( 'RAYNET_LEAD_VERSION', '2.7.0' );
defined( 'RAYNET_LEAD_FILE' ) || define( 'RAYNET_LEAD_FILE', __FILE__ );
defined( 'RAYNET_LEAD_PATH' ) || define( 'RAYNET_LEAD_PATH', plugin_dir_path( __FILE__ ) );
defined( 'RAYNET_LEAD_URL' ) || define( 'RAYNET_LEAD_URL', plugin_dir_url( __FILE__ ) );

require_once RAYNET_LEAD_PATH . 'includes/class-raynet-settings.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-definition.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-post-type.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-renderer.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-api-client.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-lead-fields.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-lead-form.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-admin.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-form-builder-admin.php';
require_once RAYNET_LEAD_PATH . 'includes/class-raynet-updater.php';
require_once RAYNET_LEAD_PATH . 'includes/elementor/class-raynet-elementor-forms.php';
require_once RAYNET_LEAD_PATH . 'includes/elementor/class-raynet-elementor-forms-admin.php';

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
		( new Raynet_Elementor_Forms_Admin() )->register();
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
 * Registers the submit action for Elementor Pro Forms.
 *
 * The class file is required here and nowhere else, so on a site without
 * Elementor Pro it is never read and `extends Action_Base` cannot fail.
 * The hook firing at all is a better signal than a version constant: the
 * constant can be defined while the Forms module is not running.
 *
 * @param object $registrar Elementor's form actions registrar.
 * @return void
 */
function raynet_lead_register_elementor_action( $registrar ) {
	if ( ! class_exists( '\\ElementorPro\\Modules\\Forms\\Classes\\Action_Base' ) ) {
		return;
	}

	require_once RAYNET_LEAD_PATH . 'includes/elementor/class-raynet-elementor-form-action.php';

	$registrar->register( new Raynet_Elementor_Form_Action() );
}
add_action( 'elementor_pro/forms/actions/register', 'raynet_lead_register_elementor_action' );

/**
 * Loads the script that keeps the mapping rows current in the Elementor editor.
 *
 * @return void
 */
function raynet_lead_elementor_editor_scripts() {
	if ( ! class_exists( 'Raynet_Elementor_Form_Action' ) ) {
		return;
	}

	wp_enqueue_script(
		'raynet-elementor-editor',
		RAYNET_LEAD_URL . 'assets/js/raynet-elementor-editor.js',
		array( 'jquery' ),
		RAYNET_LEAD_VERSION,
		true
	);

	wp_localize_script(
		'raynet-elementor-editor',
		'raynetElementorEditor',
		array( 'rows' => Raynet_Elementor_Form_Action::remote_fields() )
	);
}
add_action( 'elementor/editor/after_enqueue_scripts', 'raynet_lead_elementor_editor_scripts' );

/**
 * Warns when Elementor Pro is present but no longer offers the expected API.
 *
 * Without this the action would simply stop registering and forms would quietly
 * stop creating leads.
 *
 * @return void
 */
function raynet_lead_elementor_notice() {
	if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( class_exists( '\\ElementorPro\\Modules\\Forms\\Classes\\Action_Base' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__(
		'RAYNET: integrace s Elementor Pro Forms není aktivní — tato verze Elementor Pro neposkytuje očekávané API. Leady z formulářů Elementoru se neodesílají.',
		'raynet-lead-api-integration'
	) . '</p></div>';
}
add_action( 'admin_notices', 'raynet_lead_elementor_notice' );

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
