<?php
/**
 * Forms as a custom post type: registration, storage and lookup.
 *
 * WordPress supplies the list table, the trash and the capability checks, so a
 * form that is embedded on three pages cannot be deleted beyond recovery.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage and lookup for RAYNET forms.
 */
class Raynet_Lead_Form_Post_Type {

	/**
	 * Post type name.
	 */
	const POST_TYPE = 'raynet_form';

	/**
	 * Meta key holding the field definitions.
	 */
	const META_FIELDS = '_raynet_form_fields';

	/**
	 * Meta key holding the per-form lead settings.
	 */
	const META_LEAD = '_raynet_form_lead';

	/**
	 * Option holding the id of the form the bare shortcode renders.
	 */
	const OPTION_DEFAULT = 'raynet_lead_default_form';

	/**
	 * Registers the post type.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Formuláře', 'raynet-lead-api-integration' ),
					'singular_name'      => __( 'Formulář', 'raynet-lead-api-integration' ),
					'add_new'            => __( 'Přidat formulář', 'raynet-lead-api-integration' ),
					'add_new_item'       => __( 'Přidat formulář', 'raynet-lead-api-integration' ),
					'edit_item'          => __( 'Upravit formulář', 'raynet-lead-api-integration' ),
					'new_item'           => __( 'Nový formulář', 'raynet-lead-api-integration' ),
					'search_items'       => __( 'Hledat formuláře', 'raynet-lead-api-integration' ),
					'not_found'          => __( 'Žádné formuláře', 'raynet-lead-api-integration' ),
					'not_found_in_trash' => __( 'V koši nejsou žádné formuláře', 'raynet-lead-api-integration' ),
					'menu_name'          => __( 'Formuláře', 'raynet-lead-api-integration' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'raynet-lead-integration',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
					'delete_posts'       => 'manage_options',
					'create_posts'       => 'manage_options',
				),
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
				'has_archive'     => false,
			)
		);
	}

	/**
	 * Creates a form.
	 *
	 * @param string                          $title  Form title.
	 * @param array<int,array<string,mixed>>  $fields Field definitions.
	 * @param array<string,mixed>             $lead   Per-form lead settings.
	 * @return int New post id, or 0 on failure.
	 */
	public static function create( $title, array $fields, array $lead ) {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		self::save_fields( $post_id, $fields );
		self::save_lead_settings( $post_id, $lead );

		return (int) $post_id;
	}

	/**
	 * Returns a form's field definitions.
	 *
	 * The stored value is sanitized again on read, so a definition written by an
	 * older version or edited in the database cannot reach the renderer raw.
	 *
	 * @param int $post_id Form id.
	 * @return array<int,array<string,mixed>> Field definitions.
	 */
	public static function get_fields( $post_id ) {
		return Raynet_Lead_Form_Definition::sanitize_fields(
			get_post_meta( (int) $post_id, self::META_FIELDS, true )
		);
	}

	/**
	 * Returns a form's lead settings.
	 *
	 * @param int $post_id Form id.
	 * @return array<string,mixed> Lead settings.
	 */
	public static function get_lead_settings( $post_id ) {
		return Raynet_Lead_Form_Definition::sanitize_lead_settings(
			get_post_meta( (int) $post_id, self::META_LEAD, true )
		);
	}

	/**
	 * Stores a form's field definitions.
	 *
	 * @param int   $post_id Form id.
	 * @param array $fields  Field definitions.
	 * @return void
	 */
	public static function save_fields( $post_id, array $fields ) {
		update_post_meta(
			(int) $post_id,
			self::META_FIELDS,
			Raynet_Lead_Form_Definition::sanitize_fields( $fields )
		);
	}

	/**
	 * Stores a form's lead settings.
	 *
	 * @param int   $post_id Form id.
	 * @param array $lead    Lead settings.
	 * @return void
	 */
	public static function save_lead_settings( $post_id, array $lead ) {
		update_post_meta(
			(int) $post_id,
			self::META_LEAD,
			Raynet_Lead_Form_Definition::sanitize_lead_settings( $lead )
		);
	}

	/**
	 * Resolves a shortcode's id attribute to a form.
	 *
	 * Accepts a post id, a slug, or an empty value meaning "the default form".
	 *
	 * @param string|int $id Shortcode id attribute.
	 * @return int Form id, or 0 when nothing usable was found.
	 */
	public static function resolve( $id ) {
		$id = trim( (string) $id );

		if ( '' === $id ) {
			return self::is_usable( self::default_id() ) ? self::default_id() : 0;
		}

		if ( ctype_digit( $id ) ) {
			return self::is_usable( (int) $id ) ? (int) $id : 0;
		}

		$post = get_page_by_path( sanitize_title( $id ), OBJECT, self::POST_TYPE );

		if ( ! $post ) {
			return 0;
		}

		return self::is_usable( (int) $post->ID ) ? (int) $post->ID : 0;
	}

	/**
	 * Returns the id of the form the bare shortcode renders.
	 *
	 * @return int Form id, or 0.
	 */
	public static function default_id() {
		return (int) get_option( self::OPTION_DEFAULT, 0 );
	}

	/**
	 * Marks a form as the one the bare shortcode renders.
	 *
	 * @param int $post_id Form id.
	 * @return void
	 */
	public static function set_default( $post_id ) {
		update_option( self::OPTION_DEFAULT, (int) $post_id );
	}

	/**
	 * Creates the form a 2.0 site was already rendering, once.
	 *
	 * The field list is the one the 2.0 shortcode produced by default, so pages
	 * that already carry [raynet_lead_form] keep rendering what they rendered
	 * before the upgrade.
	 *
	 * Lead settings are left empty on purpose. Empty means inherit, so the
	 * global settings stay the single place those values are edited, and a later
	 * change to them still reaches this form.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( 'raynet_lead_migrated_forms' ) ) {
			return;
		}

		if ( self::resolve( '' ) ) {
			// A default form already exists; nothing to carry over.
			update_option( 'raynet_lead_migrated_forms', 1 );
			return;
		}

		$settings = Raynet_Lead_Settings::all();
		$fields   = array();

		foreach ( Raynet_Lead_Form_Definition::default_fields() as $field ) {
			if ( 'consent' === $field['source'] ) {
				if ( empty( $settings['consent_enabled'] ) ) {
					continue;
				}

				if ( '' !== trim( (string) $settings['consent_label'] ) ) {
					$field['label'] = $settings['consent_label'];
				}
			}

			// The 2.0 shortcode defaulted to required="email,message".
			if ( Raynet_Lead_Form_Definition::is_lead_source( $field['source'] ) ) {
				$field['required'] = in_array( $field['source'], array( 'email', 'message' ), true );
			}

			$fields[] = $field;
		}

		$post_id = self::create(
			__( 'Kontaktní formulář', 'raynet-lead-api-integration' ),
			$fields,
			array()
		);

		if ( $post_id ) {
			self::set_default( $post_id );
		}

		update_option( 'raynet_lead_migrated_forms', 1 );
	}

	/**
	 * Tells whether a post id names a published form.
	 *
	 * @param int $post_id Candidate id.
	 * @return bool True when it can be rendered.
	 */
	private static function is_usable( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}

		$post = get_post( (int) $post_id );

		return $post
			&& self::POST_TYPE === $post->post_type
			&& 'publish' === $post->post_status;
	}
}
