<?php
/**
 * Finding Elementor forms on the site and configuring them in bulk.
 *
 * Elementor keeps a page's whole layout in one JSON blob in post meta, so the
 * work here is walking that tree, reading the form widgets out of it and
 * writing settings back. Every write keeps the previous blob so a mistake can
 * be undone.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner, auto-mapper and bulk configurator for Elementor forms.
 */
class Raynet_Elementor_Forms {

	/**
	 * Name the submit action is registered and stored under.
	 *
	 * Declared here rather than on the action class, because that one is only
	 * loaded when Elementor Pro is present and this screen has to work either
	 * way.
	 */
	const ACTION_NAME = 'raynet_crm';

	/**
	 * Meta key holding a page's Elementor layout.
	 */
	const DATA_META = '_elementor_data';

	/**
	 * Meta key holding the layout as it was before the last apply.
	 */
	const BACKUP_META = '_raynet_elementor_backup';

	/**
	 * Meta key holding a fingerprint of the layout as this plugin last wrote it.
	 *
	 * Lets a rollback tell "nothing has happened since" from "the page has been
	 * edited in Elementor since", which decides whether putting the old tree
	 * back would destroy somebody's work.
	 */
	const STAMP_META = '_raynet_elementor_stamp';

	/**
	 * Option holding the saved templates.
	 */
	const TEMPLATES_OPTION = 'raynet_lead_elementor_templates';

	/**
	 * Widget setting listing the form fields whose values go to the lead note.
	 */
	const NOTICE_KEY = 'raynet_crm_notice_fields';

	/**
	 * Widget setting listing the form fields someone chose not to send.
	 *
	 * Kept apart from "never decided", so the mapping screen stops proposing
	 * a target for a field that was left out on purpose.
	 */
	const IGNORED_KEY = 'raynet_crm_ignored_fields';

	/**
	 * Mapping target meaning "write the value into the lead note".
	 */
	const TARGET_NOTICE = '__notice';

	/**
	 * Field types that carry nothing a lead should hold, even in its note.
	 *
	 * A password is the one that is not empty: it must never reach a CRM in
	 * plain text, whoever set up the mapping.
	 */
	const UNSENDABLE_TYPES = array( 'upload', 'password', 'recaptcha', 'recaptcha_v3', 'honeypot', 'html', 'step' );

	/**
	 * Post type of Elementor's template library.
	 *
	 * Popups, headers, footers, saved sections, loop items and global widgets
	 * all live here, and a form placed in one of them keeps its settings here
	 * too: that is the document Elementor reads when the form is submitted.
	 */
	const LIBRARY_TYPE = 'elementor_library';

	/**
	 * Meta key Elementor uses to say it renders a post.
	 */
	const EDIT_MODE_META = '_elementor_edit_mode';

	/**
	 * Element type of Elementor 4's atomic form.
	 *
	 * It is a different element from the Form widget, with its own fixed list
	 * of actions and no room for this plugin's settings, so it is listed but
	 * cannot be configured.
	 */
	const ATOMIC_TYPE = 'e-form';

	/**
	 * Label fragments that identify an attribute, in the order they are tried.
	 *
	 * The whole-name entry comes first because "jméno a příjmení" also contains
	 * the fragments for both halves.
	 *
	 * @return array<string,string[]> Source => fragments, already accent free.
	 */
	public static function label_hints() {
		return array(
			'fullName'    => array( 'jmeno a prijmeni', 'cele jmeno', 'jmeno i prijmeni', 'full name', 'name and surname' ),
			'lastName'    => array( 'prijmeni*', 'last name', 'surname' ),
			'firstName'   => array( 'jmeno*', 'first name', 'krestni*' ),
			// Before the company, so "IČO firmy" is the number, not the name; and
			// the tax number before the registration number, so "IČ DPH" is not
			// read as "IČ".
			'taxNumber'   => array( 'dic', 'ic dph', 'vat' ),
			'regNumber'   => array( 'ico', 'ic', 'identifikacni cislo' ),
			'companyName' => array( 'firma', 'firmy', 'spolecnost*', 'company', 'organizace' ),
			'email'       => array( 'mail*', 'e-mail' ),
			'phone'       => array( 'telefon*', 'mobil*', 'phone', 'tel' ),
			// Before the street, so "Adresa webu" is not read as an address.
			'www'         => array( 'web*', 'www', 'website' ),
			'topic'       => array( 'predmet*', 'subject', 'tema', 'nadpis' ),
			'message'     => array( 'zprav*', 'poznamk*', 'dotaz*', 'poptavk*', 'vzkaz*', 'message', 'komentar*' ),
			'street'      => array( 'ulice', 'adresa', 'adresu', 'street', 'address' ),
			'city'        => array( 'mest*', 'obec', 'obce', 'city' ),
			'zipCode'     => array( 'psc', 'zip', 'postal*' ),
			'province'    => array( 'kraj', 'region' ),
			'country'     => array( 'zeme', 'stat', 'country' ),
			'titleBefore' => array( 'titul*' ),
			'databox'     => array( 'datova schranka', 'datove schranky' ),
		);
	}

	/**
	 * Field types that hold no value worth mapping.
	 *
	 * An acceptance box labelled "Chci novinky e-mailem" would otherwise be
	 * claimed by the e-mail attribute and the real address dropped.
	 *
	 * @return string[] Elementor field types to ignore.
	 */
	public static function skip_types() {
		return array( 'acceptance', 'upload', 'recaptcha', 'recaptcha_v3', 'honeypot', 'hidden', 'html', 'step' );
	}

	/**
	 * Field types that settle an attribute on their own.
	 *
	 * @return array<string,string> Elementor field type => source.
	 */
	public static function type_hints() {
		return array(
			'email'    => 'email',
			'tel'      => 'phone',
			'textarea' => 'message',
			'url'      => 'www',
		);
	}

	/**
	 * Lists every Elementor form on the site.
	 *
	 * Asks the database for the posts whose layout holds a form widget, of any
	 * post type — the template library included — and with no cap. Asking for
	 * "any" post type would silently skip the library, which WordPress keeps
	 * out of searches, and a cap would silently drop the oldest pages, which
	 * are the ones a site's main contact form tends to live on.
	 *
	 * @return array<int,array<string,mixed>> One entry per form widget.
	 */
	public static function scan() {
		$posts = get_posts(
			array(
				'post_type'        => self::scanned_post_types(),
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin screen; narrows the scan to posts that hold a form.
				'meta_query'       => array(
					'relation' => 'OR',
					array(
						'key'     => self::DATA_META,
						'value'   => '"widgetType":"form"',
						'compare' => 'LIKE',
					),
					array(
						'key'     => self::DATA_META,
						'value'   => '"elType":"' . self::ATOMIC_TYPE . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$forms = array();

		foreach ( $posts as $post_id ) {
			foreach ( self::forms_in_post( (int) $post_id ) as $form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Post types a form can live in.
	 *
	 * Everything registered except revisions: an autosave carries a copy of
	 * the layout and would list every form twice.
	 *
	 * @return string[] Post type names.
	 */
	public static function scanned_post_types() {
		return array_values( array_diff( get_post_types( array(), 'names' ), array( 'revision', 'attachment' ) ) );
	}

	/**
	 * Tells whether Elementor actually renders a post's layout.
	 *
	 * A page switched back to the WordPress editor keeps its old layout in
	 * meta, and Elementor ignores it. Configuring a form there would report
	 * success and change nothing a visitor ever sees. Library templates are
	 * rendered through whatever places them, so they always count.
	 *
	 * @param int $post_id Post id.
	 * @return bool True when the layout is live.
	 */
	public static function is_rendered( $post_id ) {
		if ( self::LIBRARY_TYPE === get_post_type( $post_id ) ) {
			return true;
		}

		return (bool) get_post_meta( (int) $post_id, self::EDIT_MODE_META, true );
	}

	/**
	 * Human description of where a form lives.
	 *
	 * @param int $post_id Post id.
	 * @return string Label such as "Stránka", "Popup" or "Patička".
	 */
	public static function location_label( $post_id ) {
		$type = get_post_type( $post_id );

		if ( self::LIBRARY_TYPE !== $type ) {
			$object = get_post_type_object( $type );

			return $object ? $object->labels->singular_name : (string) $type;
		}

		$template = (string) get_post_meta( (int) $post_id, '_elementor_template_type', true );
		$labels   = array(
			'popup'     => __( 'Popup', 'raynet-lead-api-integration' ),
			'header'    => __( 'Hlavička', 'raynet-lead-api-integration' ),
			'footer'    => __( 'Patička', 'raynet-lead-api-integration' ),
			'widget'    => __( 'Globální widget', 'raynet-lead-api-integration' ),
			'section'   => __( 'Uložená sekce', 'raynet-lead-api-integration' ),
			'container' => __( 'Uložená sekce', 'raynet-lead-api-integration' ),
			'page'      => __( 'Šablona stránky', 'raynet-lead-api-integration' ),
			'loop-item' => __( 'Položka smyčky', 'raynet-lead-api-integration' ),
		);

		if ( isset( $labels[ $template ] ) ) {
			return $labels[ $template ];
		}

		return '' === $template
			? __( 'Šablona', 'raynet-lead-api-integration' )
			: __( 'Šablona motivu', 'raynet-lead-api-integration' );
	}

	/**
	 * Reads the form widgets out of one page.
	 *
	 * @param int $post_id Page id.
	 * @return array<int,array<string,mixed>> Forms.
	 */
	public static function forms_in_post( $post_id ) {
		$tree = self::read_tree( $post_id );

		if ( empty( $tree ) ) {
			return array();
		}

		$found = array();

		self::walk(
			$tree,
			static function ( $element ) use ( &$found, $post_id ) {
				if ( isset( $element['elType'] ) && self::ATOMIC_TYPE === $element['elType'] ) {
					$found[] = self::atomic_row( $post_id, $element );
					return;
				}

				if ( ! isset( $element['widgetType'] ) || 'form' !== $element['widgetType'] ) {
					return;
				}

				$settings = isset( $element['settings'] ) ? (array) $element['settings'] : array();
				$actions  = self::submit_actions( $settings );

				$found[] = array(
					'post_id'     => $post_id,
					'post_title'  => get_the_title( $post_id ),
					'location'    => self::location_label( $post_id ),
					'rendered'    => self::is_rendered( $post_id ),
					'widget_id'   => isset( $element['id'] ) ? (string) $element['id'] : '',
					'form_name'   => isset( $settings['form_name'] ) ? (string) $settings['form_name'] : '',
					'fields'      => self::readable_fields( $settings ),
					'enabled'     => in_array( self::ACTION_NAME, $actions, true ),
					'mapped'      => self::mapped_count( $settings ),
					'has_contact' => self::maps_contact( $settings ),
					'undecided'   => count( array_keys( self::field_targets( $settings ), '', true ) ),
					'noted'       => count( array_keys( self::field_targets( $settings ), self::TARGET_NOTICE, true ) ),
					'atomic'      => false,
					'edit_url'    => self::edit_url( $post_id ),
				);
			}
		);

		return $found;
	}

	/**
	 * Describes an Elementor 4 atomic form for the list.
	 *
	 * @param int                 $post_id Post id.
	 * @param array<string,mixed> $element Element data.
	 * @return array<string,mixed> Row in the shape forms_in_post() returns.
	 */
	private static function atomic_row( $post_id, array $element ) {
		$settings = isset( $element['settings'] ) ? (array) $element['settings'] : array();
		$name     = isset( $settings['form-name'] ) ? $settings['form-name'] : '';

		// Atomic settings are typed values: {"$$type": "string", "value": "..."}.
		if ( is_array( $name ) ) {
			$name = isset( $name['value'] ) && is_scalar( $name['value'] ) ? $name['value'] : '';
		}

		return array(
			'post_id'     => $post_id,
			'post_title'  => get_the_title( $post_id ),
			'location'    => self::location_label( $post_id ),
			'rendered'    => self::is_rendered( $post_id ),
			'widget_id'   => isset( $element['id'] ) ? (string) $element['id'] : '',
			'form_name'   => (string) $name,
			'fields'      => array(),
			'enabled'     => false,
			'mapped'      => 0,
			'has_contact' => false,
			'undecided'   => 0,
			'noted'       => 0,
			'atomic'      => true,
			'edit_url'    => self::edit_url( $post_id ),
		);
	}

	/**
	 * Reduces a form's field definitions to what the screen needs.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,array<string,string>> Fields.
	 */
	public static function readable_fields( array $settings ) {
		$fields = array();

		foreach ( (array) ( isset( $settings['form_fields'] ) ? $settings['form_fields'] : array() ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['custom_id'] ) ) {
				continue;
			}

			$fields[] = array(
				'id'    => (string) $field['custom_id'],
				'type'  => isset( $field['field_type'] ) ? (string) $field['field_type'] : 'text',
				'label' => isset( $field['field_label'] ) ? (string) $field['field_label'] : '',
			);
		}

		return $fields;
	}

	/**
	 * Counts how many attributes a form already has mapped.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return int Count.
	 */
	private static function mapped_count( array $settings ) {
		$key   = self::ACTION_NAME . '_fields_map';
		$count = 0;

		foreach ( (array) ( isset( $settings[ $key ] ) ? $settings[ $key ] : array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['local_id'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Tells whether a form maps an e-mail or a phone.
	 *
	 * RAYNET refuses a lead without either, so a form lacking both would take
	 * every visitor's submission and turn none of them into a lead.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return bool True when at least one of them is mapped to a field.
	 */
	public static function maps_contact( array $settings ) {
		$map = self::current_map( $settings );

		return ! empty( $map['email'] ) || ! empty( $map['phone'] );
	}

	/**
	 * The mapping a form has now, as attribute => field id.
	 *
	 * Rows pointing at a field the form no longer has are left out.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string> Attribute => field id.
	 */
	public static function current_map( array $settings ) {
		$key    = self::ACTION_NAME . '_fields_map';
		$fields = array_column( self::readable_fields( $settings ), 'id' );
		$map    = array();

		foreach ( (array) ( isset( $settings[ $key ] ) ? $settings[ $key ] : array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['remote_id'] ) || empty( $row['local_id'] ) ) {
				continue;
			}

			if ( in_array( (string) $row['local_id'], $fields, true ) ) {
				$map[ (string) $row['remote_id'] ] = (string) $row['local_id'];
			}
		}

		return $map;
	}

	/**
	 * The submit actions a form runs.
	 *
	 * Elementor saves a control only when it differs from its default, so a
	 * form whose actions were never touched has no `submit_actions` at all and
	 * runs the default — the e-mail notification. Reading the missing key as
	 * "no actions" and then adding ours would switch that e-mail off.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return string[] Action names.
	 */
	public static function submit_actions( array $settings ) {
		if ( array_key_exists( 'submit_actions', $settings ) ) {
			return array_values( array_filter( array_map( 'strval', (array) $settings['submit_actions'] ), 'strlen' ) );
		}

		/** This filter is documented in elementor-pro/modules/forms/widgets/form.php */
		return array_values( (array) apply_filters( 'elementor_pro/forms/default_submit_actions', array( 'email' ) ) );
	}

	/**
	 * Guesses which form field feeds which RAYNET attribute.
	 *
	 * Labels decide first, the field type settles the rest, and each field is
	 * used once. A mapping passed in `$keep` is taken as given — someone chose
	 * it — and the guess only fills what it leaves open.
	 *
	 * @param array<int,array<string,string>> $fields Form fields.
	 * @param array<string,string>            $keep   Attribute => field id to preserve.
	 * @return array<int,array<string,string>> Rows for the mapping control.
	 */
	public static function auto_map( array $fields, array $keep = array() ) {
		$usable = array();

		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], self::skip_types(), true ) || in_array( $field['id'], $keep, true ) ) {
				continue;
			}

			$usable[] = $field;
		}

		// A kept whole name rules out guessing the halves, and the other way
		// round: both at once would have one overwrite the other.
		$excluded = array_keys( $keep );

		if ( isset( $keep['fullName'] ) ) {
			$excluded[] = 'firstName';
			$excluded[] = 'lastName';
		}

		if ( isset( $keep['firstName'] ) || isset( $keep['lastName'] ) ) {
			$excluded[] = 'fullName';
		}

		$matched = self::match_fields( $usable, $excluded );

		// The halves are the more precise answer, so when the guess finds both a
		// whole name and a half, the whole-name field is released.
		if ( isset( $matched['fullName'] ) && ( isset( $matched['firstName'] ) || isset( $matched['lastName'] ) ) ) {
			$excluded[] = 'fullName';
			$matched    = self::match_fields( $usable, $excluded );
		}

		$matched = array_merge( $matched, $keep );
		$rows    = array();
		$listed  = array();

		foreach ( Raynet_Lead_Fields::mapping_rows() as $row ) {
			$listed[ $row['id'] ] = true;
			$rows[]               = array(
				'_id'          => 'auto' . ( count( $rows ) + 1 ),
				'remote_id'    => $row['id'],
				'remote_label' => $row['label'],
				'remote_type'  => 'text',
				'local_id'     => isset( $matched[ $row['id'] ] ) ? $matched[ $row['id'] ] : '',
			);
		}

		// A custom field mapped on a site that has not fetched it yet keeps its
		// row; dropping it would lose the choice for good.
		foreach ( $keep as $remote => $local ) {
			if ( isset( $listed[ $remote ] ) || ! Raynet_Lead_Fields::is_custom_id( $remote ) ) {
				continue;
			}

			$rows[] = array(
				'_id'          => 'auto' . ( count( $rows ) + 1 ),
				'remote_id'    => $remote,
				'remote_label' => Raynet_Lead_Fields::custom_name( $remote ),
				'remote_type'  => 'text',
				'local_id'     => $local,
			);
		}

		return $rows;
	}

	/**
	 * Runs one matching pass over the fields.
	 *
	 * Each field goes first to a custom field whose label it repeats exactly —
	 * someone named the two alike on purpose — then to the highest-priority
	 * attribute its label fits, so "E-mailová adresa" cannot be taken for a
	 * street just because the e-mail slot was already filled. A second e-mail
	 * box stays unmapped rather than guessed into E-mail 2: it is as often a
	 * "confirm your e-mail" box as a second address. The field type then
	 * settles whatever the labels left open.
	 *
	 * @param array<int,array<string,string>> $fields   Usable form fields.
	 * @param string[]                        $excluded Sources to leave unmatched.
	 * @return array<string,string> Source => field id.
	 */
	private static function match_fields( array $fields, array $excluded ) {
		$matched = array();
		$taken   = array();
		$custom  = self::custom_labels( $excluded );

		foreach ( $fields as $field ) {
			$words = self::words( $field['label'] );
			$best  = isset( $custom[ $words ] ) && ! isset( $matched[ $custom[ $words ] ] )
				? $custom[ $words ]
				: self::best_source( $field['label'], $excluded );

			if ( '' === $best || isset( $matched[ $best ] ) ) {
				continue;
			}

			$matched[ $best ] = $field['id'];
			$taken[]          = $field['id'];
		}

		foreach ( self::type_hints() as $type => $source ) {
			if ( isset( $matched[ $source ] ) || in_array( $source, $excluded, true ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( in_array( $field['id'], $taken, true ) || $field['type'] !== $type ) {
					continue;
				}

				$matched[ $source ] = $field['id'];
				$taken[]            = $field['id'];
				break;
			}
		}

		return $matched;
	}

	/**
	 * Custom fields keyed by their label, reduced to comparable words.
	 *
	 * @param string[] $excluded Mapping ids to leave out.
	 * @return array<string,string> Words => mapping id.
	 */
	private static function custom_labels( array $excluded ) {
		$labels = array();

		foreach ( Raynet_Lead_Fields::custom() as $name => $meta ) {
			$id    = Raynet_Lead_Fields::CUSTOM_PREFIX . $name;
			$words = self::words( $meta['label'] );

			if ( '' !== $words && ! in_array( $id, $excluded, true ) && ! isset( $labels[ $words ] ) ) {
				$labels[ $words ] = $id;
			}
		}

		return $labels;
	}

	/**
	 * A label reduced to lowercase words without accents or punctuation.
	 *
	 * @param string $label Label.
	 * @return string Words joined by single spaces.
	 */
	private static function words( $label ) {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', self::normalize( $label ) ) );
	}

	/**
	 * The attribute a label fits best.
	 *
	 * @param string   $label    Field label.
	 * @param string[] $excluded Sources to skip.
	 * @return string Source, or an empty string.
	 */
	private static function best_source( $label, array $excluded ) {
		$label = self::normalize( $label );

		if ( '' === $label ) {
			return '';
		}

		foreach ( self::label_hints() as $source => $fragments ) {
			if ( in_array( $source, $excluded, true ) ) {
				continue;
			}

			foreach ( $fragments as $fragment ) {
				if ( self::label_matches( $label, $fragment ) ) {
					return $source;
				}
			}
		}

		return '';
	}

	/**
	 * Tests one label fragment.
	 *
	 * A fragment ending in an asterisk matches any word starting with it, so
	 * "poznamk*" catches both "poznámka" and "poznámky". Without the asterisk
	 * the whole word has to match, which keeps "obec" out of "obecné".
	 *
	 * @param string $label    Normalized label.
	 * @param string $fragment Fragment from the hint list.
	 * @return bool True on a match.
	 */
	private static function label_matches( $label, $fragment ) {
		$stem = '*' === substr( $fragment, -1 );
		$word = preg_quote( $stem ? substr( $fragment, 0, -1 ) : $fragment, '/' );
		$tail = $stem ? '' : '(?![a-z0-9])';

		return 1 === preg_match( '/(?<![a-z0-9])' . $word . $tail . '/', $label );
	}

	/**
	 * Lowercases a label and drops its accents, so "PSČ" matches "psc".
	 *
	 * @param string $label Field label.
	 * @return string Comparable form.
	 */
	private static function normalize( $label ) {
		return trim( strtolower( remove_accents( (string) $label ) ) );
	}

	/**
	 * Applies a template to forms on one page.
	 *
	 * @param int                  $post_id    Page holding the forms.
	 * @param string|string[]      $widget_ids One or more form widget ids.
	 * @param array<string,mixed>  $lead       Lead settings from the template.
	 * @param bool                 $automap    Fill the field mapping by guessing.
	 * @return string[]|WP_Error Ids of the forms actually configured; ids that
	 *                           were asked for but not found are not in it.
	 */
	public static function apply( $post_id, $widget_ids, array $lead, $automap = true, $notice_rest = false ) {
		return self::update_forms(
			$post_id,
			$widget_ids,
			static function ( array $settings ) use ( $lead, $automap, $notice_rest ) {
				return self::configure( $settings, $lead, $automap, $notice_rest );
			}
		);
	}

	/**
	 * Changes the settings of form widgets on one page.
	 *
	 * The single way this plugin writes a page: it keeps the rollback point,
	 * refuses a page Elementor does not render, and carries the change into
	 * pending drafts.
	 *
	 * @param int             $post_id    Page holding the forms.
	 * @param string|string[] $widget_ids One or more form widget ids.
	 * @param callable        $change     Receives a widget's settings, returns the new ones.
	 * @return string[]|WP_Error Ids of the forms actually changed.
	 */
	public static function update_forms( $post_id, $widget_ids, callable $change ) {
		$widget_ids = array_values( array_filter( array_map( 'strval', (array) $widget_ids ), 'strlen' ) );

		if ( empty( $widget_ids ) ) {
			// An empty id used to match every form widget on the page, which is
			// not something any caller asks for on purpose.
			return new WP_Error( 'raynet_no_widget', __( 'Formulář nemá identifikátor, nelze ho nastavit.', 'raynet-lead-api-integration' ) );
		}

		if ( ! self::is_rendered( $post_id ) ) {
			return new WP_Error( 'raynet_not_rendered', __( 'Stránka se už nevykresluje Elementorem, nastavení by se neprojevilo.', 'raynet-lead-api-integration' ) );
		}

		$tree = self::read_tree( $post_id );

		if ( empty( $tree ) ) {
			return new WP_Error( 'raynet_no_data', __( 'Stránka nemá data Elementoru.', 'raynet-lead-api-integration' ) );
		}

		$configured = self::change_tree( $tree, $widget_ids, $change );

		if ( empty( $configured ) ) {
			return new WP_Error( 'raynet_no_form', __( 'Formulář se na stránce nenašel.', 'raynet-lead-api-integration' ) );
		}

		$written = self::write_tree( $post_id, $tree, true );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Elementor opens its editor on an autosave that is newer than the
		// page — an unpublished draft, or a session left without Update. Such a
		// copy would show the form without the change, and the next Update
		// would save it over the page. Changing the page's date to make the
		// autosave look older would hide the draft instead, and the editor's
		// next autosave would overwrite it. So the change goes into the draft
		// as well: the draft stays, and it carries the new settings.
		foreach ( self::pending_autosaves( $post_id ) as $autosave_id ) {
			$draft = self::read_tree( $autosave_id );

			if ( empty( $draft ) || empty( self::change_tree( $draft, $widget_ids, $change ) ) ) {
				continue;
			}

			$encoded = wp_json_encode( $draft );

			// update_post_meta() would redirect a revision id to its parent.
			if ( false !== $encoded ) {
				update_metadata( 'post', $autosave_id, self::DATA_META, wp_slash( $encoded ) );
			}
		}

		return array_values( array_unique( $configured ) );
	}

	/**
	 * Applies a change to the named form widgets inside one layout.
	 *
	 * @param array<int,mixed> $tree       Layout, by reference.
	 * @param string[]         $widget_ids Form widget ids.
	 * @param callable         $change     Receives settings, returns settings.
	 * @return string[] Ids of the widgets found and changed.
	 */
	private static function change_tree( array &$tree, array $widget_ids, callable $change ) {
		$configured = array();

		self::walk(
			$tree,
			static function ( &$element ) use ( $widget_ids, $change, &$configured ) {
				if ( ! isset( $element['widgetType'] ) || 'form' !== $element['widgetType'] ) {
					return;
				}

				if ( ! isset( $element['id'] ) || ! in_array( (string) $element['id'], $widget_ids, true ) ) {
					return;
				}

				$element['settings'] = $change( isset( $element['settings'] ) ? (array) $element['settings'] : array() );
				$configured[]        = (string) $element['id'];
			}
		);

		return $configured;
	}

	/**
	 * Settings of one form widget as stored on its page.
	 *
	 * @param int    $post_id   Page id.
	 * @param string $widget_id Form widget id.
	 * @return array<string,mixed>|null Settings, or null when not found.
	 */
	public static function form_settings( $post_id, $widget_id ) {
		$tree  = self::read_tree( $post_id );
		$found = null;

		self::walk(
			$tree,
			static function ( $element ) use ( $widget_id, &$found ) {
				if ( null === $found && isset( $element['widgetType'], $element['id'] ) && 'form' === $element['widgetType'] && (string) $element['id'] === (string) $widget_id ) {
					$found = isset( $element['settings'] ) ? (array) $element['settings'] : array();
				}
			}
		);

		return $found;
	}

	/**
	 * Form fields that can be mapped or written to the note.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,array<string,string>> Fields.
	 */
	public static function mappable_fields( array $settings ) {
		return array_values(
			array_filter(
				self::readable_fields( $settings ),
				static function ( $field ) {
					return ! in_array( $field['type'], self::UNSENDABLE_TYPES, true );
				}
			)
		);
	}

	/**
	 * Where each form field goes now.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string> Field id => attribute id, TARGET_NOTICE,
	 *                              "-" for left out on purpose, or "" undecided.
	 */
	public static function field_targets( array $settings ) {
		$targets = array_fill_keys( array_column( self::mappable_fields( $settings ), 'id' ), '' );

		foreach ( self::current_map( $settings ) as $remote => $local ) {
			if ( isset( $targets[ $local ] ) && '' === $targets[ $local ] ) {
				$targets[ $local ] = $remote;
			}
		}

		foreach ( self::id_list( $settings, self::NOTICE_KEY ) as $local ) {
			if ( isset( $targets[ $local ] ) && '' === $targets[ $local ] ) {
				$targets[ $local ] = self::TARGET_NOTICE;
			}
		}

		foreach ( self::id_list( $settings, self::IGNORED_KEY ) as $local ) {
			if ( isset( $targets[ $local ] ) && '' === $targets[ $local ] ) {
				$targets[ $local ] = '-';
			}
		}

		return $targets;
	}

	/**
	 * Proposes a target for every field nobody has decided on yet.
	 *
	 * The guess takes what the label and type suggest; a field RAYNET has no
	 * attribute for is proposed for the note, so its value is not lost. The
	 * consent box is left out: the note records consent on its own switch.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string> Field id => proposed target, for undecided fields only.
	 */
	public static function suggest_targets( array $settings ) {
		$targets    = self::field_targets( $settings );
		$undecided  = array();

		foreach ( self::mappable_fields( $settings ) as $field ) {
			if ( '' === $targets[ $field['id'] ] ) {
				$undecided[] = $field;
			}
		}

		if ( empty( $undecided ) ) {
			return array();
		}

		$guess = array();

		foreach ( self::auto_map( $undecided, self::current_map( $settings ) ) as $row ) {
			if ( '' !== $row['local_id'] && '' === ( $targets[ $row['local_id'] ] ?? 'x' ) && ! isset( $guess[ $row['local_id'] ] ) ) {
				$guess[ $row['local_id'] ] = $row['remote_id'];
			}
		}

		$proposed = array();

		foreach ( $undecided as $field ) {
			if ( isset( $guess[ $field['id'] ] ) ) {
				$proposed[ $field['id'] ] = $guess[ $field['id'] ];
			} else {
				$proposed[ $field['id'] ] = 'acceptance' === $field['type'] ? '-' : self::TARGET_NOTICE;
			}
		}

		return $proposed;
	}

	/**
	 * Checks targets chosen on the mapping screen.
	 *
	 * @param array<string,mixed>  $settings Widget settings.
	 * @param array<string,string> $targets  Field id => target.
	 * @return array<string,string>|WP_Error Clean targets, or why they cannot be saved.
	 */
	public static function validate_targets( array $settings, array $targets ) {
		$fields  = array_column( self::mappable_fields( $settings ), 'label', 'id' );
		$allowed = array_column( Raynet_Lead_Fields::mapping_rows(), 'id' );

		// A custom field mapped before, but not in the fetched list, stays a
		// valid choice: it may simply not have been fetched on this site.
		foreach ( self::current_map( $settings ) as $remote => $local ) {
			if ( Raynet_Lead_Fields::is_custom_id( $remote ) ) {
				$allowed[] = $remote;
			}
		}

		$clean = array();
		$used  = array();

		foreach ( $targets as $field => $target ) {
			$field  = (string) $field;
			$target = (string) $target;

			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}

			if ( '' !== $target && '-' !== $target && self::TARGET_NOTICE !== $target && ! in_array( $target, $allowed, true ) ) {
				return new WP_Error(
					'raynet_unknown_target',
					sprintf(
						/* translators: %s: form field label. */
						__( 'U pole %s je vybraný atribut, který plugin nezná. Načtěte pole z RAYNETu znovu a zkuste to ještě jednou.', 'raynet-lead-api-integration' ),
						'' !== $fields[ $field ] ? $fields[ $field ] : $field
					)
				);
			}

			if ( '' !== $target && '-' !== $target && self::TARGET_NOTICE !== $target ) {
				if ( isset( $used[ $target ] ) ) {
					return new WP_Error(
						'raynet_duplicate_target',
						sprintf(
							/* translators: 1: RAYNET attribute, 2: first form field, 3: second form field. */
							__( 'Atribut %1$s je vybraný u dvou polí: %2$s a %3$s. Každý atribut může plnit jen jedno pole.', 'raynet-lead-api-integration' ),
							html_entity_decode( self::target_label( $target ), ENT_QUOTES, 'UTF-8' ),
							'' !== $fields[ $used[ $target ] ] ? $fields[ $used[ $target ] ] : $used[ $target ],
							'' !== $fields[ $field ] ? $fields[ $field ] : $field
						)
					);
				}

				$used[ $target ] = $field;
			}

			$clean[ $field ] = $target;
		}

		if ( isset( $used['fullName'] ) && ( isset( $used['firstName'] ) || isset( $used['lastName'] ) ) ) {
			return new WP_Error(
				'raynet_name_conflict',
				__( 'Jméno a příjmení v jednom poli nejde kombinovat se samostatným jménem nebo příjmením — jedno by přepsalo druhé.', 'raynet-lead-api-integration' )
			);
		}

		return $clean;
	}

	/**
	 * Writes changed targets into a widget's settings.
	 *
	 * Relative, not absolute: only the fields named in `$changes` are touched,
	 * and only if this form has them. Everything else in the mapping stays as
	 * it was — a field feeding two attributes, an upload mapped in the editor,
	 * a field that exists only in an unpublished draft. The screen shows one
	 * target per field and none of those, so rebuilding the whole mapping from
	 * what it posted would quietly delete them.
	 *
	 * @param array<string,mixed>  $settings Widget settings.
	 * @param array<string,string> $changes  Field id => new target, for changed fields only.
	 * @param bool                 $enable   Turn the RAYNET action on as well.
	 * @return array<string,mixed> Settings.
	 */
	public static function apply_targets( array $settings, array $changes, $enable ) {
		$present = array_map( 'strval', array_column( self::mappable_fields( $settings ), 'id' ) );
		$key     = self::ACTION_NAME . '_fields_map';
		$map     = array();

		foreach ( (array) ( isset( $settings[ $key ] ) ? $settings[ $key ] : array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['remote_id'] ) && ! empty( $row['local_id'] ) ) {
				$map[ (string) $row['remote_id'] ] = (string) $row['local_id'];
			}
		}

		$notice  = self::id_list( $settings, self::NOTICE_KEY );
		$ignored = self::id_list( $settings, self::IGNORED_KEY );

		foreach ( $changes as $field => $target ) {
			$field  = (string) $field;
			$target = (string) $target;

			if ( ! in_array( $field, $present, true ) ) {
				continue;
			}

			// The field's old targets go, whatever they were.
			foreach ( array_keys( $map, $field, true ) as $remote ) {
				unset( $map[ $remote ] );
			}

			$notice  = array_values( array_diff( $notice, array( $field ) ) );
			$ignored = array_values( array_diff( $ignored, array( $field ) ) );

			if ( self::TARGET_NOTICE === $target ) {
				$notice[] = $field;
			} elseif ( '-' === $target ) {
				$ignored[] = $field;
			} elseif ( '' !== $target ) {
				// One attribute, one field: whoever held it before lets go.
				$map[ $target ] = $field;
			}
		}

		$rows   = array();
		$listed = array();

		foreach ( Raynet_Lead_Fields::mapping_rows() as $row ) {
			$listed[ $row['id'] ] = true;
			$rows[]               = array(
				'_id'          => 'map' . ( count( $rows ) + 1 ),
				'remote_id'    => $row['id'],
				'remote_label' => $row['label'],
				'remote_type'  => 'text',
				'local_id'     => isset( $map[ $row['id'] ] ) ? $map[ $row['id'] ] : '',
			);
		}

		foreach ( $map as $remote => $local ) {
			if ( ! isset( $listed[ $remote ] ) && Raynet_Lead_Fields::is_custom_id( $remote ) ) {
				$rows[] = array(
					'_id'          => 'map' . ( count( $rows ) + 1 ),
					'remote_id'    => $remote,
					'remote_label' => esc_html( Raynet_Lead_Fields::custom_name( $remote ) ),
					'remote_type'  => 'text',
					'local_id'     => $local,
				);
			}
		}

		$settings[ $key ]              = $rows;
		$settings[ self::NOTICE_KEY ]  = array_values( array_unique( $notice ) );
		$settings[ self::IGNORED_KEY ] = array_values( array_unique( $ignored ) );

		if ( $enable ) {
			$actions = self::submit_actions( $settings );

			if ( ! in_array( self::ACTION_NAME, $actions, true ) ) {
				$actions[] = self::ACTION_NAME;
			}

			$settings['submit_actions'] = $actions;
		}

		return $settings;
	}

	/**
	 * Other attributes a field feeds besides the one the screen shows.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string[]> Field id => further attribute ids.
	 */
	public static function extra_targets( array $settings ) {
		$shown = self::field_targets( $settings );
		$extra = array();

		foreach ( self::current_map( $settings ) as $remote => $local ) {
			if ( isset( $shown[ $local ] ) && (string) $shown[ $local ] !== (string) $remote ) {
				$extra[ (string) $local ][] = (string) $remote;
			}
		}

		return $extra;
	}

	/**
	 * Saves targets chosen on the mapping screen for one form.
	 *
	 * @param int                  $post_id   Page id.
	 * @param string               $widget_id Form widget id.
	 * @param array<string,string> $targets   Field id => target.
	 * @param bool                 $enable    Turn the RAYNET action on as well.
	 * @return true|WP_Error True on success.
	 */
	public static function save_targets( $post_id, $widget_id, array $targets, $enable ) {
		$settings = self::form_settings( $post_id, $widget_id );

		if ( null === $settings ) {
			return new WP_Error( 'raynet_no_form', __( 'Formulář se na stránce nenašel.', 'raynet-lead-api-integration' ) );
		}

		$clean = self::validate_targets( $settings, $targets );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// What changed is measured against what the screen showed — the
		// published page — and only that is carried into every copy. A draft
		// keeps whatever the editor set there for fields nobody touched here.
		$shown   = self::field_targets( $settings );
		$changes = array();

		foreach ( $clean as $field => $target ) {
			if ( ! isset( $shown[ $field ] ) || (string) $shown[ $field ] !== (string) $target ) {
				$changes[ (string) $field ] = $target;
			}
		}

		$enabled = in_array( self::ACTION_NAME, self::submit_actions( $settings ), true );

		if ( empty( $changes ) && ( $enabled || ! $enable ) ) {
			return true;
		}

		$result = self::update_forms(
			$post_id,
			$widget_id,
			static function ( array $current ) use ( $changes, $enable ) {
				return self::apply_targets( $current, $changes, $enable );
			}
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Human label of a mapping target.
	 *
	 * @param string $target Target id.
	 * @return string Label, HTML-escaped like the mapping rows.
	 */
	public static function target_label( $target ) {
		if ( self::TARGET_NOTICE === $target ) {
			return esc_html__( 'Zapsat do poznámky', 'raynet-lead-api-integration' );
		}

		if ( '-' === $target || '' === $target ) {
			return esc_html__( 'Neodesílat', 'raynet-lead-api-integration' );
		}

		foreach ( Raynet_Lead_Fields::mapping_rows() as $row ) {
			if ( $row['id'] === $target ) {
				return $row['label'];
			}
		}

		return esc_html( Raynet_Lead_Fields::custom_name( $target ) );
	}

	/**
	 * A list of field ids kept in a widget setting.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param string              $key      Setting key.
	 * @return string[] Field ids.
	 */
	public static function id_list( array $settings, $key ) {
		$list = isset( $settings[ $key ] ) ? $settings[ $key ] : array();

		if ( is_string( $list ) ) {
			$list = '' === $list ? array() : explode( ',', $list );
		}

		return array_values( array_filter( array_map( 'strval', (array) $list ), 'strlen' ) );
	}

	/**
	 * Elementor autosaves of a post that are newer than the post, any author.
	 *
	 * Those are what Elementor's editor opens instead of the post: a draft on
	 * a live page exists only there.
	 *
	 * @param int $post_id Post id.
	 * @return int[] Autosave revision ids.
	 */
	public static function pending_autosaves( $post_id ) {
		global $wpdb;

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Mirrors Elementor's own lookup, for every author.
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name LIKE %s AND post_modified_gmt > %s",
				(int) $post_id,
				$wpdb->esc_like( (int) $post_id . '-autosave' ) . '%',
				$post->post_modified_gmt
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Turns the action on in one widget's settings and fills it in.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param array<string,mixed> $lead     Lead settings from the template.
	 * @param bool                $automap  Fill the field mapping by guessing.
	 * @return array<string,mixed> Settings.
	 */
	public static function configure( array $settings, array $lead, $automap = true, $notice_rest = false ) {
		$action  = self::ACTION_NAME;
		$actions = self::submit_actions( $settings );

		if ( ! in_array( $action, $actions, true ) ) {
			$actions[] = $action;
		}

		$settings['submit_actions'] = array_values( $actions );

		$lead = Raynet_Lead_Form_Definition::sanitize_lead_settings( $lead );

		$controls = array(
			'topic'          => 'raynet_crm_topic',
			'priority'       => 'raynet_crm_priority',
			'lead_person'    => 'raynet_crm_lead_person',
			'notice_prefix'  => 'raynet_crm_notice_prefix',
			'tags'           => 'raynet_crm_tags',
			'notify_emails'  => 'raynet_crm_notify_emails',
			'category'       => 'raynet_crm_category',
			'lead_phase'     => 'raynet_crm_lead_phase',
			'contact_source' => 'raynet_crm_contact_source',
			'owner'          => 'raynet_crm_owner',
			'security_level' => 'raynet_crm_security_level',
		);

		foreach ( $controls as $key => $control ) {
			$value = $lead[ $key ];

			// Zero and empty both mean "inherit", and Elementor stores an empty
			// control as an empty string.
			$settings[ $control ] = ( '' === $value || 0 === $value ) ? '' : (string) $value;
		}

		if ( $automap ) {
			// What is mapped already stays; the guess only fills the gaps. Someone
			// may have fixed a mapping by hand in Elementor since the last apply.
			$known = array_column( Raynet_Lead_Fields::mapping_rows(), 'id' );
			$keep  = array();

			foreach ( self::current_map( $settings ) as $remote => $local ) {
				if ( in_array( $remote, $known, true ) || Raynet_Lead_Fields::is_custom_id( $remote ) ) {
					$keep[ $remote ] = $local;
				}
			}

			// A field sent to the note or left out on the mapping screen was
			// decided by someone; the guess must not pull it back into the map.
			$decided   = array_merge( self::id_list( $settings, self::NOTICE_KEY ), self::id_list( $settings, self::IGNORED_KEY ) );
			$candidates = array();

			foreach ( self::readable_fields( $settings ) as $field ) {
				if ( ! in_array( (string) $field['id'], $decided, true ) && ! in_array( $field['type'], self::UNSENDABLE_TYPES, true ) ) {
					$candidates[] = $field;
				}
			}

			$settings[ $action . '_fields_map' ] = self::auto_map( $candidates, $keep );
		}

		// Fields nobody mapped, noted or left out on purpose go to the note, so
		// what a visitor typed reaches the CRM even without an attribute for it.
		if ( $notice_rest ) {
			$targets = self::field_targets( $settings );
			$notice  = self::id_list( $settings, self::NOTICE_KEY );
			$ignored = self::id_list( $settings, self::IGNORED_KEY );

			foreach ( self::mappable_fields( $settings ) as $meta ) {
				if ( ! isset( $targets[ $meta['id'] ] ) || '' !== $targets[ $meta['id'] ] ) {
					continue;
				}

				// The consent box is recorded by its own switch; in the note it
				// would only say "on".
				if ( 'acceptance' === $meta['type'] ) {
					$ignored[] = (string) $meta['id'];
				} else {
					$notice[] = (string) $meta['id'];
				}
			}

			$settings[ self::NOTICE_KEY ]  = array_values( array_unique( $notice ) );
			$settings[ self::IGNORED_KEY ] = array_values( array_unique( $ignored ) );
		}

		return $settings;
	}

	/**
	 * Puts back the layout as it was before the last apply.
	 *
	 * @param int $post_id Page id.
	 * @return true|WP_Error True on success.
	 */
	public static function restore( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		$backup  = get_post_meta( $post_id, self::BACKUP_META, true );

		if ( '' === $backup || ! is_string( $backup ) ) {
			return new WP_Error( 'raynet_no_backup', __( 'Pro tuto stránku není uložená záloha.', 'raynet-lead-api-integration' ) );
		}

		$tree = json_decode( $backup, true );

		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'raynet_bad_backup', __( 'Záloha je poškozená.', 'raynet-lead-api-integration' ) );
		}

		// A draft is newer than the page and carries the applied settings. The
		// page alone going back would leave the draft to bring them back on its
		// next Update, and overwriting the draft would lose its layout work.
		if ( ! empty( self::pending_autosaves( $post_id ) ) ) {
			return new WP_Error(
				'raynet_pending_draft',
				__( 'Stránka má v Elementoru neuložený koncept. Otevřete ji v Elementoru, koncept publikujte nebo zahoďte a vrácení zopakujte.', 'raynet-lead-api-integration' )
			);
		}

		if ( ! $force && self::edited_since( $post_id ) ) {
			return new WP_Error(
				'raynet_stale_backup',
				__( 'Stránka byla od nasazení upravena v Elementoru. Vrácení by o tyto úpravy připravilo.', 'raynet-lead-api-integration' )
			);
		}

		$result = self::write_tree( $post_id, $tree, false );

		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $post_id, self::BACKUP_META );
			delete_post_meta( $post_id, self::STAMP_META );
		}

		return $result;
	}

	/**
	 * Tells whether the page changed after this plugin last wrote it.
	 *
	 * @param int $post_id Page id.
	 * @return bool True when the layout no longer matches what was written.
	 */
	public static function edited_since( $post_id ) {
		$stamp = (string) get_post_meta( (int) $post_id, self::STAMP_META, true );

		if ( '' === $stamp ) {
			// Written by a version that kept no fingerprint; assume the worst.
			return true;
		}

		return $stamp !== self::stamp( (string) get_post_meta( (int) $post_id, self::DATA_META, true ) );
	}

	/**
	 * Fingerprint of a stored layout.
	 *
	 * @param string $raw Layout as stored.
	 * @return string Fingerprint.
	 */
	private static function stamp( $raw ) {
		return md5( (string) $raw );
	}

	/**
	 * Tells whether a page can be rolled back.
	 *
	 * @param int $post_id Page id.
	 * @return bool True when a backup is stored.
	 */
	public static function has_backup( $post_id ) {
		return '' !== (string) get_post_meta( (int) $post_id, self::BACKUP_META, true );
	}

	/**
	 * Saved templates.
	 *
	 * @return array<string,array<string,mixed>> Slug => {name, lead}.
	 */
	public static function templates() {
		$stored = get_option( self::TEMPLATES_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Stores a template.
	 *
	 * @param string              $name Template name.
	 * @param array<string,mixed> $lead Lead settings.
	 * @return string Slug it was stored under.
	 */
	public static function save_template( $name, array $lead ) {
		$name = sanitize_text_field( $name );
		$slug = sanitize_title( $name );

		if ( '' === $slug ) {
			$slug = 'sablona';
		}

		$templates          = self::templates();
		$templates[ $slug ] = array(
			'name' => $name,
			'lead' => Raynet_Lead_Form_Definition::sanitize_lead_settings( $lead ),
		);

		update_option( self::TEMPLATES_OPTION, $templates );

		return $slug;
	}

	/**
	 * Removes a template.
	 *
	 * @param string $slug Template slug.
	 * @return void
	 */
	public static function delete_template( $slug ) {
		$templates = self::templates();

		unset( $templates[ sanitize_title( $slug ) ] );

		update_option( self::TEMPLATES_OPTION, $templates );
	}

	/**
	 * Reads a page's Elementor layout.
	 *
	 * @param int $post_id Page id.
	 * @return array<int,mixed> Tree, or an empty array.
	 */
	private static function read_tree( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::DATA_META, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$tree = json_decode( $raw, true );

		return is_array( $tree ) ? $tree : array();
	}

	/**
	 * Writes a page's Elementor layout back.
	 *
	 * @param int              $post_id Page id.
	 * @param array<int,mixed> $tree    Layout.
	 * @param bool             $backup  Keep the previous layout first.
	 * @return true|WP_Error True on success.
	 */
	private static function write_tree( $post_id, array $tree, $backup ) {
		$encoded = wp_json_encode( $tree );

		if ( false === $encoded ) {
			return new WP_Error( 'raynet_encode_failed', __( 'Data Elementoru se nepodařilo zakódovat.', 'raynet-lead-api-integration' ) );
		}

		// The rollback point is the page as it was before this plugin started
		// changing it. Two applies in a row — a page holding two forms gets one
		// per form — keep the first point, or it would be replaced by a
		// half-applied copy. But once the page has been edited in Elementor
		// since the last apply, the old point would undo that work too, so the
		// page as it is now becomes the new one.
		if ( $backup && ( ! metadata_exists( 'post', (int) $post_id, self::BACKUP_META ) || self::edited_since( $post_id ) ) ) {
			$previous = get_post_meta( (int) $post_id, self::DATA_META, true );

			if ( is_string( $previous ) && '' !== $previous ) {
				update_post_meta( (int) $post_id, self::BACKUP_META, wp_slash( $previous ) );
			}
		}

		update_post_meta( (int) $post_id, self::DATA_META, wp_slash( $encoded ) );
		update_post_meta( (int) $post_id, self::STAMP_META, self::stamp( $encoded ) );

		// Elementor caches the rendered CSS per page; the settings changed here
		// do not affect it, but a stale cache after a rollback would be worse
		// than the cost of rebuilding it.
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return true;
	}

	/**
	 * Walks every element of a layout, depth first.
	 *
	 * @param array<int,mixed> $elements Elements, by reference so a visitor can edit them.
	 * @param callable         $visitor  Called with each element by reference.
	 * @return void
	 */
	private static function walk( array &$elements, callable $visitor ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$visitor( $element );

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $visitor );
			}
		}

		unset( $element );
	}

	/**
	 * Link that opens a page in the Elementor editor.
	 *
	 * @param int $post_id Page id.
	 * @return string URL.
	 */
	private static function edit_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' );
	}
}
