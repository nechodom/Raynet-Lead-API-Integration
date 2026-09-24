<?php
/**
 * Server side HTTP client for the RAYNET CRM REST API v2.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request() that speaks RAYNET's conventions.
 */
class Raynet_Lead_Api_Client {

	/**
	 * API base URL, with a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Basic auth user name (the e-mail the API key is registered to).
	 *
	 * @var string
	 */
	private $username;

	/**
	 * API key used as the basic auth password.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Instance name sent in X-Instance-Name.
	 *
	 * @var string
	 */
	private $instance_name;

	/**
	 * Instance id sent in X-Instance-Id, which takes precedence when set.
	 *
	 * @var string
	 */
	private $instance_id;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $config Connection configuration.
	 */
	public function __construct( array $config ) {
		$this->base_url      = isset( $config['base_url'] ) ? trailingslashit( (string) $config['base_url'] ) : '';
		$this->username      = isset( $config['username'] ) ? (string) $config['username'] : '';
		$this->api_key       = isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
		$this->instance_name = isset( $config['instance_name'] ) ? (string) $config['instance_name'] : '';
		$this->instance_id   = isset( $config['instance_id'] ) ? (string) $config['instance_id'] : '';
		$this->timeout       = isset( $config['timeout'] ) ? (int) $config['timeout'] : 15;
	}

	/**
	 * Builds a client from the stored settings.
	 *
	 * @return self Configured client.
	 */
	public static function from_settings() {
		return new self(
			array(
				'base_url'      => Raynet_Lead_Settings::api_base_url(),
				'username'      => Raynet_Lead_Settings::get( 'username' ),
				'api_key'       => Raynet_Lead_Settings::get( 'api_key' ),
				'instance_name' => Raynet_Lead_Settings::get( 'instance_name' ),
				'instance_id'   => Raynet_Lead_Settings::get( 'instance_id' ),
				'timeout'       => Raynet_Lead_Settings::get( 'timeout' ),
			)
		);
	}

	/**
	 * Creates a lead.
	 *
	 * RAYNET creates records with PUT on the collection endpoint and answers
	 * with HTTP 201 and the new record id.
	 *
	 * @param array<string,mixed> $lead Lead payload.
	 * @return array<string,mixed>|WP_Error Decoded response, or an error.
	 */
	public function create_lead( array $lead ) {
		return $this->request( 'PUT', 'lead/', $lead );
	}

	/**
	 * Returns information about the authenticated user and instance.
	 *
	 * Used by the "test connection" button in the admin.
	 *
	 * @return array<string,mixed>|WP_Error Decoded response, or an error.
	 */
	public function get_account_info() {
		return $this->request( 'GET', 'security/info' );
	}

	/**
	 * Records a GDPR legal title, such as a consent, on a lead.
	 *
	 * @param array<string,mixed> $record Body: gdprTemplate, lead, validFrom and
	 *                                    optionally validTill, gdprFormAgreement.
	 * @return array<string,mixed>|WP_Error Decoded response, or an error.
	 */
	public function create_gdpr( array $record ) {
		return $this->request( 'PUT', 'gdpr/', $record );
	}

	/**
	 * Fetches the configuration of custom fields for every entity.
	 *
	 * RAYNET has no filter on this endpoint; it answers with one list per entity
	 * under `data`, keyed Company, Person, Lead and so on.
	 *
	 * @return array<string,mixed>|WP_Error The `data` object, or an error.
	 */
	public function get_custom_field_config() {
		$response = $this->request( 'GET', 'customField/config/' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// A 200 without `data` is a proxy, a maintenance page or a wrong URL,
		// not an instance without custom fields. Taking it for the latter would
		// wipe the stored configuration and report success.
		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error(
				'raynet_bad_response',
				__( 'RAYNET vrátil neočekávanou odpověď bez dat. Zkontrolujte adresu API.', 'raynet-lead-api-integration' )
			);
		}

		return $response['data'];
	}

	/**
	 * Fetches a code list (číselník) and reduces it to id => label pairs.
	 *
	 * @param string $endpoint Endpoint path, e.g. "leadCategory/".
	 * @param int    $limit    Maximum number of rows.
	 * @return array<int,string>|WP_Error Id => label map, or an error.
	 */
	public function get_code_list( $endpoint, $limit = 100 ) {
		$response = $this->request( 'GET', add_query_arg( 'limit', (int) $limit, $endpoint ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$result = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}

			$label = '';

			foreach ( array( 'name', 'code01', 'value', 'strValue01' ) as $label_key ) {
				if ( ! empty( $row[ $label_key ] ) && is_string( $row[ $label_key ] ) ) {
					$label = $row[ $label_key ];
					break;
				}
			}

			$result[ (int) $row['id'] ] = '' === $label ? (string) $row['id'] : $label;
		}

		return $result;
	}

	/**
	 * Performs a request against the API.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path relative to the API base URL.
	 * @param array<string,mixed>|null $body   Optional JSON body.
	 * @return array<string,mixed>|WP_Error Decoded response, or an error.
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->base_url ) {
			return new WP_Error(
				'raynet_not_configured',
				__( 'Není nastavena adresa RAYNET API.', 'raynet-lead-api-integration' )
			);
		}

		if ( '' === $this->username || '' === $this->api_key ) {
			return new WP_Error(
				'raynet_not_configured',
				__( 'Chybí uživatelské jméno nebo API klíč.', 'raynet-lead-api-integration' )
			);
		}

		if ( '' === $this->instance_name && '' === $this->instance_id ) {
			return new WP_Error(
				'raynet_not_configured',
				__( 'Chybí název instance (X-Instance-Name) ani její ID.', 'raynet-lead-api-integration' )
			);
		}

		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by HTTP basic auth.
		);

		if ( '' !== $this->instance_id ) {
			$headers['X-Instance-Id'] = $this->instance_id;
		} else {
			$headers['X-Instance-Name'] = $this->instance_name;
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
			$args['data_format']             = 'body';

			if ( false === $args['body'] ) {
				return new WP_Error(
					'raynet_encode_failed',
					__( 'Data se nepodařilo zakódovat do JSON.', 'raynet-lead-api-integration' )
				);
			}
		}

		$url      = $this->base_url . ltrim( (string) $path, '/' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'raynet_transport_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Spojení s RAYNET API selhalo: %s', 'raynet-lead-api-integration' ),
					$response->get_error_message()
				)
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}

		return new WP_Error(
			'raynet_http_' . $status,
			$this->error_message_for( $status, $decoded, $raw_body ),
			array(
				'status' => $status,
				'body'   => $decoded,
			)
		);
	}

	/**
	 * Turns an API error response into a message worth logging.
	 *
	 * @param int                 $status   HTTP status code.
	 * @param array<string,mixed> $decoded  Decoded response body.
	 * @param string              $raw_body Raw response body.
	 * @return string Error message.
	 */
	private function error_message_for( $status, array $decoded, $raw_body ) {
		switch ( $status ) {
			case 401:
				return __( 'RAYNET odmítl přihlášení (401). Zkontrolujte e-mail, API klíč a název instance. Po 20 neúspěšných pokusech RAYNET blokuje IP adresu na 60 minut.', 'raynet-lead-api-integration' );

			case 403:
				return __( 'Uživatel nemá oprávnění zakládat leady (403).', 'raynet-lead-api-integration' );

			case 404:
				return __( 'Endpoint nebyl nalezen (404). Zkontrolujte zvolený region RAYNET API.', 'raynet-lead-api-integration' );

			case 429:
				return __( 'Byl překročen limit RAYNET API (429). Zkuste to prosím za chvíli.', 'raynet-lead-api-integration' );
		}

		$detail = '';

		if ( ! empty( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			$detail = $decoded['message'];
		} elseif ( ! empty( $decoded['errorMessage'] ) && is_string( $decoded['errorMessage'] ) ) {
			$detail = $decoded['errorMessage'];
		} elseif ( ! empty( $decoded['data'] ) ) {
			$detail = wp_json_encode( $decoded['data'] );
		} elseif ( '' !== trim( (string) $raw_body ) ) {
			$detail = substr( (string) $raw_body, 0, 500 );
		}

		return sprintf(
			/* translators: 1: HTTP status code, 2: error detail from the API. */
			__( 'RAYNET API vrátilo chybu %1$d: %2$s', 'raynet-lead-api-integration' ),
			$status,
			'' === $detail ? __( 'bez dalších údajů.', 'raynet-lead-api-integration' ) : $detail
		);
	}
}
