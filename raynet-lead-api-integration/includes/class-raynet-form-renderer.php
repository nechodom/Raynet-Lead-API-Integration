<?php
/**
 * Turns field definitions into form markup.
 *
 * The shortcode and the builder's preview both call this, so what an admin sees
 * while editing is produced by the same code a visitor gets.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field renderer.
 */
class Raynet_Lead_Form_Renderer {

	/**
	 * Renders every field of a form.
	 *
	 * @param array<int,array<string,mixed>> $fields   Sanitized field definitions.
	 * @param string                         $form_uid Unique prefix for element ids.
	 * @return string Markup.
	 */
	public static function render_fields( array $fields, $form_uid ) {
		$html = '';

		foreach ( $fields as $field ) {
			$html .= self::render_one( $field, $form_uid );
		}

		return $html;
	}

	/**
	 * Renders one field.
	 *
	 * @param array<string,mixed> $field    Sanitized field definition.
	 * @param string              $form_uid Unique prefix for element ids.
	 * @return string Markup.
	 */
	private static function render_one( array $field, $form_uid ) {
		$element_id = $form_uid . '-' . $field['id'];
		$name       = self::input_name( $field );

		if ( 'consent' === $field['type'] ) {
			return self::render_consent( $field, $element_id, $name );
		}

		$classes = 'raynet-lead-form__row raynet-lead-form__row--' . $field['source'];

		if ( 'half' === $field['width'] ) {
			$classes .= ' raynet-lead-form__row--half';
		}

		$html  = '<div class="' . esc_attr( $classes ) . '">';
		$html .= '<label class="raynet-lead-form__label" for="' . esc_attr( $element_id ) . '">';
		$html .= esc_html( $field['label'] );

		if ( $field['required'] ) {
			$html .= ' <span class="raynet-lead-form__required" aria-hidden="true">*</span>';
		}

		$html .= '</label>';
		$html .= self::render_control( $field, $element_id, $name );

		if ( '' !== trim( (string) $field['help'] ) ) {
			$html .= '<p class="raynet-lead-form__help" id="' . esc_attr( $element_id ) . '-help">'
				. esc_html( $field['help'] ) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders the input, textarea, select or checkbox of a field.
	 *
	 * @param array<string,mixed> $field      Field definition.
	 * @param string              $element_id Element id.
	 * @param string              $name       Input name.
	 * @return string Markup.
	 */
	private static function render_control( array $field, $element_id, $name ) {
		$required    = $field['required'] ? ' required' : '';
		$described   = '' !== trim( (string) $field['help'] )
			? ' aria-describedby="' . esc_attr( $element_id ) . '-help"'
			: '';
		$placeholder = '' !== trim( (string) $field['placeholder'] )
			? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"'
			: '';

		if ( 'textarea' === $field['type'] ) {
			return '<textarea id="' . esc_attr( $element_id ) . '" name="' . esc_attr( $name ) . '" rows="5"'
				. $placeholder . $described . $required . '></textarea>';
		}

		if ( 'select' === $field['type'] ) {
			$html = '<select id="' . esc_attr( $element_id ) . '" name="' . esc_attr( $name ) . '"' . $described . $required . '>';
			$html .= '<option value="">' . esc_html__( 'Vyberte…', 'raynet-lead-api-integration' ) . '</option>';

			foreach ( $field['options'] as $option ) {
				$html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
			}

			return $html . '</select>';
		}

		if ( 'checkbox' === $field['type'] ) {
			return '<label class="raynet-lead-form__checkbox"><input type="checkbox" id="' . esc_attr( $element_id )
				. '" name="' . esc_attr( $name ) . '" value="1"' . $described . $required . ' /> '
				. esc_html( $field['label'] ) . '</label>';
		}

		return '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $element_id )
			. '" name="' . esc_attr( $name ) . '"' . $placeholder
			. ' autocomplete="' . esc_attr( self::autocomplete( $field ) ) . '"'
			. $described . $required . ' />';
	}

	/**
	 * Renders the consent checkbox.
	 *
	 * Its label carries the privacy wording and usually a link, so it is the one
	 * label that keeps markup.
	 *
	 * @param array<string,mixed> $field      Field definition.
	 * @param string              $element_id Element id.
	 * @param string              $name       Input name.
	 * @return string Markup.
	 */
	private static function render_consent( array $field, $element_id, $name ) {
		return '<div class="raynet-lead-form__row raynet-lead-form__row--consent">'
			. '<label class="raynet-lead-form__consent" for="' . esc_attr( $element_id ) . '">'
			. '<input type="checkbox" id="' . esc_attr( $element_id ) . '" name="' . esc_attr( $name ) . '" value="1" required />'
			. '<span>' . wp_kses_post( $field['label'] ) . '</span>'
			. '</label></div>';
	}

	/**
	 * Name the field submits under.
	 *
	 * Lead attributes keep their own name so the collector reads them unchanged.
	 * Custom fields are namespaced so one cannot overwrite a lead attribute or a
	 * control field such as raynet_form_id.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @return string Input name.
	 */
	public static function input_name( array $field ) {
		if ( 'consent' === $field['source'] ) {
			return 'consent';
		}

		if ( 'custom' === $field['source'] ) {
			return 'raynet_custom[' . $field['id'] . ']';
		}

		return $field['source'];
	}

	/**
	 * Autocomplete token for a field.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @return string Token.
	 */
	private static function autocomplete( array $field ) {
		$catalogue = Raynet_Lead_Form_Definition::catalogue();

		return isset( $catalogue[ $field['source'] ] )
			? $catalogue[ $field['source'] ]['autocomplete']
			: 'off';
	}
}
