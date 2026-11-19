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
	 * Verdict row of a submission the model could not judge: a batch of it
	 * alone was refused, or it was left out of two answers.
	 */
	const NONE = 'none';

	/**
	 * Verdict row noting a first omission from an answer; not a verdict.
	 */
	const MISSED = 'missed';

	/**
	 * Whether any verdict row exists, as any_stored() found; null until asked.
	 *
	 * @var bool|null
	 */
	private static $any = null;

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
		// The at sign also as visitors spell it to dodge harvesters:
		// "jan (at) firma.cz", "jan [at] firma.cz", "jan @ firma.cz".
		$at   = '(?:\s*(?:@|＠)\s*|\s*[(\[]\s*(?:at|zavináč)\s*[)\]]\s*)';
		$text = (string) preg_replace( '/[\p{L}\p{N}._%+\-]+' . $at . '[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*\.\p{L}{2,}/iu', '[e-mail]', (string) $text );

		// Digits with up to three separators between them: spaces of any
		// kind (also the non-breaking ones pasted from signatures), dashes,
		// dots, slashes ("602/123 456") and parentheses.
		return (string) preg_replace_callback(
			'/(?:\+|\()*\d(?:[\s\x{00A0}\x{2007}\x{202F}\x{2011}\x{2013}.\-\/()]{0,3}\d)+/u',
			static function ( $match ) {
				return strlen( (string) preg_replace( '/\D/u', '', $match[0] ) ) >= self::PHONE_DIGITS ? '[telefon]' : $match[0];
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
		// "(+420) 603 …": the prefix written in parentheses.
		$phone = ltrim( trim( (string) $phone ), '( ' );

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
	 * note field), and key order does not matter. The model and the business
	 * text are part of the question too: changing either judges rows again.
	 *
	 * @param array<string,mixed> $prepared Result of prepare().
	 * @return string Hash.
	 */
	public static function hash( array $prepared ) {
		return hash( 'sha256', (string) wp_json_encode( array( self::sorted( $prepared ), self::context() ) ) );
	}

	/**
	 * What the verdicts depend on besides the submission: model and business
	 * text. Cached index entries carry AI verdicts, so their fingerprint
	 * includes this as well.
	 *
	 * @return string Hash.
	 */
	public static function context() {
		return md5( Raynet_OpenAI_Client::model() . "\n" . Raynet_OpenAI_Client::business() );
	}

	/**
	 * The stored verdict of a submission, if it still fits the data.
	 *
	 * @param int    $id   Submission id.
	 * @param string $hash Hash of the payload as it would be sent now.
	 * @return array{verdict:string,reason:string,model:string,hash:string,time:int}|null Verdict, or null.
	 */
	public static function get( $id, $hash ) {
		return self::from_stored( get_option( self::OPTION_PREFIX . (int) $id, null ), $hash );
	}

	/**
	 * A stored verdict row, validated as get() does.
	 *
	 * Besides import and reject a row can hold NONE: the model could not
	 * judge the submission, so it is not sent again until the verdicts are
	 * dropped. A row noting a first omission (MISSED) is no verdict.
	 *
	 * @param mixed  $stored Value of the option row.
	 * @param string $hash   Hash of the payload as it would be sent now.
	 * @return array{verdict:string,reason:string,model:string,hash:string,time:int}|null Verdict, or null.
	 */
	public static function from_stored( $stored, $hash ) {
		if ( ! is_array( $stored ) || ! isset( $stored['verdict'], $stored['hash'] ) ) {
			return null;
		}

		if ( ! in_array( $stored['verdict'], array( 'import', 'reject', self::NONE ), true ) || ! is_string( $stored['hash'] ) || '' === $hash || ! hash_equals( $stored['hash'], (string) $hash ) ) {
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
		if ( ! isset( $verdict['verdict'] ) || ! in_array( $verdict['verdict'], array( 'import', 'reject', self::NONE ), true ) ) {
			return;
		}

		self::$any = true;

		update_option(
			self::OPTION_PREFIX . (int) $id,
			array(
				'verdict' => $verdict['verdict'],
				'reason'  => self::NONE === $verdict['verdict'] ? '' : Raynet_OpenAI_Client::plain_reason( isset( $verdict['reason'] ) ? (string) $verdict['reason'] : '' ),
				'model'   => Raynet_OpenAI_Client::model(),
				'hash'    => (string) $hash,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Notes that the model left a submission out of a valid answer.
	 *
	 * The first time only a MISSED row is kept: the submission stays without
	 * a verdict and is sent again on the next run. The second time (same
	 * payload) it is marked NONE and no longer sent.
	 *
	 * @param int    $id   Submission id.
	 * @param string $hash Hash of the payload that was sent.
	 * @return bool True when the submission is now marked NONE.
	 */
	public static function note_missing( $id, $hash ) {
		$stored = get_option( self::OPTION_PREFIX . (int) $id, null );

		if ( is_array( $stored ) && isset( $stored['verdict'], $stored['hash'] ) && self::MISSED === $stored['verdict'] && is_string( $stored['hash'] ) && hash_equals( $stored['hash'], (string) $hash ) ) {
			self::set( $id, array( 'verdict' => self::NONE ), $hash );

			return true;
		}

		self::$any = true;

		update_option(
			self::OPTION_PREFIX . (int) $id,
			array(
				'verdict' => self::MISSED,
				'reason'  => '',
				'model'   => Raynet_OpenAI_Client::model(),
				'hash'    => (string) $hash,
				'tries'   => 1,
				'time'    => time(),
			),
			false
		);

		return false;
	}

	/**
	 * Tells whether any verdict row exists at all, once per request.
	 *
	 * The index build skips the whole AI layer when there is neither a key
	 * nor a stored row.
	 *
	 * @param bool $fresh Ask the database again (a long-running process).
	 * @return bool True when one does.
	 */
	public static function any_stored( $fresh = false ) {
		if ( null === self::$any || $fresh ) {
			global $wpdb;

			// The pattern is left unescaped: SQLite's LIKE has no default
			// escape character, and an underscore matching any character
			// only widens the match.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One existence check per request.
			self::$any = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", self::OPTION_PREFIX . '%' ) );
		}

		return self::$any;
	}

	/**
	 * The verdict rows of some submissions, in one query.
	 *
	 * @param int[] $ids Submission ids.
	 * @return array<int,mixed> Submission id => stored value, only for rows that exist.
	 */
	public static function stored_rows( array $ids ) {
		global $wpdb;

		$names = array();

		foreach ( $ids as $id ) {
			$names[] = self::OPTION_PREFIX . (int) $id;
		}

		if ( empty( $names ) ) {
			return array();
		}

		$in = $wpdb->prepare( implode( ',', array_fill( 0, count( $names ), '%s' ) ), $names );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The name list is prepared above.
		$records = (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$in})", ARRAY_A );
		$rows    = array();

		foreach ( $records as $record ) {
			$rows[ (int) substr( (string) $record['option_name'], strlen( self::OPTION_PREFIX ) ) ] = maybe_unserialize( $record['option_value'] );
		}

		return $rows;
	}

	/**
	 * Deletes the stored verdicts of some submissions.
	 *
	 * @param int[] $ids Submission ids.
	 * @return int How many verdicts there were.
	 */
	public static function forget( array $ids ) {
		self::$any = null;
		$deleted   = 0;

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

		// Marked as not judgeable: the rules stand, and the mark keeps the
		// row out of the next run.
		if ( is_array( $ai ) && isset( $ai['verdict'] ) && self::NONE === $ai['verdict'] ) {
			$result['ai'] = array(
				'verdict' => self::NONE,
				'reason'  => '',
			);

			return $result;
		}

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

			// Merged once already (an entry read back from the cache): the
			// reason is there, and a second one would show twice.
			if ( ! self::has_ai_reason( $reasons ) ) {
				$reasons[] = array(
					'id'       => self::REASON_ID,
					'strength' => Raynet_Submission_Spam_Score::STRONG,
					'count'    => 0,
				);
			}
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
	 * Tells whether the rules found a strong signal.
	 *
	 * Reasons come in several shapes: from classify() with a strength, from
	 * the cached index possibly without one, and from the
	 * raynet_import_spam_score filter as anything at all. A valid strength is
	 * taken as given; otherwise the id's strength is looked up. A reason
	 * whose strength cannot be known (an unknown id, no id, a plain string)
	 * counts as strong: the model's "import" must never be what moves an
	 * unexplained signal off the Spam tab.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return bool True when one is strong or of unknown strength.
	 */
	public static function has_strong( array $reasons ) {
		$known = array( Raynet_Submission_Spam_Score::STRONG, Raynet_Submission_Spam_Score::MEDIUM, Raynet_Submission_Spam_Score::WEAK );

		foreach ( $reasons as $reason ) {
			if ( ! is_array( $reason ) ) {
				return true;
			}

			$strength = isset( $reason['strength'] ) && is_string( $reason['strength'] ) ? $reason['strength'] : '';

			if ( ! in_array( $strength, $known, true ) ) {
				$strength = isset( $reason['id'] ) && is_scalar( $reason['id'] ) ? Raynet_Submission_Spam_Score::strength_of( (string) $reason['id'] ) : '';
			}

			if ( '' === $strength || Raynet_Submission_Spam_Score::STRONG === $strength ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the AI reject reason is among the reasons already.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return bool True when it is.
	 */
	private static function has_ai_reason( array $reasons ) {
		foreach ( $reasons as $reason ) {
			if ( is_array( $reason ) && isset( $reason['id'] ) && self::REASON_ID === $reason['id'] ) {
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
