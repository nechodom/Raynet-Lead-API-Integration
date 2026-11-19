<?php
/**
 * Spam scoring of stored form submissions before they are imported.
 *
 * Pure PHP on purpose: no WordPress or Elementor function is called here, so
 * the rules can be tested on their own. The caller applies WordPress filters
 * to the config and the result and turns signal ids into labels.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores one stored submission and turns its signals into a verdict.
 */
class Raynet_Submission_Spam_Score {

	const VERDICT_OK      = 'ok';
	const VERDICT_SUSPECT = 'suspect';
	const VERDICT_SPAM    = 'spam';

	const STRONG = 'strong';
	const MEDIUM = 'medium';
	const WEAK   = 'weak';

	/**
	 * Values every submission is read with; a missing one counts as empty.
	 */
	const VALUE_KEYS = array( 'firstName', 'lastName', 'companyName', 'email', 'phone', 'topic', 'message' );

	/**
	 * Fields too short to carry a link legitimately.
	 */
	const SHORT_FIELDS = array( 'firstName', 'lastName', 'companyName', 'phone', 'topic' );

	/**
	 * One link: `[url`, `http(s)://` or `www.`, each counted once even when
	 * they follow each other (`[url=http://www.x]` is one link).
	 */
	const LINK_PATTERN = '~\[url(?:=|\])?(?:https?://(?:www\.)?|www\.)?|https?://(?:www\.)?|www\.~i';

	/**
	 * HTML tags and common BBCode tags.
	 */
	const MARKUP_PATTERNS = array(
		'~</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>~i',
		'~\[/?(?:url|link|img|b|i|u|s|quote|color|size|font|code|email)(?:=[^\]]*)?\]~i',
	);

	/**
	 * User agents of scripts and crawlers rather than browsers.
	 */
	const BOT_AGENT_PATTERN = '~curl|wget|python|libwww|perl|java/|go-http|okhttp|axios|node-fetch|httpclient|scrapy|headless|phantomjs|spider|crawler|[a-z]*bot/|\bbot\b~i';

	/**
	 * Latin vowels, with the diacritics of the languages a name is likely in.
	 */
	const VOWELS = 'aeiouyáàâäãåāăąéèêëěēęíìîïīįóòôöõőōøúùûüůűūųýÿæœ';

	/**
	 * Consonants that carry a syllable in Czech and Slovak (prst, vlk,
	 * scvrkl), so a word holding one is not "without a vowel".
	 */
	const SYLLABIC = 'rlŕĺ';

	/**
	 * Strength of every signal score() reports, by reason id.
	 *
	 * Matches the signal table in score() (tests/test-import-ai.php checks
	 * it), for code that holds a reason without its strength, such as a
	 * cached index entry or a reason a filter added.
	 */
	const STRENGTHS = array(
		'link_short'       => self::STRONG,
		'markup'           => self::STRONG,
		'foreign_script'   => self::STRONG,
		'same_text'        => self::STRONG,
		'links_many'       => self::STRONG,
		'link_one'         => self::MEDIUM,
		'keywords'         => self::MEDIUM,
		'same_ip'          => self::MEDIUM,
		'odd_name'         => self::MEDIUM,
		'bot_agent'        => self::MEDIUM,
		'bad_phone'        => self::MEDIUM,
		'home_phone'       => self::MEDIUM,
		'disposable_email' => self::WEAK,
		'short_message'    => self::MEDIUM,
		'no_words'         => self::MEDIUM,
		'gibberish'        => self::MEDIUM,
		'one_word'         => self::WEAK,
		'message_is_name'  => self::MEDIUM,
		'duplicate'        => self::MEDIUM,
	);

	/**
	 * Strength of a known reason id.
	 *
	 * @param string $id Reason id.
	 * @return string One of STRONG, MEDIUM, WEAK, or an empty string for an id
	 *                score() never reports (one added by a filter, say).
	 */
	public static function strength_of( $id ) {
		$id = (string) $id;

		return isset( self::STRENGTHS[ $id ] ) ? self::STRENGTHS[ $id ] : '';
	}

	/**
	 * Default thresholds, word lists and switches.
	 *
	 * @return array<string,mixed> Config.
	 */
	public static function default_config() {
		return array(
			'strong_to_spam'       => 1,
			'medium_to_spam'       => 2,
			'medium_to_suspect'    => 1,
			// A row is pre-ticked only with no signal at all: one weak
			// signal already asks for a human look.
			'weak_to_suspect'      => 1,
			// Weak signals that tip a single medium one into spam; 0 turns
			// this off.
			'medium_weak_to_spam'  => 2,
			'same_text_senders'    => 3,
			// Shorter messages ("Prosím o zavolání.") are stock lines real
			// people share, so repeating them is no sign of spam.
			'same_text_min_length' => 40,
			'same_ip_count'        => 5,
			'min_message_length'   => 10,
			'min_phone_digits'     => 9,
			// International prefixes (+420, +421) a site serves. Empty means
			// the country of a number is never judged: real leads come from
			// abroad too.
			'home_phone_prefixes'  => array(),
			'keywords'             => array( 'seo', 'backlink', 'backlinks', 'casino', 'crypto', 'bitcoin', 'viagra', 'cialis', 'loan', 'loans', 'guest post', 'ranking', 'traffic', 'forex', 'porn' ),
			'disposable_domains'   => array( 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com' ),
			'allowed_scripts'      => array( 'Latin' ),
			'disabled'             => array(),
		);
	}

	/**
	 * Key under which the same message from different senders is counted.
	 *
	 * @param string $message Message text.
	 * @return string Empty for an empty message, otherwise a hash of the
	 *                lowercased text with whitespace collapsed.
	 */
	public static function text_key( $message ) {
		$text = trim( (string) preg_replace( '~\s+~u', ' ', (string) $message ) );

		if ( '' === $text ) {
			return '';
		}

		return md5( self::lower( $text ) );
	}

	/**
	 * Scores one submission.
	 *
	 * @param array<string,mixed> $submission Values, other texts, IP, user agent and flags.
	 * @param array<string,mixed> $stats      Counts across all submissions of the form.
	 * @param array<string,mixed> $config     Config; missing keys fall back to the defaults.
	 * @return array{verdict:string,reasons:array<int,array{id:string,strength:string,count:int}>} Result.
	 */
	public static function score( array $submission, array $stats, array $config ) {
		$config   = $config + self::default_config();
		$disabled = array_map( 'strval', (array) $config['disabled'] );
		$reasons  = array();

		// The strength of each signal comes from STRENGTHS alone, in its order,
		// so the two cannot drift apart: a test compares the list's ids with it.
		foreach ( self::signal_list( $submission, $stats, $config ) as $id => $signal ) {
			list( $fired, $count ) = $signal;

			if ( $fired && ! in_array( $id, $disabled, true ) ) {
				$reasons[] = array(
					'id'       => $id,
					'strength' => self::strength_of( $id ),
					'count'    => $count,
				);
			}
		}

		return array(
			'verdict' => self::verdict( $reasons, $config ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Every signal of one submission, fired or not.
	 *
	 * @param array<string,mixed> $submission Values, other texts, IP, user agent and flags.
	 * @param array<string,mixed> $stats      Counts across all submissions of the form.
	 * @param array<string,mixed> $config     Full config.
	 * @return array<string,array{0:bool,1:int}> Signal id => whether it fired and the count it carries, in the order reasons are reported.
	 */
	private static function signal_list( array $submission, array $stats, array $config ) {
		$values = array();
		$raw    = isset( $submission['values'] ) && is_array( $submission['values'] ) ? $submission['values'] : array();
		foreach ( self::VALUE_KEYS as $key ) {
			$values[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		}

		$texts = array();
		if ( isset( $submission['texts'] ) && is_array( $submission['texts'] ) ) {
			foreach ( $submission['texts'] as $text ) {
				if ( is_scalar( $text ) ) {
					$texts[] = (string) $text;
				}
			}
		}

		$ip         = isset( $submission['ip'] ) && is_scalar( $submission['ip'] ) ? trim( (string) $submission['ip'] ) : '';
		$user_agent = isset( $submission['user_agent'] ) && is_scalar( $submission['user_agent'] ) ? (string) $submission['user_agent'] : null;
		$message    = $values['message'];
		$links      = self::count_links( $message );
		$same_min   = isset( $config['same_text_min_length'] ) ? (int) $config['same_text_min_length'] : 40;
		// The message is judged only where the form has one; trimmed, as a
		// visitor's stray spaces say nothing.
		$judge_text = ! empty( $submission['has_message_field'] );
		$trimmed    = trim( $message );
		$no_words   = $judge_text && self::has_no_words( $trimmed );

		$text_senders = isset( $stats['text_senders'] ) && is_array( $stats['text_senders'] ) ? $stats['text_senders'] : array();
		$ip_counts    = isset( $stats['ip_counts'] ) && is_array( $stats['ip_counts'] ) ? $stats['ip_counts'] : array();
		$text_key     = self::text_key( $message );
		$senders      = '' !== $text_key && isset( $text_senders[ $text_key ] ) ? (int) $text_senders[ $text_key ] : 0;
		$ip_count     = '' !== $ip && isset( $ip_counts[ $ip ] ) ? (int) $ip_counts[ $ip ] : 0;

		$free_text = array_merge(
			array( $values['firstName'], $values['lastName'], $values['companyName'], $values['topic'], $message ),
			$texts
		);

		// Each signal in the order reasons are reported: whether it fired, and
		// the count it carries. Strengths are in STRENGTHS.
		return array(
			'link_short'       => array( self::has_short_link( $values ), 0 ),
			'markup'           => array( self::has_markup( array_merge( array_values( $values ), $texts ) ), 0 ),
			'foreign_script'   => array( self::is_foreign_script( implode( "\n", $free_text ), (array) $config['allowed_scripts'] ), 0 ),
			'same_text'        => array( $senders >= (int) $config['same_text_senders'] && self::length( trim( $message ) ) >= $same_min, $senders ),
			'links_many'       => array( $links >= 2, 0 ),
			'link_one'         => array( 1 === $links, 0 ),
			'keywords'         => array( self::has_keyword( implode( "\n", array_merge( array( $message, $values['topic'] ), $texts ) ), (array) $config['keywords'] ), 0 ),
			'same_ip'          => array( $ip_count >= (int) $config['same_ip_count'], $ip_count ),
			'odd_name'         => array( self::is_odd_name( $values['firstName'], $values['lastName'] ), 0 ),
			'bot_agent'        => array( null !== $user_agent && ( '' === trim( $user_agent ) || 1 === preg_match( self::BOT_AGENT_PATTERN, $user_agent ) ), 0 ),
			'bad_phone'        => array( self::is_bad_phone( $values['phone'], (int) $config['min_phone_digits'] ), 0 ),
			'home_phone'       => array( self::is_foreign_phone( $values['phone'], (array) $config['home_phone_prefixes'] ), 0 ),
			'disposable_email' => array( self::is_disposable_email( $values['email'], (array) $config['disposable_domains'] ), 0 ),
			// A message without words is short too; counting both would make
			// "ok" or "2 ks" spam on its own.
			'short_message'    => array( $judge_text && ! $no_words && self::length( $trimmed ) < (int) $config['min_message_length'], 0 ),
			'no_words'         => array( $no_words, 0 ),
			'gibberish'        => array( $judge_text && self::is_gibberish( $trimmed ), 0 ),
			'one_word'         => array( $judge_text && ! $no_words && '' !== $trimmed && 1 !== preg_match( '~\s~u', $trimmed ), 0 ),
			'message_is_name'  => array( $judge_text && self::is_message_name( $trimmed, $values ), 0 ),
			'duplicate'        => array( isset( $submission['duplicate'] ) && true === $submission['duplicate'], 0 ),
		);
	}

	/**
	 * Turns reasons into a verdict by counting them per strength.
	 *
	 * @param array<int,array<string,mixed>> $reasons Reasons from score().
	 * @param array<string,mixed>            $config  Config; missing keys fall back to the defaults.
	 * @return string One of the VERDICT_* constants.
	 */
	public static function verdict( array $reasons, array $config ) {
		$config = $config + self::default_config();
		$counts = array(
			self::STRONG => 0,
			self::MEDIUM => 0,
			self::WEAK   => 0,
		);

		foreach ( $reasons as $reason ) {
			$strength = isset( $reason['strength'] ) ? (string) $reason['strength'] : '';
			if ( isset( $counts[ $strength ] ) ) {
				$counts[ $strength ]++;
			}
		}

		$medium_weak = (int) $config['medium_weak_to_spam'];

		if ( $counts[ self::STRONG ] >= (int) $config['strong_to_spam'] || $counts[ self::MEDIUM ] >= (int) $config['medium_to_spam']
			|| ( $medium_weak > 0 && $counts[ self::MEDIUM ] >= 1 && $counts[ self::WEAK ] >= $medium_weak ) ) {
			return self::VERDICT_SPAM;
		}

		if ( $counts[ self::MEDIUM ] >= (int) $config['medium_to_suspect'] || $counts[ self::WEAK ] >= (int) $config['weak_to_suspect'] ) {
			return self::VERDICT_SUSPECT;
		}

		return self::VERDICT_OK;
	}

	/**
	 * Counts links in a text.
	 *
	 * @param string $text Text.
	 * @return int Number of links.
	 */
	private static function count_links( $text ) {
		return (int) preg_match_all( self::LINK_PATTERN, $text );
	}

	/**
	 * Whether a field too short for a link holds one.
	 *
	 * @param array<string,string> $values Submission values.
	 * @return bool Whether a short field holds a link.
	 */
	private static function has_short_link( array $values ) {
		foreach ( self::SHORT_FIELDS as $key ) {
			if ( self::count_links( $values[ $key ] ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any of the texts holds an HTML or BBCode tag.
	 *
	 * @param string[] $texts Texts.
	 * @return bool Whether markup was found.
	 */
	private static function has_markup( array $texts ) {
		foreach ( $texts as $text ) {
			foreach ( self::MARKUP_PATTERNS as $pattern ) {
				if ( 1 === preg_match( $pattern, $text ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether most letters of a text are in scripts the site does not use.
	 *
	 * Fires when letters outside the allowed scripts are more than half of
	 * all letters and at least five of them.
	 *
	 * @param string   $text    Text.
	 * @param string[] $scripts Allowed Unicode script names, such as Latin.
	 * @return bool Whether the text is mostly in a foreign script.
	 */
	private static function is_foreign_script( $text, array $scripts ) {
		$letters = (int) preg_match_all( '~\p{L}~u', $text );
		$allowed = 0;

		foreach ( $scripts as $script ) {
			// Only a bare script name may reach the pattern.
			$script = preg_replace( '~[^A-Za-z_]~', '', (string) $script );
			if ( '' !== $script ) {
				$allowed += (int) @preg_match_all( '~\p{' . $script . '}~u', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown script name only counts as nothing.
			}
		}

		$foreign = $letters - $allowed;

		return $foreign >= 5 && $foreign * 2 > $letters;
	}

	/**
	 * Whether a text holds one of the keywords as a whole word or phrase.
	 *
	 * @param string   $text     Text.
	 * @param string[] $keywords Keywords and phrases.
	 * @return bool Whether a keyword was found.
	 */
	private static function has_keyword( $text, array $keywords ) {
		foreach ( $keywords as $keyword ) {
			$words = preg_split( '~\s+~u', trim( (string) $keyword ), -1, PREG_SPLIT_NO_EMPTY );
			if ( empty( $words ) ) {
				continue;
			}

			$phrase  = implode( '\s+', array_map( 'preg_quote', $words, array_fill( 0, count( $words ), '~' ) ) );
			$pattern = '~(?<![\p{L}\p{N}])' . $phrase . '(?![\p{L}\p{N}])~iu';

			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a name looks made up.
	 *
	 * First and last name the same, a digit in either, a Latin word of five
	 * or more letters without a vowel, or a word switching from lower to
	 * upper case twice or more. Short vowelless names (Ng, Vlk, Smrž) and
	 * names like McDonald stay clean.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return bool Whether the name looks made up.
	 */
	private static function is_odd_name( $first, $last ) {
		$first = trim( $first );
		$last  = trim( $last );

		if ( '' !== $first && self::lower( $first ) === self::lower( $last ) ) {
			return true;
		}

		foreach ( array( $first, $last ) as $name ) {
			if ( 1 === preg_match( '~\p{N}~u', $name ) ) {
				return true;
			}

			$words = preg_split( '~[^\p{L}]+~u', $name, -1, PREG_SPLIT_NO_EMPTY );

			foreach ( (array) $words as $word ) {
				$is_latin = 1 === preg_match( '~^\p{Latin}+$~u', $word );

				if ( $is_latin && self::length( $word ) >= 5 && 1 !== preg_match( '~[' . self::VOWELS . ']~iu', $word ) ) {
					return true;
				}

				if ( (int) preg_match_all( '~\p{Ll}\p{Lu}~u', $word ) >= 2 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a filled-in phone number cannot be real.
	 *
	 * @param string $phone      Phone number.
	 * @param int    $min_digits Fewest digits a real number has.
	 * @return bool Whether the number is implausible; an empty one is not.
	 */
	private static function is_bad_phone( $phone, $min_digits ) {
		$digits = preg_replace( '~\D~', '', $phone );

		if ( '' === trim( $phone ) ) {
			return false;
		}

		return strlen( $digits ) < $min_digits || 1 === preg_match( '~^(\d)\1*$~', $digits ) || self::is_digit_run( $digits );
	}

	/**
	 * Whether digits climb or fall by one all the way (123456789, 987654321),
	 * the number a keyboard gives when someone just wants past the field.
	 *
	 * @param string $digits Digits only.
	 * @return bool Whether the digits are one consecutive run.
	 */
	private static function is_digit_run( $digits ) {
		$length = strlen( $digits );

		if ( $length < 3 ) {
			return false;
		}

		foreach ( array( 1, -1 ) as $step ) {
			$run = true;
			for ( $i = 1; $i < $length; $i++ ) {
				if ( (int) $digits[ $i ] - (int) $digits[ $i - 1 ] !== $step ) {
					$run = false;
					break;
				}
			}

			if ( $run ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a number with an international prefix is from a country the
	 * site does not serve.
	 *
	 * Off until a site lists its prefixes: real leads come from abroad, so
	 * a country is no sign of spam unless the site says so. A number written
	 * without + or 00 is never judged, as its country cannot be told.
	 *
	 * @param string   $phone    Phone number.
	 * @param string[] $prefixes Allowed prefixes, such as +420.
	 * @return bool Whether the number carries a prefix not in the list.
	 */
	private static function is_foreign_phone( $phone, array $prefixes ) {
		$allowed = array();
		foreach ( $prefixes as $prefix ) {
			$digits = preg_replace( '~^00~', '', preg_replace( '~[^\d]~', '', (string) $prefix ) );
			if ( '' !== $digits ) {
				$allowed[] = $digits;
			}
		}

		$compact = preg_replace( '~[\s().\-/]~', '', trim( $phone ) );

		if ( empty( $allowed ) || 1 !== preg_match( '~^(?:\+|00)(\d+)$~', $compact, $match ) ) {
			return false;
		}

		foreach ( $allowed as $digits ) {
			if ( 0 === strpos( $match[1], $digits ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a filled-in message holds fewer than three letters: digits,
	 * punctuation or emoji only ("199999", "???").
	 *
	 * @param string $message Trimmed message.
	 * @return bool Whether the message has no words.
	 */
	private static function has_no_words( $message ) {
		return '' !== $message && (int) preg_match_all( '~\p{L}~u', $message ) < 3;
	}

	/**
	 * Whether a short message is a keyboard mash rather than words.
	 *
	 * Only a message of at most three words is judged. It is gibberish when
	 * one of its Latin-script words (letters only, split at anything else)
	 * has eight or more letters of which fewer than 20 % are vowels
	 * ("Mggkgmegvkfv", "Wtssdres"), or five or more letters and neither a
	 * vowel nor r or l. Czech and Slovak use r and l as syllable carriers
	 * (prst, vlk, scvrkl), so those words stay clean, and their long words
	 * (zmrzlina, čtvrthodina) keep a vowel share well above 20 %. Words in
	 * other scripts are not judged here.
	 *
	 * @param string $message Trimmed message.
	 * @return bool Whether the message looks like gibberish.
	 */
	private static function is_gibberish( $message ) {
		$words = preg_split( '~\s+~u', $message, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $words ) || count( $words ) > 3 ) {
			return false;
		}

		$parts = preg_split( '~[^\p{L}]+~u', $message, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $parts as $part ) {
			// What is left of a URL is no word anyone typed: link_one judges it.
			if ( 1 !== preg_match( '~^\p{Latin}+$~u', $part ) || in_array( self::lower( $part ), array( 'http', 'https', 'www' ), true ) ) {
				continue;
			}

			$letters = self::length( $part );
			// r and l carry syllables in Czech and Slovak ("štvrťrok",
			// "zmrzlina"), and count as vowels for the share.
			$vowels = (int) preg_match_all( '~[' . self::VOWELS . self::SYLLABIC . ']~iu', $part );

			if ( $letters >= 8 && $vowels * 5 < $letters ) {
				return true;
			}

			if ( $letters >= 5 && 0 === $vowels && 1 !== preg_match( '~[' . self::SYLLABIC . ']~iu', $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a message only repeats the sender's name, e-mail or phone.
	 *
	 * Compared lowercased, with punctuation and whitespace ignored: every
	 * word of the message must be a word of the first or last name, or the
	 * whole message the e-mail address or the phone number.
	 *
	 * @param string               $message Trimmed message.
	 * @param array<string,string> $values  Submission values.
	 * @return bool Whether the message holds nothing but the sender's details.
	 */
	private static function is_message_name( $message, array $values ) {
		if ( '' === $message ) {
			return false;
		}

		$lower = self::lower( $message );
		$email = self::lower( trim( $values['email'] ) );

		if ( '' !== $email && preg_replace( '~\s+~u', '', $lower ) === $email ) {
			return true;
		}

		$phone = preg_replace( '~\D~', '', $values['phone'] );

		if ( '' !== $phone && 1 !== preg_match( '~\p{L}~u', $message ) && preg_replace( '~\D~', '', $message ) === $phone ) {
			return true;
		}

		$words = preg_split( '~[^\p{L}\p{N}]+~u', $lower, -1, PREG_SPLIT_NO_EMPTY );
		$names = preg_split( '~[^\p{L}\p{N}]+~u', self::lower( $values['firstName'] . ' ' . $values['lastName'] ), -1, PREG_SPLIT_NO_EMPTY );

		return ! empty( $words ) && ! empty( $names ) && array() === array_diff( $words, $names );
	}

	/**
	 * Whether an e-mail address is at a throwaway domain or its subdomain.
	 *
	 * @param string   $email   E-mail address.
	 * @param string[] $domains Throwaway domains.
	 * @return bool Whether the domain is a throwaway one.
	 */
	private static function is_disposable_email( $email, array $domains ) {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}

		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );
		if ( '' === $domain ) {
			return false;
		}

		foreach ( $domains as $disposable ) {
			$disposable = strtolower( trim( (string) $disposable ) );
			if ( '' === $disposable ) {
				continue;
			}

			if ( $domain === $disposable || substr( $domain, -strlen( $disposable ) - 1 ) === '.' . $disposable ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercases a UTF-8 string, letters with diacritics included where
	 * mbstring is available.
	 *
	 * @param string $text Text.
	 * @return string Lowercased text.
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Length of a UTF-8 string in characters.
	 *
	 * @param string $text Text.
	 * @return int Number of characters; bytes when the text is not valid UTF-8.
	 */
	private static function length( $text ) {
		$count = preg_match_all( '~.~us', $text );

		return false === $count ? strlen( $text ) : $count;
	}
}
