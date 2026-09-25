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

		// The definition comes from the database, never from the request, so a
		// hand-crafted POST cannot add fields the visitor was never shown.
		$form_post_id = Raynet_Lead_Form_Post_Type::resolve(
			isset( $input['raynet_form_id'] ) ? (string) $input['raynet_form_id'] : ''
		);

		$fields = $form_post_id ? Raynet_Lead_Form_Post_Type::get_fields( $form_post_id ) : array();

		if ( $form_post_id ) {
			$settings = $this->merge_lead_settings(
				$settings,
				Raynet_Lead_Form_Post_Type::get_lead_settings( $form_post_id )
			);
		}

		$has_consent   = false;
		$consent_text  = '';
		$message_label = '';

		foreach ( $fields as $field ) {
			if ( 'message' === $field['source'] && isset( $field['label'] ) ) {
				$message_label = wp_strip_all_tags( (string) $field['label'] );
			}
		}

		foreach ( $fields as $field ) {
			if ( 'consent' === $field['source'] ) {
				$has_consent  = true;
				$consent_text = isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : '';
				break;
			}
		}

		if ( $has_consent && empty( $input['consent'] ) ) {
			return new WP_Error(
				'raynet_consent_required',
				__( 'Bez souhlasu se zpracováním údajů nelze formulář odeslat.', 'raynet-lead-api-integration' )
			);
		}

		$values = $this->collect_values( $input, $fields );

		// The context is built key by key rather than handed the request. The
		// payload builder also reads keys that only trusted callers set — the
		// Elementor action's cleaned attributes and custom fields — and a
		// visitor posting those would write straight into the lead: its owner,
		// its note, or RAYNET's notification e-mails.
		$context = array(
			'raynet_fixed_topic' => isset( $input['raynet_fixed_topic'] ) ? $input['raynet_fixed_topic'] : '',
			'raynet_source_url'  => isset( $input['raynet_source_url'] ) ? $input['raynet_source_url'] : '',
			'raynet_extras'      => $this->collect_custom( $input, $fields ),
			'raynet_has_consent'    => $has_consent,
			'raynet_consent_text'   => $consent_text,
			// The builder's consent box is required and was checked above.
			'raynet_consent_record' => $has_consent,
			'raynet_message_label'  => $message_label,
		);

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

		return $this->submit_lead( $values, $settings, $context );
	}

	/**
	 * Builds the payload, sends the lead and handles failure.
	 *
	 * The shared end of the pipeline. Every caller does its own validation and
	 * anti-spam first; from here on the work is identical, so the documented
	 * hooks and the fallback path exist exactly once.
	 *
	 * @param array<string,string> $values   Sanitized values, keyed by supported field.
	 * @param array<string,mixed>  $settings Effective settings for this submission.
	 * @param array<string,mixed>  $context  Optional context: raynet_fixed_topic,
	 *                                       raynet_source_url, raynet_extras and
	 *                                       raynet_has_consent.
	 * @return array<string,mixed>|WP_Error Result, or an error.
	 */
	public function submit_lead( array $values, array $settings, array $context = array() ) {
		if ( ! Raynet_Lead_Settings::is_configured() ) {
			$this->log_error( 'Plugin is not configured; lead was not sent.' );
			$this->send_fallback_email( $values, __( 'Plugin není nastaven.', 'raynet-lead-api-integration' ), $context );

			return new WP_Error(
				'raynet_not_configured',
				$this->public_error_message( $settings ),
				array( 'diagnostic' => __( 'Plugin není nastaven.', 'raynet-lead-api-integration' ) )
			);
		}

		$payload = $this->build_payload( $values, $settings, $context );

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
			$this->send_fallback_email( $values, $response->get_error_message(), $context );

			/**
			 * Fires when RAYNET refused or could not receive the lead.
			 *
			 * @param WP_Error            $response Error returned by the client.
			 * @param array<string,mixed> $payload  Payload that was attempted.
			 */
			do_action( 'raynet_lead_failed', $response, $payload );

			// The visitor gets the configured wording; the real reason travels in
			// the data so a caller with somewhere to put it — Elementor's
			// admin-only error channel — can surface it.
			return new WP_Error(
				'raynet_api_error',
				$this->public_error_message( $settings ),
				array( 'diagnostic' => $response->get_error_message() )
			);
		}

		$lead_id = isset( $response['data']['id'] ) ? (int) $response['data']['id'] : 0;

		if ( $lead_id > 0 && ! empty( $context['raynet_has_consent'] ) && ! empty( $context['raynet_consent_record'] ) ) {
			$this->record_consent( $client, $lead_id, $settings );
		}

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
			'lead_id'  => $lead_id,
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
	 * Extracts and sanitizes the lead attributes a form actually asks for.
	 *
	 * The returned array always carries every supported key, so callers can read
	 * $values['email'] without checking. Attributes the form does not contain
	 * stay empty, which is what stops a hand-crafted POST from filling a field
	 * the visitor was never shown.
	 *
	 * @param array<string,mixed>            $input  Raw request data.
	 * @param array<int,array<string,mixed>> $fields Field definitions. Empty means all supported fields.
	 * @return array<string,string> Sanitized values.
	 */
	public function collect_values( array $input, array $fields = array() ) {
		$values = array();

		// With no definition every attribute is allowed, the derived ones too:
		// the Elementor action maps by attribute name and has no field list.
		$allowed = array_merge( self::SUPPORTED_FIELDS, Raynet_Lead_Form_Definition::DERIVED_SOURCES );

		if ( ! empty( $fields ) ) {
			$allowed = array();

			foreach ( $fields as $field ) {
				if ( Raynet_Lead_Form_Definition::is_lead_source( $field['source'] ) ) {
					$allowed[] = $field['source'];
				}
			}
		}

		$whole_name = in_array( 'fullName', $allowed, true ) && isset( $input['fullName'] )
			? (string) $input['fullName']
			: '';

		foreach ( self::SUPPORTED_FIELDS as $field ) {
			$raw = in_array( $field, $allowed, true ) && isset( $input[ $field ] )
				? (string) $input[ $field ]
				: '';

			if ( 'message' === $field ) {
				$values[ $field ] = mb_substr( sanitize_textarea_field( $raw ), 0, 5000 );
			} elseif ( 'email' === $field ) {
				$values[ $field ] = sanitize_email( trim( $raw ) );
			} else {
				$values[ $field ] = mb_substr( sanitize_text_field( $raw ), 0, 255 );
			}
		}

		if ( '' !== trim( $whole_name ) ) {
			$split = Raynet_Lead_Form_Definition::split_name( sanitize_text_field( $whole_name ) );

			$whole = trim( sanitize_text_field( $whole_name ) );

			// A separately asked first or last name is the more deliberate
			// answer, so it wins over the split. The exception is the same field
			// mapped twice, which arrives as the whole name in both slots and
			// would otherwise put "Jan Novák" in firstName.
			foreach ( array( 'firstName', 'lastName' ) as $part ) {
				if ( '' === $values[ $part ] || $values[ $part ] === $whole ) {
					$values[ $part ] = mb_substr( $split[ $part ], 0, 255 );
				}
			}
		}

		return $values;
	}

	/**
	 * Reads the custom fields a form declares.
	 *
	 * Values are keyed by the label the admin gave the field, because that label
	 * is what ends up in the lead note.
	 *
	 * @param array<string,mixed>            $input  Raw request data.
	 * @param array<int,array<string,mixed>> $fields Field definitions.
	 * @return array<string,string> Label => value.
	 */
	private function collect_custom( array $input, array $fields ) {
		$raw = isset( $input['raynet_custom'] ) && is_array( $input['raynet_custom'] )
			? $input['raynet_custom']
			: array();

		$extras = array();

		foreach ( $fields as $field ) {
			if ( 'custom' !== $field['source'] ) {
				continue;
			}

			$value = isset( $raw[ $field['id'] ] ) ? $raw[ $field['id'] ] : '';

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$value = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );

			if ( 'checkbox' === $field['type'] ) {
				// An unticked box is an answer too, and often the interesting one.
				$extras[ $field['label'] ] = '' !== $value
					? __( 'ano', 'raynet-lead-api-integration' )
					: __( 'ne', 'raynet-lead-api-integration' );
				continue;
			}

			if ( '' === trim( $value ) ) {
				continue;
			}

			$extras[ $field['label'] ] = $value;
		}

		return $extras;
	}

	/**
	 * Lays a form's own lead settings over the global ones.
	 *
	 * An empty string, or zero for the code-list ids, means the form inherits.
	 *
	 * @param array<string,mixed> $settings Global plugin settings.
	 * @param array<string,mixed> $lead     Per-form lead settings.
	 * @return array<string,mixed> Effective settings.
	 */
	public function merge_lead_settings( array $settings, array $lead ) {
		$text = array(
			'topic'           => 'default_topic',
			'priority'        => 'priority',
			'notice_prefix'   => 'notice_prefix',
			'tags'            => 'tags',
			'notify_emails'   => 'notify_emails',
			'success_message' => 'success_message',
			'redirect_url'    => 'redirect_url',
		);

		foreach ( $text as $from => $to ) {
			if ( '' !== trim( (string) $lead[ $from ] ) ) {
				$settings[ $to ] = $lead[ $from ];
			}
		}

		foreach ( array( 'category', 'lead_phase', 'contact_source', 'owner', 'security_level' ) as $key ) {
			if ( (int) $lead[ $key ] > 0 ) {
				$settings[ $key ] = (int) $lead[ $key ];
			}
		}

		if ( '' !== (string) $lead['lead_person'] ) {
			$settings['lead_person'] = (int) $lead['lead_person'];
		}

		return $settings;
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
			'notice'      => $this->build_notice(
				$values,
				$settings,
				$source_url,
				isset( $input['raynet_extras'] ) && is_array( $input['raynet_extras'] ) ? $input['raynet_extras'] : array(),
				! empty( $input['raynet_has_consent'] ),
				isset( $input['raynet_consent_text'] ) ? sanitize_text_field( (string) $input['raynet_consent_text'] ) : '',
				isset( $input['raynet_message_label'] ) ? sanitize_text_field( (string) $input['raynet_message_label'] ) : ''
			),
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

		// Attributes beyond the basic ones arrive already cleaned, keyed by where
		// they belong in the payload: "regNumber", "contactInfo.email2", ...
		if ( isset( $input['raynet_attributes'] ) && is_array( $input['raynet_attributes'] ) ) {
			foreach ( $input['raynet_attributes'] as $path => $value ) {
				$this->set_path( $payload, (string) $path, $value );
			}
		}

		if ( ! empty( $input['raynet_custom_fields'] ) && is_array( $input['raynet_custom_fields'] ) ) {
			$payload['customFields'] = $input['raynet_custom_fields'];
		}

		// A company registration number means a company, as a company name does.
		if ( ! empty( $payload['regNumber'] ) ) {
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
	 * Logs a problem and shows it to administrators on the settings page.
	 *
	 * For failures after the lead exists, which must not reach the visitor.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function report_error( $message ) {
		$this->log_error( $message );
		$this->remember_last_error( $message );
	}

	/**
	 * Records the visitor's consent as a GDPR legal title on the new lead.
	 *
	 * Only when a legal title template is configured. The lead already exists,
	 * so a failure here does not turn the submission into an error: it is
	 * logged and shown on the settings page, and the note still says the
	 * consent was given.
	 *
	 * @param Raynet_Lead_Api_Client $client   Client.
	 * @param int                    $lead_id  New lead id.
	 * @param array<string,mixed>    $settings Plugin settings.
	 * @return void
	 */
	private function record_consent( $client, $lead_id, array $settings ) {
		$template = isset( $settings['gdpr_template'] ) ? (int) $settings['gdpr_template'] : 0;

		if ( $template <= 0 ) {
			return;
		}

		$record = array(
			'gdprTemplate' => $template,
			'lead'         => (int) $lead_id,
			'validFrom'    => current_time( 'Y-m-d' ),
		);

		$agreement = isset( $settings['gdpr_form_agreement'] ) ? (int) $settings['gdpr_form_agreement'] : 0;
		$months    = isset( $settings['gdpr_valid_months'] ) ? (int) $settings['gdpr_valid_months'] : 0;

		if ( $agreement > 0 ) {
			$record['gdprFormAgreement'] = $agreement;
		}

		if ( $months > 0 ) {
			$record['validTill'] = self::add_months( $record['validFrom'], $months );
		}

		/**
		 * Filters the GDPR legal title recorded for a consenting visitor.
		 *
		 * @param array<string,mixed> $record  Request body for PUT /gdpr/.
		 * @param int                 $lead_id New lead id.
		 */
		$record = apply_filters( 'raynet_lead_gdpr_record', $record, $lead_id );

		$result = $client->create_gdpr( $record );

		if ( is_wp_error( $result ) ) {
			$message = sprintf(
				/* translators: 1: lead id, 2: error message. */
				__( 'Lead %1$d byl založen, ale GDPR souhlas se k němu nepodařilo zapsat: %2$s', 'raynet-lead-api-integration' ),
				(int) $lead_id,
				$result->get_error_message()
			);

			$this->log_error( $message );
			$this->remember_last_error( $message );
		}
	}

	/**
	 * Adds whole months to a date, keeping to the last day of a short month.
	 *
	 * strtotime( '+1 month' ) on 31 January lands on 3 March; a consent given
	 * on the last day of a month is valid until the last day of the target
	 * month, not a few days beyond it.
	 *
	 * @param string $date   Date as Y-m-d.
	 * @param int    $months Months to add.
	 * @return string Date as Y-m-d.
	 */
	public static function add_months( $date, $months ) {
		$from   = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
		$target = $from->modify( 'first day of +' . (int) $months . ' months' );
		$day    = min( (int) $from->format( 'j' ), (int) $target->format( 't' ) );

		return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), $day )->format( 'Y-m-d' );
	}

	/**
	 * Writes a value into the payload at a dotted path.
	 *
	 * Only the paths of the extended attributes are accepted, so a path cannot
	 * plant a key anywhere else — the owner, the note, the notification
	 * addresses. Empty values are skipped: sending one would clear whatever
	 * RAYNET holds.
	 *
	 * @param array<string,mixed> $payload Payload, by reference.
	 * @param string              $path    "attribute" or "object.attribute".
	 * @param mixed               $value   Clean value.
	 * @return void
	 */
	private function set_path( array &$payload, $path, $value ) {
		if ( ! $this->is_not_empty( $value ) || ! in_array( $path, array_column( Raynet_Lead_Fields::extended(), 'path' ), true ) ) {
			return;
		}

		$parts = explode( '.', $path );

		if ( 1 === count( $parts ) && preg_match( '/^[A-Za-z0-9]+$/', $parts[0] ) ) {
			$payload[ $parts[0] ] = $value;
			return;
		}

		if ( 2 !== count( $parts ) || ! in_array( $parts[0], array( 'contactInfo', 'address', 'socialNetworkContact' ), true ) || ! preg_match( '/^[A-Za-z0-9]+$/', $parts[1] ) ) {
			return;
		}

		if ( ! isset( $payload[ $parts[0] ] ) || ! is_array( $payload[ $parts[0] ] ) ) {
			$payload[ $parts[0] ] = array();
		}

		$payload[ $parts[0] ][ $parts[1] ] = $value;
	}

	/**
	 * Composes the lead note out of the message, the configured prefix and context.
	 *
	 * @param array<string,string> $values     Sanitized form values.
	 * @param array<string,mixed>  $settings   Plugin settings.
	 * @param string               $source_url  URL the form was submitted from.
	 * @param array<string,string> $extras      Custom field labels and values.
	 * @param bool                 $has_consent Whether the form carried a consent box.
	 * @return string Note text.
	 */
	private function build_notice( array $values, array $settings, $source_url, array $extras = array(), $has_consent = false, $consent_text = '', $message_label = '' ) {
		$parts = array();

		if ( '' !== trim( (string) $settings['notice_prefix'] ) ) {
			$parts[] = trim( (string) $settings['notice_prefix'] );
		}

		if ( '' !== $values['message'] ) {
			// Under the name of the field it was typed into, so the note reads
			// like the form did.
			$parts[] = '' !== trim( (string) $message_label )
				? trim( (string) $message_label ) . ":\n" . $values['message']
				: $values['message'];
		}

		if ( ! empty( $extras ) ) {
			$lines = array();

			foreach ( $extras as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}

			$parts[] = implode( "\n", $lines );
		}

		if ( '' !== $source_url ) {
			$parts[] = sprintf(
				/* translators: %s: page URL. */
				__( 'Odesláno ze stránky: %s', 'raynet-lead-api-integration' ),
				$source_url
			);
		}

		// Only a form that actually showed a consent box may claim one was given.
		if ( $has_consent ) {
			$line = sprintf(
				/* translators: %s: date and time of the consent. */
				__( 'Souhlas se zpracováním údajů udělen: %s', 'raynet-lead-api-integration' ),
				current_time( 'Y-m-d H:i' )
			);

			// The wording the visitor ticked is what the consent covers.
			if ( '' !== trim( (string) $consent_text ) ) {
				$line .= ' — „' . trim( (string) $consent_text ) . '“';
			}

			$parts[] = $line;
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
	private function send_fallback_email( array $values, $reason, array $context = array() ) {
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

		// The e-mail exists so a lead RAYNET refused is not lost; everything
		// that would have gone into it belongs here, not just the basics.
		$paths  = array_column( Raynet_Lead_Fields::extended(), 'label', 'path' );
		$custom = Raynet_Lead_Fields::custom();
		$more   = array();

		$kinds = array_column( Raynet_Lead_Fields::extended(), 'kind', 'path' );

		foreach ( isset( $context['raynet_attributes'] ) && is_array( $context['raynet_attributes'] ) ? $context['raynet_attributes'] : array() as $path => $value ) {
			// The consent travels as its opposite, "do not send marketing".
			if ( isset( $kinds[ $path ] ) && 'optin' === $kinds[ $path ] && is_bool( $value ) ) {
				$value = ! $value;
			}

			$more[ isset( $paths[ $path ] ) ? $paths[ $path ] : $path ] = $value;
		}

		foreach ( isset( $context['raynet_custom_fields'] ) && is_array( $context['raynet_custom_fields'] ) ? $context['raynet_custom_fields'] : array() as $name => $value ) {
			$more[ isset( $custom[ $name ] ) ? $custom[ $name ]['label'] : $name ] = $value;
		}

		foreach ( isset( $context['raynet_extras'] ) && is_array( $context['raynet_extras'] ) ? $context['raynet_extras'] : array() as $label => $value ) {
			$more[ $label ] = $value;
		}

		if ( ! empty( $more ) ) {
			$lines[] = '';

			foreach ( $more as $label => $value ) {
				if ( is_bool( $value ) ) {
					$value = $value ? __( 'ano', 'raynet-lead-api-integration' ) : __( 'ne', 'raynet-lead-api-integration' );
				}

				$lines[] = $label . ': ' . $value;
			}
		}

		$files       = isset( $context['raynet_files'] ) && is_array( $context['raynet_files'] ) ? $context['raynet_files'] : array();
		$attachments = array();

		foreach ( isset( $files['attach'] ) && is_array( $files['attach'] ) ? $files['attach'] : array() as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				$attachments[] = $file;
			}
		}

		if ( ! empty( $files['skipped'] ) && is_array( $files['skipped'] ) ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: list of file names. */
				__( 'Nepřiloženo, na e-mail příliš velké: %s', 'raynet-lead-api-integration' ),
				implode( ', ', array_map( 'strval', $files['skipped'] ) )
			);
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Neodeslaný lead z webového formuláře', 'raynet-lead-api-integration' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		if ( wp_mail( $to, $subject, implode( "\n", $lines ), '', $attachments ) || empty( $attachments ) ) {
			return;
		}

		// The lead's data matters more than its files: if the server refused
		// the message with them, it goes again without.
		$lines[] = '';
		$lines[] = __( 'Přílohy se k e-mailu nepodařilo připojit.', 'raynet-lead-api-integration' );

		if ( ! wp_mail( $to, $subject, implode( "\n", $lines ) ) ) {
			$this->log_error( 'Fallback e-mail could not be sent.' );
		}
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
