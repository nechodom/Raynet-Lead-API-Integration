<?php
/**
 * Updates straight from the plugin's GitHub releases.
 *
 * The plugin is not on wordpress.org, so WordPress has nowhere to look for a
 * new version. This tells it where: the newest published release, and the
 * installable archive attached to it.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Release checker and update provider.
 */
class Raynet_Lead_Updater {

	/**
	 * Repository the releases are published to.
	 */
	const REPO = 'nechodom/Raynet-Lead-API-Integration';

	/**
	 * Transient holding the last release seen.
	 */
	const CACHE_KEY = 'raynet_lead_latest_release';

	/**
	 * How long a lookup is reused. GitHub allows 60 unauthenticated calls an
	 * hour per address, and a WordPress site is often one of many behind it.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Hooks the updater into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'wp_ajax_raynet_lead_check_update', array( $this, 'handle_manual_check' ) );

		// A failed or cancelled update leaves a stale cache behind.
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
	}

	/**
	 * The plugin's file path relative to the plugins directory.
	 *
	 * @return string Basename, e.g. raynet-lead-api-integration/raynet-lead-api-integration.php.
	 */
	public static function basename() {
		return plugin_basename( RAYNET_LEAD_FILE );
	}

	/**
	 * The plugin's directory name, which is also its update slug.
	 *
	 * @return string Slug.
	 */
	public static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * Adds this plugin to the list of available updates.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed The transient, possibly with our entry added.
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! Raynet_Lead_Settings::get( 'updates_enabled' ) ) {
			return $transient;
		}

		$release = $this->fetch_latest();

		if ( ! $release ) {
			return $transient;
		}

		$basename = self::basename();

		if ( ! version_compare( RAYNET_LEAD_VERSION, $release['version'], '<' ) ) {
			// Up to date. Saying so explicitly keeps the "last checked" line honest.
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $basename ] = $this->offer( $release, false );
			}

			return $transient;
		}

		$transient->response[ $basename ] = $this->offer( $release, true );

		return $transient;
	}

	/**
	 * Builds the object WordPress expects for one plugin.
	 *
	 * @param array<string,mixed> $release   Release data.
	 * @param bool                $is_update Whether this describes a newer version.
	 * @return object Update offer.
	 */
	private function offer( array $release, $is_update ) {
		return (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => self::slug(),
			'plugin'        => self::basename(),
			'new_version'   => $is_update ? $release['version'] : RAYNET_LEAD_VERSION,
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $is_update ? $release['package'] : '',
			'tested'        => $release['tested'],
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * Fills the "View details" dialog.
	 *
	 * @param mixed  $result The value plugins_api is building.
	 * @param string $action Requested action.
	 * @param object $args   Request arguments.
	 * @return mixed Plugin information, or the untouched result.
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Raynet Lead API Integration',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/nechodom">Matěj Kevin Nechodom</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '5.6',
			'requires_php'  => '7.4',
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Builder formulářů, který odesílá poptávky do RAYNET CRM jako Leady přes REST API v2.', 'raynet-lead-api-integration' ),
				'changelog'   => $this->changelog_html( $release ),
			),
		);
	}

	/**
	 * Renders the release notes for the details dialog.
	 *
	 * The notes come from GitHub, so they are escaped rather than trusted as
	 * markup.
	 *
	 * @param array<string,mixed> $release Release data.
	 * @return string HTML.
	 */
	private function changelog_html( array $release ) {
		$notes = trim( (string) $release['notes'] );

		if ( '' === $notes ) {
			return '<p>' . esc_html__( 'Tato verze nemá popis změn.', 'raynet-lead-api-integration' ) . '</p>';
		}

		return '<h4>' . esc_html( $release['version'] ) . '</h4>'
			. '<pre style="white-space:pre-wrap">' . esc_html( mb_substr( $notes, 0, 20000 ) ) . '</pre>';
	}

	/**
	 * Returns the newest release, from cache when possible.
	 *
	 * @param bool $force Skip the cache.
	 * @return array<string,mixed>|null Release data, or null when unavailable.
	 */
	public function fetch_latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return isset( $cached['version'] ) ? $cached : null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'raynet-lead-api-integration/' . RAYNET_LEAD_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a broken network does not slow every
			// update check, but not for the full period.
			set_site_transient( self::CACHE_KEY, array( 'failed' => true ), HOUR_IN_SECONDS );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, array( 'failed' => true ), HOUR_IN_SECONDS );

			return null;
		}

		$package = $this->find_package( isset( $body['assets'] ) ? $body['assets'] : array() );

		if ( '' === $package ) {
			// Without the built archive there is nothing installable. The source
			// zipball is not usable: its folder is named after the tag, so
			// WordPress would install the plugin into the wrong directory.
			set_site_transient( self::CACHE_KEY, array( 'failed' => true ), self::CACHE_TTL );

			return null;
		}

		$release = array(
			'version'   => ltrim( sanitize_text_field( (string) $body['tag_name'] ), 'vV' ),
			'package'   => $package,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? sanitize_text_field( (string) $body['published_at'] ) : '',
			'tested'    => get_bloginfo( 'version' ),
			'checked'   => time(),
		);

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Picks the installable archive from a release's assets.
	 *
	 * Only an asset served by GitHub is accepted, so a tampered API response
	 * cannot point WordPress at somebody else's archive.
	 *
	 * @param array<int,array<string,mixed>> $assets Release assets.
	 * @return string Download URL, or an empty string.
	 */
	private function find_package( $assets ) {
		foreach ( (array) $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			$url  = (string) $asset['browser_download_url'];
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( 'github.com' !== strtolower( (string) $host ) ) {
				continue;
			}

			if ( '.zip' !== strtolower( substr( $url, -4 ) ) ) {
				continue;
			}

			return esc_url_raw( $url );
		}

		return '';
	}

	/**
	 * Returns what the settings screen shows about updates.
	 *
	 * @return array<string,mixed> Status.
	 */
	public function status() {
		$cached = get_site_transient( self::CACHE_KEY );
		$known  = is_array( $cached ) && isset( $cached['version'] ) ? $cached : null;

		return array(
			'current'   => RAYNET_LEAD_VERSION,
			'latest'    => $known ? $known['version'] : '',
			'checked'   => $known ? (int) $known['checked'] : 0,
			'available' => $known && version_compare( RAYNET_LEAD_VERSION, $known['version'], '<' ),
		);
	}

	/**
	 * Drops the cached release.
	 *
	 * @return void
	 */
	public function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Re-checks on demand from the settings screen.
	 *
	 * @return void
	 */
	public function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'raynet-lead-api-integration' ) ), 403 );
		}

		check_ajax_referer( 'raynet_lead_check_update', 'nonce' );

		$release = $this->fetch_latest( true );

		if ( ! $release ) {
			wp_send_json_error(
				array( 'message' => __( 'Zjištění nejnovější verze se nezdařilo. Zkuste to prosím později.', 'raynet-lead-api-integration' ) )
			);
		}

		// Make WordPress notice on the next screen load rather than in twelve hours.
		delete_site_transient( 'update_plugins' );

		$available = version_compare( RAYNET_LEAD_VERSION, $release['version'], '<' );

		wp_send_json_success(
			array(
				'available' => $available,
				'latest'    => $release['version'],
				'message'   => $available
					? sprintf(
						/* translators: %s: version number. */
						__( 'K dispozici je verze %s. Nainstalujete ji na stránce Pluginy.', 'raynet-lead-api-integration' ),
						$release['version']
					)
					: __( 'Používáte nejnovější verzi.', 'raynet-lead-api-integration' ),
				'updateUrl' => admin_url( 'plugins.php' ),
			)
		);
	}
}
