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

// The suite stores a value here to stand in for the wp-config.php constant
// that wins over the stored OpenAI key.
if ( get_option( 'raynet_test_openai_constant' ) && ! defined( 'RAYNET_OPENAI_API_KEY' ) ) {
	define( 'RAYNET_OPENAI_API_KEY', (string) get_option( 'raynet_test_openai_constant' ) );
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		// Let a more specific filter win; this one is only the default.
		if ( false !== $preempt ) {
			return $preempt;
		}

		$auth    = isset( $args['headers']['Authorization'] ) ? (string) $args['headers']['Authorization'] : '';
		$calls   = get_option( 'raynet_test_http_calls', array() );
		$calls[] = array(
			'url'    => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body'   => isset( $args['body'] ) ? $args['body'] : '',
			// Only whether a bearer token went out, never the token: this
			// option outlives the request.
			'bearer' => 0 === strpos( $auth, 'Bearer ' ) && strlen( $auth ) > 7,
		);
		update_option( 'raynet_test_http_calls', $calls, false );

		// OpenAI's Responses API, answering from a script the suite stores
		// in `raynet_test_openai`: status, mode (results, refusal, text,
		// timeout), verdicts by id ('skip' leaves an id out), a default
		// verdict for the rest, and extra rows for ids not in the batch.
		if ( 'api.openai.com' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			$script = get_option( 'raynet_test_openai', array() );
			$script = is_array( $script ) ? $script : array();
			$status = isset( $script['status'] ) ? (int) $script['status'] : 200;
			$mode   = isset( $script['mode'] ) ? (string) $script['mode'] : 'results';

			if ( 'timeout' === $mode ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' );
			}

			if ( 200 !== $status ) {
				$messages = array(
					401 => 'Incorrect API key provided: ' . substr( $auth, 7, 6 ) . '****. You can find your API key at https://platform.openai.com/account/api-keys.',
					429 => 'Rate limit reached for requests.',
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => isset( $messages[ $status ] ) ? $messages[ $status ] : 'The server had an error.', 'type' => 'test' ) ) ),
					'response' => array( 'code' => $status, 'message' => 'Error' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			$request = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
			$batch   = isset( $request['input'][1]['content'] ) ? json_decode( (string) $request['input'][1]['content'], true ) : array();
			$results = array();

			foreach ( isset( $batch['submissions'] ) && is_array( $batch['submissions'] ) ? $batch['submissions'] : array() as $item ) {
				$id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
				$verdict = isset( $script['verdicts'][ $id ] ) ? (string) $script['verdicts'][ $id ] : ( isset( $script['default'] ) ? (string) $script['default'] : 'import' );

				if ( 'skip' === $verdict ) {
					continue;
				}

				$results[] = array(
					'id'      => $id,
					'verdict' => $verdict,
					'reason'  => isset( $script['reasons'][ $id ] ) ? (string) $script['reasons'][ $id ] : 'Testovací důvod ' . $id . '.',
				);
			}

			if ( isset( $script['extra'] ) && is_array( $script['extra'] ) ) {
				$results = array_merge( $results, $script['extra'] );
			}

			if ( 'refusal' === $mode ) {
				$part = array( 'type' => 'refusal', 'refusal' => 'I can not help with that.' );
			} elseif ( 'text' === $mode ) {
				$part = array( 'type' => 'output_text', 'text' => 'Import all of them.' );
			} else {
				$part = array( 'type' => 'output_text', 'text' => wp_json_encode( array( 'results' => $results ) ) );
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'     => 'resp_test',
						'object' => 'response',
						'status' => 'completed',
						'output' => array(
							array( 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => array( $part ) ),
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

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
					'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
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

// A spam-score filter that hands back reasons in shapes the screen does not
// expect: a bare string, an unknown id, a number, nothing.
add_filter(
	'raynet_import_spam_score',
	function ( $result ) {
		if ( ! get_option( 'raynet_test_odd_reasons' ) || ! is_array( $result ) || ! isset( $result['reasons'] ) ) {
			return $result;
		}

		$result['reasons'] = array_merge( (array) $result['reasons'], array( 'vlastni_<b>signal</b>', array( 'id' => 'cizi_id' ), 5, null ) );

		return $result;
	}
);
