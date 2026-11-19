<?php
/**
 * Hooks that put the RAYNET CRM action into Elementor 4's atomic form.
 *
 * Elementor Pro 4.1 runs an atomic form's actions after submit from its own
 * registry (Action_Runner), apart from the classic Form widget's. This class
 * registers the action there, offers it among the form's actions in the
 * editor and names it in the submissions action log.
 *
 * It extends nothing from Elementor, so it loads on any site; the action
 * class, which extends Elementor Pro's Action_Base, is read only from the
 * registry's own hook.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the RAYNET CRM action into Elementor Pro's atomic form module.
 */
class Raynet_Atomic_Form_Integration {

	/**
	 * Slug of the action, the same as the classic widget's.
	 */
	const ACTION_TYPE = Raynet_Elementor_Forms::ACTION_NAME;

	/**
	 * Name of the action in the editor and in the action log.
	 */
	const LABEL = 'RAYNET CRM';

	/**
	 * Base class of an atomic form action in Elementor Pro.
	 */
	const ACTION_BASE = '\\ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Base';

	/**
	 * Hooks into Elementor and Elementor Pro.
	 *
	 * Every callback checks for the classes it needs, so on a site without
	 * them (or with an Elementor Pro that has no atomic registry) nothing runs.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'elementor_pro/atomic_forms/actions/register', array( __CLASS__, 'register_action' ) );
		add_filter( 'elementor/atomic-widgets/controls', array( __CLASS__, 'add_chip' ), 10, 2 );
		add_filter( 'elementor_pro/atomic_forms/action_log_label', array( __CLASS__, 'log_label' ), 10, 2 );
	}

	/**
	 * Registers the action in Elementor Pro's atomic action registry.
	 *
	 * @param string $runner Class name of the registry (Action_Runner), which
	 *                       is what Elementor Pro passes.
	 * @return void
	 */
	public static function register_action( $runner ) {
		if ( ! class_exists( self::ACTION_BASE ) || ! is_string( $runner ) || ! class_exists( $runner ) || ! method_exists( $runner, 'register_action' ) ) {
			return;
		}

		require_once RAYNET_LEAD_PATH . 'includes/elementor/class-raynet-atomic-form-action.php';

		$runner::register_action( new Raynet_Atomic_Form_Action() );
	}

	/**
	 * Adds RAYNET CRM to the atomic form's "Actions after submit" chips.
	 *
	 * Offered only where the registry can run the action: a chip without it
	 * would make every submission report an invalid action.
	 *
	 * @param array<int,mixed> $controls Controls of the element.
	 * @param object           $element  The element.
	 * @return array<int,mixed> Controls.
	 */
	public static function add_chip( $controls, $element ) {
		$form_class = '\\Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Form\\Atomic_Form';

		if ( ! is_array( $controls ) || ! class_exists( $form_class ) || ! ( $element instanceof $form_class ) || ! Raynet_Elementor_Atomic_Forms::supported() ) {
			return $controls;
		}

		self::add_option( $controls );

		return $controls;
	}

	/**
	 * Name of the action in the submissions action log.
	 *
	 * @param string $label       Elementor's default, "Raynet Crm".
	 * @param string $action_type Action slug.
	 * @return string Label.
	 */
	public static function log_label( $label, $action_type ) {
		return self::ACTION_TYPE === $action_type ? self::LABEL : $label;
	}

	/**
	 * The submitted fields in the shape the lead builder reads.
	 *
	 * Elementor Pro hands an action only `$form_data`: field id => value, as
	 * the browser posted it and Elementor sanitized it. The browser also posts
	 * each field's type and label, so neither is trusted: both come from the
	 * stored tree, and a posted id the tree does not have is dropped.
	 *
	 * A radio group posts one entry, under the id of the checked button (of
	 * the first one when nobody chose); it is filed under the group's field.
	 *
	 * A field the request did not carry is left out rather than sent empty,
	 * except a checkbox: one that is missing was not ticked, and leaving it
	 * out would let the per-form consent switch claim consent for a form
	 * whose box nobody ticked.
	 *
	 * @param array<int,mixed>     $form_fields Rows of Raynet_Elementor_Atomic_Forms::fields().
	 * @param array<string,mixed>  $form_data   Posted values, keyed by field id.
	 * @param array<string,string> $groups      Result of Raynet_Elementor_Atomic_Forms::radio_groups().
	 * @return array<string,array<string,string>> Field id => id, type, title, value, raw_value.
	 */
	public static function submitted_fields( array $form_fields, array $form_data, array $groups = array() ) {
		$fields = array();

		foreach ( $groups as $member => $group ) {
			$member = (string) $member;
			$group  = (string) $group;

			if ( $member === $group || ! array_key_exists( $member, $form_data ) ) {
				continue;
			}

			// An empty value (the group nobody chose) never hides a chosen one.
			if ( ! array_key_exists( $group, $form_data ) || '' === self::join( $form_data[ $group ] ) ) {
				$form_data[ $group ] = $form_data[ $member ];
			}

			unset( $form_data[ $member ] );
		}

		foreach ( $form_fields as $row ) {
			if ( ! is_array( $row ) || empty( $row['custom_id'] ) ) {
				continue;
			}

			$id   = (string) $row['custom_id'];
			$type = isset( $row['field_type'] ) ? (string) $row['field_type'] : 'text';

			if ( ! array_key_exists( $id, $form_data ) ) {
				if ( 'acceptance' !== $type ) {
					continue;
				}

				$form_data[ $id ] = '';
			}

			$value = 'upload' === $type
				? self::file_names( $form_data[ $id ] )
				: self::join( $form_data[ $id ] );

			$fields[ $id ] = array(
				'id'        => $id,
				'type'      => $type,
				'title'     => isset( $row['field_label'] ) ? (string) $row['field_label'] : '',
				'value'     => $value,
				// Elementor's classic widget keeps a server path here for an
				// upload and the unconverted input for a number. Neither applies:
				// atomic values are not converted, and a path must not travel.
				'raw_value' => $value,
			);
		}

		return $fields;
	}

	/**
	 * The posted values under the ids they were posted with.
	 *
	 * Elementor element ids are seven hex characters, so now and then one is
	 * all digits, and PHP keys it as an integer. Elementor Pro's controller
	 * then calls array_merge( $form_data, $upload_overrides ), which renumbers
	 * every integer key from 0 (atomic-form-controller.php:86 in Pro 4.1.1):
	 * the value of field "1066726" arrives as key 0 and its field looks empty.
	 *
	 * The ids survive as the keys of `field_metadata`, built from the same
	 * posted rows in the same order, and of `files`, built in the same loop as
	 * the upload overrides. array_merge() numbers the integer keys of the
	 * posted data first, then those of the overrides, so the n-th renumbered
	 * key is the n-th integer id of those two lists. Data whose integer keys
	 * are not exactly 0, 1, 2, … was not renumbered and is returned as it is.
	 *
	 * @param array<int|string,mixed> $form_data Values as Elementor Pro passes them.
	 * @param array<string,mixed>     $context   Elementor Pro's context.
	 * @return array<int|string,mixed> Values keyed by field id.
	 */
	public static function posted_data( array $form_data, array $context ) {
		$numbered = array_values( array_filter( array_keys( $form_data ), 'is_int' ) );

		if ( empty( $numbered ) || range( 0, count( $numbered ) - 1 ) !== $numbered ) {
			return $form_data;
		}

		$ids = array();

		foreach ( array( 'field_metadata', 'files' ) as $source ) {
			if ( isset( $context[ $source ] ) && is_array( $context[ $source ] ) ) {
				$ids = array_merge( $ids, array_values( array_filter( array_keys( $context[ $source ] ), 'is_int' ) ) );
			}
		}

		// Ids that are themselves 0, 1, 2 … would read the same either way;
		// without the lists nothing can be told apart.
		if ( empty( $ids ) || count( $ids ) < count( $numbered ) ) {
			return $form_data;
		}

		$restored = array();

		foreach ( $form_data as $key => $value ) {
			// An override comes after the posted value and replaces it, as
			// Elementor Pro meant it to.
			$restored[ is_int( $key ) ? $ids[ $key ] : $key ] = $value;
		}

		return $restored;
	}

	/**
	 * Lines for the lead note: the names of the uploaded files.
	 *
	 * Files are not sent to RAYNET from an atomic form. Their names go to the
	 * note, as the classic widget lists its attachments there, unless the
	 * field was left out on purpose or the mapping already sends it.
	 *
	 * @param array<string,array<string,string>> $fields        Result of submitted_fields().
	 * @param array<string,mixed>                $form_settings Form settings.
	 * @return array<string,string> Field label => file names.
	 */
	public static function upload_lines( array $fields, array $form_settings ) {
		$ignored = array_merge(
			Raynet_Elementor_Forms::id_list( $form_settings, Raynet_Elementor_Forms::IGNORED_KEY ),
			array_map( 'strval', array_values( Raynet_Elementor_Forms::current_map( $form_settings ) ) )
		);
		$lines   = array();

		foreach ( $fields as $id => $field ) {
			if ( 'upload' !== $field['type'] || '' === $field['value'] || in_array( (string) $id, $ignored, true ) ) {
				continue;
			}

			$label = '' !== trim( $field['title'] ) ? sanitize_text_field( $field['title'] ) : (string) $id;

			$lines = Raynet_Elementor_Lead_Builder::merge_lines( $lines, array( $label => $field['value'] ) );
		}

		return $lines;
	}

	/**
	 * Finds an atomic form in the stored layout of a document.
	 *
	 * Read from the layout Elementor Pro itself reads for the submission, and
	 * only an `e-form`: an id posted for any other element finds nothing.
	 *
	 * @param int    $post_id Document id.
	 * @param string $form_id Form element id.
	 * @return array<string,mixed>|null The element, or null.
	 */
	public static function form_element( $post_id, $form_id ) {
		$post_id = (int) $post_id;
		$form_id = (string) $form_id;

		if ( $post_id <= 0 || '' === $form_id ) {
			return null;
		}

		$raw  = get_post_meta( $post_id, Raynet_Elementor_Forms::DATA_META, true );
		$tree = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		return is_array( $tree ) ? self::find( $tree, $form_id ) : null;
	}

	/**
	 * Looks for an `e-form` by id in a list of elements.
	 *
	 * @param array<int,mixed> $elements Elements.
	 * @param string           $form_id  Form element id.
	 * @return array<string,mixed>|null The element, or null.
	 */
	private static function find( array $elements, $form_id ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'], $element['elType'] ) && $form_id === (string) $element['id'] && Raynet_Elementor_Forms::ATOMIC_TYPE === $element['elType'] ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find( $element['elements'], $form_id );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Appends this action to the chips bound to the actions after submit.
	 *
	 * Sections hold the controls; they are objects, so changing one in place
	 * changes it in the list.
	 *
	 * @param array<int,mixed> $items Controls and sections.
	 * @return void
	 */
	private static function add_option( array $items ) {
		$chips = '\\Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Chips_Control';

		foreach ( $items as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_items' ) && method_exists( $item, 'set_items' ) ) {
				self::add_option( (array) $item->get_items() );
				continue;
			}

			if ( ! class_exists( $chips ) || ! ( $item instanceof $chips ) || Raynet_Elementor_Atomic_Forms::ACTIONS_PROP !== $item->get_bind() ) {
				continue;
			}

			$props   = $item->get_props();
			$options = isset( $props['options'] ) && is_array( $props['options'] ) ? $props['options'] : array();

			foreach ( $options as $option ) {
				if ( is_array( $option ) && isset( $option['value'] ) && self::ACTION_TYPE === $option['value'] ) {
					continue 2;
				}
			}

			$options[] = array(
				'label' => self::LABEL,
				'value' => self::ACTION_TYPE,
			);

			$item->set_options( $options );
		}
	}

	/**
	 * A posted value as one string; a list is joined with commas.
	 *
	 * @param mixed $value Posted value.
	 * @return string Value.
	 */
	private static function join( $value ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}

		$parts = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$parts[] = (string) $item;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * The file names of an upload, without where the files are kept.
	 *
	 * Elementor Pro replaces an upload's value with the URLs of the stored
	 * copies. Those sit in a folder Elementor keeps for form files, which
	 * nobody outside the site should learn; the visitor's file name is enough.
	 *
	 * @param mixed $value URL, path or a list of them.
	 * @return string Names, joined with commas.
	 */
	private static function file_names( $value ) {
		$names = array();

		foreach ( (array) $value as $item ) {
			if ( ! is_scalar( $item ) || '' === trim( (string) $item ) ) {
				continue;
			}

			$path = wp_parse_url( (string) $item, PHP_URL_PATH );
			$name = sanitize_text_field( basename( rawurldecode( is_string( $path ) ? $path : (string) $item ) ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return implode( ', ', $names );
	}
}
