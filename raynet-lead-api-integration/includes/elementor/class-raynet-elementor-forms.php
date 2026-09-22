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
			'companyName' => array( 'firma', 'firmy', 'spolecnost*', 'company', 'organizace', 'ico' ),
			'email'       => array( 'mail*', 'e-mail' ),
			'phone'       => array( 'telefon*', 'mobil*', 'phone', 'tel' ),
			'topic'       => array( 'predmet*', 'subject', 'tema', 'nadpis' ),
			'message'     => array( 'zprav*', 'poznamk*', 'dotaz*', 'poptavk*', 'vzkaz*', 'message', 'komentar*' ),
			'street'      => array( 'ulice', 'adresa', 'adresu', 'street', 'address' ),
			'city'        => array( 'mest*', 'obec', 'obce', 'city' ),
			'zipCode'     => array( 'psc', 'zip', 'postal*' ),
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
		);
	}

	/**
	 * Lists every Elementor form on the site.
	 *
	 * @return array<int,array<string,mixed>> One entry per form widget.
	 */
	public static function scan() {
		$posts = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 200,
				'meta_key'         => self::DATA_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen, bounded.
				'fields'           => 'ids',
				'suppress_filters' => true,
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
				if ( ! isset( $element['widgetType'] ) || 'form' !== $element['widgetType'] ) {
					return;
				}

				$settings = isset( $element['settings'] ) ? (array) $element['settings'] : array();
				$actions  = isset( $settings['submit_actions'] ) ? (array) $settings['submit_actions'] : array();

				$found[] = array(
					'post_id'   => $post_id,
					'post_title' => get_the_title( $post_id ),
					'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : '',
					'form_name' => isset( $settings['form_name'] ) ? (string) $settings['form_name'] : '',
					'fields'    => self::readable_fields( $settings ),
					'enabled'   => in_array( self::ACTION_NAME, $actions, true ),
					'mapped'    => self::mapped_count( $settings ),
					'edit_url'  => self::edit_url( $post_id ),
				);
			}
		);

		return $found;
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
	 * Guesses which form field feeds which RAYNET attribute.
	 *
	 * The field type decides where it can, because an Email field is an e-mail
	 * whatever it is called. Labels settle the rest. Each field is used once.
	 *
	 * @param array<int,array<string,string>> $fields Form fields.
	 * @return array<int,array<string,string>> Rows for the mapping control.
	 */
	public static function auto_map( array $fields ) {
		$usable = array();

		foreach ( $fields as $field ) {
			if ( ! in_array( $field['type'], self::skip_types(), true ) ) {
				$usable[] = $field;
			}
		}

		$matched = self::match_fields( $usable, array() );

		// Asking for the whole name and one half of it at once would have one
		// overwrite the other. The halves are the more precise answer, so the
		// whole-name field is released and the pass run again without it.
		if ( isset( $matched['fullName'] ) && ( isset( $matched['firstName'] ) || isset( $matched['lastName'] ) ) ) {
			$matched = self::match_fields( $usable, array( 'fullName' ) );
		}

		$catalogue = Raynet_Lead_Form_Definition::catalogue();
		$rows      = array();
		$index     = 0;

		foreach ( $catalogue as $source => $meta ) {
			if ( 'consent' === $source ) {
				continue;
			}

			$index++;
			$rows[] = array(
				'_id'          => 'auto' . $index,
				'remote_id'    => $source,
				'remote_label' => $meta['label'],
				'remote_type'  => 'text',
				'local_id'     => isset( $matched[ $source ] ) ? $matched[ $source ] : '',
			);
		}

		return $rows;
	}

	/**
	 * Runs one matching pass over the fields.
	 *
	 * Labels are read first and each field goes to the highest-priority
	 * attribute its label fits, so "E-mailová adresa" cannot be taken for a
	 * street just because the e-mail slot was already filled. The field type
	 * then settles whatever the labels left open.
	 *
	 * @param array<int,array<string,string>> $fields   Usable form fields.
	 * @param string[]                        $excluded Sources to leave unmatched.
	 * @return array<string,string> Source => field id.
	 */
	private static function match_fields( array $fields, array $excluded ) {
		$matched = array();
		$taken   = array();

		foreach ( $fields as $field ) {
			$best = self::best_source( $field['label'], $excluded );

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
	 * Applies a template to one form.
	 *
	 * @param int                  $post_id    Page holding the forms.
	 * @param string|string[]      $widget_ids One or more form widget ids.
	 * @param array<string,mixed>  $lead       Lead settings from the template.
	 * @param bool                 $automap    Fill the field mapping by guessing.
	 * @return true|WP_Error True on success.
	 */
	public static function apply( $post_id, $widget_ids, array $lead, $automap = true ) {
		$widget_ids = array_values( array_filter( array_map( 'strval', (array) $widget_ids ), 'strlen' ) );

		if ( empty( $widget_ids ) ) {
			// An empty id used to match every form widget on the page, which is
			// not something any caller asks for on purpose.
			return new WP_Error( 'raynet_no_widget', __( 'Formulář nemá identifikátor, nelze ho nastavit.', 'raynet-lead-api-integration' ) );
		}

		$tree = self::read_tree( $post_id );

		if ( empty( $tree ) ) {
			return new WP_Error( 'raynet_no_data', __( 'Stránka nemá data Elementoru.', 'raynet-lead-api-integration' ) );
		}

		$touched = false;

		self::walk(
			$tree,
			static function ( &$element ) use ( $widget_ids, $lead, $automap, &$touched ) {
				if ( ! isset( $element['widgetType'] ) || 'form' !== $element['widgetType'] ) {
					return;
				}

				if ( ! isset( $element['id'] ) || ! in_array( (string) $element['id'], $widget_ids, true ) ) {
					return;
				}

				$element['settings'] = self::configure( isset( $element['settings'] ) ? (array) $element['settings'] : array(), $lead, $automap );
				$touched             = true;
			}
		);

		if ( ! $touched ) {
			return new WP_Error( 'raynet_no_form', __( 'Formulář se na stránce nenašel.', 'raynet-lead-api-integration' ) );
		}

		return self::write_tree( $post_id, $tree, true );
	}

	/**
	 * Turns the action on in one widget's settings and fills it in.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param array<string,mixed> $lead     Lead settings from the template.
	 * @param bool                $automap  Fill the field mapping by guessing.
	 * @return array<string,mixed> Settings.
	 */
	public static function configure( array $settings, array $lead, $automap = true ) {
		$action  = self::ACTION_NAME;
		$actions = isset( $settings['submit_actions'] ) ? (array) $settings['submit_actions'] : array();

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
			$settings[ $action . '_fields_map' ] = self::auto_map( self::readable_fields( $settings ) );
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

		if ( $backup && ! metadata_exists( 'post', (int) $post_id, self::BACKUP_META ) ) {
			$previous = get_post_meta( (int) $post_id, self::DATA_META, true );

			// Only the first write keeps a rollback point. Applying twice to one
			// page — which a page holding two forms does in a single submit —
			// would otherwise replace the original with the half-applied copy.
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
