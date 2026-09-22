<?php
/**
 * Canonical shape of a form's field definitions and per-form lead settings.
 *
 * Everything that reaches storage passes through here, so the builder screen,
 * the shortcode and the submission handler all read the same shape. Input from
 * the browser only ever proposes values; this class decides what they become.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field and lead-setting sanitizer.
 */
class Raynet_Lead_Form_Definition {

	/**
	 * Lead attributes a field can feed, mirroring Raynet_Lead_Form::SUPPORTED_FIELDS.
	 */
	const SOURCES = array(
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
	 * Input types a custom field may choose.
	 */
	const CUSTOM_TYPES = array( 'text', 'textarea', 'select', 'checkbox' );

	/**
	 * Counter making ids unique within one sanitize_fields() call.
	 *
	 * @var int
	 */
	private static $id_counter = 0;

	/**
	 * Every source a field can have, with its default label and input type.
	 *
	 * @return array<string,array<string,string>> Source => meta.
	 */
	public static function catalogue() {
		return array(
			'firstName'   => array( 'label' => __( 'Jméno', 'raynet-lead-api-integration' ), 'autocomplete' => 'given-name' ),
			'lastName'    => array( 'label' => __( 'Příjmení', 'raynet-lead-api-integration' ), 'autocomplete' => 'family-name' ),
			'companyName' => array( 'label' => __( 'Společnost', 'raynet-lead-api-integration' ), 'autocomplete' => 'organization' ),
			'email'       => array( 'label' => __( 'E-mail', 'raynet-lead-api-integration' ), 'autocomplete' => 'email' ),
			'phone'       => array( 'label' => __( 'Telefon', 'raynet-lead-api-integration' ), 'autocomplete' => 'tel' ),
			'topic'       => array( 'label' => __( 'Předmět', 'raynet-lead-api-integration' ), 'autocomplete' => 'off' ),
			'message'     => array( 'label' => __( 'Zpráva', 'raynet-lead-api-integration' ), 'autocomplete' => 'off' ),
			'street'      => array( 'label' => __( 'Ulice', 'raynet-lead-api-integration' ), 'autocomplete' => 'street-address' ),
			'city'        => array( 'label' => __( 'Město', 'raynet-lead-api-integration' ), 'autocomplete' => 'address-level2' ),
			'zipCode'     => array( 'label' => __( 'PSČ', 'raynet-lead-api-integration' ), 'autocomplete' => 'postal-code' ),
			'consent'     => array( 'label' => __( 'Souhlasím se zpracováním osobních údajů za účelem vyřízení mé poptávky.', 'raynet-lead-api-integration' ), 'autocomplete' => 'off' ),
		);
	}

	/**
	 * Input type a source dictates.
	 *
	 * Only custom fields pick their own type; letting an admin turn the e-mail
	 * field into a textarea would break both validation and the RAYNET mapping.
	 *
	 * @param string $source Field source.
	 * @return string Input type.
	 */
	public static function type_for_source( $source ) {
		switch ( $source ) {
			case 'email':
				return 'email';

			case 'phone':
				return 'tel';

			case 'message':
				return 'textarea';

			case 'consent':
				return 'consent';
		}

		return 'text';
	}

	/**
	 * Tells whether a source feeds a RAYNET lead attribute.
	 *
	 * @param string $source Field source.
	 * @return bool True for the ten mapped attributes.
	 */
	public static function is_lead_source( $source ) {
		return in_array( $source, self::SOURCES, true );
	}

	/**
	 * Brings a whole field list into canonical shape.
	 *
	 * Drops unknown sources, repeated lead attributes, a second consent box and
	 * custom fields with no label. Order is preserved.
	 *
	 * @param mixed $raw Raw list, typically decoded from the builder's JSON.
	 * @return array<int,array<string,mixed>> Clean definitions.
	 */
	public static function sanitize_fields( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		$used  = array();

		foreach ( $raw as $row ) {
			$field = self::sanitize_field( $row, $used );

			if ( null === $field ) {
				continue;
			}

			if ( 'custom' !== $field['source'] ) {
				$used[] = $field['source'];
			}

			$clean[] = $field;
		}

		return $clean;
	}

	/**
	 * Brings one field into canonical shape.
	 *
	 * @param mixed    $raw  Raw definition.
	 * @param string[] $used Sources already taken by earlier fields.
	 * @return array<string,mixed>|null Clean definition, or null when it must be dropped.
	 */
	public static function sanitize_field( $raw, array $used = array() ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$catalogue = self::catalogue();
		$source    = isset( $raw['source'] ) ? (string) $raw['source'] : '';

		$known = 'custom' === $source || isset( $catalogue[ $source ] );

		if ( ! $known || in_array( $source, $used, true ) ) {
			return null;
		}

		$label = isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '';

		if ( '' === trim( $label ) ) {
			if ( 'custom' === $source ) {
				// A custom field with no label has nothing to write into the note.
				return null;
			}

			$label = $catalogue[ $source ]['label'];
		}

		if ( 'custom' === $source ) {
			$type = isset( $raw['type'] ) ? (string) $raw['type'] : 'text';
			$type = in_array( $type, self::CUSTOM_TYPES, true ) ? $type : 'text';
		} else {
			$type = self::type_for_source( $source );
		}

		$width = isset( $raw['width'] ) && 'half' === $raw['width'] ? 'half' : 'full';

		if ( in_array( $type, array( 'textarea', 'consent', 'checkbox' ), true ) ) {
			$width = 'full';
		}

		$options = array();

		if ( 'select' === $type ) {
			foreach ( (array) ( isset( $raw['options'] ) ? $raw['options'] : array() ) as $option ) {
				$option = sanitize_text_field( (string) $option );

				if ( '' !== trim( $option ) ) {
					$options[] = $option;
				}
			}
		}

		$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

		if ( ! preg_match( '/^f_[a-z0-9]{6}$/', $id ) ) {
			$id = self::new_id( $raw );
		}

		return array(
			'id'          => $id,
			'source'      => $source,
			'type'        => $type,
			'label'       => $label,
			'placeholder' => isset( $raw['placeholder'] ) ? sanitize_text_field( (string) $raw['placeholder'] ) : '',
			'help'        => isset( $raw['help'] ) ? sanitize_text_field( (string) $raw['help'] ) : '',
			// A consent box that is optional is worse than none at all.
			'required'    => 'consent' === $source ? true : ! empty( $raw['required'] ),
			'width'       => $width,
			'options'     => $options,
		);
	}

	/**
	 * The field list a freshly created form starts with.
	 *
	 * @return array<int,array<string,mixed>> Clean definitions.
	 */
	public static function default_fields() {
		return self::sanitize_fields(
			array(
				array( 'source' => 'firstName', 'width' => 'half' ),
				array( 'source' => 'lastName', 'width' => 'half' ),
				array( 'source' => 'email', 'required' => true ),
				array( 'source' => 'phone' ),
				array( 'source' => 'message', 'required' => true ),
				array( 'source' => 'consent' ),
			)
		);
	}

	/**
	 * Keys of the per-form lead settings, with the value meaning "inherit".
	 *
	 * @return array<string,mixed> Defaults.
	 */
	public static function lead_defaults() {
		return array(
			'topic'           => '',
			'priority'        => '',
			'lead_person'     => '',
			'notice_prefix'   => '',
			'category'        => 0,
			'lead_phase'      => 0,
			'contact_source'  => 0,
			'owner'           => 0,
			'security_level'  => 0,
			'tags'            => '',
			'notify_emails'   => '',
			'success_message' => '',
			'redirect_url'    => '',
		);
	}

	/**
	 * Brings the per-form lead settings into canonical shape.
	 *
	 * An empty string, or zero for the numeric ids, means "inherit from the
	 * plugin settings".
	 *
	 * @param mixed $raw Raw settings.
	 * @return array<string,mixed> Clean settings.
	 */
	public static function sanitize_lead_settings( $raw ) {
		$clean = self::lead_defaults();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$priority          = isset( $raw['priority'] ) ? strtoupper( sanitize_text_field( (string) $raw['priority'] ) ) : '';
		$clean['priority'] = in_array( $priority, Raynet_Lead_Settings::priorities(), true ) ? $priority : '';

		$person               = isset( $raw['lead_person'] ) ? (string) $raw['lead_person'] : '';
		$clean['lead_person'] = in_array( $person, array( '0', '1' ), true ) ? $person : '';

		$clean['topic']           = isset( $raw['topic'] ) ? sanitize_text_field( (string) $raw['topic'] ) : '';
		$clean['notice_prefix']   = isset( $raw['notice_prefix'] ) ? sanitize_textarea_field( (string) $raw['notice_prefix'] ) : '';
		$clean['tags']            = isset( $raw['tags'] ) ? sanitize_text_field( (string) $raw['tags'] ) : '';
		$clean['success_message'] = isset( $raw['success_message'] ) ? sanitize_text_field( (string) $raw['success_message'] ) : '';
		$clean['redirect_url']    = isset( $raw['redirect_url'] ) ? esc_url_raw( trim( (string) $raw['redirect_url'] ) ) : '';

		foreach ( array( 'category', 'lead_phase', 'contact_source', 'owner', 'security_level' ) as $numeric ) {
			$clean[ $numeric ] = isset( $raw[ $numeric ] ) ? max( 0, (int) $raw[ $numeric ] ) : 0;
		}

		$clean['notify_emails'] = isset( $raw['notify_emails'] )
			? implode( ',', Raynet_Lead_Settings::sanitize_email_list( (string) $raw['notify_emails'] ) )
			: '';

		return $clean;
	}

	/**
	 * Mints an id for a field that has none.
	 *
	 * Ids name custom fields in the submitted HTML, so they have to survive a
	 * label change. A counter keeps them distinct within one call.
	 *
	 * @param mixed $raw Raw definition, mixed into the hash.
	 * @return string Field id.
	 */
	private static function new_id( $raw ) {
		self::$id_counter++;

		$seed = 'raynet_field|' . self::$id_counter . '|' . wp_json_encode( $raw );

		return 'f_' . substr( wp_hash( $seed ), 0, 6 );
	}
}
