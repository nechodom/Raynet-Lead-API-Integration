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
	 * Default thresholds, word lists and switches.
	 *
	 * @return array<string,mixed> Config.
	 */
	public static function default_config() {
		return array(
			'strong_to_spam'     => 1,
			'medium_to_spam'     => 2,
			'medium_to_suspect'  => 1,
			'weak_to_suspect'    => 2,
			'same_text_senders'  => 3,
			'same_ip_count'      => 5,
			'min_message_length' => 10,
			'min_phone_digits'   => 9,
			'keywords'           => array( 'seo', 'backlink', 'backlinks', 'casino', 'crypto', 'bitcoin', 'viagra', 'cialis', 'loan', 'loans', 'guest post', 'ranking', 'traffic', 'forex', 'porn' ),
			'disposable_domains' => array( 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com' ),
			'allowed_scripts'    => array( 'Latin' ),
			'disabled'           => array(),
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
		$config = $config + self::default_config();

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

		$text_senders = isset( $stats['text_senders'] ) && is_array( $stats['text_senders'] ) ? $stats['text_senders'] : array();
		$ip_counts    = isset( $stats['ip_counts'] ) && is_array( $stats['ip_counts'] ) ? $stats['ip_counts'] : array();
		$text_key     = self::text_key( $message );
		$senders      = '' !== $text_key && isset( $text_senders[ $text_key ] ) ? (int) $text_senders[ $text_key ] : 0;
		$ip_count     = '' !== $ip && isset( $ip_counts[ $ip ] ) ? (int) $ip_counts[ $ip ] : 0;

		$free_text = array_merge(
			array( $values['firstName'], $values['lastName'], $values['companyName'], $values['topic'], $message ),
			$texts
		);

		// Each signal in the order reasons are reported: id, strength, whether
		// it fired, and the count it carries.
		$signals = array(
			array( 'link_short', self::STRONG, self::has_short_link( $values ), 0 ),
			array( 'markup', self::STRONG, self::has_markup( array_merge( array_values( $values ), $texts ) ), 0 ),
			array( 'foreign_script', self::STRONG, self::is_foreign_script( implode( "\n", $free_text ), (array) $config['allowed_scripts'] ), 0 ),
			array( 'same_text', self::STRONG, $senders >= (int) $config['same_text_senders'], $senders ),
			array( 'links_many', self::STRONG, $links >= 2, 0 ),
			array( 'link_one', self::MEDIUM, 1 === $links, 0 ),
			array( 'keywords', self::MEDIUM, self::has_keyword( implode( "\n", array_merge( array( $message, $values['topic'] ), $texts ) ), (array) $config['keywords'] ), 0 ),
			array( 'same_ip', self::MEDIUM, $ip_count >= (int) $config['same_ip_count'], $ip_count ),
			array( 'odd_name', self::MEDIUM, self::is_odd_name( $values['firstName'], $values['lastName'] ), 0 ),
			array( 'bot_agent', self::MEDIUM, null !== $user_agent && ( '' === trim( $user_agent ) || 1 === preg_match( self::BOT_AGENT_PATTERN, $user_agent ) ), 0 ),
			array( 'bad_phone', self::WEAK, self::is_bad_phone( $values['phone'], (int) $config['min_phone_digits'] ), 0 ),
			array( 'disposable_email', self::WEAK, self::is_disposable_email( $values['email'], (array) $config['disposable_domains'] ), 0 ),
			array( 'short_message', self::WEAK, ! empty( $submission['has_message_field'] ) && self::length( trim( $message ) ) < (int) $config['min_message_length'], 0 ),
			array( 'duplicate', self::MEDIUM, isset( $submission['duplicate'] ) && true === $submission['duplicate'], 0 ),
		);

		$disabled = array_map( 'strval', (array) $config['disabled'] );
		$reasons  = array();

		foreach ( $signals as $signal ) {
			list( $id, $strength, $fired, $count ) = $signal;

			if ( $fired && ! in_array( $id, $disabled, true ) ) {
				$reasons[] = array(
					'id'       => $id,
					'strength' => $strength,
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

		if ( $counts[ self::STRONG ] >= (int) $config['strong_to_spam'] || $counts[ self::MEDIUM ] >= (int) $config['medium_to_spam'] ) {
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

		return strlen( $digits ) < $min_digits || 1 === preg_match( '~^(\d)\1*$~', $digits );
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
