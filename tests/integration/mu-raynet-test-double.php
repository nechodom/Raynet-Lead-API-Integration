<?php
/**
 * Test double for the integration suite.
 *
 * Intercepts outbound HTTP so a test run never touches RAYNET or GitHub, and
 * records what the plugin tried to send.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		// Let a more specific filter win; this one is only the default.
		if ( false !== $preempt ) {
			return $preempt;
		}

		$calls   = get_option( 'raynet_test_http_calls', array() );
		$calls[] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : '',
		);
		update_option( 'raynet_test_http_calls', $calls, false );

		// The custom field configuration of an imaginary instance, one field of
		// each kind a form can fill.
		if ( false !== strpos( $url, 'raynet' ) && false !== strpos( $url, 'customField/config' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'data'    => array(
							'Company' => array( array( 'name' => 'Jen_firmy_z1', 'label' => 'Jen u firem', 'dataType' => 'STRING' ) ),
							'Lead'    => array(
								array( 'name' => 'Pocet_zam_a1b2c', 'label' => 'Počet zaměstnanců', 'dataType' => 'BIG_DECIMAL', 'groupName' => 'Firma' ),
								array( 'name' => 'Velikost_d3e4f', 'label' => 'Velikost zakázky', 'dataType' => 'ENUMERATION', 'enumeration' => array( 'Malá', 'Velká' ) ),
								array( 'name' => 'Termin_g5h6', 'label' => 'Termín realizace', 'dataType' => 'DATE' ),
								array( 'name' => 'VIP_b91d1', 'label' => 'VIP', 'dataType' => 'BOOLEAN' ),
								array( 'name' => 'Priloha_l0', 'label' => 'Příloha', 'dataType' => 'FILE' ),
							),
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// The instance's users: two people, an account without a person and a
		// row RAYNET marks INVALID.
		if ( false !== strpos( $url, 'raynet' ) && false !== strpos( $url, '/userAccount/' ) ) {
			// The API key may lack the right to list users.
			if ( get_option( 'raynet_test_refuse_users' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'message' => 'Nemáte dostatečná oprávnění' ) ),
					'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success'    => true,
						'totalCount' => 4,
						'data'       => array(
							array( 'id' => 8, 'username' => 'petr@firma.cz', 'person' => array( 'id' => 9, 'fullName' => 'Petr Svoboda' ), 'userRole' => 'USER' ),
							array( 'id' => 10, 'username' => 'jana@firma.cz', 'person' => array( 'id' => 11, 'fullName' => 'Jana Nováková' ), 'userRole' => 'ADMIN' ),
							array( 'id' => 12, 'username' => 'api@firma.cz', 'person' => null ),
							array( 'id' => 13, 'username' => 'byvaly@firma.cz', 'person' => array( 'id' => 14, 'fullName' => 'Bývalý Kolega' ), 'rowInfo.rowAccess' => 'INVALID' ),
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// A RAYNET outage, switched on by the suite for the fallback tests.
		if ( false !== strpos( $url, 'raynet' ) && false !== strpos( $url, '/lead/' ) && get_option( 'raynet_test_refuse_leads' ) ) {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// The first step of an attachment: RAYNET stores the file and names it.
		if ( false !== strpos( $url, 'raynet' ) && false !== strpos( $url, '/fileUpload' ) ) {
			preg_match( '/filename="([^"]*)"/', (string) ( isset( $args['body'] ) ? $args['body'] : '' ), $name );

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'uuid'        => 'test-uuid-' . md5( (string) $args['body'] ),
						'fileName'    => isset( $name[1] ) ? $name[1] : 'soubor',
						'contentType' => 'application/pdf',
						'fileSize'    => strlen( (string) $args['body'] ),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( false !== strpos( $url, 'raynet' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'success' => true, 'data' => array( 'id' => 4242 ) ) ),
				'response' => array( 'code' => 201, 'message' => 'Created' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( false !== strpos( $url, 'api.github.com' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'tag_name'     => 'v99.0.0',
						'published_at' => '2026-01-01T00:00:00Z',
						'body'         => 'Testovací vydání.',
						'assets'       => array(
							array( 'browser_download_url' => 'https://github.com/nechodom/Raynet-Lead-API-Integration/releases/download/v99.0.0/raynet-lead-api-integration.zip' ),
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// Nothing else leaves the machine. WordPress checks wordpress.org for
		// updates after a quiet spell, and a slow answer used to stall the
		// single-threaded test server past its time limit.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return new WP_Error( 'raynet_test_offline', 'Testovací prostředí nepouští požadavky ven: ' . $host );
		}

		return $preempt;
	},
	10,
	3
);

// Mail is captured instead of sent: what went out, with which attachments.
add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$mails   = get_option( 'raynet_test_mails', array() );
		$mails[] = array(
			'to'          => $atts['to'],
			'subject'     => $atts['subject'],
			'message'     => $atts['message'],
			'attachments' => array_map(
				function ( $path ) {
					return array( 'name' => basename( $path ), 'exists' => is_file( $path ), 'content' => is_file( $path ) ? (string) file_get_contents( $path ) : '' );
				},
				array_values( (array) $atts['attachments'] )
			),
		);
		update_option( 'raynet_test_mails', $mails, false );

		return true;
	},
	10,
	2
);
