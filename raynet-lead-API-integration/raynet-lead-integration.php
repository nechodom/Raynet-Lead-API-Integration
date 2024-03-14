<?php
/**
 * Plugin Name: Raynet Lead API Integration
 * Description: Handles form submissions and forwards them to Raynet CRM with Basic Auth.
 * Version: 1.0
 * Author: Matěj Kevin Nechodom
 */

if (!defined('WPINC')) {
    die;
}

// Enqueue and localize scripts
function raynet_lead_integration_enqueue_scripts() {
    wp_enqueue_script('raynet-lead-integration-js', plugin_dir_url(__FILE__) . 'js/script.js', array('jquery'), null, true);
    wp_localize_script('raynet-lead-integration-js', 'raynetCredentials', array(
        'username' => get_option('raynet_username'),
        'apiKey' => get_option('raynet_api_key'),
        'instanceName' => get_option('raynet_instance_name'),
        'apiUrl' => get_option('raynet_api_url'), // Pass the API URL to the script
        'note' => get_option('raynet_custom_note')
    ));
}

add_action('wp_enqueue_scripts', 'raynet_lead_integration_enqueue_scripts');

// Handle form submission
add_action('wp_ajax_submit_to_raynet', 'submit_to_raynet_handler');
add_action('wp_ajax_nopriv_submit_to_raynet', 'submit_to_raynet_handler');

function submit_to_raynet_handler() {
    // Basic Auth credentials from settings
    $username = get_option('raynet_username');
    $password = get_option('raynet_api_key');
    $instance_name = get_option('raynet_instance_name');

    $data = json_decode(file_get_contents('php://input'), true);
    $api_url = 'https://app.raynet.cz/api/v2/lead/';

    $args = array(
        'method'    => 'PUT',
        'headers'   => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("$username:$password"),
            'X-Instance-Name' => $instance_name,
        ),
        'body' => wp_json_encode($data),
        'data_format' => 'body',
    );

    $response = wp_remote_request($api_url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error('Failed to submit lead: ' . $response->get_error_message());
    } else {
        wp_send_json_success(json_decode(wp_remote_retrieve_body($response), true));
    }

    wp_die();
}

// Admin menu and settings
function raynet_lead_integration_admin_menu() {
    add_menu_page('Raynet Lead Integration Settings', 'Raynet CRM Nastavení', 'manage_options', 'raynet-lead-integration-settings', 'raynet_lead_integration_settings_page', 'dashicons-admin-generic');
}
add_action('admin_menu', 'raynet_lead_integration_admin_menu');

function raynet_lead_integration_settings_page() {
?>
    <div class="wrap">
        <h2>Raynet Lead API Integration - NASTAVENÍ ⚙️</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('raynet-lead-integration-options');
            do_settings_sections('raynet-lead-integration-settings');
            submit_button();
            ?>
        </form>
    </div>
<?php
}

function raynet_lead_integration_register_settings() {
    // Register existing settings
    register_setting('raynet-lead-integration-options', 'raynet_username');
    register_setting('raynet-lead-integration-options', 'raynet_api_key');
    register_setting('raynet-lead-integration-options', 'raynet_instance_name');
    register_setting('raynet-lead-integration-options', 'raynet_api_url');
    register_setting('raynet-lead-integration-options', 'raynet_custom_note'); // Register the new setting for the custom note

    // Add settings section and fields as before
    add_settings_section('raynet-lead-integration-main', 'Main Settings', null, 'raynet-lead-integration-settings');

    // Existing fields
    add_settings_field('raynet_username', 'Username', 'raynet_lead_integration_setting_username', 'raynet-lead-integration-settings', 'raynet-lead-integration-main');
    add_settings_field('raynet_api_key', 'API Key', 'raynet_lead_integration_setting_api_key', 'raynet-lead-integration-settings', 'raynet-lead-integration-main');
    add_settings_field('raynet_api_url', 'API URL', 'raynet_lead_integration_setting_api_url', 'raynet-lead-integration-settings', 'raynet-lead-integration-main');
    add_settings_field('raynet_instance_name', 'X-Instance-Name', 'raynet_lead_integration_setting_instance_name', 'raynet-lead-integration-settings', 'raynet-lead-integration-main');

    // New note field
    add_settings_field('raynet_custom_note', 'Custom Note', 'raynet_lead_integration_setting_custom_note', 'raynet-lead-integration-settings', 'raynet-lead-integration-main');
}
add_action('admin_init', 'raynet_lead_integration_register_settings');


function raynet_lead_integration_setting_username() {
    $username = get_option('raynet_username');
    echo "<input type='text' name='raynet_username' value='" . esc_attr($username) . "' />";
    echo "<p>Zde zadejte e-mailovou adresu, na který je API klíč registrovaný.</p>";
}

function raynet_lead_integration_setting_api_key() {
    $apiKey = get_option('raynet_api_key');
    echo "<input type='text' name='raynet_api_key' value='" . esc_attr($apiKey) . "' />";
    echo "<p>Zde zadejte Váš API klíč, který jste vygeneroval u RayNetu.</p>";
}

function raynet_lead_integration_setting_api_url() {
    $apiUrl = get_option('raynet_api_url'); // Retrieve the stored API URL
    echo "<input type='text' name='raynet_api_url' value='" . esc_attr($apiUrl) . "' />";
    echo "<p>Zde zadejte URL pro API.</p>";
}

function raynet_lead_integration_setting_custom_note() {
    $customNote = get_option('raynet_custom_note'); // Retrieve the stored custom note
    echo "<textarea id='raynet_custom_note' name='raynet_custom_note'>" . esc_textarea($customNote) . "</textarea>";
    echo "<p>Zde můžete přidat vlastní poznámku ke každému leadu. Doporučuji přidat např. Přidáno z webové stránky xy.</p>";
}


function raynet_lead_integration_setting_instance_name() {
    $instanceName = get_option('raynet_instance_name');
    echo "<input type='text' name='raynet_instance_name' value='" . esc_attr($instanceName) . "' />";
    echo "<p>V adrese: https://app.raynet.cz/mujraynet/?view=ListView&en=Lead - vyberte pouze slovo mujraynet a vložte ho do pole. Pravděpodobně tam budete mít název vaší firmy.</p>";
    echo "<p>Tento plugin lze použít na jakékoliv API s funkcí Basic Auth.</p>";
}


// Show an admin notice after settings are saved
function raynet_lead_integration_admin_notices() {
    // Check if on the specific settings page and if settings have been updated
    if (isset($_GET['page']) && $_GET['page'] == 'raynet-lead-integration-settings' && isset($_GET['settings-updated']) && $_GET['settings-updated']) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Vše uloženo, můžete tuto stránku bezpečně zavřít.', 'text-domain'); ?></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'raynet_lead_integration_admin_notices');
