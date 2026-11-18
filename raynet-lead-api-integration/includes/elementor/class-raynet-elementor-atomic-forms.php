<?php
/**
 * Elementor 4's atomic form, seen through the settings of the classic widget.
 *
 * An atomic form (`e-form`) is an element whose fields are child widgets and
 * whose settings are typed values ({"$$type": "string", "value": "…"}). The
 * mapping, template and lead-building code of this plugin works on the flat
 * settings of the classic Form widget. This adapter turns an `e-form` into
 * those settings and writes changes back, so that code runs unchanged.
 *
 * The RAYNET settings of an atomic form are kept in post meta of the document
 * holding it: the atomic schema has no room for them, and anything it does not
 * know must not be written into the element. The one thing written into the
 * element is the `raynet_crm` slug among its actions after submit.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter between an `e-form` element and classic Form widget settings.
 */
class Raynet_Elementor_Atomic_Forms {

	/**
	 * Post meta holding the RAYNET settings: form element id => settings.
	 */
	const META = '_raynet_crm_atomic_forms';

	/**
	 * Element setting listing the actions run after submit.
	 */
	const ACTIONS_PROP = 'actions-after-submit';

	/**
	 * Prefix of the settings this plugin keeps for a form.
	 */
	const KEY_PREFIX = 'raynet_crm_';

	/**
	 * Field widgets and the classic field type each stands for.
	 *
	 * The input's type depends on its `type` prop; `text` is its default.
	 */
	const FIELD_TYPES = array(
		'e-form-input'        => 'text',
		'e-form-textarea'     => 'textarea',
		'e-form-checkbox'     => 'acceptance',
		'e-form-radio-button' => 'radio',
		'e-form-select'       => 'select',
		'e-form-date-picker'  => 'date',
		'e-form-time-picker'  => 'time',
		'e-form-file-upload'  => 'upload',
	);

	/**
	 * Values the input's `type` prop may carry; anything else reads as text.
	 */
	const INPUT_TYPES = array( 'text', 'email', 'tel', 'number', 'url', 'password' );

	/**
	 * Widget type of the label element.
	 */
	const LABEL_TYPE = 'e-form-label';

	/**
	 * Tells whether Elementor Pro can run an action of this plugin on an
	 * atomic form.
	 *
	 * @return bool True when the atomic action registry is there.
	 */
	public static function supported() {
		return class_exists( '\\ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Runner' );
	}

	/**
	 * The form's fields as rows of the classic `form_fields` setting.
	 *
	 * @param array<string,mixed> $element The `e-form` element.
	 * @return array<int,array<string,string>> Rows with custom_id, field_type,
	 *                                         field_label and, for a checkbox,
	 *                                         acceptance_text.
	 */
	public static function fields( array $element ) {
		$widgets = array();
		$labels  = array();

		self::collect( isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array(), $widgets, $labels );

		$rows = array();

		foreach ( $widgets as $widget ) {
			$settings = isset( $widget['settings'] ) && is_array( $widget['settings'] ) ? $widget['settings'] : array();
			$type     = self::field_type( self::element_type( $widget ), $settings );
			$cssid    = self::string( $settings, '_cssid' );
			$label    = '' !== $cssid && isset( $labels[ $cssid ] ) ? $labels[ $cssid ] : '';

			if ( '' === $label ) {
				$label = self::plain( self::string( $settings, 'placeholder' ) );
			}

			if ( '' === $label ) {
				$label = sprintf(
					/* translators: 1: position of the field in the form, 2: field type. */
					__( 'Pole %1$d (%2$s)', 'raynet-lead-api-integration' ),
					count( $rows ) + 1,
					$type
				);
			}

			$row = array(
				'custom_id'   => isset( $widget['id'] ) ? (string) $widget['id'] : '',
				'field_type'  => $type,
				'field_label' => $label,
			);

			// A checkbox's label is what the visitor agrees to.
			if ( 'acceptance' === $type ) {
				$row['acceptance_text'] = $label;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The actions the form runs after submit.
	 *
	 * Elementor stores no default, so a form whose actions were never touched
	 * has no such setting. Elementor Pro then runs the e-mail action if the
	 * form stores e-mail settings, and nothing otherwise.
	 *
	 * @param array<string,mixed> $element The `e-form` element.
	 * @return string[] Action slugs.
	 */
	public static function actions( array $element ) {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		// Mirrors Elementor Pro's Atomic_Form_Controller::resolve_widget_settings():
		// the e-mail fallback applies only when e-mail settings are stored.
		if ( ! isset( $settings[ self::ACTIONS_PROP ] ) ) {
			return isset( $settings['email'] ) ? array( 'email' ) : array();
		}

		$actions = array();

		foreach ( (array) self::unwrap( $settings[ self::ACTIONS_PROP ] ) as $item ) {
			$slug = self::unwrap( $item );

			if ( is_scalar( $slug ) && '' !== (string) $slug ) {
				$actions[] = (string) $slug;
			}
		}

		return array_values( array_unique( $actions ) );
	}

	/**
	 * The form as classic Form widget settings.
	 *
	 * @param int                 $post_id Document holding the form.
	 * @param array<string,mixed> $element The `e-form` element.
	 * @return array<string,mixed> Stored RAYNET keys plus form_name, form_fields
	 *                             and submit_actions.
	 */
	public static function settings( $post_id, array $element ) {
		$own      = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$settings = self::stored( $post_id, isset( $element['id'] ) ? (string) $element['id'] : '' );

		$settings['form_name']      = self::plain( self::string( $own, 'form-name' ) );
		$settings['form_fields']    = self::fields( $element );
		$settings['submit_actions'] = self::actions( $element );

		return $settings;
	}

	/**
	 * RAYNET settings stored for one form.
	 *
	 * An entry for a form no longer in the document is never asked for, so it
	 * does no harm; one that is not an array is read as nothing stored.
	 *
	 * @param int    $post_id Document holding the form.
	 * @param string $form_id Form element id.
	 * @return array<string,mixed> Settings, keys starting with raynet_crm_.
	 */
	public static function stored( $post_id, $form_id ) {
		$all     = self::all( $post_id );
		$form_id = (string) $form_id;

		if ( '' === $form_id || ! isset( $all[ $form_id ] ) || ! is_array( $all[ $form_id ] ) ) {
			return array();
		}

		return self::own_keys( $all[ $form_id ] );
	}

	/**
	 * Stores the RAYNET settings of one form, replacing what was there.
	 *
	 * @param int                 $post_id  Document holding the form.
	 * @param string              $form_id  Form element id.
	 * @param array<string,mixed> $settings Settings; only raynet_crm_ keys are kept.
	 * @return bool True when the meta holds them afterwards.
	 */
	public static function store( $post_id, $form_id, array $settings ) {
		$post_id = (int) $post_id;
		$form_id = (string) $form_id;

		if ( $post_id <= 0 || '' === $form_id ) {
			return false;
		}

		$clean = self::own_keys( $settings );
		$all   = self::all( $post_id );

		if ( isset( $all[ $form_id ] ) && $all[ $form_id ] === $clean ) {
			return true;
		}

		$all[ $form_id ] = $clean;

		return (bool) update_post_meta( $post_id, self::META, wp_slash( $all ) );
	}

	/**
	 * Writes classic-shaped settings back into the form.
	 *
	 * The element gets only its actions after submit, rewritten from
	 * `submit_actions`, and only when they changed; every other setting stays
	 * as it was. The RAYNET keys go to post meta.
	 *
	 * @param array<string,mixed> $element  The `e-form` element.
	 * @param array<string,mixed> $settings Settings as settings() returns them, changed.
	 * @param int                 $post_id  Document to store the RAYNET keys on;
	 *                                      0 rewrites the element only.
	 * @return array<string,mixed> The element.
	 */
	public static function apply( array $element, array $settings, $post_id ) {
		if ( array_key_exists( 'submit_actions', $settings ) ) {
			$actions = array_values( array_unique( array_filter( array_map( 'strval', (array) $settings['submit_actions'] ), 'strlen' ) ) );

			if ( $actions !== self::actions( $element ) ) {
				$items = array();

				foreach ( $actions as $action ) {
					$items[] = array(
						'$$type' => 'string',
						'value'  => $action,
					);
				}

				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
					$element['settings'] = array();
				}

				$element['settings'][ self::ACTIONS_PROP ] = array(
					'$$type' => 'string-array',
					'value'  => $items,
				);
			}
		}

		if ( (int) $post_id > 0 && isset( $element['id'] ) ) {
			self::store( (int) $post_id, (string) $element['id'], $settings );
		}

		return $element;
	}

	/**
	 * All stored entries of a document.
	 *
	 * @param int $post_id Document id.
	 * @return array<string,mixed> Form id => settings.
	 */
	private static function all( $post_id ) {
		$all = get_post_meta( (int) $post_id, self::META, true );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Settings reduced to the keys this plugin owns.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed> Keys starting with raynet_crm_.
	 */
	private static function own_keys( array $settings ) {
		$own = array();

		foreach ( $settings as $key => $value ) {
			if ( 0 === strpos( (string) $key, self::KEY_PREFIX ) ) {
				$own[ (string) $key ] = $value;
			}
		}

		return $own;
	}

	/**
	 * Gathers field widgets and label texts, depth first in tree order.
	 *
	 * @param array<int,mixed>                 $elements Child elements.
	 * @param array<int,array<string,mixed>>   $widgets  Field widgets, by reference.
	 * @param array<string,string>             $labels   Input id => label text, by reference.
	 * @return void
	 */
	private static function collect( array $elements, array &$widgets, array &$labels ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type     = self::element_type( $element );
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( array_key_exists( $type, self::FIELD_TYPES ) ) {
				$widgets[] = $element;
			} elseif ( self::LABEL_TYPE === $type ) {
				$input = self::string( $settings, 'input-id' );
				$text  = self::plain( self::text( isset( $settings['text'] ) ? $settings['text'] : '' ) );

				if ( '' !== $input && '' !== $text && ! isset( $labels[ $input ] ) ) {
					$labels[ $input ] = $text;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect( $element['elements'], $widgets, $labels );
			}
		}
	}

	/**
	 * An element's type: the widget type for widgets, else the element type.
	 *
	 * @param array<string,mixed> $element Element.
	 * @return string Type.
	 */
	private static function element_type( array $element ) {
		if ( isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ) {
			return $element['widgetType'];
		}

		return isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '';
	}

	/**
	 * The classic field type of a field widget.
	 *
	 * @param string              $widget   Widget type.
	 * @param array<string,mixed> $settings Widget settings.
	 * @return string Classic field type.
	 */
	private static function field_type( $widget, array $settings ) {
		if ( 'e-form-input' !== $widget ) {
			return self::FIELD_TYPES[ $widget ];
		}

		$type = strtolower( self::string( $settings, 'type' ) );

		return in_array( $type, self::INPUT_TYPES, true ) ? $type : 'text';
	}

	/**
	 * A setting read as a string, typed or not.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Setting key.
	 * @return string Value, or an empty string.
	 */
	private static function string( array $settings, $key ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return '';
		}

		$value = self::unwrap( $settings[ $key ] );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The value inside a typed value; anything else as it is.
	 *
	 * @param mixed $value Value.
	 * @return mixed Unwrapped value.
	 */
	private static function unwrap( $value ) {
		if ( is_array( $value ) && isset( $value['$$type'] ) && array_key_exists( 'value', $value ) ) {
			return $value['value'];
		}

		return $value;
	}

	/**
	 * Text of a label, whatever shape it was stored in.
	 *
	 * Elementor 4.1 stores html-v3: {content: typed string, children: [...]}.
	 * Older versions stored html or a plain string. When the content is
	 * empty, the children's text is used.
	 *
	 * @param mixed $value Stored value.
	 * @return string Text, possibly with markup.
	 */
	private static function text( $value ) {
		$value = self::unwrap( $value );

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( array_key_exists( 'content', $value ) ) {
			$content = self::text( $value['content'] );

			if ( '' !== trim( $content ) ) {
				return $content;
			}
		}

		$parts = array();

		foreach ( isset( $value['children'] ) && is_array( $value['children'] ) ? $value['children'] : array() as $child ) {
			$part = self::text( $child );

			if ( '' !== trim( $part ) ) {
				$parts[] = $part;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Text without markup, entities or runs of white space.
	 *
	 * @param string $text Text.
	 * @return string Plain text.
	 */
	private static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
