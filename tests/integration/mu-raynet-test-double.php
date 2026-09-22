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
		$calls   = get_option( 'raynet_test_http_calls', array() );
		$calls[] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : '',
		);
		update_option( 'raynet_test_http_calls', $calls, false );

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

		return $preempt;
	},
	10,
	3
);
