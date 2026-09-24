<?php
/**
 * Every lead attribute a form field can be mapped to.
 *
 * The form builder offers a short, fixed list. A mapping made in Elementor can
 * reach further: the rest of RAYNET's standard lead attributes and the custom
 * fields an instance defines for itself. This class knows both, keeps the
 * custom field configuration fetched from RAYNET, and turns what a visitor
 * typed into the value each attribute accepts.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry of standard and custom lead attributes.
 */
class Raynet_Lead_Fields {

	/**
	 * Option holding the custom field configuration fetched from RAYNET.
	 */
	const OPTION = 'raynet_lead_custom_fields';

	/**
	 * Prefix that marks a mapping row as a custom field.
	 *
	 * RAYNET generates custom field names such as "Cislo_klie_cd702", which
	 * cannot collide with the camelCase standard names, but the prefix keeps the
	 * two apart without having to reason about it.
	 */
	const CUSTOM_PREFIX = 'cf:';

	/**
	 * How long a fetched configuration is trusted before it is fetched again.
	 */
	const MAX_AGE = 12 * HOUR_IN_SECONDS;

	/**
	 * How long to wait after a failed fetch before trying again on its own.
	 *
	 * RAYNET blocks an IP for an hour after 20 bad logins, so a screen that
	 * retried on every load with wrong credentials would lock the site out.
	 */
	const RETRY_AFTER = HOUR_IN_SECONDS;

	/**
	 * ISO 3166-1 alpha-2 codes, the only country values RAYNET accepts.
	 */
	const ISO_COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

	/**
	 * Custom field types a form value can be turned into.
	 *
	 * FILE is left out: a form upload is a path on this server, not something
	 * RAYNET can store.
	 */
	const CUSTOM_TYPES = array( 'STRING', 'TEXT', 'HYPERLINK', 'BIG_DECIMAL', 'MONETARY', 'PERCENT', 'BOOLEAN', 'DATE', 'DATETIME', 'TIME', 'ENUMERATION' );

	/**
	 * Standard lead attributes beyond the ones the form builder offers.
	 *
	 * `path` is where the value goes in the lead payload; a dot means a nested
	 * object. `kind` decides how the value is cleaned.
	 *
	 * @return array<string,array<string,string>> Key => {label, path, kind}.
	 */
	public static function extended() {
		return array(
			'titleBefore'      => array( 'label' => __( 'Titul před jménem', 'raynet-lead-api-integration' ), 'path' => 'titleBefore', 'kind' => 'text' ),
			'titleAfter'       => array( 'label' => __( 'Titul za jménem', 'raynet-lead-api-integration' ), 'path' => 'titleAfter', 'kind' => 'text' ),
			'regNumber'        => array( 'label' => __( 'IČO', 'raynet-lead-api-integration' ), 'path' => 'regNumber', 'kind' => 'text' ),
			'taxNumber'        => array( 'label' => __( 'DIČ', 'raynet-lead-api-integration' ), 'path' => 'taxNumber', 'kind' => 'text' ),
			'databox'          => array( 'label' => __( 'Datová schránka', 'raynet-lead-api-integration' ), 'path' => 'databox', 'kind' => 'text' ),
			'email2'           => array( 'label' => __( 'E-mail 2', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.email2', 'kind' => 'email' ),
			'phone2'           => array( 'label' => __( 'Telefon 2', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.tel2', 'kind' => 'text' ),
			'www'              => array( 'label' => __( 'Web', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.www', 'kind' => 'text' ),
			'fax'              => array( 'label' => __( 'Fax', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.fax', 'kind' => 'text' ),
			'otherContact'     => array( 'label' => __( 'Jiný kontakt', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.otherContact', 'kind' => 'text' ),
			'gdprConsent'      => array( 'label' => __( 'Souhlas se zpracováním údajů (GDPR)', 'raynet-lead-api-integration' ), 'path' => '', 'kind' => 'consent' ),
			'marketingConsent' => array( 'label' => __( 'Souhlas s marketingovými sděleními', 'raynet-lead-api-integration' ), 'path' => 'contactInfo.doNotSendMM', 'kind' => 'optin' ),
			'province'         => array( 'label' => __( 'Kraj', 'raynet-lead-api-integration' ), 'path' => 'address.province', 'kind' => 'text' ),
			'country'          => array( 'label' => __( 'Země', 'raynet-lead-api-integration' ), 'path' => 'address.country', 'kind' => 'country' ),
			'facebook'         => array( 'label' => 'Facebook', 'path' => 'socialNetworkContact.facebook', 'kind' => 'text' ),
			'instagram'        => array( 'label' => 'Instagram', 'path' => 'socialNetworkContact.instagram', 'kind' => 'text' ),
			'linkedin'         => array( 'label' => 'LinkedIn', 'path' => 'socialNetworkContact.linkedin', 'kind' => 'text' ),
			'twitter'          => array( 'label' => 'X (Twitter)', 'path' => 'socialNetworkContact.twitter', 'kind' => 'text' ),
			'youtube'          => array( 'label' => 'YouTube', 'path' => 'socialNetworkContact.youtube', 'kind' => 'text' ),
			'tiktok'           => array( 'label' => 'TikTok', 'path' => 'socialNetworkContact.tiktok', 'kind' => 'text' ),
			'whatsapp'         => array( 'label' => 'WhatsApp', 'path' => 'socialNetworkContact.whatsapp', 'kind' => 'text' ),
			'skype'            => array( 'label' => 'Skype', 'path' => 'socialNetworkContact.skype', 'kind' => 'text' ),
			'pinterest'        => array( 'label' => 'Pinterest', 'path' => 'socialNetworkContact.pinterest', 'kind' => 'text' ),
			'threads'          => array( 'label' => 'Threads', 'path' => 'socialNetworkContact.threads', 'kind' => 'text' ),
		);
	}

	/**
	 * Tells whether a key is one of the extended standard attributes.
	 *
	 * @param string $key Attribute key.
	 * @return bool True when known.
	 */
	public static function is_extended( $key ) {
		return isset( self::extended()[ (string) $key ] );
	}

	/**
	 * Tells whether a mapping id points at a custom field.
	 *
	 * @param string $remote_id Mapping id.
	 * @return bool True for the custom field prefix.
	 */
	public static function is_custom_id( $remote_id ) {
		return 0 === strpos( (string) $remote_id, self::CUSTOM_PREFIX );
	}

	/**
	 * Custom field name out of a mapping id.
	 *
	 * @param string $remote_id Mapping id.
	 * @return string Field name, or an empty string.
	 */
	public static function custom_name( $remote_id ) {
		if ( ! self::is_custom_id( $remote_id ) ) {
			return '';
		}

		return self::sanitize_name( substr( (string) $remote_id, strlen( self::CUSTOM_PREFIX ) ) );
	}

	/**
	 * Keeps a custom field name to what RAYNET generates.
	 *
	 * The name ends up as a JSON key in the payload; anything outside letters,
	 * digits and underscores is not a name RAYNET produced.
	 *
	 * @param string $name Raw name.
	 * @return string Clean name.
	 */
	public static function sanitize_name( $name ) {
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
	}

	/**
	 * Stored custom field state.
	 *
	 * @return array{fields:array<string,array<string,mixed>>,fetched_at:int,failed_at:int,error:string} State.
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'fields'     => isset( $stored['fields'] ) && is_array( $stored['fields'] ) ? $stored['fields'] : array(),
			'fetched_at' => isset( $stored['fetched_at'] ) ? (int) $stored['fetched_at'] : 0,
			'failed_at'  => isset( $stored['failed_at'] ) ? (int) $stored['failed_at'] : 0,
			'error'      => isset( $stored['error'] ) ? (string) $stored['error'] : '',
		);
	}

	/**
	 * Custom lead fields of the connected instance.
	 *
	 * @return array<string,array<string,mixed>> Name => {label, type, enum, group}.
	 */
	public static function custom() {
		return self::state()['fields'];
	}

	/**
	 * Fetches the custom field configuration from RAYNET and stores it.
	 *
	 * A failure keeps the previous configuration, so a RAYNET outage does not
	 * make mapped fields vanish from the editor.
	 *
	 * @param Raynet_Lead_Api_Client|null $client Client, or null for the configured one.
	 * @return int|WP_Error Number of custom lead fields, or an error.
	 */
	public static function refresh( $client = null ) {
		$client = $client ? $client : Raynet_Lead_Api_Client::from_settings();
		$state  = self::state();
		$config = $client->get_custom_field_config();

		if ( is_wp_error( $config ) ) {
			$state['failed_at'] = time();
			$state['error']     = $config->get_error_message();
			update_option( self::OPTION, $state, false );

			return $config;
		}

		$rows   = isset( $config['Lead'] ) && is_array( $config['Lead'] ) ? $config['Lead'] : array();
		$fields = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}

			$name = self::sanitize_name( $row['name'] );
			$type = isset( $row['dataType'] ) ? strtoupper( (string) $row['dataType'] ) : 'STRING';

			// A read-only field cannot be written, and a type we cannot fill from
			// a form would only ever produce a rejected lead.
			if ( '' === $name || ! empty( $row['readOnly'] ) || ! in_array( $type, self::CUSTOM_TYPES, true ) ) {
				continue;
			}

			$enum = array();

			// Items are kept exactly as RAYNET knows them: an item goes back to
			// RAYNET verbatim, and sanitize_text_field() would turn
			// "< 10 zaměstnanců" into "&lt; 10 zaměstnanců", which RAYNET does
			// not have. Everything printed from here is escaped on output.
			if ( 'ENUMERATION' === $type && isset( $row['enumeration'] ) && is_array( $row['enumeration'] ) ) {
				foreach ( $row['enumeration'] as $item ) {
					$item = is_scalar( $item ) ? trim( wp_check_invalid_utf8( (string) $item ) ) : '';

					if ( '' !== $item ) {
						$enum[] = $item;
					}
				}
			}

			$label = isset( $row['label'] ) ? trim( wp_strip_all_tags( wp_check_invalid_utf8( (string) $row['label'] ) ) ) : '';

			$fields[ $name ] = array(
				'label' => '' !== $label ? $label : $name,
				'type'  => $type,
				'enum'  => $enum,
				'group' => isset( $row['groupName'] ) ? trim( wp_strip_all_tags( wp_check_invalid_utf8( (string) $row['groupName'] ) ) ) : '',
			);
		}

		update_option(
			self::OPTION,
			array(
				'fields'     => $fields,
				'fetched_at' => time(),
				'failed_at'  => 0,
				'error'      => '',
			),
			false
		);

		return count( $fields );
	}

	/**
	 * Refreshes the configuration when it is missing or old.
	 *
	 * Meant for admin screens only; a visitor's submission never waits on it.
	 *
	 * @return void
	 */
	public static function maybe_refresh() {
		if ( ! Raynet_Lead_Settings::is_configured() ) {
			return;
		}

		$state = self::state();

		if ( $state['failed_at'] && time() - $state['failed_at'] < self::RETRY_AFTER ) {
			return;
		}

		if ( $state['fetched_at'] && time() - $state['fetched_at'] < self::MAX_AGE ) {
			return;
		}

		self::refresh();
	}

	/**
	 * Every attribute a mapping can offer, in the order it should be shown.
	 *
	 * Labels come out HTML-escaped: Elementor's mapping control prints them
	 * as markup, and a custom field's label is whatever someone typed in RAYNET.
	 *
	 * @return array<int,array<string,string>> Rows of {id, label, group}.
	 */
	public static function mapping_rows() {
		$rows = array();

		foreach ( Raynet_Lead_Form_Definition::catalogue() as $source => $meta ) {
			if ( 'consent' === $source ) {
				continue;
			}

			$rows[] = array(
				'id'    => $source,
				'label' => esc_html( $meta['label'] ),
				'group' => 'basic',
			);
		}

		foreach ( self::extended() as $key => $meta ) {
			$rows[] = array(
				'id'    => $key,
				'label' => esc_html( $meta['label'] ),
				'group' => 'extended',
			);
		}

		foreach ( self::custom() as $name => $meta ) {
			$rows[] = array(
				'id'    => self::CUSTOM_PREFIX . $name,
				/* translators: %s: custom field label in RAYNET. */
				'label' => esc_html( sprintf( __( '%s (vlastní pole)', 'raynet-lead-api-integration' ), $meta['label'] ) ),
				'group' => 'custom',
			);
		}

		return $rows;
	}

	/**
	 * Turns one mapped value into what its attribute accepts.
	 *
	 * @param string $remote_id  Mapping id: an extended key or a custom field id.
	 * @param mixed  $raw        Value as submitted.
	 * @param string $field_type Elementor type of the form field, when known.
	 * @return array{status:string,value:mixed,label:string,raw:string,note:bool}
	 *         status is "ok", "empty" (nothing to send) or "invalid" (kept for
	 *         the note); note asks for the raw answer in the note as well.
	 */
	public static function coerce( $remote_id, $raw, $field_type = '' ) {
		$raw = is_array( $raw ) ? implode( ', ', array_map( 'strval', $raw ) ) : (string) $raw;
		$raw = trim( $raw );

		if ( self::is_extended( $remote_id ) ) {
			$meta = self::extended()[ $remote_id ];

			if ( 'optin' === $meta['kind'] ) {
				return self::optin( $meta['label'], $raw, $field_type );
			}

			if ( 'consent' === $meta['kind'] ) {
				// Given only by a clear yes; anything unclear is not consent, and
				// goes to the note so a person can see what was answered.
				$yes            = self::answer( $raw, $field_type );
				$result         = self::result( true === $yes, $meta['label'], $raw );
				$result['note'] = null === $yes;

				return $result;
			}

			return self::result( self::coerce_extended( $meta['kind'], $raw ), $meta['label'], $raw );
		}

		$name   = self::custom_name( $remote_id );
		$fields = self::custom();

		if ( '' === $name ) {
			return self::result( null, (string) $remote_id, $raw );
		}

		if ( ! isset( $fields[ $name ] ) ) {
			// Mapped in the editor, but the configuration no longer knows it:
			// deleted in RAYNET, or not fetched on this site. Its type is unknown,
			// so it goes to the note rather than risk a refused lead.
			return self::result( '' === $raw ? '' : null, $name, $raw );
		}

		return self::result( self::coerce_custom( $fields[ $name ], $raw, $field_type ), $fields[ $name ]['label'], $raw );
	}

	/**
	 * Reads a marketing consent answer.
	 *
	 * RAYNET stores the opposite, "do not send marketing". A consent that
	 * cannot be read as a clear yes is not a consent: the lead is marked as
	 * refusing, and an answer nobody could read goes to the note so a person
	 * can decide.
	 *
	 * @param string $label      Attribute label.
	 * @param string $raw        Trimmed value.
	 * @param string $field_type Elementor field type.
	 * @return array{status:string,value:mixed,label:string,raw:string,note:bool} Result.
	 */
	private static function optin( $label, $raw, $field_type ) {
		$yes = self::answer( $raw, $field_type );

		$result          = self::result( true !== $yes, $label, $raw );
		$result['note']  = null === $yes;

		return $result;
	}

	/**
	 * Packs a coercion result.
	 *
	 * @param mixed  $value Clean value; '' for nothing to send, null for invalid.
	 * @param string $label Human label.
	 * @param string $raw   Value as submitted.
	 * @return array{status:string,value:mixed,label:string,raw:string} Result.
	 */
	private static function result( $value, $label, $raw ) {
		if ( null === $value ) {
			$status = '' === $raw ? 'empty' : 'invalid';
		} elseif ( '' === $value ) {
			$status = 'empty';
		} else {
			$status = 'ok';
		}

		return array(
			'status' => $status,
			'value'  => $value,
			'label'  => $label,
			'raw'    => $raw,
			'note'   => false,
		);
	}

	/**
	 * Cleans a value for an extended standard attribute.
	 *
	 * @param string $kind Attribute kind.
	 * @param string $raw  Trimmed value.
	 * @return mixed Clean value, '' for nothing to send, null when invalid.
	 */
	private static function coerce_extended( $kind, $raw ) {
		switch ( $kind ) {
			case 'email':
				if ( '' === $raw ) {
					return '';
				}

				return is_email( $raw ) ? sanitize_email( $raw ) : null;

			case 'country':
				if ( '' === $raw ) {
					return '';
				}

				return self::country_code( $raw );
		}

		return mb_substr( sanitize_text_field( $raw ), 0, 255 );
	}

	/**
	 * Cleans a value for a custom field of a known type.
	 *
	 * @param array<string,mixed> $field      Field configuration.
	 * @param string              $raw        Trimmed value.
	 * @param string              $field_type Elementor field type.
	 * @return mixed Clean value, '' for nothing to send, null when invalid.
	 */
	private static function coerce_custom( array $field, $raw, $field_type = '' ) {
		if ( '' === $raw ) {
			// An empty value would clear the field in RAYNET; on a new lead there
			// is nothing to clear, so it is simply not sent.
			return '';
		}

		switch ( $field['type'] ) {
			case 'TEXT':
				return mb_substr( sanitize_textarea_field( $raw ), 0, 5000 );

			case 'BIG_DECIMAL':
			case 'MONETARY':
			case 'PERCENT':
				return self::number( $raw );

			case 'BOOLEAN':
				return self::answer( $raw, $field_type );

			case 'DATE':
				return self::date( $raw, false );

			case 'DATETIME':
				return self::date( $raw, true );

			case 'TIME':
				return self::time( $raw );

			case 'ENUMERATION':
				return self::enum_value( $field['enum'], $raw );
		}

		return mb_substr( sanitize_text_field( $raw ), 0, 255 );
	}

	/**
	 * Reads a yes/no answer.
	 *
	 * Three outcomes, not two: guessing "anything but an explicit no is yes"
	 * would read "Nesouhlasím" or "Ne, děkuji" as consent. A ticked box is
	 * different — Elementor sends "on" for an acceptance box and the option's
	 * own text for a checkbox, and either being there means it was ticked.
	 *
	 * @param string $raw        Trimmed value.
	 * @param string $field_type Elementor field type, when known.
	 * @return bool|null True for yes, false for no, null when it cannot be told.
	 */
	public static function answer( $raw, $field_type = '' ) {
		$text = trim( strtolower( remove_accents( html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' ) ) ) );

		if ( '' === $text ) {
			return false;
		}

		$words = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$first = $words ? $words[0] : '';

		if ( in_array( $text, array( '1', 'on', 'true', 'checked' ), true ) || in_array( $first, array( 'ano', 'yes', 'y', 'jo', 'ok', 'souhlasim', 'souhlas', 'chci', 'prejiji', 'agree', 'accept' ), true ) ) {
			return true;
		}

		if ( in_array( $text, array( '0', 'off', 'false' ), true )
			|| in_array( $first, array( 'ne', 'n', 'no', 'not', 'nope', 'dont', 'decline' ), true )
			|| preg_match( '/^ne(souhlas|chci|mam|zasilat|posilat|odebirat|potrebuj|prej|zajem)/', $first ) ) {
			return false;
		}

		return in_array( $field_type, array( 'acceptance', 'checkbox' ), true ) ? true : null;
	}

	/**
	 * Parses a time of day.
	 *
	 * @param string $raw Trimmed value.
	 * @return string|null "H:i", or null when it is not a valid time.
	 */
	private static function time( $raw ) {
		return preg_match( '/^([01]?\d|2[0-3])[:.]([0-5]\d)(?::[0-5]\d)?$/', $raw, $m )
			? sprintf( '%02d:%s', (int) $m[1], $m[2] )
			: null;
	}

	/**
	 * Parses a number typed the Czech way or the English way.
	 *
	 * "1 234,50 Kč", "1234.5", "1,234.50", "1 499,-" and "15 %" all work. A
	 * number that cannot be read for certain is refused rather than guessed,
	 * because a guess lands in the CRM as a plausible, wrong figure: words
	 * ("50 tis.", "od 10 do 20") are refused, and so is a single separator
	 * followed by exactly three digits — "25.000" is twenty-five thousand in
	 * Czech and twenty-five in English.
	 *
	 * @param string $raw Trimmed value.
	 * @return float|int|null Number, or null when it is not one.
	 */
	private static function number( $raw ) {
		$text = str_replace( array( "\xc2\xa0", ' ' ), '', $raw );
		$text = preg_replace( '/(k\x{010D}|kc|czk|eur|usd|\x{20AC}|\$|%)/iu', '', $text );
		$text = preg_replace( '/[,.]-$/', '', (string) $text );

		if ( '' === $text || ! preg_match( '/^[+-]?[\d.,]+$/', $text ) ) {
			return null;
		}

		$comma = substr_count( $text, ',' );
		$dot   = substr_count( $text, '.' );

		if ( $comma && $dot ) {
			// Both: the later one is the decimal point, the other groups thousands.
			$decimal = strrpos( $text, ',' ) > strrpos( $text, '.' ) ? ',' : '.';
			$text    = str_replace( ',' === $decimal ? '.' : ',', '', $text );
			$text    = str_replace( ',', '.', $text );
		} elseif ( $comma > 1 || $dot > 1 ) {
			// One kind, repeated: it can only group thousands.
			$text = str_replace( array( ',', '.' ), '', $text );
		} elseif ( $comma || $dot ) {
			$parts = explode( $comma ? ',' : '.', $text );

			if ( 3 === strlen( $parts[1] ) && ! in_array( ltrim( $parts[0], '+-' ), array( '', '0' ), true ) ) {
				return null;
			}

			$text = $parts[0] . '.' . $parts[1];
		}

		if ( ! is_numeric( $text ) ) {
			return null;
		}

		$number = (float) $text;

		return floor( $number ) === $number && abs( $number ) < PHP_INT_MAX ? (int) $number : $number;
	}

	/**
	 * Parses a date, and optionally a time, into RAYNET's format.
	 *
	 * @param string $raw       Trimmed value.
	 * @param bool   $with_time Produce "Y-m-d H:i" instead of "Y-m-d".
	 * @return string|null Formatted value, or null when unreadable.
	 */
	private static function date( $raw, $with_time ) {
		$raw  = preg_replace( '/\s+/', ' ', $raw );
		$time = '00:00';

		if ( preg_match( '/^(.*?)[ T](\d{1,2}[:.]\d{2}(?::\d{2})?)$/', $raw, $m ) ) {
			$raw  = trim( $m[1] );
			$time = self::time( $m[2] );

			if ( null === $time ) {
				return null;
			}
		}

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})$/', $raw, $m ) || preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return null;
		}

		if ( ! checkdate( (int) $mo, (int) $d, (int) $y ) ) {
			return null;
		}

		$date = sprintf( '%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d );

		return $with_time ? $date . ' ' . $time : $date;
	}

	/**
	 * Finds the enumeration item a value stands for.
	 *
	 * Case and accents are ignored, so "velka" still selects "Velká", but the
	 * value sent is always the item exactly as RAYNET knows it.
	 *
	 * @param string[] $items Enumeration items.
	 * @param string   $raw   Trimmed value.
	 * @return string|null Matching item, or null.
	 */
	private static function enum_value( array $items, $raw ) {
		// Elementor sanitizes a select value, so "< 10" arrives as "&lt; 10".
		$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );

		if ( in_array( $raw, $items, true ) ) {
			return $raw;
		}

		$want = strtolower( remove_accents( $raw ) );

		foreach ( $items as $item ) {
			if ( strtolower( remove_accents( $item ) ) === $want ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Turns a country name or code into ISO 3166-1 alpha-2.
	 *
	 * RAYNET only takes the code. The names are the ones a Czech or Slovak
	 * form is likely to receive; anything else goes to the note.
	 *
	 * @param string $raw Trimmed value.
	 * @return string|null Code, or null when unknown.
	 */
	private static function country_code( $raw ) {
		$names = array(
			// Czech shorthand first: here "ČR" and "SR" mean Czechia and
			// Slovakia, not Costa Rica and Suriname, and "UK" is not a code.
			'cr'                     => 'CZ',
			'sr'                     => 'SK',
			'uk'                     => 'GB',
			'cesko'                  => 'CZ',
			'ceska republika'        => 'CZ',
			'czech republic'         => 'CZ',
			'czechia'                => 'CZ',
			'slovensko'              => 'SK',
			'slovenska republika'    => 'SK',
			'slovakia'               => 'SK',
			'polsko'                 => 'PL',
			'poland'                 => 'PL',
			'nemecko'                => 'DE',
			'germany'                => 'DE',
			'rakousko'               => 'AT',
			'austria'                => 'AT',
			'madarsko'               => 'HU',
			'hungary'                => 'HU',
			'velka britanie'         => 'GB',
			'spojene kralovstvi'     => 'GB',
			'united kingdom'         => 'GB',
			'spojene staty'          => 'US',
			'spojene staty americke' => 'US',
			'usa'                    => 'US',
			'united states'          => 'US',
		);

		$key = trim( strtolower( remove_accents( $raw ) ) );

		if ( isset( $names[ $key ] ) ) {
			return $names[ $key ];
		}

		$code = strtoupper( $key );

		return 2 === strlen( $code ) && false !== strpos( self::ISO_COUNTRIES, $code ) && 0 === strpos( self::ISO_COUNTRIES, $code ) % 3
			? $code
			: null;
	}
}
