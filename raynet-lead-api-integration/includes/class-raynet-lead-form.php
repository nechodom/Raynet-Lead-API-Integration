<?php
/**
 * Front end form: rendering, validation and submission to RAYNET.
 *
 * The browser only ever talks to WordPress. Credentials stay on the server.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcode and the submission endpoints.
 */
class Raynet_Lead_Form {

	/**
	 * Nonce action used by the submission endpoints.
	 */
	const NONCE_ACTION = 'raynet_lead_submit';

	/**
	 * Fields the shortcode knows how to render.
	 */
	const SUPPORTED_FIELDS = array(
		'firstName',
		'lastName',
		'companyName',
		'email',
		'phone',
		'topic',
		'message',
		'street',
		'city',
		'zipCode',
	);

	/**
	 * Tracks whether assets were already enqueued on this request.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'raynet_lead_form', array( $this, 'render_shortcode' ) );

		add_action( 'wp_ajax_raynet_lead_submit', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_raynet_lead_submit', array( $this, 'handle_ajax' ) );

		add_action( 'wp_ajax_raynet_lead_refresh_nonce', array( $this, 'handle_nonce_refresh' ) );
		add_action( 'wp_ajax_nopriv_raynet_lead_refresh_nonce', array( $this, 'handle_nonce_refresh' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Registers (but does not enqueue) the front end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'raynet-lead-form',
			RAYNET_LEAD_URL . 'assets/css/raynet-lead-form.css',
			array(),
			RAYNET_LEAD_VERSION
		);

		wp_register_script(
			'raynet-lead-form',
			RAYNET_LEAD_URL . 'assets/js/raynet-lead-form.js',
			array(),
			RAYNET_LEAD_VERSION,
			true
		);
	}

	/**
	 * Enqueues the assets the first time a form is rendered on the page.
	 *
	 * @return void
	 */
	private function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}

		wp_enqueue_style( 'raynet-lead-form' );
		wp_enqueue_script( 'raynet-lead-form' );

		wp_localize_script(
			'raynet-lead-form',
			'raynetLeadForm',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => 'raynet_lead_submit',
				'nonceAction'  => 'raynet_lead_refresh_nonce',
				'genericError' => __( 'Odeslání formuláře selhalo. Zkuste to prosím znovu.', 'raynet-lead-api-integration' ),
				'sending'      => __( 'Odesílám…', 'raynet-lead-api-integration' ),
			)
		);

		$this->assets_enqueued = true;
	}

	/**
	 * Registers the REST alternative to admin-ajax.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			'raynet-lead/v1',
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Renders the [raynet_lead_form] shortcode.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string Form markup.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'       => '',
				'fields'   => '',
				'required' => '',
				'topic'    => '',
				'title'    => '',
				'button'   => __( 'Odeslat', 'raynet-lead-api-integration' ),
				'class'    => '',
				'redirect' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'raynet_lead_form'
		);

		$form_post_id = Raynet_Lead_Form_Post_Type::resolve( $atts['id'] );

		if ( ! $form_post_id ) {
			return $this->missing_form_notice( $atts['id'] );
		}

		$fields = $this->apply_legacy_atts( Raynet_Lead_Form_Post_Type::get_fields( $form_post_id ), $atts );

		$this->enqueue_assets();

		$form_uid = wp_unique_id( 'raynet-lead-form-' );
		$settings = Raynet_Lead_Settings::all();
		$lead     = Raynet_Lead_Form_Post_Type::get_lead_settings( $form_post_id );
		$stamp    = time();

		$redirect = $atts['redirect'];

		if ( '' === $redirect ) {
			$redirect = '' !== $lead['redirect_url'] ? $lead['redirect_url'] : $settings['redirect_url'];
		}

		ob_start();
		?>
		<form
			id="<?php echo esc_attr( $form_uid ); ?>"
			class="raynet-lead-form <?php echo esc_attr( $atts['class'] ); ?>"
			method="post"
			novalidate
			data-redirect="<?php echo esc_url( $redirect ); ?>"
		>
			<?php if ( '' !== $atts['title'] ) : ?>
				<h3 class="raynet-lead-form__title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>

			<?php wp_nonce_field( self::NONCE_ACTION, 'raynet_nonce', false ); ?>
			<input type="hidden" name="raynet_form_id" value="<?php echo esc_attr( (string) $form_post_id ); ?>" />
			<input type="hidden" name="raynet_ts" value="<?php echo esc_attr( (string) $stamp ); ?>" />
			<input type="hidden" name="raynet_ts_hash" value="<?php echo esc_attr( $this->stamp_hash( $stamp ) ); ?>" />
			<input type="hidden" name="raynet_fixed_topic" value="<?php echo esc_attr( $atts['topic'] ); ?>" />
			<input type="hidden" name="raynet_source_url" value="<?php echo esc_url( $this->current_url() ); ?>" />

			<?php if ( ! empty( $settings['honeypot_enabled'] ) ) : ?>
				<div class="raynet-lead-form__hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $form_uid ); ?>-website"><?php esc_html_e( 'Nevyplňujte toto pole', 'raynet-lead-api-integration' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $form_uid ); ?>-website" name="website" tabindex="-1" autocomplete="off" value="" />
				</div>
			<?php endif; ?>

			<?php
			// Every value is escaped inside the renderer.
			echo Raynet_Lead_Form_Renderer::render_fields( $fields, $form_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<div class="raynet-lead-form__row raynet-lead-form__row--submit">
				<button type="submit" class="raynet-lead-form__submit"><?php echo esc_html( $atts['button'] ); ?></button>
			</div>

			<div class="raynet-lead-form__notification" role="status" aria-live="polite"></div>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Applies the deprecated fields= and required= attributes to a form.
	 *
	 * Version 2.0 sites embedded the shortcode with these attributes. Treating
	 * them as a filter over the resolved form keeps those pages rendering what
	 * they rendered before, without creating a second source of truth for the
	 * field list.
	 *
	 * @param array<int,array<string,mixed>> $fields Field definitions.
	 * @param array<string,string>           $atts   Shortcode attributes.
	 * @return array<int,array<string,mixed>> Possibly narrowed definitions.
	 */
	private function apply_legacy_atts( array $fields, array $atts ) {
		if ( '' !== trim( (string) $atts['required'] ) ) {
			$required = $this->parse_field_list( $atts['required'], array() );

			foreach ( $fields as $index => $field ) {
				if ( Raynet_Lead_Form_Definition::is_lead_source( $field['source'] ) ) {
					$fields[ $index ]['required'] = in_array( $field['source'], $required, true );
				}
			}
		}

		if ( '' === trim( (string) $atts['fields'] ) ) {
			return $fields;
		}

		$wanted = $this->parse_field_list( $atts['fields'], array() );

		if ( empty( $wanted ) ) {
			// Nothing recognizable was named, so the filter is not applied at all
			// rather than emptying the form.
			return $fields;
		}

		$narrowed = array();

		foreach ( $wanted as $source ) {
			foreach ( $fields as $field ) {
				if ( $field['source'] === $source ) {
					$narrowed[] = $field;
				}
			}
		}

		// Consent and custom fields cannot be named in the legacy list, so they
		// survive the filter and keep their place at the end.
		foreach ( $fields as $field ) {
			if ( ! Raynet_Lead_Form_Definition::is_lead_source( $field['source'] ) ) {
				$narrowed[] = $field;
			}
		}

		return $narrowed;
	}

	/**
	 * Output shown when the shortcode names no usable form.
	 *
	 * A visitor must never see a technical error, so this renders a message for
	 * users who can act on it and nothing at all for everyone else.
	 *
	 * @param string $requested The id attribute that failed to resolve.
	 * @return string Markup, or an empty string.
	 */
	private function missing_form_notice( $requested ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$message = '' !== trim( (string) $requested )
			? sprintf(
				/* translators: %s: the id given to the shortcode. */
				__( 'RAYNET: formulář „%s“ neexistuje nebo je v koši.', 'raynet-lead-api-integration' ),
				$requested
			)
			: __( 'RAYNET: není nastaven výchozí formulář. Založte ho v RAYNET CRM → Formuláře.', 'raynet-lead-api-integration' );

		return '<p class="raynet-lead-form__admin-notice">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Handles an admin-ajax submission.
	 *
	 * @return void
	 */
	public function handle_ajax() {
		$result = $this->process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified inside process().

		if ( is_wp_error( $result ) ) {
			$status = 'raynet_nonce_expired' === $result->get_error_code() ? 403 : 400;

			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handles a REST submission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function handle_rest( WP_REST_Request $request ) {
		$result = $this->process( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
	}

	/**
	 * Returns a fresh nonce, so cached pages can recover from a stale one.
	 *
	 * @return void
	 */
	public function handle_nonce_refresh() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Validates a submission and forwards it to RAYNET.
	 *
	 * @param array<string,mixed> $input Raw request data.
	 * @return array<string,mixed>|WP_Error Result payload, or an error.
	 */
	private function process( array $input ) {
		$settings = Raynet_Lead_Settings::all();

		$nonce = isset( $input['raynet_nonce'] ) ? (string) $input['raynet_nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'raynet_nonce_expired',
				__( 'Platnost formuláře vypršela. Zkuste to prosím znovu.', 'raynet-lead-api-integration' )
			);
		}

		$spam = $this->check_spam( $input, $settings );

		if ( is_wp_error( $spam ) ) {
			if ( 'raynet_throttled' !== $spam->get_error_code() ) {
				$this->mark_throttled();
			}

			return $spam;
		}

		if ( ! empty( $settings['consent_enabled'] ) && empty( $input['consent'] ) ) {
			return new WP_Error(
				'raynet_consent_required',
				__( 'Bez souhlasu se zpracováním údajů nelze formulář odeslat.', 'raynet-lead-api-integration' )
			);
		}

		$values = $this->collect_values( $input );

		if ( '' === $values['email'] && '' === $values['phone'] ) {
			return new WP_Error(
				'raynet_contact_required',
				__( 'Vyplňte prosím e-mail nebo telefon.', 'raynet-lead-api-integration' )
			);
		}

		if ( '' !== $values['email'] && ! is_email( $values['email'] ) ) {
			return new WP_Error(
				'raynet_invalid_email',
				__( 'Zadaná e-mailová adresa není platná.', 'raynet-lead-api-integration' )
			);
		}

		if ( ! Raynet_Lead_Settings::is_configured() ) {
			$this->log_error( 'Plugin is not configured; lead was not sent.' );
			$this->send_fallback_email( $values, __( 'Plugin není nastaven.', 'raynet-lead-api-integration' ) );

			return new WP_Error(
				'raynet_not_configured',
				$this->public_error_message( $settings )
			);
		}

		$payload = $this->build_payload( $values, $settings, $input );

		/**
		 * Filters the lead payload right before it is sent to RAYNET.
		 *
		 * @param array<string,mixed> $payload Lead payload.
		 * @param array<string,string> $values Sanitized form values.
		 */
		$payload = apply_filters( 'raynet_lead_payload', $payload, $values );

		$this->mark_throttled();

		$client   = Raynet_Lead_Api_Client::from_settings();
		$response = $client->create_lead( $payload );

		if ( is_wp_error( $response ) ) {
			$this->log_error( $response->get_error_message() );
			$this->remember_last_error( $response->get_error_message() );
			$this->send_fallback_email( $values, $response->get_error_message() );

			/**
			 * Fires when RAYNET refused or could not receive the lead.
			 *
			 * @param WP_Error            $response Error returned by the client.
			 * @param array<string,mixed> $payload  Payload that was attempted.
			 */
			do_action( 'raynet_lead_failed', $response, $payload );

			return new WP_Error( 'raynet_api_error', $this->public_error_message( $settings ) );
		}

		$lead_id = isset( $response['data']['id'] ) ? (int) $response['data']['id'] : 0;

		/**
		 * Fires after a lead has been created in RAYNET.
		 *
		 * @param int                 $lead_id New lead id.
		 * @param array<string,mixed> $payload Payload that was sent.
		 */
		do_action( 'raynet_lead_created', $lead_id, $payload );

		$message = '' !== trim( (string) $settings['success_message'] )
			? $settings['success_message']
			: __( 'Formulář byl úspěšně odeslán. Děkujeme, ozveme se vám.', 'raynet-lead-api-integration' );

		return array(
			'message'  => $message,
			'redirect' => (string) $settings['redirect_url'],
		);
	}

	/**
	 * Runs the anti spam checks.
	 *
	 * @param array<string,mixed> $input    Raw request data.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return true|WP_Error True when the submission looks human.
	 */
	private function check_spam( array $input, array $settings ) {
		if ( ! empty( $settings['honeypot_enabled'] ) && ! empty( $input['website'] ) ) {
			return new WP_Error(
				'raynet_spam',
				__( 'Odeslání formuláře selhalo.', 'raynet-lead-api-integration' )
			);
		}

		$min_seconds = (int) $settings['min_fill_seconds'];

		if ( $min_seconds > 0 ) {
			$stamp = isset( $input['raynet_ts'] ) ? (int) $input['raynet_ts'] : 0;
			$hash  = isset( $input['raynet_ts_hash'] ) ? (string) $input['raynet_ts_hash'] : '';

			if ( ! $stamp || ! hash_equals( $this->stamp_hash( $stamp ), $hash ) ) {
				return new WP_Error(
					'raynet_invalid_form',
					__( 'Platnost formuláře vypršela. Načtěte prosím stránku znovu.', 'raynet-lead-api-integration' )
				);
			}

			$age = time() - $stamp;

			if ( $age < $min_seconds || $age > DAY_IN_SECONDS ) {
				return new WP_Error(
					'raynet_too_fast',
					__( 'Formulář byl odeslán příliš rychle. Zkuste to prosím znovu.', 'raynet-lead-api-integration' )
				);
			}
		}

		if ( (int) $settings['throttle_seconds'] > 0 && get_transient( $this->throttle_key() ) ) {
			return new WP_Error(
				'raynet_throttled',
				__( 'Počkejte prosím chvíli před dalším odesláním.', 'raynet-lead-api-integration' )
			);
		}

		return true;
	}

	/**
	 * Starts the per IP cool down.
	 *
	 * Called when a spam trap trips and after a send is attempted, so that a
	 * mistyped e-mail address does not lock a visitor out for half a minute.
	 *
	 * @return void
	 */
	private function mark_throttled() {
		$seconds = (int) Raynet_Lead_Settings::get( 'throttle_seconds' );

		if ( $seconds > 0 ) {
			set_transient( $this->throttle_key(), 1, $seconds );
		}
	}

	/**
	 * Transient key holding the cool down for the current client.
	 *
	 * @return string Transient key.
	 */
	private function throttle_key() {
		return 'raynet_lead_throttle_' . md5( $this->client_ip() );
	}

	/**
	 * Extracts and sanitizes every known form field.
	 *
	 * @param array<string,mixed> $input Raw request data.
	 * @return array<string,string> Sanitized values.
	 */
	private function collect_values( array $input ) {
		$values = array();

		foreach ( self::SUPPORTED_FIELDS as $field ) {
			$raw = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';

			if ( 'message' === $field ) {
				$values[ $field ] = mb_substr( sanitize_textarea_field( $raw ), 0, 5000 );
			} elseif ( 'email' === $field ) {
				$values[ $field ] = sanitize_email( trim( $raw ) );
			} else {
				$values[ $field ] = mb_substr( sanitize_text_field( $raw ), 0, 255 );
			}
		}

		return $values;
	}

	/**
	 * Builds the RAYNET lead payload.
	 *
	 * @param array<string,string> $values   Sanitized form values.
	 * @param array<string,mixed>  $settings Plugin settings.
	 * @param array<string,mixed>  $input    Raw request data.
	 * @return array<string,mixed> Lead payload.
	 */
	private function build_payload( array $values, array $settings, array $input ) {
		$fixed_topic = isset( $input['raynet_fixed_topic'] ) ? sanitize_text_field( (string) $input['raynet_fixed_topic'] ) : '';
		$source_url  = isset( $input['raynet_source_url'] ) ? esc_url_raw( (string) $input['raynet_source_url'] ) : '';

		$topic = $values['topic'];

		if ( '' === $topic ) {
			$topic = '' !== $fixed_topic ? $fixed_topic : (string) $settings['default_topic'];
		}

		if ( '' === $topic ) {
			$topic = sprintf(
				/* translators: %s: site name. */
				__( 'Poptávka z webu %s', 'raynet-lead-api-integration' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);
		}

		$payload = array(
			'topic'       => mb_substr( $topic, 0, 255 ),
			'priority'    => (string) $settings['priority'],
			'leadDate'    => current_time( 'Y-m-d' ),
			'leadPerson'  => (bool) $settings['lead_person'],
			'firstName'   => $values['firstName'],
			'lastName'    => $values['lastName'],
			'companyName' => $values['companyName'],
			'notice'      => $this->build_notice( $values, $settings, $source_url ),
			'contactInfo' => array_filter(
				array(
					'email' => $values['email'],
					'tel1'  => $values['phone'],
				),
				array( $this, 'is_not_empty' )
			),
			'address'     => array_filter(
				array(
					'street'  => $values['street'],
					'city'    => $values['city'],
					'zipCode' => $values['zipCode'],
				),
				array( $this, 'is_not_empty' )
			),
		);

		if ( ! empty( $values['companyName'] ) ) {
			$payload['leadPerson'] = false;
		}

		foreach ( array(
			'category'       => 'category',
			'lead_phase'     => 'leadPhase',
			'contact_source' => 'contactSource',
			'owner'          => 'owner',
			'security_level' => 'securityLevel',
		) as $setting_key => $api_key ) {
			if ( (int) $settings[ $setting_key ] > 0 ) {
				$payload[ $api_key ] = (int) $settings[ $setting_key ];
			}
		}

		if ( '' !== trim( (string) $settings['tags'] ) ) {
			$payload['tags'] = (string) $settings['tags'];
		}

		$notify = Raynet_Lead_Settings::sanitize_email_list( (string) $settings['notify_emails'] );

		if ( ! empty( $notify ) ) {
			$payload['notificationEmailAddresses'] = $notify;
			$payload['notificationMessage']        = sprintf(
				/* translators: %s: lead topic. */
				__( 'Nový lead z webu: %s', 'raynet-lead-api-integration' ),
				$payload['topic']
			);
		}

		return array_filter( $payload, array( $this, 'is_not_empty' ) );
	}

	/**
	 * Composes the lead note out of the message, the configured prefix and context.
	 *
	 * @param array<string,string> $values     Sanitized form values.
	 * @param array<string,mixed>  $settings   Plugin settings.
	 * @param string               $source_url URL the form was submitted from.
	 * @return string Note text.
	 */
	private function build_notice( array $values, array $settings, $source_url ) {
		$parts = array();

		if ( '' !== trim( (string) $settings['notice_prefix'] ) ) {
			$parts[] = trim( (string) $settings['notice_prefix'] );
		}

		if ( '' !== $values['message'] ) {
			$parts[] = $values['message'];
		}

		if ( '' !== $source_url ) {
			$parts[] = sprintf(
				/* translators: %s: page URL. */
				__( 'Odesláno ze stránky: %s', 'raynet-lead-api-integration' ),
				$source_url
			);
		}

		if ( ! empty( $settings['consent_enabled'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: date and time of the consent. */
				__( 'Souhlas se zpracováním údajů udělen: %s', 'raynet-lead-api-integration' ),
				current_time( 'Y-m-d H:i' )
			);
		}

		return mb_substr( implode( "\n\n", $parts ), 0, 10000 );
	}

	/**
	 * Sends the lead to a fallback mailbox when RAYNET cannot take it.
	 *
	 * @param array<string,string> $values Sanitized form values.
	 * @param string               $reason Failure reason.
	 * @return void
	 */
	private function send_fallback_email( array $values, $reason ) {
		$to = (string) Raynet_Lead_Settings::get( 'fallback_email' );

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$lines = array(
			__( 'Lead se nepodařilo odeslat do RAYNET CRM.', 'raynet-lead-api-integration' ),
			sprintf(
				/* translators: %s: failure reason. */
				__( 'Důvod: %s', 'raynet-lead-api-integration' ),
				$reason
			),
			'',
		);

		foreach ( $values as $key => $value ) {
			if ( '' !== $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Neodeslaný lead z webového formuláře', 'raynet-lead-api-integration' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * Returns the message shown to a visitor when the send fails.
	 *
	 * The real reason is logged, never shown, so the API is not probed
	 * through the public form.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string Message.
	 */
	private function public_error_message( array $settings ) {
		return '' !== trim( (string) $settings['error_message'] )
			? (string) $settings['error_message']
			: __( 'Odeslání formuláře se nezdařilo. Zkuste to prosím znovu později.', 'raynet-lead-api-integration' );
	}

	/**
	 * Writes a message to the PHP error log when logging is enabled.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log_error( $message ) {
		if ( ! Raynet_Lead_Settings::get( 'log_errors' ) ) {
			return;
		}

		error_log( '[Raynet Lead API Integration] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in diagnostic logging.
	}

	/**
	 * Stores the last API error so the admin screen can surface it.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function remember_last_error( $message ) {
		update_option(
			'raynet_lead_last_error',
			array(
				'message' => $message,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Signs the render timestamp so the time trap cannot be forged.
	 *
	 * @param int $stamp Unix timestamp.
	 * @return string Hash.
	 */
	private function stamp_hash( $stamp ) {
		return wp_hash( 'raynet_lead_ts|' . (int) $stamp );
	}

	/**
	 * Best effort client IP, used only for throttling.
	 *
	 * @return string IP address, or "unknown".
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' === $ip ? 'unknown' : $ip;
	}

	/**
	 * Returns the URL of the page currently being rendered.
	 *
	 * @return string URL.
	 */
	private function current_url() {
		$permalink = is_singular() ? get_permalink() : '';

		return $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * Splits a comma separated field list into known field names.
	 *
	 * @param string   $list     Raw list.
	 * @param string[] $fallback Value returned when nothing valid is left.
	 * @return string[] Field names.
	 */
	private function parse_field_list( $list, array $fallback ) {
		$parts  = preg_split( '/[,\s]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
		$fields = array();

		foreach ( (array) $parts as $part ) {
			foreach ( self::SUPPORTED_FIELDS as $known ) {
				if ( strtolower( $part ) === strtolower( $known ) ) {
					$fields[] = $known;
				}
			}
		}

		$fields = array_values( array_unique( $fields ) );

		return empty( $fields ) ? $fallback : $fields;
	}

	/**
	 * Filter callback keeping only non empty values.
	 *
	 * @param mixed $value Value under test.
	 * @return bool True when the value should be kept.
	 */
	public function is_not_empty( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return true;
		}

		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		return '' !== trim( (string) $value );
	}
}
