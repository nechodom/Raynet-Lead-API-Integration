<?php
/**
 * Central store for every plugin option.
 *
 * All values live in a single option array so there is exactly one place to
 * sanitize, one place to read defaults from, and one row to clean up on
 * uninstall.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings repository.
 */
class Raynet_Lead_Settings {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'raynet_lead_settings';

	/**
	 * Option group used by the Settings API.
	 */
	const GROUP = 'raynet_lead_settings_group';

	/**
	 * Known RAYNET regional API endpoints, as published in the API docs.
	 *
	 * @return array<string,string> Region key => base URL.
	 */
	public static function regions() {
		return array(
			'cz'  => 'https://app.raynet.cz/api/v2/',
			'sk'  => 'https://app.raynetcrm.sk/api/v2/',
			'com' => 'https://app.raynetcrm.com/api/v2/',
			'eu'  => 'https://eu.raynetcrm.com/api/v2/',
		);
	}

	/**
	 * Allowed values of the RAYNET lead "priority" attribute.
	 *
	 * @return string[] List of priorities.
	 */
	public static function priorities() {
		return array( 'MINOR', 'DEFAULT', 'CRITICAL' );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed> Defaults.
	 */
	public static function defaults() {
		return array(
			// Connection.
			'region'            => 'cz',
			'custom_api_url'    => '',
			'username'          => '',
			'api_key'           => '',
			'instance_name'     => '',
			'instance_id'       => '',
			'timeout'           => 15,

			// Lead defaults sent with every submission.
			'priority'          => 'DEFAULT',
			'lead_person'       => 1,
			'default_topic'     => '',
			'notice_prefix'     => '',
			'category'          => 0,
			'lead_phase'        => 0,
			'contact_source'    => 0,
			'owner'             => 0,
			'security_level'    => 0,
			'tags'              => '',
			'notify_emails'     => '',

			// Form behaviour.
			'success_message'   => '',
			'error_message'     => '',
			'consent_enabled'   => 1,
			'consent_label'     => '',
			'honeypot_enabled'  => 1,
			'min_fill_seconds'  => 3,
			'throttle_seconds'  => 20,
			'redirect_url'      => '',
			'fallback_email'    => '',
			'log_errors'        => 1,
			'updates_enabled'   => 1,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Returns every setting, merged over the defaults.
	 *
	 * @return array<string,mixed> Settings.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is unknown.
	 * @return mixed Setting value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Resolves the API base URL from the selected region.
	 *
	 * @return string Base URL with a trailing slash, or an empty string.
	 */
	public static function api_base_url() {
		$region = self::get( 'region' );

		if ( 'custom' === $region ) {
			$url = self::get( 'custom_api_url' );
		} else {
			$regions = self::regions();
			$url     = isset( $regions[ $region ] ) ? $regions[ $region ] : $regions['cz'];
		}

		$url = trim( (string) $url );

		return '' === $url ? '' : trailingslashit( $url );
	}

	/**
	 * Tells whether the connection is configured well enough to be attempted.
	 *
	 * @return bool True when credentials and an instance identifier are present.
	 */
	public static function is_configured() {
		$settings = self::all();

		$has_instance = '' !== trim( (string) $settings['instance_name'] )
			|| '' !== trim( (string) $settings['instance_id'] );

		return '' !== trim( (string) $settings['username'] )
			&& '' !== trim( (string) $settings['api_key'] )
			&& $has_instance
			&& '' !== self::api_base_url();
	}

	/**
	 * Sanitizes the whole settings array before it is stored.
	 *
	 * An empty API key field means "keep the stored key", so the secret never
	 * has to be rendered back into the admin form.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array<string,mixed> Clean settings.
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$clean   = self::defaults();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$regions           = self::regions();
		$region            = isset( $input['region'] ) ? sanitize_key( $input['region'] ) : 'cz';
		$clean['region']   = ( isset( $regions[ $region ] ) || 'custom' === $region ) ? $region : 'cz';
		$custom_url        = isset( $input['custom_api_url'] ) ? esc_url_raw( trim( (string) $input['custom_api_url'] ) ) : '';
		$clean['custom_api_url'] = $custom_url;

		$clean['username']      = isset( $input['username'] ) ? sanitize_text_field( trim( (string) $input['username'] ) ) : '';
		$clean['instance_name'] = isset( $input['instance_name'] ) ? sanitize_text_field( trim( (string) $input['instance_name'] ) ) : '';
		$clean['instance_id']   = isset( $input['instance_id'] ) ? sanitize_text_field( trim( (string) $input['instance_id'] ) ) : '';

		$submitted_key     = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$clean['api_key']  = '' === $submitted_key ? (string) $current['api_key'] : sanitize_text_field( $submitted_key );

		$clean['timeout'] = isset( $input['timeout'] ) ? (int) $input['timeout'] : 15;
		$clean['timeout'] = max( 5, min( 60, $clean['timeout'] ) );

		$priority           = isset( $input['priority'] ) ? strtoupper( sanitize_text_field( (string) $input['priority'] ) ) : 'DEFAULT';
		$clean['priority']  = in_array( $priority, self::priorities(), true ) ? $priority : 'DEFAULT';

		$clean['lead_person']    = empty( $input['lead_person'] ) ? 0 : 1;
		$clean['default_topic']  = isset( $input['default_topic'] ) ? sanitize_text_field( (string) $input['default_topic'] ) : '';
		$clean['notice_prefix']  = isset( $input['notice_prefix'] ) ? sanitize_textarea_field( (string) $input['notice_prefix'] ) : '';

		foreach ( array( 'category', 'lead_phase', 'contact_source', 'owner', 'security_level' ) as $numeric ) {
			$clean[ $numeric ] = isset( $input[ $numeric ] ) ? max( 0, (int) $input[ $numeric ] ) : 0;
		}

		$clean['tags'] = isset( $input['tags'] ) ? sanitize_text_field( (string) $input['tags'] ) : '';

		$clean['notify_emails'] = isset( $input['notify_emails'] )
			? implode( ',', self::sanitize_email_list( (string) $input['notify_emails'] ) )
			: '';

		$clean['success_message'] = isset( $input['success_message'] ) ? sanitize_text_field( (string) $input['success_message'] ) : '';
		$clean['error_message']   = isset( $input['error_message'] ) ? sanitize_text_field( (string) $input['error_message'] ) : '';

		$clean['consent_enabled']  = empty( $input['consent_enabled'] ) ? 0 : 1;
		$clean['consent_label']    = isset( $input['consent_label'] ) ? wp_kses_post( (string) $input['consent_label'] ) : '';
		$clean['honeypot_enabled'] = empty( $input['honeypot_enabled'] ) ? 0 : 1;

		$clean['min_fill_seconds'] = isset( $input['min_fill_seconds'] ) ? (int) $input['min_fill_seconds'] : 3;
		$clean['min_fill_seconds'] = max( 0, min( 120, $clean['min_fill_seconds'] ) );

		$clean['throttle_seconds'] = isset( $input['throttle_seconds'] ) ? (int) $input['throttle_seconds'] : 20;
		$clean['throttle_seconds'] = max( 0, min( 3600, $clean['throttle_seconds'] ) );

		$clean['redirect_url'] = isset( $input['redirect_url'] ) ? esc_url_raw( trim( (string) $input['redirect_url'] ) ) : '';

		$fallback = isset( $input['fallback_email'] ) ? sanitize_email( trim( (string) $input['fallback_email'] ) ) : '';
		$clean['fallback_email'] = is_email( $fallback ) ? $fallback : '';

		$clean['log_errors']          = empty( $input['log_errors'] ) ? 0 : 1;
		$clean['updates_enabled']     = empty( $input['updates_enabled'] ) ? 0 : 1;
		$clean['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Parses a comma or newline separated list of e-mail addresses.
	 *
	 * @param string $raw Raw list.
	 * @return string[] Valid addresses.
	 */
	public static function sanitize_email_list( $raw ) {
		$parts  = preg_split( '/[,;\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$emails = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( $part ) );

			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Imports settings from the 1.x option layout, once.
	 *
	 * Version 1.x stored each value in its own option and shipped the API key
	 * to the browser. The key is carried over so existing sites keep working,
	 * but it is now only ever read server side.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( 'raynet_lead_migrated_v2' ) ) {
			return;
		}

		$legacy = array(
			'username'      => get_option( 'raynet_username', '' ),
			'api_key'       => get_option( 'raynet_api_key', '' ),
			'instance_name' => get_option( 'raynet_instance_name', '' ),
			'notice_prefix' => get_option( 'raynet_custom_note', '' ),
		);

		$legacy = array_filter(
			$legacy,
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $legacy ) ) {
			$legacy_url = trim( (string) get_option( 'raynet_api_url', '' ) );

			if ( '' !== $legacy_url ) {
				$region = self::region_from_url( $legacy_url );

				if ( null === $region ) {
					$legacy['region']         = 'custom';
					$legacy['custom_api_url'] = self::strip_endpoint_path( $legacy_url );
				} else {
					$legacy['region'] = $region;
				}
			}

			update_option( self::OPTION, array_merge( self::all(), $legacy ) );
		}

		update_option( 'raynet_lead_migrated_v2', 1 );
	}

	/**
	 * Matches a legacy full endpoint URL against the known regional hosts.
	 *
	 * @param string $url Legacy URL, e.g. https://app.raynet.cz/api/v2/lead/.
	 * @return string|null Region key, or null when the host is unknown.
	 */
	private static function region_from_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return null;
		}

		foreach ( self::regions() as $key => $base ) {
			if ( strtolower( $host ) === strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Reduces a legacy endpoint URL to its API base.
	 *
	 * @param string $url Legacy URL.
	 * @return string Base URL ending in /api/v2/.
	 */
	private static function strip_endpoint_path( $url ) {
		$base = preg_replace( '#/api/v2/.*$#', '/api/v2/', $url );

		return trailingslashit( (string) $base );
	}
}
