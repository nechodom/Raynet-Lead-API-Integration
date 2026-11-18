<?php
/**
 * Turns the fields of an Elementor form submission into a RAYNET lead.
 *
 * Kept apart from the submit action, which extends an Elementor Pro class and
 * so cannot load without it, so that a stored submission can be built into a
 * lead by exactly the same rules as a live one.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds lead values and context from submitted Elementor fields.
 */
class Raynet_Elementor_Lead_Builder {

	/**
	 * Reads the submitted fields and sorts them by where they go in RAYNET.
	 *
	 * Basic attributes go through the same collection as the shortcode form.
	 * Extended and custom attributes are cleaned here, against their type; a
	 * value that does not fit — a date nobody can read, an option the
	 * enumeration does not have — lands in the lead note instead of making
	 * RAYNET refuse the whole lead.
	 *
	 * @param array<string,array<string,mixed>> $fields        Submitted fields, keyed by field id.
	 * @param array<string,mixed>               $form_settings Form settings.
	 * @return array{basic:array<string,string>,attributes:array<string,mixed>,custom:array<string,mixed>,extras:array<string,string>,consent:bool|null,consent_text:string,message_label:string} Values.
	 */
	public static function mapped_values( array $fields, array $form_settings ) {
		$values = array();
		$types  = array();

		foreach ( $fields as $id => $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			// A password never leaves the site, whatever the mapping says.
			if ( 'password' === $type ) {
				continue;
			}

			// `value` is the sanitized string. `raw_value` holds server paths for
			// upload fields, which must never reach the CRM. A Number field is the
			// exception the other way: Elementor runs its value through intval(),
			// so an empty box arrives as 0, "02795281" as 2795281 and 1499.90 as
			// 1499. What the visitor typed is in `raw_value`.
			if ( 'number' === $type && isset( $field['raw_value'] ) && is_scalar( $field['raw_value'] ) ) {
				$values[ $id ] = sanitize_text_field( (string) $field['raw_value'] );
			} else {
				$values[ $id ] = isset( $field['value'] ) ? $field['value'] : '';
			}

			$types[ $id ] = $type;
		}

		$map    = isset( $form_settings[ Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' ] )
			? (array) $form_settings[ Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' ]
			: array();
		$result = array(
			'basic'         => array(),
			'attributes'    => array(),
			'custom'        => array(),
			'extras'        => array(),
			'consent'       => null,
			'consent_text'  => '',
			'message_label' => '',
		);
		$titles = array();

		foreach ( $fields as $id => $field ) {
			$titles[ $id ] = isset( $field['title'] ) ? sanitize_text_field( (string) $field['title'] ) : '';
		}

		// The wording a visitor agrees to is the checkbox's text, not its
		// label, which is often a short internal name such as "gdpr".
		foreach ( (array) ( isset( $form_settings['form_fields'] ) ? $form_settings['form_fields'] : array() ) as $definition ) {
			if ( is_array( $definition ) && ! empty( $definition['custom_id'] ) && ! empty( $definition['acceptance_text'] ) ) {
				$text = trim( sanitize_text_field( wp_strip_all_tags( (string) $definition['acceptance_text'] ) ) );

				if ( '' !== $text ) {
					$titles[ (string) $definition['custom_id'] ] = $text;
				}
			}
		}

		$extended = Raynet_Lead_Fields::extended();

		foreach ( $map as $row ) {
			$remote = isset( $row['remote_id'] ) ? (string) $row['remote_id'] : '';
			$local  = isset( $row['local_id'] ) ? (string) $row['local_id'] : '';

			if ( '' === $remote || '' === $local || ! array_key_exists( $local, $values ) ) {
				continue;
			}

			if ( Raynet_Lead_Form_Definition::is_lead_source( $remote ) ) {
				$result['basic'][ $remote ] = $values[ $local ];

				// The note says which field the message was typed into.
				if ( 'message' === $remote && isset( $titles[ $local ] ) ) {
					$result['message_label'] = $titles[ $local ];
				}

				continue;
			}

			if ( ! Raynet_Lead_Fields::is_extended( $remote ) && ! Raynet_Lead_Fields::is_custom_id( $remote ) ) {
				continue;
			}

			$clean = Raynet_Lead_Fields::coerce( $remote, $values[ $local ], isset( $types[ $local ] ) ? $types[ $local ] : '' );

			// Consent is not a lead attribute: it decides whether the note and a
			// GDPR record say that consent was given.
			if ( 'gdprConsent' === $remote ) {
				$result['consent']      = true === $clean['value'];
				$result['consent_text'] = isset( $titles[ $local ] ) ? $titles[ $local ] : '';

				if ( ! empty( $clean['note'] ) && '' !== $clean['raw'] ) {
					$result['extras'][ $clean['label'] ] = mb_substr( sanitize_textarea_field( $clean['raw'] ), 0, 1000 );
				}

				continue;
			}

			if ( 'invalid' === $clean['status'] ) {
				$result['extras'][ $clean['label'] ] = mb_substr( sanitize_textarea_field( $clean['raw'] ), 0, 1000 );
				continue;
			}

			if ( 'ok' !== $clean['status'] ) {
				continue;
			}

			// Sent, but the answer itself was unclear: a person should see it.
			if ( ! empty( $clean['note'] ) && '' !== $clean['raw'] ) {
				$result['extras'][ $clean['label'] ] = mb_substr( sanitize_textarea_field( $clean['raw'] ), 0, 1000 );
			}

			if ( Raynet_Lead_Fields::is_extended( $remote ) ) {
				$result['attributes'][ $extended[ $remote ]['path'] ] = $clean['value'];
			} else {
				$result['custom'][ Raynet_Lead_Fields::custom_name( $remote ) ] = $clean['value'];
			}
		}

		return $result;
	}

	/**
	 * Values of the fields chosen for the lead note, keyed by their labels.
	 *
	 * @param array<string,array<string,mixed>> $fields        Submitted fields, keyed by field id.
	 * @param array<string,mixed>               $form_settings Form settings.
	 * @return array<string,string> Label => value.
	 */
	public static function noted_values( array $fields, array $form_settings ) {
		$chosen = Raynet_Elementor_Forms::id_list( $form_settings, Raynet_Elementor_Forms::NOTICE_KEY );
		$lines  = array();

		if ( empty( $chosen ) ) {
			return $lines;
		}

		// A field the mapping already sends is not repeated in the note; the
		// mapping wins, as it does on the mapping screen.
		$chosen = array_values( array_diff( $chosen, array_values( Raynet_Elementor_Forms::current_map( $form_settings ) ) ) );

		foreach ( $fields as $id => $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( ! in_array( (string) $id, $chosen, true ) || in_array( $type, Raynet_Elementor_Forms::UNSENDABLE_TYPES, true ) ) {
				continue;
			}

			// Same rule as the mapping: a Number field's real input is raw_value.
			$value = 'number' === $type && isset( $field['raw_value'] ) && is_scalar( $field['raw_value'] )
				? (string) $field['raw_value']
				: ( isset( $field['value'] ) ? (string) $field['value'] : '' );
			$value = mb_substr( sanitize_textarea_field( $value ), 0, 1000 );

			if ( '' === trim( $value ) ) {
				continue;
			}

			$label = isset( $field['title'] ) && '' !== trim( (string) $field['title'] ) ? sanitize_text_field( (string) $field['title'] ) : (string) $id;

			// Two fields sharing a label would overwrite each other.
			$base = $label;

			for ( $n = 2; isset( $lines[ $label ] ); $n++ ) {
				$label = $base . ' (' . $n . ')';
			}

			$lines[ $label ] = $value;
		}

		return $lines;
	}

	/**
	 * Consent claimed by the older per-form switch.
	 *
	 * The switch says "this form has a consent box". If the submission does
	 * carry a checkbox of Elementor's acceptance type, at least one of them
	 * must be ticked for the claim to hold.
	 *
	 * @param array<string,array<string,mixed>> $fields        Submitted fields, keyed by field id.
	 * @param array<string,mixed>               $form_settings Form settings.
	 * @return bool True when consent may be claimed.
	 */
	public static function switch_consent( array $fields, array $form_settings ) {
		if ( 'yes' !== self::setting( $form_settings, 'raynet_crm_consent_note' ) ) {
			return false;
		}

		$boxes  = 0;
		$ticked = 0;

		foreach ( $fields as $field ) {
			if ( isset( $field['type'] ) && 'acceptance' === $field['type'] ) {
				$boxes++;

				if ( isset( $field['value'] ) && '' !== trim( (string) $field['value'] ) ) {
					$ticked++;
				}
			}
		}

		return 0 === $boxes || $ticked > 0;
	}

	/**
	 * Joins two label => value lists for the note.
	 *
	 * Keys stay strings — array_merge() would renumber a label such as "2024" —
	 * and a label already taken gets a number instead of overwriting.
	 *
	 * @param array<string,string> $first  Lines that come first.
	 * @param array<string,string> $second Lines appended after them.
	 * @return array<string,string> Joined lines.
	 */
	public static function merge_lines( array $first, array $second ) {
		$lines = $first;

		foreach ( $second as $label => $value ) {
			$label = (string) $label;
			$base  = $label;

			for ( $n = 2; array_key_exists( $label, $lines ); $n++ ) {
				$label = $base . ' (' . $n . ')';
			}

			$lines[ $label ] = $value;
		}

		return $lines;
	}

	/**
	 * Collects the per-form lead settings in the shape merge_lead_settings expects.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array<string,mixed> Lead settings.
	 */
	public static function lead_settings( array $form_settings ) {
		$lead = Raynet_Lead_Form_Definition::lead_defaults();

		$text = array(
			'raynet_crm_topic'         => 'topic',
			'raynet_crm_priority'      => 'priority',
			'raynet_crm_lead_person'   => 'lead_person',
			'raynet_crm_notice_prefix' => 'notice_prefix',
			'raynet_crm_tags'          => 'tags',
			'raynet_crm_notify_emails' => 'notify_emails',
		);

		foreach ( $text as $control => $key ) {
			$lead[ $key ] = self::setting( $form_settings, $control );
		}

		$numeric = array(
			'raynet_crm_category'       => 'category',
			'raynet_crm_lead_phase'     => 'lead_phase',
			'raynet_crm_contact_source' => 'contact_source',
			'raynet_crm_owner'          => 'owner',
			'raynet_crm_security_level' => 'security_level',
		);

		foreach ( $numeric as $control => $key ) {
			$lead[ $key ] = max( 0, (int) self::setting( $form_settings, $control ) );
		}

		return Raynet_Lead_Form_Definition::sanitize_lead_settings( $lead );
	}

	/**
	 * Why RAYNET would refuse the contact details, before asking it.
	 *
	 * RAYNET's own rule, which Elementor knows nothing about.
	 *
	 * @param array<string,string> $values Collected basic values, with `email` and `phone`.
	 * @return string Error message, or an empty string when the contact will do.
	 */
	public static function contact_error( array $values ) {
		$email = isset( $values['email'] ) ? (string) $values['email'] : '';
		$phone = isset( $values['phone'] ) ? (string) $values['phone'] : '';

		if ( '' === $email && '' === $phone ) {
			return __( 'Lead neobsahuje e-mail ani telefon.', 'raynet-lead-api-integration' );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return __( 'Zadaná e-mailová adresa není platná.', 'raynet-lead-api-integration' );
		}

		return '';
	}

	/**
	 * Submission context for Raynet_Lead_Form::submit_lead().
	 *
	 * The source URL and the copies of uploaded files depend on the request
	 * being handled, so the caller adds `raynet_source_url` and `raynet_files`.
	 *
	 * @param array<string,mixed>               $mapped        Result of mapped_values(), extras already merged.
	 * @param array<string,array<string,mixed>> $fields        Submitted fields, keyed by field id.
	 * @param array<string,mixed>               $form_settings Form settings.
	 * @return array<string,mixed> Context.
	 */
	public static function context( array $mapped, array $fields, array $form_settings ) {
		return array(
			'raynet_fixed_topic'    => isset( $form_settings['raynet_crm_topic'] ) ? $form_settings['raynet_crm_topic'] : '',
			// A mapped consent field decides by being ticked or not; without
			// one, the older per-form switch still does.
			'raynet_has_consent'    => null !== $mapped['consent'] ? $mapped['consent'] : self::switch_consent( $fields, $form_settings ),
			'raynet_consent_text'   => $mapped['consent_text'],
			// A formal GDPR record needs proof: a mapped box that was ticked.
			// The switch only ever claims consent in the note.
			'raynet_consent_record' => true === $mapped['consent'],
			'raynet_message_label'  => $mapped['message_label'],
			'raynet_attributes'     => $mapped['attributes'],
			'raynet_custom_fields'  => $mapped['custom'],
			'raynet_extras'         => $mapped['extras'],
		);
	}

	/**
	 * Reads one form setting as a string.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param string              $key           Control id.
	 * @return string Value.
	 */
	private static function setting( array $form_settings, $key ) {
		return isset( $form_settings[ $key ] ) ? (string) $form_settings[ $key ] : '';
	}
}
