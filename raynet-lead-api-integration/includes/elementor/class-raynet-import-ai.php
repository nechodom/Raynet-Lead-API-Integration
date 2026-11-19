<?php
/**
 * AI review of stored submissions: what is sent per submission, where the
 * verdicts are kept, and how they combine with the rule-based verdict.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payload, verdict store and merge for the AI review of the import.
 */
class Raynet_Import_Ai {

	/**
	 * Prefix of the option rows holding one verdict each, by submission id.
	 */
	const OPTION_PREFIX = 'raynet_import_ai_';

	/**
	 * Reason id added to a row the model rejected.
	 */
	const REASON_ID = 'ai_reject';

	/**
	 * Longest message sent, in characters.
	 */
	const MESSAGE_MAX = 1500;

	/**
	 * Longest short value (name, company, topic) sent, in characters.
	 */
	const SHORT_MAX = 200;

	/**
	 * Most note fields sent per submission.
	 */
	const NOTES_MAX = 10;

	/**
	 * Longest note value sent, in characters.
	 */
	const NOTE_MAX = 300;

	/**
	 * Longest note label sent, in characters.
	 */
	const LABEL_MAX = 100;

	/**
	 * Fewest digits a run needs to be taken for a phone number.
	 */
	const PHONE_DIGITS = 7;

	/**
	 * What is sent for a phone without an international prefix.
	 */
	const NO_PREFIX = 'bez předvolby';

	/**
	 * Default number of submissions per OpenAI call.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Countries by calling code, for the codes a Czech business mostly sees.
	 * An unknown code is sent as the bare number.
	 *
	 * @return array<string,string> Code without "+" => country.
	 */
	public static function prefixes() {
		return array(
			'420' => 'Česko',
			'421' => 'Slovensko',
			'48'  => 'Polsko',
			'49'  => 'Německo',
			'43'  => 'Rakousko',
			'36'  => 'Maďarsko',
			'44'  => 'Spojené království',
			'353' => 'Irsko',
			'33'  => 'Francie',
			'32'  => 'Belgie',
			'31'  => 'Nizozemsko',
			'352' => 'Lucembursko',
			'41'  => 'Švýcarsko',
			'39'  => 'Itálie',
			'34'  => 'Španělsko',
			'351' => 'Portugalsko',
			'30'  => 'Řecko',
			'45'  => 'Dánsko',
			'46'  => 'Švédsko',
			'47'  => 'Norsko',
			'358' => 'Finsko',
			'370' => 'Litva',
			'371' => 'Lotyšsko',
			'372' => 'Estonsko',
			'40'  => 'Rumunsko',
			'359' => 'Bulharsko',
			'385' => 'Chorvatsko',
			'386' => 'Slovinsko',
			'381' => 'Srbsko',
			'380' => 'Ukrajina',
			'375' => 'Bělorusko',
			'373' => 'Moldavsko',
			'7'   => 'Rusko / Kazachstán',
			'90'  => 'Turecko',
			'972' => 'Izrael',
			'971' => 'Spojené arabské emiráty',
			'1'   => 'USA / Kanada',
			'52'  => 'Mexiko',
			'55'  => 'Brazílie',
			'61'  => 'Austrálie',
			'86'  => 'Čína',
			'81'  => 'Japonsko',
			'82'  => 'Jižní Korea',
			'84'  => 'Vietnam',
			'91'  => 'Indie',
			'92'  => 'Pákistán',
			'62'  => 'Indonésie',
			'63'  => 'Filipíny',
			'234' => 'Nigérie',
			'27'  => 'Jihoafrická republika',
			'20'  => 'Egypt',
		);
	}

	/**
	 * Number of submissions per OpenAI call.
	 *
	 * @return int Between 1 and 50.
	 */
	public static function batch_size() {
		/**
		 * Filters how many submissions go to OpenAI in one call.
		 *
		 * @param int $size Batch size; clamped to 1–50.
		 */
		$size = (int) apply_filters( 'raynet_import_ai_batch_size', self::BATCH_SIZE );

		return max( 1, min( 50, $size ) );
	}

	/**
	 * The exact payload sent to OpenAI for one submission.
	 *
	 * Neither the full e-mail address nor the phone number, the IP or the
	 * user agent goes out: only the e-mail's domain and the phone's country.
	 * Free text is masked too, as visitors write their number into the
	 * message as often as into its field.
	 *
	 * @param array<string,mixed>  $row    Stored submission; only `id` is read.
	 * @param array<string,string> $values Collected values, as prepare() of the submissions returns them.
	 * @param array<string,string> $notes  Note lines, label => value.
	 * @return array<string,mixed> Payload.
	 */
	public static function prepare( array $row, array $values, array $notes ) {
		$value = static function ( $key ) use ( $values ) {
			return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
		};

		$lines = array();

		foreach ( $notes as $label => $note ) {
			if ( count( $lines ) >= self::NOTES_MAX ) {
				break;
			}

			$note = is_scalar( $note ) ? self::clean( (string) $note, self::NOTE_MAX ) : '';

			if ( '' === $note ) {
				continue;
			}

			$lines[] = array(
				'label' => self::clean( (string) $label, self::LABEL_MAX ),
				'value' => $note,
			);
		}

		return array(
			'id'            => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'first_name'    => self::clean( $value( 'firstName' ), self::SHORT_MAX ),
			'last_name'     => self::clean( $value( 'lastName' ), self::SHORT_MAX ),
			'company'       => self::clean( $value( 'companyName' ), self::SHORT_MAX ),
			'topic'         => self::clean( $value( 'topic' ), self::SHORT_MAX ),
			'message'       => self::clean( $value( 'message' ), self::MESSAGE_MAX ),
			'email_domain'  => self::email_domain( $value( 'email' ) ),
			'phone_country' => self::phone_country( $value( 'phone' ) ),
			'notes'         => $lines,
		);
	}

	/**
	 * Replaces e-mail addresses and phone numbers in a text.
	 *
	 * A run of digits counts as a phone number from PHONE_DIGITS digits on,
	 * with spaces, dashes, dots, parentheses and a leading + between them, so
	 * a quantity or a year stays.
	 *
	 * @param string $text Text.
	 * @return string Masked text.
	 */
	public static function mask( $text ) {
		$text = (string) preg_replace( '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*\.\p{L}{2,}/u', '[e-mail]', (string) $text );

		return (string) preg_replace_callback(
			'/(?:\+|\()?\d(?:[ \t.\-()]{0,2}\d)+/',
			static function ( $match ) {
				return strlen( (string) preg_replace( '/\D/', '', $match[0] ) ) >= self::PHONE_DIGITS ? '[telefon]' : $match[0];
			},
			$text
		);
	}

	/**
	 * Country of a phone number's international prefix.
	 *
	 * @param string $phone Phone as entered.
	 * @return string Country, the bare prefix ("+9999") when unknown, or NO_PREFIX.
	 */
	public static function phone_country( $phone ) {
		$phone = trim( (string) $phone );

		if ( 0 === strpos( $phone, '+' ) ) {
			$rest = substr( $phone, 1 );
		} elseif ( 0 === strpos( $phone, '00' ) ) {
			$rest = substr( $phone, 2 );
		} else {
			return self::NO_PREFIX;
		}

		$digits = (string) preg_replace( '/\D/', '', $rest );

		if ( '' === $digits ) {
			return self::NO_PREFIX;
		}

		$prefixes = self::prefixes();

		for ( $length = 3; $length >= 1; $length-- ) {
			$code = substr( $digits, 0, $length );

			if ( strlen( $code ) === $length && isset( $prefixes[ $code ] ) ) {
				return $prefixes[ $code ];
			}
		}

		// Unknown: the group the visitor typed before the first separator,
		// capped so a number written without spaces gives little away.
		preg_match( '/^\s*(\d+)/', $rest, $group );
		$code = isset( $group[1] ) ? $group[1] : $digits;

		return '+' . substr( $code, 0, 4 );
	}

	/**
	 * Domain of an e-mail address.
	 *
	 * @param string $email E-mail.
	 * @return string Lowercased domain, or an empty string.
	 */
	public static function email_domain( $email ) {
		$email = trim( (string) $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at ) {
			return '';
		}

		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );

		return 1 === preg_match( '/^[a-z0-9.\-]+$/', $domain ) && false !== strpos( $domain, '.' ) ? $domain : '';
	}

	/**
	 * Fingerprint of a payload.
	 *
	 * Taken over the payload exactly as sent, so a verdict stops counting as
	 * soon as what the model would see changes (a new mapping, an edited
	 * note field), and key order does not matter.
	 *
	 * @param array<string,mixed> $prepared Result of prepare().
	 * @return string Hash.
	 */
	public static function hash( array $prepared ) {
		return hash( 'sha256', (string) wp_json_encode( self::sorted( $prepared ) ) );
	}

	/**
	 * The stored verdict of a submission, if it still fits the data.
	 *
	 * @param int    $id   Submission id.
	 * @param string $hash Hash of the payload as it would be sent now.
	 * @return array{verdict:string,reason:string,model:string,hash:string,time:int}|null Verdict, or null.
	 */
	public static function get( $id, $hash ) {
		$stored = get_option( self::OPTION_PREFIX . (int) $id, null );

		if ( ! is_array( $stored ) || ! isset( $stored['verdict'], $stored['hash'] ) ) {
			return null;
		}

		if ( ! in_array( $stored['verdict'], array( 'import', 'reject' ), true ) || ! is_string( $stored['hash'] ) || '' === $hash || ! hash_equals( $stored['hash'], (string) $hash ) ) {
			return null;
		}

		return array(
			'verdict' => $stored['verdict'],
			'reason'  => isset( $stored['reason'] ) && is_string( $stored['reason'] ) ? Raynet_OpenAI_Client::plain_reason( $stored['reason'] ) : '',
			'model'   => isset( $stored['model'] ) && is_string( $stored['model'] ) ? $stored['model'] : '',
			'hash'    => $stored['hash'],
			'time'    => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
		);
	}

	/**
	 * Stores the verdict of a submission.
	 *
	 * @param int                 $id      Submission id.
	 * @param array<string,mixed> $verdict verdict and reason, as review() returns them.
	 * @param string              $hash    Hash of the payload that was sent.
	 * @return void
	 */
	public static function set( $id, array $verdict, $hash ) {
		if ( ! isset( $verdict['verdict'] ) || ! in_array( $verdict['verdict'], array( 'import', 'reject' ), true ) ) {
			return;
		}

		update_option(
			self::OPTION_PREFIX . (int) $id,
			array(
				'verdict' => $verdict['verdict'],
				'reason'  => Raynet_OpenAI_Client::plain_reason( isset( $verdict['reason'] ) ? (string) $verdict['reason'] : '' ),
				'model'   => Raynet_OpenAI_Client::model(),
				'hash'    => (string) $hash,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Deletes the stored verdicts of some submissions.
	 *
	 * @param int[] $ids Submission ids.
	 * @return int How many verdicts there were.
	 */
	public static function forget( array $ids ) {
		$deleted = 0;

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( false !== get_option( self::OPTION_PREFIX . $id, false ) ) {
				delete_option( self::OPTION_PREFIX . $id );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Lays an AI verdict over the rule verdict.
	 *
	 * Reject makes the row spam. Import makes it OK unless a rule found a
	 * strong signal, which the model may simply not have seen. Given a full
	 * classify() result, the tab and preselection follow the new verdict; a
	 * row already sent or blocked keeps its tab, and only a row never sent
	 * and without an attempt record is preselected.
	 *
	 * @param array<string,mixed>      $classified Result with verdict and reasons, possibly all of classify().
	 * @param array<string,mixed>|null $ai         verdict and reason, or null.
	 * @return array<string,mixed> Result with `ai` added.
	 */
	public static function merge( array $classified, $ai ) {
		$result = $classified;

		if ( ! is_array( $ai ) || ! isset( $ai['verdict'] ) || ! in_array( $ai['verdict'], array( 'import', 'reject' ), true ) ) {
			$result['ai'] = null;

			return $result;
		}

		$result['ai'] = array(
			'verdict' => $ai['verdict'],
			'reason'  => isset( $ai['reason'] ) && is_string( $ai['reason'] ) ? $ai['reason'] : '',
		);
		$reasons      = isset( $result['reasons'] ) && is_array( $result['reasons'] ) ? array_values( $result['reasons'] ) : array();

		if ( 'reject' === $ai['verdict'] ) {
			$result['verdict'] = Raynet_Submission_Spam_Score::VERDICT_SPAM;
			$reasons[]         = array(
				'id'       => self::REASON_ID,
				'strength' => Raynet_Submission_Spam_Score::STRONG,
				'count'    => 0,
			);
		} elseif ( ! self::has_strong( $reasons ) ) {
			$result['verdict'] = Raynet_Submission_Spam_Score::VERDICT_OK;
		}

		$result['reasons'] = $reasons;

		if ( isset( $result['tab'] ) && in_array( $result['tab'], array( Raynet_Elementor_Submissions::TAB_REVIEW, Raynet_Elementor_Submissions::TAB_SPAM ), true ) ) {
			$result['tab'] = Raynet_Submission_Spam_Score::VERDICT_SPAM === $result['verdict'] ? Raynet_Elementor_Submissions::TAB_SPAM : Raynet_Elementor_Submissions::TAB_REVIEW;
		}

		if ( array_key_exists( 'preselect', $result ) ) {
			$state               = isset( $result['state'] ) ? (string) $result['state'] : '';
			$result['preselect'] = isset( $result['tab'] ) && Raynet_Elementor_Submissions::TAB_REVIEW === $result['tab']
				&& Raynet_Submission_Spam_Score::VERDICT_OK === $result['verdict']
				&& Raynet_Elementor_Submissions::STATE_NEW === $state;
		}

		return $result;
	}

	/**
	 * Tells whether any reason is a strong one.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return bool True when one is.
	 */
	private static function has_strong( array $reasons ) {
		foreach ( $reasons as $reason ) {
			if ( is_array( $reason ) && isset( $reason['strength'] ) && Raynet_Submission_Spam_Score::STRONG === $reason['strength'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Plain, masked, single-spaced text cut to a length.
	 *
	 * Masked before cutting, so an address on the edge cannot survive in part.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string Clean text.
	 */
	private static function clean( $text, $max ) {
		$text = trim( self::mask( wp_strip_all_tags( (string) $text ) ) );

		return trim( mb_substr( $text, 0, $max ) );
	}

	/**
	 * Sorts the keys of every associative array, leaving lists in order.
	 *
	 * @param mixed $value Value.
	 * @return mixed Sorted value.
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( __CLASS__, 'sorted' ), $value );

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		return $value;
	}
}
