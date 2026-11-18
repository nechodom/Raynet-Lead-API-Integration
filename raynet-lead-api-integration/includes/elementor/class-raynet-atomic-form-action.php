<?php
/**
 * Atomic form action for Elementor Pro: send the submission to RAYNET CRM.
 *
 * Loaded only from the `elementor_pro/atomic_forms/actions/register` callback,
 * so without Elementor Pro's atomic form module this file is never read and
 * `extends` cannot fail.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

use ElementorPro\Modules\AtomicForm\Actions\Action_Base;

/**
 * Sends an Elementor 4 atomic form submission to RAYNET as a Lead.
 *
 * Runs inside admin-ajax.php for anonymous visitors. Elementor Pro returns
 * every action's result to the browser as it is, the message of a failure
 * included (Atomic_Form_Controller::send_response()), so a failure carries
 * only the wording visitors are meant to see. The real reason goes to the
 * plugin's log and its "last API error".
 */
class Raynet_Atomic_Form_Action extends Action_Base {

	/**
	 * Slug in the registry and in `actions-after-submit`.
	 *
	 * @return string Slug.
	 */
	public function get_type(): string {
		return Raynet_Atomic_Form_Integration::ACTION_TYPE;
	}

	/**
	 * Sends the submission to RAYNET.
	 *
	 * The same steps as Raynet_Elementor_Form_Action::run(), without the
	 * attachments: field types and labels from the stored form, the mapping
	 * and note from the plugin's settings for this form, then submit_lead(),
	 * which sends the fallback e-mail when RAYNET refuses.
	 *
	 * A failure is thrown, not returned. Elementor Pro's runner counts only a
	 * thrown Exception as a failed action; a returned `failed` result still
	 * gives the visitor the form's success state. The exception carries the
	 * public wording only, because its message reaches the browser.
	 *
	 * @param array<string,mixed> $form_data       Field id => sanitized value.
	 * @param array<string,mixed> $widget_settings Resolved settings of the form element.
	 * @param array<string,mixed> $context         post_id, form_id and more.
	 * @return array<string,mixed> Result for Elementor Pro's runner.
	 * @throws \Exception When the lead was not created; the message is the public wording.
	 */
	public function execute( array $form_data, array $widget_settings, array $context ): array {
		$public  = $this->public_message( array() );
		$lead_id = null;

		try {
			$public  = $this->public_message( Raynet_Lead_Settings::all() );
			$lead_id = $this->send( $form_data, $context );
		} catch ( \Throwable $e ) {
			// Not rethrown: the runner catches Exception only, so anything else
			// would end the request with a bare 500, and the message of either
			// would reach the browser.
			( new Raynet_Lead_Form() )->report_error( $e->getMessage() );
		}

		if ( null === $lead_id ) {
			throw new \Exception( $public ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned as JSON, never printed as HTML.
		}

		return $this->success( 'Lead created', array( 'leadId' => $lead_id ) );
	}

	/**
	 * Builds the lead and submits it.
	 *
	 * @param array<string,mixed> $form_data Field id => sanitized value.
	 * @param array<string,mixed> $context   Elementor Pro's context.
	 * @return int|null Lead id, or null when no lead was created; the reason
	 *                  is then in the plugin's log and last API error.
	 */
	private function send( array $form_data, array $context ) {
		$post_id = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;
		$form_id = isset( $context['form_id'] ) && is_scalar( $context['form_id'] ) ? (string) $context['form_id'] : '';
		$element = Raynet_Atomic_Form_Integration::form_element( $post_id, $form_id );
		$form    = new Raynet_Lead_Form();

		if ( null === $element ) {
			$form->report_error(
				sprintf(
					/* translators: 1: form element id, 2: post id. */
					__( 'Atomový formulář %1$s na stránce %2$d nebyl nalezen; lead nebyl odeslán.', 'raynet-lead-api-integration' ),
					$form_id,
					$post_id
				)
			);

			return null;
		}

		$form_settings = Raynet_Elementor_Atomic_Forms::settings( $post_id, $element );
		$fields        = Raynet_Atomic_Form_Integration::submitted_fields( $form_settings['form_fields'], $form_data, Raynet_Elementor_Atomic_Forms::radio_groups( $element ) );

		$mapped           = Raynet_Elementor_Lead_Builder::mapped_values( $fields, $form_settings );
		$mapped['extras'] = Raynet_Elementor_Lead_Builder::merge_lines( Raynet_Elementor_Lead_Builder::noted_values( $fields, $form_settings ), $mapped['extras'] );
		$mapped['extras'] = Raynet_Elementor_Lead_Builder::merge_lines( $mapped['extras'], Raynet_Atomic_Form_Integration::upload_lines( $fields, $form_settings ) );

		$values = $form->collect_values( $mapped['basic'] );
		$error  = Raynet_Elementor_Lead_Builder::contact_error( $values );

		if ( '' !== $error ) {
			// The classic widget shows this to administrators only; here that
			// channel would reach the visitor's browser.
			$form->report_error( $error );

			return null;
		}

		$settings = $form->merge_lead_settings(
			Raynet_Lead_Settings::all(),
			Raynet_Elementor_Lead_Builder::lead_settings( $form_settings )
		);

		$lead_context = Raynet_Elementor_Lead_Builder::context( $mapped, $fields, $form_settings );

		$lead_context['raynet_source_url'] = $this->source_url( $form_settings, $post_id, $context );
		// No files travel to RAYNET or into the fallback e-mail.
		$lead_context['raynet_files'] = array();

		$result = $form->submit_lead( $values, $settings, $lead_context );

		// On an error submit_lead() has logged the reason, kept it as the last
		// API error (when RAYNET was asked) and sent the fallback e-mail.
		if ( is_wp_error( $result ) ) {
			return null;
		}

		return isset( $result['lead_id'] ) ? (int) $result['lead_id'] : 0;
	}

	/**
	 * What the visitor may be told when the lead was not created.
	 *
	 * The plugin-wide wording; a form's own lead settings do not change it.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string Message.
	 */
	private function public_message( array $settings ) {
		return isset( $settings['error_message'] ) && '' !== trim( (string) $settings['error_message'] )
			? (string) $settings['error_message']
			: __( 'Odeslání formuláře se nezdařilo. Zkuste to prosím znovu později.', 'raynet-lead-api-integration' );
	}

	/**
	 * URL of the page the form was submitted from.
	 *
	 * The document holding the form, when it is a public page of its own. A
	 * form in a template (header, footer, popup) reports the template, so the
	 * page shown travels only as the browser's location; like the classic
	 * widget's referrer, that is taken only when it points at this site.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param int                 $post_id       Document holding the form.
	 * @param array<string,mixed> $context       Elementor Pro's context.
	 * @return string URL, or an empty string.
	 */
	private function source_url( array $form_settings, $post_id, array $context ) {
		// On unless switched off, as in the classic widget: its control
		// defaults to yes, and the plugin never stores the switch for an
		// atomic form it enables.
		if ( isset( $form_settings['raynet_crm_source_url'] ) && 'yes' !== (string) $form_settings['raynet_crm_source_url'] ) {
			return '';
		}

		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) && Raynet_Elementor_Forms::LIBRARY_TYPE !== get_post_type( $post_id ) && is_post_type_viewable( get_post_type( $post_id ) ) ) {
			$url = get_permalink( $post_id );

			if ( $url ) {
				return $url;
			}
		}

		$referrer = isset( $context['referrer'] ) && is_string( $context['referrer'] ) ? $context['referrer'] : '';

		return '' !== $referrer ? (string) wp_validate_redirect( $referrer, '' ) : '';
	}
}
