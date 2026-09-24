<?php
/**
 * Submit action for Elementor Pro Forms: send the submission to RAYNET CRM.
 *
 * Loaded only from the `elementor_pro/forms/actions/register` callback, so
 * without Elementor Pro this file is never read and `extends` cannot fail.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use ElementorPro\Modules\Forms\Classes\Action_Base;

/**
 * Sends an Elementor form submission to RAYNET as a Lead.
 */
class Raynet_Elementor_Form_Action extends Action_Base {

	/**
	 * Key in the registry and value inside `submit_actions`.
	 *
	 * Elementor compares it strictly, so it has to match character for
	 * character. The scanner owns the value because it loads without Elementor.
	 */
	const ACTION_NAME = Raynet_Elementor_Forms::ACTION_NAME;

	/**
	 * Action name, as stored on the form.
	 *
	 * @return string Name.
	 */
	public function get_name() {
		return self::ACTION_NAME;
	}

	/**
	 * Label shown in "Actions After Submit".
	 *
	 * @return string Label.
	 */
	public function get_label() {
		return esc_html__( 'RAYNET CRM', 'raynet-lead-api-integration' );
	}

	/**
	 * RAYNET attributes offered in the field mapping.
	 *
	 * The basic attributes, the rest of RAYNET's standard lead attributes and
	 * the instance's own custom fields, in that order — the same list the
	 * bulk-apply screen maps against.
	 *
	 * Every row is `remote_type` text on purpose. Elementor's editor filters the
	 * local dropdown by type, so declaring `email` would offer only Elementor
	 * Email fields and leave the dropdown mysteriously empty on a form that uses
	 * a plain Text field for the address. The value is validated server side
	 * anyway.
	 *
	 * @return array<int,array<string,string>> Rows for the Fields_Map control.
	 */
	public static function remote_fields() {
		$rows = array();

		foreach ( Raynet_Lead_Fields::mapping_rows() as $row ) {
			$rows[] = array(
				'remote_id'    => $row['id'],
				'remote_label' => $row['label'],
				'remote_type'  => 'text',
			);
		}

		return $rows;
	}

	/**
	 * Registers the field mapping control.
	 *
	 * Integration_Base::register_fields_map_control() cannot be used here. It
	 * declares only `remote_id` and `local_id` on the repeater, and Elementor
	 * drops any key of a static PHP default that has no matching control. The
	 * editor view then reads `remote_type` as undefined and its filter
	 *
	 *     if ( 'text' !== remoteType && remoteType !== model.get('field_type') )
	 *
	 * skips every field, leaving each row titled "Item #1" over an empty list.
	 * Elementor's own integrations avoid this by pushing the rows in from
	 * JavaScript, which we have no reason to do for a fixed list of ten.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget    Form widget.
	 * @param array<string,string>                      $condition Section condition.
	 * @return void
	 */
	private function register_mapping_control( $widget, array $condition ) {
		$repeater = new Repeater();

		$repeater->add_control( 'remote_id', array( 'type' => Controls_Manager::HIDDEN ) );
		$repeater->add_control( 'remote_label', array( 'type' => Controls_Manager::HIDDEN ) );
		$repeater->add_control( 'remote_type', array( 'type' => Controls_Manager::HIDDEN ) );
		$repeater->add_control( 'local_id', array( 'type' => Controls_Manager::SELECT ) );

		$widget->add_control(
			$this->get_name() . '_fields_map',
			array(
				'label'       => esc_html__( 'Mapování polí', 'raynet-lead-api-integration' ),
				'type'        => self::mapping_control_type(),
				'separator'   => 'before',
				'fields'      => $repeater->get_controls(),
				'default'     => self::remote_fields(),
				'description' => esc_html__( 'Přiřaďte pole formuláře k atributům leadu. Nenamapovaná pole se neodesílají.', 'raynet-lead-api-integration' ),
				'condition'   => $condition,
			)
		);
	}

	/**
	 * Control type of Elementor's field mapping widget.
	 *
	 * @return string Control type.
	 */
	private static function mapping_control_type() {
		return class_exists( '\\ElementorPro\\Modules\\Forms\\Controls\\Fields_Map' )
			? \ElementorPro\Modules\Forms\Controls\Fields_Map::CONTROL_TYPE
			: 'fields_map';
	}

	/**
	 * Adds the action's own controls to the form widget.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget Form widget.
	 * @return void
	 */
	public function register_settings_section( $widget ) {
		$condition = array( 'submit_actions' => $this->get_name() );
		$inherit   = esc_html__( 'Zdědit z nastavení pluginu', 'raynet-lead-api-integration' );

		$widget->start_controls_section(
			'section_raynet_crm',
			array(
				'label'     => esc_html__( 'RAYNET CRM', 'raynet-lead-api-integration' ),
				'condition' => $condition,
			)
		);

		$this->register_mapping_control( $widget, $condition );

		$widget->add_control(
			'raynet_crm_heading_lead',
			array(
				'label'     => esc_html__( 'Nastavení leadu', 'raynet-lead-api-integration' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_topic',
			array(
				'label'       => esc_html__( 'Předmět leadu', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => esc_html__( 'Prázdné = předmět z nastavení pluginu. Namapované pole Předmět má přednost.', 'raynet-lead-api-integration' ),
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_priority',
			array(
				'label'       => esc_html__( 'Priorita', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''         => $inherit,
					'MINOR'    => esc_html__( 'Nízká', 'raynet-lead-api-integration' ),
					'DEFAULT'  => esc_html__( 'Běžná', 'raynet-lead-api-integration' ),
					'CRITICAL' => esc_html__( 'Kritická', 'raynet-lead-api-integration' ),
				),
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_lead_person',
			array(
				'label'       => esc_html__( 'Typ leadu', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''  => $inherit,
					'1' => esc_html__( 'Fyzická osoba', 'raynet-lead-api-integration' ),
					'0' => esc_html__( 'Firma', 'raynet-lead-api-integration' ),
				),
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_notice_prefix',
			array(
				'label'       => esc_html__( 'Předpona poznámky', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_consent_note',
			array(
				'label'        => esc_html__( 'Zapsat udělení souhlasu', 'raynet-lead-api-integration' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'description'  => esc_html__( 'Zapněte jen tehdy, když formulář obsahuje pole se souhlasem. Do poznámky leadu se pak zapíše datum a čas.', 'raynet-lead-api-integration' ),
				'render_type'  => 'none',
				'condition'    => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_source_url',
			array(
				'label'       => esc_html__( 'Uvést URL stránky', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_heading_ids',
			array(
				'label'     => esc_html__( 'Číselníky RAYNET', 'raynet-lead-api-integration' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $condition,
			)
		);

		$numeric = array(
			'raynet_crm_category'       => esc_html__( 'Kategorie (ID)', 'raynet-lead-api-integration' ),
			'raynet_crm_lead_phase'     => esc_html__( 'Stav leadu (ID)', 'raynet-lead-api-integration' ),
			'raynet_crm_contact_source' => esc_html__( 'Zdroj kontaktu (ID)', 'raynet-lead-api-integration' ),
			'raynet_crm_owner'          => esc_html__( 'Vlastník (ID)', 'raynet-lead-api-integration' ),
			'raynet_crm_security_level' => esc_html__( 'Bezpečnostní úroveň (ID)', 'raynet-lead-api-integration' ),
		);

		foreach ( $numeric as $control => $label ) {
			$widget->add_control(
				$control,
				array(
					'label'       => $label,
					'type'        => Controls_Manager::NUMBER,
					'min'         => 0,
					'default'     => '',
					'description' => esc_html__( 'Prázdné = zdědit z nastavení pluginu.', 'raynet-lead-api-integration' ),
					'render_type' => 'none',
					'condition'   => $condition,
				)
			);
		}

		$widget->add_control(
			'raynet_crm_tags',
			array(
				'label'       => esc_html__( 'Štítky', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 'web,poptavka',
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->add_control(
			'raynet_crm_notify_emails',
			array(
				'label'       => esc_html__( 'Notifikační e-maily', 'raynet-lead-api-integration' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'render_type' => 'none',
				'condition'   => $condition,
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Sends the submission to RAYNET.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Submission.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Handler.
	 * @return void
	 * @throws \Exception When RAYNET refuses the lead.
	 */
	public function run( $record, $ajax_handler ) {
		try {
			$form_settings = (array) $record->get( 'form_settings' );
			$mapped        = $this->mapped_values( $record, $form_settings );

			$form   = new Raynet_Lead_Form();
			$values = $form->collect_values( $mapped['basic'] );

			// RAYNET's own rule, which Elementor knows nothing about.
			if ( '' === $values['email'] && '' === $values['phone'] ) {
				throw new \Exception( esc_html__( 'Lead neobsahuje e-mail ani telefon.', 'raynet-lead-api-integration' ) );
			}

			if ( '' !== $values['email'] && ! is_email( $values['email'] ) ) {
				throw new \Exception( esc_html__( 'Zadaná e-mailová adresa není platná.', 'raynet-lead-api-integration' ) );
			}

			$settings = $form->merge_lead_settings(
				Raynet_Lead_Settings::all(),
				$this->lead_settings( $form_settings )
			);

			$result = $form->submit_lead(
				$values,
				$settings,
				array(
					'raynet_fixed_topic'   => isset( $form_settings['raynet_crm_topic'] ) ? $form_settings['raynet_crm_topic'] : '',
					'raynet_source_url'    => $this->source_url( $form_settings ),
					'raynet_has_consent'   => 'yes' === $this->setting( $form_settings, 'raynet_crm_consent_note' ),
					'raynet_attributes'    => $mapped['attributes'],
					'raynet_custom_fields' => $mapped['custom'],
					'raynet_extras'        => $mapped['extras'],
				)
			);

			if ( is_wp_error( $result ) ) {
				$data       = $result->get_error_data();
				$diagnostic = is_array( $data ) && isset( $data['diagnostic'] )
					? (string) $data['diagnostic']
					: $result->get_error_message();

				throw new \Exception( esc_html( $diagnostic ) );
			}

			if ( ! empty( $result['lead_id'] ) ) {
				$ajax_handler->add_response_data( 'raynet_lead_id', (int) $result['lead_id'] );
			}
		} catch ( \Exception $e ) {
			// Elementor turns this into an admin-only notice and shows the
			// visitor the form's own error message.
			throw $e;
		} catch ( \Throwable $e ) {
			// Ajax_Handler catches Exception only; a TypeError would take the
			// whole request down with a bare 500.
			throw new \Exception( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Reads the submitted fields and sorts them by where they go in RAYNET.
	 *
	 * Basic attributes go through the same collection as the shortcode form.
	 * Extended and custom attributes are cleaned here, against their type; a
	 * value that does not fit — a date nobody can read, an option the
	 * enumeration does not have — lands in the lead note instead of making
	 * RAYNET refuse the whole lead.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record        Submission.
	 * @param array<string,mixed>                            $form_settings Form settings.
	 * @return array{basic:array<string,string>,attributes:array<string,mixed>,custom:array<string,mixed>,extras:array<string,string>} Values.
	 */
	private function mapped_values( $record, array $form_settings ) {
		$fields = array();
		$types  = array();

		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';

			// `value` is the sanitized string. `raw_value` holds server paths for
			// upload fields, which must never reach the CRM. A Number field is the
			// exception the other way: Elementor runs its value through intval(),
			// so an empty box arrives as 0, "02795281" as 2795281 and 1499.90 as
			// 1499. What the visitor typed is in `raw_value`.
			if ( 'number' === $type && isset( $field['raw_value'] ) && is_scalar( $field['raw_value'] ) ) {
				$fields[ $id ] = sanitize_text_field( (string) $field['raw_value'] );
			} else {
				$fields[ $id ] = isset( $field['value'] ) ? $field['value'] : '';
			}

			$types[ $id ] = $type;
		}

		$map    = isset( $form_settings[ self::ACTION_NAME . '_fields_map' ] )
			? (array) $form_settings[ self::ACTION_NAME . '_fields_map' ]
			: array();
		$result = array(
			'basic'      => array(),
			'attributes' => array(),
			'custom'     => array(),
			'extras'     => array(),
		);

		$extended = Raynet_Lead_Fields::extended();

		foreach ( $map as $row ) {
			$remote = isset( $row['remote_id'] ) ? (string) $row['remote_id'] : '';
			$local  = isset( $row['local_id'] ) ? (string) $row['local_id'] : '';

			if ( '' === $remote || '' === $local || ! array_key_exists( $local, $fields ) ) {
				continue;
			}

			if ( Raynet_Lead_Form_Definition::is_lead_source( $remote ) ) {
				$result['basic'][ $remote ] = $fields[ $local ];
				continue;
			}

			if ( ! Raynet_Lead_Fields::is_extended( $remote ) && ! Raynet_Lead_Fields::is_custom_id( $remote ) ) {
				continue;
			}

			$clean = Raynet_Lead_Fields::coerce( $remote, $fields[ $local ], isset( $types[ $local ] ) ? $types[ $local ] : '' );

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
	 * Collects the per-form lead settings in the shape merge_lead_settings expects.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array<string,mixed> Lead settings.
	 */
	private function lead_settings( array $form_settings ) {
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
			$lead[ $key ] = $this->setting( $form_settings, $control );
		}

		$numeric = array(
			'raynet_crm_category'       => 'category',
			'raynet_crm_lead_phase'     => 'lead_phase',
			'raynet_crm_contact_source' => 'contact_source',
			'raynet_crm_owner'          => 'owner',
			'raynet_crm_security_level' => 'security_level',
		);

		foreach ( $numeric as $control => $key ) {
			$lead[ $key ] = max( 0, (int) $this->setting( $form_settings, $control ) );
		}

		return Raynet_Lead_Form_Definition::sanitize_lead_settings( $lead );
	}

	/**
	 * URL of the page the form was submitted from.
	 *
	 * Derived from the post id Elementor injects, not from the referrer, which
	 * the sender controls.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return string URL, or an empty string.
	 */
	private function source_url( array $form_settings ) {
		if ( 'yes' !== $this->setting( $form_settings, 'raynet_crm_source_url' ) ) {
			return '';
		}

		// A form in a popup, header, footer or global widget reports the
		// template as its post; the page it was shown on travels as queried_id.
		// Only an existing, public, published post is taken from it, so the
		// value can name a page but not make one up.
		$queried = isset( $_POST['queried_id'] ) ? absint( wp_unslash( $_POST['queried_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Elementor has validated the submission.

		foreach ( array( $queried, isset( $form_settings['form_post_id'] ) ? absint( $form_settings['form_post_id'] ) : 0 ) as $post_id ) {
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || Raynet_Elementor_Forms::LIBRARY_TYPE === get_post_type( $post_id ) || ! is_post_type_viewable( get_post_type( $post_id ) ) ) {
				continue;
			}

			$url = get_permalink( $post_id );

			if ( $url ) {
				return $url;
			}
		}

		return (string) wp_get_referer();
	}

	/**
	 * Reads one form setting as a string.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param string              $key           Control id.
	 * @return string Value.
	 */
	private function setting( array $form_settings, $key ) {
		return isset( $form_settings[ $key ] ) ? (string) $form_settings[ $key ] : '';
	}

	/**
	 * Strips this action's settings when a form is exported as a template.
	 *
	 * The values live under `settings`; Elementor's own integrations unset them
	 * there, and the official example that unsets them at the top level removes
	 * nothing.
	 *
	 * Code list ids and the owner belong to one RAYNET instance, so carrying
	 * them to another site would file leads under a stranger's category. The
	 * field mapping points at field ids the target form may not share.
	 *
	 * @param array<string,mixed> $element Element data.
	 * @return array<string,mixed> Element without this action's settings.
	 */
	public function on_export( $element ) {
		foreach ( array(
			self::ACTION_NAME . '_fields_map',
			'raynet_crm_topic',
			'raynet_crm_notice_prefix',
			'raynet_crm_tags',
			'raynet_crm_notify_emails',
			'raynet_crm_category',
			'raynet_crm_lead_phase',
			'raynet_crm_contact_source',
			'raynet_crm_owner',
			'raynet_crm_security_level',
		) as $control ) {
			unset( $element['settings'][ $control ] );
		}

		return $element;
	}
}
