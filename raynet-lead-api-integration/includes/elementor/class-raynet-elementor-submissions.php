<?php
/**
 * Stored Elementor Pro form submissions: reading, judging and importing them.
 *
 * Elementor Pro keeps every submission of a form that collects them in its
 * own tables. A submission that arrived before the RAYNET action was switched
 * on, or that RAYNET refused at the time, exists only there. This class reads
 * those tables, suggests which rows are real enquiries, and sends one row at a
 * time to RAYNET by the same rules as a live submission.
 *
 * Loaded without Elementor Pro: every method that touches its tables or
 * classes checks available() first.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads Elementor's stored submissions and turns one into a RAYNET lead.
 */
class Raynet_Elementor_Submissions {

	const STATE_NEW    = 'new';
	const STATE_FAILED = 'failed';
	const STATE_SENT   = 'sent';

	const TAB_REVIEW  = 'review';
	const TAB_SPAM    = 'spam';
	const TAB_SENT    = 'sent';
	const TAB_BLOCKED = 'blocked';

	/**
	 * Prefix of the transients holding one form's index: a head named
	 * CACHE_PREFIX . md5( "post_id:widget_id" ), and its chunks named the
	 * same with "_0", "_1", ... appended.
	 */
	const CACHE_PREFIX = 'raynet_import_idx_';

	/**
	 * Option holding the generation of every cached index. forget_index()
	 * replaces it; an index built under an older one is never read.
	 */
	const CACHE_GENERATION = 'raynet_import_idx_gen';

	/**
	 * How long an index stays cached, in seconds.
	 */
	const CACHE_TTL = 600;

	/**
	 * Submissions per cached chunk of an index; the
	 * `raynet_import_index_chunk_size` filter can lower it.
	 */
	const CACHE_CHUNK = 2000;

	/**
	 * Values of an index entry as the cache stores them: by position.
	 */
	const CACHE_TABS     = array( 'review', 'spam', 'sent', 'blocked' );
	const CACHE_VERDICTS = array( 'ok', 'suspect', 'spam' );
	const CACHE_STATES   = array( 'new', 'failed', 'sent' );

	/**
	 * Reason strengths and AI verdicts as the cache stores them: by position,
	 * with 0 for "unknown" / "none".
	 */
	const CACHE_STRENGTHS = array( '', 'strong', 'medium', 'weak' );
	const CACHE_AI        = array( '', 'import', 'reject', 'none' );

	/**
	 * Tag added to every imported lead unless the screen says otherwise.
	 */
	const DEFAULT_TAG = 'import-web';

	/**
	 * Prefix of the option that locks one submission while it is imported.
	 */
	const LOCK_PREFIX = 'raynet_import_lock_';

	/**
	 * Age in seconds after which a lock counts as left behind.
	 */
	const LOCK_TTL = 300;

	/**
	 * Age in seconds after which a form's AI review lock counts as left behind.
	 */
	const AI_LOCK_TTL = 150;

	/**
	 * Option bumped whenever AI verdicts are stored. An index build that saw
	 * it change while it ran does not cache what it built: it read the
	 * verdict rows before the change.
	 */
	const AI_STAMP = 'raynet_import_idx_ai_stamp';

	/**
	 * Outcomes of taking the import lock.
	 */
	const LOCK_HELD   = 'held';
	const LOCK_BUSY   = 'busy';
	const LOCK_FAILED = 'failed';

	/**
	 * Prefix of the option recording an import attempt of one submission that
	 * has no success row in Elementor's action log (yet).
	 */
	const ATTEMPT_PREFIX = 'raynet_import_attempt_';

	/**
	 * Outcomes of a recorded attempt: sent and never confirmed; RAYNET timed
	 * out or failed on its side; RAYNET refused the lead; the lead was created
	 * but Elementor's log row could not be written.
	 */
	const ATTEMPT_PENDING  = 'pending';
	const ATTEMPT_UNKNOWN  = 'unknown';
	const ATTEMPT_REFUSED  = 'refused';
	const ATTEMPT_UNLOGGED = 'unlogged';

	/**
	 * Characters of RAYNET's error text an attempt record keeps at most: a
	 * refused request may echo submitted values.
	 */
	const ATTEMPT_MESSAGE_MAX = 500;

	/**
	 * Elementor's query class, which owns the action log.
	 */
	const QUERY_CLASS = '\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Query';

	/**
	 * Tells whether stored submissions can be read and marked.
	 *
	 * The tables outlive a deactivated Elementor Pro, but marking a row needs
	 * its query class, so both are required.
	 *
	 * @return bool True when Elementor Pro is active and its table exists.
	 */
	public static function available() {
		if ( ! class_exists( '\\ElementorPro\\Plugin' ) || ! class_exists( self::QUERY_CLASS ) ) {
			return false;
		}

		global $wpdb;

		$table = self::table( 'e_submissions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checking for another plugin's table.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Stored submissions per form, for the whole site, in one query.
	 *
	 * A submission counts under its `post_id` and, when that differs, under
	 * the page it was sent from (`meta.edit_post_id`), because the scanner
	 * finds a form either in the page or in the template it lives in.
	 * Submissions in the trash do not count. A submission the plugin created
	 * a lead from but could not mark in Elementor's log (attempt outcome
	 * ATTEMPT_UNLOGGED) is in RAYNET, so it is not pending.
	 *
	 * @return array<string,array{pending:int,total:int}> "post_id:element_id" =>
	 *         submissions not yet in RAYNET, and all of them.
	 */
	public static function form_counts() {
		if ( ! self::available() ) {
			return array();
		}

		global $wpdb;

		$submissions = self::table( 'e_submissions' );
		$log         = self::table( 'e_submissions_actions_log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb->prefix and constants.
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.post_id, s.element_id, s.meta, COUNT(*) AS total,
				SUM( CASE WHEN EXISTS ( SELECT 1 FROM {$log} l WHERE l.submission_id = s.id AND l.action_name = %s AND l.status = %s )
					OR EXISTS ( SELECT 1 FROM {$wpdb->options} o WHERE o.option_name = CONCAT( %s, s.id ) AND o.option_value LIKE %s )
					THEN 0 ELSE 1 END ) AS pending
				FROM {$submissions} s
				WHERE s.status <> %s
				GROUP BY s.post_id, s.element_id, s.meta",
				Raynet_Elementor_Forms::ACTION_NAME,
				'success',
				self::ATTEMPT_PREFIX,
				// The outcome as serialize() writes it in record_attempt()'s array.
				'%' . $wpdb->esc_like( serialize( 'outcome' ) . serialize( self::ATTEMPT_UNLOGGED ) ) . '%', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matching a stored option value.
				'trash'
			),
			ARRAY_A
		);
		// phpcs:enable

		$counts = array();

		foreach ( (array) $groups as $group ) {
			$element = (string) $group['element_id'];
			$posts   = array( (int) $group['post_id'], self::edit_post_id( (string) $group['meta'] ) );

			foreach ( array_unique( array_filter( $posts ) ) as $post_id ) {
				$key = $post_id . ':' . $element;

				if ( ! isset( $counts[ $key ] ) ) {
					$counts[ $key ] = array(
						'pending' => 0,
						'total'   => 0,
					);
				}

				$counts[ $key ]['pending'] += (int) $group['pending'];
				$counts[ $key ]['total']   += (int) $group['total'];
			}
		}

		return $counts;
	}

	/**
	 * Walks every submission of a form in batches, newest first.
	 *
	 * @param int      $post_id   Post the form lives in.
	 * @param string   $widget_id Form widget id.
	 * @param callable $visit     Called with each batch of rows, keyed by id.
	 * @param int      $size      Rows per batch.
	 * @return void
	 */
	public static function each_batch( $post_id, $widget_id, callable $visit, $size = 500 ) {
		if ( ! self::available() ) {
			return;
		}

		global $wpdb;

		$size  = max( 1, (int) $size );
		$table = self::table( 'e_submissions' );
		$match = self::match_clause( (int) $post_id, (string) $widget_id );
		$last  = 0;

		do {
			$after = $last > 0 ? $wpdb->prepare( ' AND id < %d', $last ) : '';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Clauses are prepared above; the table name comes from $wpdb->prefix.
			$records = (array) $wpdb->get_results( "SELECT id, post_id, meta, created_at, referer, user_ip, user_agent FROM {$table} WHERE {$match}{$after} ORDER BY id DESC " . $wpdb->prepare( 'LIMIT %d', $size ), ARRAY_A );

			if ( empty( $records ) ) {
				break;
			}

			$last = (int) $records[ count( $records ) - 1 ]['id'];
			$rows = self::hydrate( $records, (int) $post_id );

			if ( ! empty( $rows ) ) {
				$visit( $rows );
			}
		} while ( count( $records ) === $size );
	}

	/**
	 * Reads chosen submissions of a form.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @param int[]  $ids       Submission ids.
	 * @return array<int,array<string,mixed>> Rows keyed by id: only those that
	 *                                        belong to the form and are not in the trash.
	 */
	public static function load( $post_id, $widget_id, array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) || ! self::available() ) {
			return array();
		}

		global $wpdb;

		$table = self::table( 'e_submissions' );
		$match = self::match_clause( (int) $post_id, (string) $widget_id );
		$in    = $wpdb->prepare( implode( ',', array_fill( 0, count( $ids ), '%d' ) ), $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Clauses are prepared above; the table name comes from $wpdb->prefix.
		$records = (array) $wpdb->get_results( "SELECT id, post_id, meta, created_at, referer, user_ip, user_agent FROM {$table} WHERE {$match} AND id IN ({$in}) ORDER BY id DESC", ARRAY_A );

		return self::hydrate( $records, (int) $post_id );
	}

	/**
	 * Rebuilds the fields of a stored submission in the shape of
	 * Form_Record::get( 'fields' ).
	 *
	 * Type and label come from the form as it is now; a field the form no
	 * longer has is plain text labelled by its id. Elementor stored a Number
	 * field through intval(), so an empty one reads 0: that is taken as empty.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array<string,array<string,string>> Fields keyed by field id.
	 */
	public static function fields_for( array $row, array $form_settings ) {
		$definitions = array();

		foreach ( (array) ( isset( $form_settings['form_fields'] ) ? $form_settings['form_fields'] : array() ) as $definition ) {
			if ( is_array( $definition ) && ! empty( $definition['custom_id'] ) ) {
				$definitions[ (string) $definition['custom_id'] ] = $definition;
			}
		}

		$fields = array();

		foreach ( (array) ( isset( $row['values'] ) ? $row['values'] : array() ) as $id => $value ) {
			$id    = (string) $id;
			$known = isset( $definitions[ $id ] );
			$type  = $known && ! empty( $definitions[ $id ]['field_type'] ) ? (string) $definitions[ $id ]['field_type'] : 'text';
			$title = $known && isset( $definitions[ $id ]['field_label'] ) ? (string) $definitions[ $id ]['field_label'] : $id;
			$value = is_scalar( $value ) ? (string) $value : '';

			if ( 'number' === $type && '0' === trim( $value ) ) {
				$value = '';
			}

			$fields[ $id ] = array(
				'id'        => $id,
				'type'      => $type,
				'title'     => $title,
				'value'     => $value,
				'raw_value' => $value,
			);
		}

		return $fields;
	}

	/**
	 * Builds what Raynet_Lead_Form::submit_lead() needs for one stored submission.
	 *
	 * The same path as the live action, with the differences an import has:
	 * the original date, no GDPR record, the referrer as the page, an extra
	 * tag, and a note line saying where the lead came from.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param string              $tag           Extra tag for the lead; empty for none.
	 * @return array{values:array<string,string>,settings:array<string,mixed>,context:array<string,mixed>}|WP_Error
	 *         Arguments, or `raynet_import_blocked` when RAYNET would refuse the contact.
	 */
	public static function prepare( array $row, array $form_settings, $tag ) {
		$parts  = self::assemble( $row, $form_settings );
		$fields = $parts['fields'];
		$mapped = $parts['mapped'];
		$error  = Raynet_Elementor_Lead_Builder::contact_error( $parts['values'] );

		if ( '' !== $error ) {
			return new WP_Error( 'raynet_import_blocked', $error );
		}

		$mapped['extras'] = Raynet_Elementor_Lead_Builder::merge_lines( Raynet_Elementor_Lead_Builder::noted_values( $fields, $form_settings ), $mapped['extras'] );
		$mapped['extras'] = Raynet_Elementor_Lead_Builder::merge_lines( $mapped['extras'], self::upload_lines( $fields, $form_settings ) );

		$form     = new Raynet_Lead_Form();
		$settings = $form->merge_lead_settings(
			Raynet_Lead_Settings::all(),
			Raynet_Elementor_Lead_Builder::lead_settings( $form_settings )
		);
		$created  = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		$context  = Raynet_Elementor_Lead_Builder::context( $mapped, $fields, $form_settings );

		// The legal title would start today, not when the visitor consented.
		$context['raynet_consent_record'] = false;
		$context['raynet_import']         = true;
		$context['raynet_lead_date']      = self::local_date( $created, 'Y-m-d' );
		$context['raynet_consent_time']   = self::local_date( $created, 'Y-m-d H:i' );
		$context['raynet_extra_tags']     = (string) $tag;
		// Elementor does not store a switch left at its default, which is on.
		$context['raynet_source_url']  = ! isset( $form_settings['raynet_crm_source_url'] ) || 'yes' === (string) $form_settings['raynet_crm_source_url']
			? ( isset( $row['referer'] ) ? (string) $row['referer'] : '' )
			: '';
		$context['raynet_import_note'] = sprintf(
			/* translators: %s: original submission date and time. */
			__( 'Importováno z uložených odeslání Elementoru. Původně odesláno: %s', 'raynet-lead-api-integration' ),
			self::local_date( $created, 'j. n. Y H:i' )
		);

		return array(
			'values'   => $parts['values'],
			'settings' => $settings,
			'context'  => $context,
		);
	}

	/**
	 * Counts across all submissions of a form, for spam scoring.
	 *
	 * @param int                 $post_id       Post the form lives in.
	 * @param string              $widget_id     Form widget id.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array{text_senders:array<string,int>,ip_counts:array<string,int>,first_of:array<string,int>}
	 *         Different senders per message, submissions per IP, and the
	 *         submission each "<email>|<text_key>" pair counts as original.
	 */
	public static function stats( $post_id, $widget_id, array $form_settings ) {
		$senders   = array();
		$ip_counts = array();
		$first     = array();

		$map = Raynet_Elementor_Forms::current_map( $form_settings );

		self::each_batch(
			$post_id,
			$widget_id,
			static function ( array $rows ) use ( $form_settings, $map, &$senders, &$ip_counts, &$first ) {
				foreach ( $rows as $row ) {
					$parts    = self::assemble( $row, $form_settings );
					$email    = strtolower( $parts['values']['email'] );
					$ip       = trim( (string) $row['ip'] );
					// The same text classify() hands to the scorer.
					$text_key = Raynet_Submission_Spam_Score::text_key( self::score_values( $parts['fields'], $map )['message'] );
					$sender   = '' !== $email ? $email : $ip;

					if ( '' !== $ip ) {
						$ip_counts[ $ip ] = ( isset( $ip_counts[ $ip ] ) ? $ip_counts[ $ip ] : 0 ) + 1;
					}

					if ( '' !== $text_key && '' !== $sender ) {
						$senders[ $text_key ][ $sender ] = true;
					}

					$key = self::duplicate_key( $email, $text_key );

					if ( '' === $key ) {
						continue;
					}

					// Rows arrive newest first, so a later one is older. A copy
					// already in RAYNET is the original whatever its age.
					$sent = self::STATE_SENT === $row['state'];

					if ( ! isset( $first[ $key ] ) || $sent || ! $first[ $key ]['sent'] ) {
						$first[ $key ] = array(
							'id'   => (int) $row['id'],
							'sent' => $sent,
						);
					}
				}
			}
		);

		return array(
			'text_senders' => array_map( 'count', $senders ),
			'ip_counts'    => $ip_counts,
			'first_of'     => array_map(
				static function ( $entry ) {
					return $entry['id'];
				},
				$first
			),
		);
	}

	/**
	 * Judges one stored submission and says where the import screen shows it.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param array<string,mixed> $stats         Result of stats().
	 * @param array<string,mixed> $config        Spam scoring config.
	 * @return array{state:string,verdict:string,reasons:array<int,array<string,mixed>>,tab:string,preselect:bool,block:string,number_warning:bool} Result.
	 */
	public static function classify( array $row, array $form_settings, array $stats, array $config ) {
		$state    = isset( $row['state'] ) ? (string) $row['state'] : self::STATE_NEW;
		$parts    = self::assemble( $row, $form_settings );
		$values   = $parts['values'];
		$block    = Raynet_Elementor_Lead_Builder::contact_error( $values );
		$map      = Raynet_Elementor_Forms::current_map( $form_settings );
		// Scored before sanitizing, which would strip the very markup the
		// rules look for. Only the scoring sees these; prepare() sends the
		// sanitized values.
		$raw      = self::score_values( $parts['fields'], $map );
		$dup_key  = self::duplicate_key( strtolower( $values['email'] ), Raynet_Submission_Spam_Score::text_key( $raw['message'] ) );
		$first_of = isset( $stats['first_of'] ) && is_array( $stats['first_of'] ) ? $stats['first_of'] : array();

		$submission = array(
			'values'            => $raw,
			'texts'             => self::other_texts( $parts['fields'], $map ),
			'ip'                => isset( $row['ip'] ) ? (string) $row['ip'] : '',
			'user_agent'        => self::stores_user_agent( $form_settings ) ? ( isset( $row['user_agent'] ) ? (string) $row['user_agent'] : '' ) : null,
			'has_message_field' => isset( $map['message'] ),
			'duplicate'         => '' !== $dup_key && isset( $first_of[ $dup_key ] ) && (int) $first_of[ $dup_key ] !== ( isset( $row['id'] ) ? (int) $row['id'] : 0 ),
		);

		$result = Raynet_Submission_Spam_Score::score( $submission, $stats, $config );

		/**
		 * Filters the spam verdict of one stored submission before import.
		 *
		 * `$submission['values']` and `$submission['texts']` are the RAW stored
		 * values: unsanitized and written by whoever sent the form. A callback
		 * that outputs or stores them must escape or sanitize them itself.
		 * A malformed result is ignored and the original verdict kept.
		 *
		 * @param array<string,mixed> $result     Verdict and reasons from Raynet_Submission_Spam_Score::score().
		 * @param array<string,mixed> $submission What was scored: raw mapped values, raw other texts, IP, user agent, flags.
		 * @param array<string,mixed> $row        Stored submission.
		 */
		$filtered = apply_filters( 'raynet_import_spam_score', $result, $submission, $row );

		// A filter that breaks the result must not turn spam into "ok", which
		// is preselected: anything malformed is ignored.
		if ( is_array( $filtered ) && isset( $filtered['verdict'], $filtered['reasons'] ) && is_array( $filtered['reasons'] )
			&& in_array( $filtered['verdict'], array( Raynet_Submission_Spam_Score::VERDICT_OK, Raynet_Submission_Spam_Score::VERDICT_SUSPECT, Raynet_Submission_Spam_Score::VERDICT_SPAM ), true ) ) {
			$result = $filtered;
		}

		$verdict = (string) $result['verdict'];
		$reasons = array_values( $result['reasons'] );

		if ( self::STATE_SENT === $state ) {
			$tab = self::TAB_SENT;
		} elseif ( '' !== $block ) {
			$tab = self::TAB_BLOCKED;
		} elseif ( Raynet_Submission_Spam_Score::VERDICT_SPAM === $verdict ) {
			$tab = self::TAB_SPAM;
		} else {
			$tab = self::TAB_REVIEW;
		}

		$number_warning = false;

		foreach ( $parts['fields'] as $field ) {
			if ( 'number' === $field['type'] && '' !== trim( $field['value'] ) ) {
				$number_warning = true;
			}
		}

		return array(
			'state'          => $state,
			'verdict'        => $verdict,
			'reasons'        => $reasons,
			'tab'            => $tab,
			'preselect'      => self::TAB_REVIEW === $tab && Raynet_Submission_Spam_Score::VERDICT_OK === $verdict && self::STATE_NEW === $state,
			'block'          => self::TAB_BLOCKED === $tab ? $block : '',
			'number_warning' => $number_warning,
		);
	}

	/**
	 * Every submission of a form, judged, newest first.
	 *
	 * Cached for a few minutes: judging needs two passes over all of them.
	 * The cache is dropped when the form's settings or the number of its
	 * submissions change, and by every import that changes a row's state
	 * (see forget_index()), also when that import runs while this index is
	 * being built. It is stored compactly, in chunks of
	 * CACHE_CHUNK submissions, so that no single row grows past what a
	 * database packet or an object cache item can hold. A cached reason keeps
	 * only its id, count and strength.
	 *
	 * A stored AI verdict that still fits the row's data is merged in here
	 * (Raynet_Import_Ai::merge()), so tabs, counts and paging follow it. Only
	 * the verdict ("import" / "reject") is cached, under `ai`; the screen
	 * reads the reason for the rows it shows. New verdicts are laid over the
	 * cache by apply_ai() and dropped verdicts drop the cache.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return array<int,array<string,mixed>> Submission id => classify() result
	 *         merged with the AI verdict, plus `ai` (null, "import", "reject",
	 *         or "none" for a row the model could not judge).
	 */
	public static function index( $post_id, $widget_id ) {
		if ( ! self::available() ) {
			return array();
		}

		// Read before anything the index is built from: an import that drops
		// the cache while this build runs replaces the generation, so what this
		// build stores is never read.
		$generation    = (string) get_option( self::CACHE_GENERATION, '' );
		$post_id       = (int) $post_id;
		$widget_id     = (string) $widget_id;
		$form_settings = Raynet_Elementor_Forms::form_settings( $post_id, $widget_id );

		// Importing an atomic form's submissions is not supported yet.
		if ( null === $form_settings || Raynet_Elementor_Forms::is_atomic( $form_settings ) ) {
			return array();
		}

		/**
		 * Filters the spam scoring config used by the import screen.
		 *
		 * Keys (see Raynet_Submission_Spam_Score::default_config()):
		 * strong_to_spam, medium_to_spam, medium_to_suspect, weak_to_suspect,
		 * medium_weak_to_spam (verdict thresholds); same_text_senders (different senders of one
		 * message for same_text); same_text_min_length (characters a message
		 * needs before same_text applies, default 40); same_ip_count;
		 * min_message_length; min_phone_digits; home_phone_prefixes
		 * (international prefixes the site serves, empty = not judged);
		 * keywords; disposable_domains; allowed_scripts (Unicode script
		 * names); disabled (signal ids).
		 *
		 * @param array<string,mixed> $config Thresholds, word lists, allowed scripts and disabled signals.
		 */
		$config      = self::spam_config();
		// The AI context too: a new model or business text makes every stored
		// verdict stale, and the cached entries carry them.
		$fingerprint = self::fingerprint( array( $generation, $form_settings, $config, self::count_submissions( $post_id, $widget_id ), Raynet_Import_Ai::context() ) );
		$cache_key   = self::cache_key( $post_id, $widget_id );
		$cached      = self::read_cache( $cache_key, $fingerprint );

		if ( null !== $cached ) {
			return $cached;
		}

		// Read before the verdict rows: see AI_STAMP.
		$stamp  = self::ai_stamp();
		$stats  = self::stats( $post_id, $widget_id, $form_settings );
		$packed = array();
		// Without a key and without a single stored row there is nothing to
		// lay over the rules: the classic screen does no AI work at all.
		$ai_on = Raynet_OpenAI_Client::configured() || Raynet_Import_Ai::any_stored();

		self::each_batch(
			$post_id,
			$widget_id,
			static function ( array $rows ) use ( $form_settings, $stats, $config, $ai_on, &$packed ) {
				$stored = $ai_on ? Raynet_Import_Ai::stored_rows( array_keys( $rows ) ) : array();

				foreach ( $rows as $id => $row ) {
					$packed[ (int) $id ] = self::pack( self::with_ai( $row, self::classify( $row, $form_settings, $stats, $config ), $form_settings, $stored ) );
				}
			}
		);

		if ( $stamp === self::ai_stamp() ) {
			self::write_cache( $cache_key, $fingerprint, $packed );
		}

		// What a cached read would give, so callers see one shape.
		return array_map( array( __CLASS__, 'unpack' ), $packed );
	}

	/**
	 * The spam scoring config, after the `raynet_import_spam_config` filter.
	 *
	 * @return array<string,mixed> Config.
	 */
	private static function spam_config() {
		/** This filter is documented in index(). */
		return (array) apply_filters( 'raynet_import_spam_config', Raynet_Submission_Spam_Score::default_config() );
	}

	/**
	 * Exactly what the AI review sends for one stored submission.
	 *
	 * The values are the sanitized ones a lead would carry, the notes the
	 * lines its note would get (fields chosen for the note and values the
	 * mapping could not place); Raynet_Import_Ai::prepare() masks and cuts
	 * them. Upload links are left out: a file URL tells the model nothing.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array<string,mixed> Payload.
	 */
	public static function ai_payload( array $row, array $form_settings ) {
		$parts = self::assemble( $row, $form_settings );
		$notes = Raynet_Elementor_Lead_Builder::merge_lines(
			Raynet_Elementor_Lead_Builder::noted_values( $parts['fields'], $form_settings ),
			$parts['mapped']['extras']
		);

		return Raynet_Import_Ai::prepare( $row, $parts['values'], $notes );
	}

	/**
	 * Submissions the AI review would send, oldest first.
	 *
	 * Only rows never sent, not blocked and without an attempt record, that
	 * have no AI verdict yet. A row the rules call Spam, or found a strong
	 * signal in, is left out: the model's word could not move it off Spam,
	 * so judging it would be paid for nothing.
	 *
	 * @param array<int,array<string,mixed>> $index Result of index().
	 * @param int                            $after Only ids above this one.
	 * @return int[] Submission ids, ascending.
	 */
	public static function ai_candidates( array $index, $after = 0 ) {
		$ids = array();

		foreach ( $index as $id => $item ) {
			if ( (int) $id <= (int) $after
				|| ! isset( $item['state'], $item['tab'] ) || self::STATE_NEW !== $item['state']
				|| ! in_array( $item['tab'], array( self::TAB_REVIEW, self::TAB_SPAM ), true )
				|| ! empty( $item['ai'] )
				// The model may only confirm, so a row the rules call Spam is
				// not worth paying for (see Raynet_Import_Ai::merge()).
				|| ( isset( $item['verdict'] ) && Raynet_Submission_Spam_Score::VERDICT_SPAM === $item['verdict'] )
				// The rule merge() applies: a row it would keep on Spam whatever the model says.
				|| Raynet_Import_Ai::has_strong( isset( $item['reasons'] ) ? (array) $item['reasons'] : array() ) ) {
				continue;
			}

			$ids[] = (int) $id;
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Lays the stored AI verdict, if it still fits the data, over a
	 * classify() result.
	 *
	 * Only rows on the two judged tabs are looked at: a row in RAYNET or one
	 * that cannot be imported keeps what the rules said.
	 *
	 * The payload is hashed only for a row that has a verdict row at all.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $item          classify() result.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param array<int,mixed>    $stored        Verdict rows of the batch, from Raynet_Import_Ai::stored_rows().
	 * @return array<string,mixed> Result with `ai` set.
	 */
	private static function with_ai( array $row, array $item, array $form_settings, array $stored ) {
		$id = (int) $row['id'];

		// What the rules said, kept apart: when the AI verdict a row was
		// lifted by turns out gone, the screen falls back to it.
		$item['rule_verdict'] = $item['verdict'];

		if ( ! isset( $stored[ $id ] ) || ! in_array( $item['tab'], array( self::TAB_REVIEW, self::TAB_SPAM ), true ) ) {
			$item['ai'] = null;

			return $item;
		}

		$payload = self::ai_payload( $row, $form_settings );

		return Raynet_Import_Ai::merge( $item, Raynet_Import_Ai::from_stored( $stored[ $id ], Raynet_Import_Ai::hash( $payload ) ) );
	}

	/**
	 * Says that AI verdicts were stored or dropped: an index build running
	 * now does not cache what it built (see AI_STAMP).
	 *
	 * @return void
	 */
	public static function verdicts_changed() {
		update_option( self::AI_STAMP, uniqid( '', true ), false );
	}

	/**
	 * The AI stamp, read past every cache: another request changes it.
	 *
	 * @return string Stamp, or an empty string.
	 */
	private static function ai_stamp() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Must see another request's write.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::AI_STAMP ) );
	}

	/**
	 * Lays new AI verdicts over the cached index of a form, in place.
	 *
	 * Rebuilding the index after every batch would judge the whole form again
	 * each time. The rule result of a row without a verdict is in the cache,
	 * strengths included, so merge() can be applied to it directly. A chunk is
	 * written back with the fingerprint it was read with: if an import
	 * replaced the generation meanwhile, the chunk no longer matches and the
	 * next index() computes everything again. Anything unexpected drops the
	 * cache, so the verdicts are then read from their option rows.
	 *
	 * Two AI runs of one form cannot interleave (lock_ai_run()). An index
	 * build running meanwhile in a page view read the verdict rows before
	 * they were stored; the AI_STAMP bumped here, before patching, keeps it
	 * from caching that result. Called after the verdicts are stored.
	 *
	 * @param int                             $post_id   Post the form lives in.
	 * @param string                          $widget_id Form widget id.
	 * @param array<int,array<string,string>> $verdicts  Submission id => verdict and reason.
	 * @return void
	 */
	public static function apply_ai( $post_id, $widget_id, array $verdicts ) {
		$post_id       = (int) $post_id;
		$widget_id     = (string) $widget_id;
		$generation    = (string) get_option( self::CACHE_GENERATION, '' );
		$form_settings = Raynet_Elementor_Forms::form_settings( $post_id, $widget_id );

		if ( empty( $verdicts ) || null === $form_settings ) {
			return;
		}

		self::verdicts_changed();

		$fingerprint = self::fingerprint( array( $generation, $form_settings, self::spam_config(), self::count_submissions( $post_id, $widget_id ), Raynet_Import_Ai::context() ) );
		$cache_key   = self::cache_key( $post_id, $widget_id );
		$head        = get_transient( $cache_key );

		// No usable cache: the next index() reads the stored verdicts anyway.
		if ( ! is_array( $head ) || ! isset( $head['fingerprint'], $head['chunks'] ) || $fingerprint !== $head['fingerprint'] ) {
			return;
		}

		$left = $verdicts;

		for ( $i = 0; $i < (int) $head['chunks'] && ! empty( $left ); $i++ ) {
			$chunk = get_transient( $cache_key . '_' . $i );

			if ( ! is_array( $chunk ) || ! isset( $chunk['fingerprint'], $chunk['items'] ) || $fingerprint !== $chunk['fingerprint'] || ! is_array( $chunk['items'] ) ) {
				self::forget_index( $post_id, $widget_id );
				return;
			}

			$changed = false;

			foreach ( $left as $id => $ai ) {
				if ( ! isset( $chunk['items'][ $id ] ) ) {
					continue;
				}

				$item = self::unpack( $chunk['items'][ $id ] );

				// A row that has a verdict already carries its reason in the
				// merged result; merging again would stack them.
				if ( null === $item || null !== $item['ai'] ) {
					self::forget_index( $post_id, $widget_id );
					return;
				}

				unset( $left[ $id ] );

				// As with_ai(): a row in RAYNET or one that cannot be imported
				// keeps what the rules said.
				if ( ! in_array( $item['tab'], array( self::TAB_REVIEW, self::TAB_SPAM ), true ) ) {
					continue;
				}

				$chunk['items'][ $id ] = self::pack( Raynet_Import_Ai::merge( $item, $ai ) );
				$changed               = true;
			}

			if ( $changed ) {
				set_transient( $cache_key . '_' . $i, $chunk, self::CACHE_TTL );
			}
		}
	}

	/**
	 * Drops the cached index of a form, head and chunks, and replaces the
	 * cache generation.
	 *
	 * The next index() recomputes it, so a row an import changed is judged
	 * again together with the rest: a copy of a submission just sent becomes
	 * its duplicate. An index() that was already running read its rows
	 * before the change and would store them after this delete; the new
	 * generation makes what it stores unreadable, and it expires with the
	 * transients' TTL. The generation is a fresh unique value rather than a
	 * counter, so two imports that replace it at once cannot both write the
	 * value such a build read. It is shared by all forms: replacing it makes
	 * every form's index be computed again once.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return void
	 */
	public static function forget_index( $post_id, $widget_id ) {
		update_option( self::CACHE_GENERATION, uniqid( '', true ), false );

		$cache_key = self::cache_key( (int) $post_id, (string) $widget_id );
		$head      = get_transient( $cache_key );

		// The head first: without it no chunk is read.
		delete_transient( $cache_key );

		$chunks = is_array( $head ) && isset( $head['chunks'] ) ? (int) $head['chunks'] : 0;

		for ( $i = 0; $i < $chunks; $i++ ) {
			delete_transient( $cache_key . '_' . $i );
		}
	}

	/**
	 * Reads a cached index.
	 *
	 * @param string $cache_key   Name of the head transient.
	 * @param string $fingerprint What the index must have been built from.
	 * @return array<int,array<string,mixed>>|null Index, or null when any part
	 *                                             is missing, stale or malformed.
	 */
	private static function read_cache( $cache_key, $fingerprint ) {
		$head = get_transient( $cache_key );

		if ( ! is_array( $head ) || ! isset( $head['fingerprint'], $head['chunks'] ) || $fingerprint !== $head['fingerprint'] ) {
			return null;
		}

		$items = array();

		for ( $i = 0; $i < (int) $head['chunks']; $i++ ) {
			$chunk = get_transient( $cache_key . '_' . $i );

			if ( ! is_array( $chunk ) || ! isset( $chunk['fingerprint'], $chunk['items'] ) || $fingerprint !== $chunk['fingerprint'] || ! is_array( $chunk['items'] ) ) {
				return null;
			}

			foreach ( $chunk['items'] as $id => $packed ) {
				$item = self::unpack( $packed );

				if ( null === $item ) {
					return null;
				}

				$items[ (int) $id ] = $item;
			}
		}

		return $items;
	}

	/**
	 * Caches an index: the chunks, then the head that makes them readable.
	 *
	 * @param string                  $cache_key   Name of the head transient.
	 * @param string                  $fingerprint What the index was built from.
	 * @param array<int,array<mixed>> $packed      Submission id => pack() result, newest first.
	 * @return void
	 */
	private static function write_cache( $cache_key, $fingerprint, array $packed ) {
		/**
		 * Filters how many submissions one cached chunk of an import index holds.
		 *
		 * Lower it when the object cache limits the size of one item.
		 *
		 * @param int $size Submissions per chunk, CACHE_CHUNK by default.
		 */
		$size   = max( 1, (int) apply_filters( 'raynet_import_index_chunk_size', self::CACHE_CHUNK ) );
		$chunks = array_chunk( $packed, $size, true );

		foreach ( $chunks as $i => $items ) {
			set_transient(
				$cache_key . '_' . $i,
				array(
					'fingerprint' => $fingerprint,
					'items'       => $items,
				),
				self::CACHE_TTL
			);
		}

		set_transient(
			$cache_key,
			array(
				'fingerprint' => $fingerprint,
				'chunks'      => count( $chunks ),
			),
			self::CACHE_TTL
		);
	}

	/**
	 * One classify() result as the cache stores it.
	 *
	 * The strength of each reason is kept, so the AI layer can tell a strong
	 * rule signal from a cached entry (see apply_ai(), ai_candidates()).
	 *
	 * @param array<string,mixed> $item classify() result, with `ai` merged in.
	 * @return array<int,mixed> Tab, verdict, preselect, state, block, reasons
	 *                          (id, count and strength, or a plain text),
	 *                          number warning, AI verdict.
	 */
	private static function pack( array $item ) {
		$reasons = array();

		foreach ( (array) $item['reasons'] as $reason ) {
			if ( is_array( $reason ) ) {
				$strength  = isset( $reason['strength'] ) && is_string( $reason['strength'] ) ? array_search( $reason['strength'], self::CACHE_STRENGTHS, true ) : false;
				$reasons[] = array(
					isset( $reason['id'] ) && is_scalar( $reason['id'] ) ? (string) $reason['id'] : '',
					isset( $reason['count'] ) && is_scalar( $reason['count'] ) ? (int) $reason['count'] : 0,
					false === $strength ? 0 : (int) $strength,
				);
			} elseif ( is_scalar( $reason ) ) {
				// A raynet_import_spam_score filter may give plain texts.
				$reasons[] = (string) $reason;
			}
		}

		return array(
			(int) array_search( $item['tab'], self::CACHE_TABS, true ),
			(int) array_search( $item['verdict'], self::CACHE_VERDICTS, true ),
			$item['preselect'] ? 1 : 0,
			(int) array_search( $item['state'], self::CACHE_STATES, true ),
			(string) $item['block'],
			$reasons,
			$item['number_warning'] ? 1 : 0,
			(int) array_search( self::ai_verdict( $item ), self::CACHE_AI, true ),
			(int) array_search( isset( $item['rule_verdict'] ) ? $item['rule_verdict'] : $item['verdict'], self::CACHE_VERDICTS, true ),
		);
	}

	/**
	 * The AI verdict of an index entry or a merge() result.
	 *
	 * @param array<string,mixed> $item Entry.
	 * @return string import, reject, none, or empty.
	 */
	private static function ai_verdict( array $item ) {
		$ai = isset( $item['ai'] ) ? $item['ai'] : null;

		if ( is_array( $ai ) ) {
			$ai = isset( $ai['verdict'] ) ? $ai['verdict'] : null;
		}

		return is_string( $ai ) && in_array( $ai, array( 'import', 'reject', Raynet_Import_Ai::NONE ), true ) ? $ai : '';
	}

	/**
	 * A cached entry back in the shape of classify()'s result.
	 *
	 * @param mixed $packed pack() result.
	 * @return array<string,mixed>|null Entry, or null when malformed.
	 */
	private static function unpack( $packed ) {
		$tabs     = self::CACHE_TABS;
		$verdicts = self::CACHE_VERDICTS;
		$states   = self::CACHE_STATES;
		$ais      = self::CACHE_AI;

		// An entry of an older, shorter shape is malformed here: the index is
		// then computed again, with strengths, AI and rule verdicts.
		if ( ! is_array( $packed ) || array( 0, 1, 2, 3, 4, 5, 6, 7, 8 ) !== array_keys( $packed ) || ! is_int( $packed[0] ) || ! isset( $tabs[ $packed[0] ] )
			|| ! is_int( $packed[1] ) || ! isset( $verdicts[ $packed[1] ] ) || ! is_int( $packed[3] ) || ! isset( $states[ $packed[3] ] ) || ! is_array( $packed[5] )
			|| ! is_int( $packed[7] ) || ! isset( $ais[ $packed[7] ] ) || ! is_int( $packed[8] ) || ! isset( $verdicts[ $packed[8] ] ) ) {
			return null;
		}

		$reasons = array();

		foreach ( $packed[5] as $reason ) {
			if ( ! is_array( $reason ) ) {
				$reasons[] = (string) $reason;
				continue;
			}

			if ( 3 !== count( $reason ) ) {
				return null;
			}

			$strengths = self::CACHE_STRENGTHS;
			$reasons[] = array(
				'id'       => (string) $reason[0],
				'strength' => isset( $strengths[ (int) $reason[2] ] ) ? $strengths[ (int) $reason[2] ] : '',
				'count'    => (int) $reason[1],
			);
		}

		return array(
			'state'          => $states[ $packed[3] ],
			'verdict'        => $verdicts[ $packed[1] ],
			'reasons'        => $reasons,
			'tab'            => $tabs[ $packed[0] ],
			'preselect'      => (bool) $packed[2],
			'block'          => (string) $packed[4],
			'number_warning' => (bool) $packed[6],
			'rule_verdict'   => $verdicts[ $packed[8] ],
			'ai'             => '' === $ais[ $packed[7] ] ? null : $ais[ $packed[7] ],
		);
	}

	/**
	 * Sends one stored submission to RAYNET as a lead.
	 *
	 * Everything is checked again against the database, not the cached index:
	 * the form, that the submission belongs to it and is not in the trash, and
	 * that its action log has no successful RAYNET entry yet.
	 *
	 * That check runs under a per-submission lock: a row in the options table
	 * whose name is unique (the table's unique key on option_name), inserted
	 * with INSERT IGNORE. Of two concurrent requests for the same submission
	 * exactly one inserts the row; the other is refused before it reads the
	 * state, so two tabs or a double click cannot both send it. A lock older
	 * than LOCK_TTL is taken as left behind by a request that died and is
	 * taken over, again by at most one request (see lock()). The lock is
	 * released on every way out, and only if it is still this request's own.
	 * A request that runs longer than LOCK_TTL can lose its lock to another;
	 * that one then still finds the success entry in the log, unless the
	 * first has not written it yet.
	 *
	 * Every send is recorded in the plugin's own option row
	 * (ATTEMPT_PREFIX . id) before RAYNET is called, as `pending`, so a
	 * request that dies on the way still leaves a trace. The row goes once
	 * Elementor's log holds the success; otherwise it says what happened:
	 * `unknown` (RAYNET timed out or failed on its side: the lead may exist),
	 * `refused`, or `unlogged` (the lead exists, Elementor's log row could not
	 * be written even on a second try). A submission with such a row is never
	 * preselected again; see hydrate(). When the `pending` row cannot be
	 * written, nothing is sent and the error is `raynet_import_lock_failed`,
	 * as for a lock that cannot be taken.
	 *
	 * Errors besides those of the checks: `raynet_import_unknown` when the
	 * outcome is unknown, `raynet_api_error` when RAYNET refused, and
	 * `raynet_import_unlogged` with the lead id in its data.
	 *
	 * @param int    $post_id       Post the form lives in.
	 * @param string $widget_id     Form widget id.
	 * @param int    $submission_id Submission id.
	 * @param string $tag           Extra tag for the lead.
	 * @return array{lead_id:int}|WP_Error New lead id, or why nothing was sent.
	 */
	public static function import( $post_id, $widget_id, $submission_id, $tag ) {
		if ( ! self::available() ) {
			return new WP_Error( 'raynet_import_unavailable', __( 'Import potřebuje aktivní Elementor Pro s uloženými odesláními.', 'raynet-lead-api-integration' ) );
		}

		$post_id       = (int) $post_id;
		$widget_id     = (string) $widget_id;
		$submission_id = (int) $submission_id;
		$form_settings = Raynet_Elementor_Forms::form_settings( $post_id, $widget_id );

		// An atomic form is refused as if it were missing: importing its
		// submissions is not supported yet, and the screen offers no link.
		if ( null === $form_settings || Raynet_Elementor_Forms::is_atomic( $form_settings ) || ! Raynet_Elementor_Forms::maps_contact( $form_settings ) ) {
			return new WP_Error( 'raynet_import_no_form', __( 'Formulář nebyl nalezen nebo nemá namapovaný e-mail ani telefon.', 'raynet-lead-api-integration' ) );
		}

		$token = time() . ':' . wp_generate_password( 12, false );

		$lock = self::lock( $submission_id, $token );

		if ( self::LOCK_FAILED === $lock ) {
			return new WP_Error( 'raynet_import_lock_failed', __( 'Import se nepodařilo spustit kvůli chybě databáze. Do RAYNETu se nic neodeslalo.', 'raynet-lead-api-integration' ) );
		}

		if ( self::LOCK_HELD !== $lock ) {
			return new WP_Error( 'raynet_import_already_sent', __( 'Toto odeslání se právě importuje.', 'raynet-lead-api-integration' ) );
		}

		try {
			return self::send( $post_id, $widget_id, $submission_id, $form_settings, $tag );
		} finally {
			self::unlock( $submission_id, $token );
		}
	}

	/**
	 * The part of import() that runs under the submission's lock.
	 *
	 * @param int                 $post_id       Post the form lives in.
	 * @param string              $widget_id     Form widget id.
	 * @param int                 $submission_id Submission id.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @param string              $tag           Extra tag for the lead.
	 * @return array{lead_id:int}|WP_Error New lead id, or why nothing was sent.
	 */
	private static function send( $post_id, $widget_id, $submission_id, array $form_settings, $tag ) {
		$rows = self::load( $post_id, $widget_id, array( $submission_id ) );

		if ( ! isset( $rows[ $submission_id ] ) ) {
			return new WP_Error( 'raynet_import_not_found', __( 'Odeslání k tomuto formuláři nepatří nebo je v koši.', 'raynet-lead-api-integration' ) );
		}

		$row = $rows[ $submission_id ];

		if ( self::STATE_SENT === $row['state'] ) {
			return new WP_Error( 'raynet_import_already_sent', __( 'Odeslání už v RAYNETu je.', 'raynet-lead-api-integration' ) );
		}

		$prepared = self::prepare( $row, $form_settings, $tag );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		// From here on a send may happen: say so before it does. Without that
		// record an unknown outcome would come back preselected, so nothing
		// is sent when it cannot be written.
		$previous = get_option( self::ATTEMPT_PREFIX . $submission_id, null );

		if ( ! self::record_attempt( $submission_id, self::ATTEMPT_PENDING ) ) {
			return new WP_Error( 'raynet_import_lock_failed', __( 'Import se nepodařilo spustit kvůli chybě databáze. Do RAYNETu se nic neodeslalo.', 'raynet-lead-api-integration' ) );
		}

		$form   = new Raynet_Lead_Form();
		$result = $form->submit_lead( $prepared['values'], $prepared['settings'], $prepared['context'] );

		if ( is_wp_error( $result ) ) {
			return self::failed( $post_id, $widget_id, $submission_id, $result, is_array( $previous ) ? $previous : null );
		}

		$lead_id = isset( $result['lead_id'] ) ? (int) $result['lead_id'] : 0;
		$query   = call_user_func( array( self::QUERY_CLASS, 'get_instance' ) );
		$status  = constant( self::QUERY_CLASS . '::ACTIONS_LOG_STATUS_SUCCESS' );
		/* translators: %d: lead id. */
		$entry = sprintf( __( 'Importováno, lead %d', 'raynet-lead-api-integration' ), $lead_id );

		// The action log is how a submission is known to be in RAYNET. The
		// success counter stays: the action may not have been on the form then.
		// One more try before giving up: a second lead is worse than a retry.
		$logged = $query->add_action_log( $submission_id, self::action(), $status, $entry );

		if ( false === $logged ) {
			$logged = $query->add_action_log( $submission_id, self::action(), $status, $entry );
		}

		self::forget_index( $post_id, $widget_id );

		// Without the log row a later index would offer the submission again;
		// the attempt row keeps it out of reach and remembers the lead.
		if ( false === $logged ) {
			self::record_attempt( $submission_id, self::ATTEMPT_UNLOGGED, '', $lead_id );

			return new WP_Error(
				'raynet_import_unlogged',
				sprintf(
					/* translators: %d: lead id. */
					__( 'Lead %d byl v RAYNETu založen, ale odeslání se nepodařilo označit jako odeslané. Než ho budete importovat znovu, ověřte v RAYNETu, že lead už existuje.', 'raynet-lead-api-integration' ),
					$lead_id
				),
				array( 'lead_id' => $lead_id )
			);
		}

		self::forget_attempt( $submission_id );

		return array( 'lead_id' => $lead_id );
	}

	/**
	 * Records what a failed send means and turns it into import()'s error.
	 *
	 * Nothing left the site (the plugin is not configured, or the payload
	 * could not be encoded): the attempt record from before this send is put
	 * back, or removed when there was none. RAYNET did not answer, or
	 * answered with a 5xx: the lead may exist, so the outcome is unknown. Any
	 * other answer is a refusal, kept with its reason.
	 *
	 * @param int                      $post_id       Post the form lives in.
	 * @param string                   $widget_id     Form widget id.
	 * @param int                      $submission_id Submission id.
	 * @param WP_Error                 $error         Error from submit_lead().
	 * @param array<string,mixed>|null $previous      Attempt record from before this send, if any.
	 * @return WP_Error `raynet_import_unknown` or `raynet_api_error`.
	 */
	private static function failed( $post_id, $widget_id, $submission_id, WP_Error $error, $previous ) {
		self::forget_index( $post_id, $widget_id );

		$data       = $error->get_error_data();
		$diagnostic = is_array( $data ) && isset( $data['diagnostic'] ) ? (string) $data['diagnostic'] : $error->get_error_message();
		$code       = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
		$status     = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 'raynet_transport_error' === $code || $status >= 500 ) {
			self::record_attempt( $submission_id, self::ATTEMPT_UNKNOWN, $diagnostic );

			return new WP_Error(
				'raynet_import_unknown',
				sprintf(
					/* translators: %s: error detail. */
					__( 'Výsledek je neznámý: RAYNET neodpověděl nebo ohlásil chybu na své straně, lead tam proto možná vznikl. Než odeslání zkusíte znovu, ověřte v RAYNETu, že lead neexistuje. (%s)', 'raynet-lead-api-integration' ),
					$diagnostic
				)
			);
		}

		if ( 0 === strpos( $code, 'raynet_http_' ) ) {
			self::record_attempt( $submission_id, self::ATTEMPT_REFUSED, $diagnostic );
		} elseif ( null !== $previous ) {
			// Nothing was sent this time: what an earlier attempt left stands.
			update_option( self::ATTEMPT_PREFIX . (int) $submission_id, $previous, false );
		} else {
			self::forget_attempt( $submission_id );
		}

		return new WP_Error( 'raynet_api_error', $diagnostic );
	}

	/**
	 * Writes the attempt record of one submission, replacing any earlier one.
	 *
	 * The message is cut to ATTEMPT_MESSAGE_MAX characters.
	 *
	 * @param int    $submission_id Submission id.
	 * @param string $outcome       One of the ATTEMPT_* outcomes.
	 * @param string $message       What RAYNET or the connection said; may be empty.
	 * @param int    $lead_id       Lead id, for ATTEMPT_UNLOGGED only.
	 * @return bool Whether the database holds the record now.
	 */
	private static function record_attempt( $submission_id, $outcome, $message = '', $lead_id = 0 ) {
		global $wpdb;

		$name    = self::ATTEMPT_PREFIX . (int) $submission_id;
		$message = (string) $message;
		$record  = array(
			'time'    => time(),
			'outcome' => (string) $outcome,
			'message' => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, self::ATTEMPT_MESSAGE_MAX, 'UTF-8' ) : substr( $message, 0, self::ATTEMPT_MESSAGE_MAX ),
			'lead_id' => (int) $lead_id,
		);

		if ( update_option( $name, $record, false ) ) {
			return true;
		}

		// update_option() is false also when the same value is stored already,
		// so ask the database, past the options cache, what it holds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checking a write the options cache would hide.
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return maybe_serialize( $record ) === $stored;
	}

	/**
	 * Removes the attempt record of one submission.
	 *
	 * @param int $submission_id Submission id.
	 * @return void
	 */
	private static function forget_attempt( $submission_id ) {
		delete_option( self::ATTEMPT_PREFIX . (int) $submission_id );
	}

	/**
	 * Takes the import lock of one submission.
	 *
	 * The lock is an options row read and written only with raw SQL, never
	 * through get_option() / add_option() / delete_option(): those go through
	 * the options and notoptions object caches, and add_option() checks for
	 * the row and inserts it in two steps, so two requests can both succeed.
	 * Here the database decides: option_name is a unique key, so of two
	 * INSERT IGNOREs exactly one affects a row.
	 *
	 * A stale lock is removed by a DELETE that names the stale value read; if
	 * two requests try, only one deletes a row, and only that one tries the
	 * INSERT again — which a third request may still win.
	 *
	 * A failing INSERT with no lock row behind it is a database error, not
	 * another import: it is reported as LOCK_FAILED.
	 *
	 * @param int    $submission_id Submission id.
	 * @param string $token         This request's token, "<unix time>:<random>".
	 * @return string LOCK_HELD when the lock is now held with this token,
	 *                LOCK_BUSY when another request holds it, LOCK_FAILED on a
	 *                database error.
	 */
	private static function lock( $submission_id, $token ) {
		return self::lock_row( self::LOCK_PREFIX . (int) $submission_id, $token, self::LOCK_TTL );
	}

	/**
	 * Takes the AI review lock of a form: one run per form at a time, so two
	 * windows never pay for the same rows.
	 *
	 * Same mechanism as lock(), with a shorter expiry: one batch takes an
	 * index read and one OpenAI call of at most 60 s, and every request of a
	 * run takes the lock afresh, so AI_LOCK_TTL only has to outlast one.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @param string $token     This request's token, "<unix time>:<random>".
	 * @return string LOCK_HELD, LOCK_BUSY or LOCK_FAILED.
	 */
	public static function lock_ai_run( $post_id, $widget_id, $token ) {
		return self::lock_row( self::ai_lock_name( $post_id, $widget_id ), $token, self::AI_LOCK_TTL );
	}

	/**
	 * Releases the AI review lock of a form, if it is still this token's.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @param string $token     Token the lock was taken with.
	 * @return void
	 */
	public static function unlock_ai_run( $post_id, $widget_id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::ai_lock_name( $post_id, $widget_id ), $token ) );
	}

	/**
	 * Option name of a form's AI review lock. It shares the import locks'
	 * prefix, so uninstall removes it with them.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return string Option name.
	 */
	private static function ai_lock_name( $post_id, $widget_id ) {
		return self::LOCK_PREFIX . 'ai_' . md5( (int) $post_id . ':' . (string) $widget_id );
	}

	/**
	 * Takes a lock row: see lock().
	 *
	 * @param string $name  Option name.
	 * @param string $token Token, "<unix time>:<random>".
	 * @param int    $ttl   Age in seconds after which the row counts as left behind.
	 * @return string LOCK_HELD, LOCK_BUSY or LOCK_FAILED.
	 */
	private static function lock_row( $name, $token, $ttl ) {
		global $wpdb;

		$inserted = self::insert_lock( $name, $token );

		if ( 1 === $inserted ) {
			return self::LOCK_HELD;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The lock bypasses the options cache on purpose.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $current ) {
			return false === $inserted ? self::LOCK_FAILED : self::LOCK_BUSY;
		}

		if ( (int) strtok( (string) $current, ':' ) > time() - (int) $ttl ) {
			return self::LOCK_BUSY;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See above.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current ) );

		if ( 1 !== $deleted ) {
			return self::LOCK_BUSY;
		}

		$inserted = self::insert_lock( $name, $token );

		if ( 1 === $inserted ) {
			return self::LOCK_HELD;
		}

		return false === $inserted ? self::LOCK_FAILED : self::LOCK_BUSY;
	}

	/**
	 * Inserts the lock row unless one exists.
	 *
	 * @param string $name  Option name.
	 * @param string $token Lock token.
	 * @return int|false 1 when this call inserted the row, 0 when one existed,
	 *                   false on a database error.
	 */
	private static function insert_lock( $name, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See lock().
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token ) );

		return false === $result ? false : (int) $result;
	}

	/**
	 * Releases the import lock of one submission, if it is still this token's.
	 *
	 * @param int    $submission_id Submission id.
	 * @param string $token         Token the lock was taken with.
	 * @return void
	 */
	private static function unlock( $submission_id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_PREFIX . (int) $submission_id, $token ) );
	}

	/**
	 * Fields, mapped values and collected basic values of a stored submission.
	 *
	 * @param array<string,mixed> $row           Stored submission.
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return array{fields:array<string,array<string,string>>,mapped:array<string,mixed>,values:array<string,string>} Parts.
	 */
	private static function assemble( array $row, array $form_settings ) {
		$fields = self::fields_for( $row, $form_settings );
		$mapped = Raynet_Elementor_Lead_Builder::mapped_values( $fields, $form_settings );
		$form   = new Raynet_Lead_Form();

		return array(
			'fields' => $fields,
			'mapped' => $mapped,
			'values' => $form->collect_values( $mapped['basic'] ),
		);
	}

	/**
	 * Note lines for upload fields: the stored link to the file.
	 *
	 * The file itself may be long gone from the server, so it is not attached.
	 * A field counts when it is attached by the mapping (the default for an
	 * upload field) or chosen for the note.
	 *
	 * @param array<string,array<string,string>> $fields        Fields from fields_for().
	 * @param array<string,mixed>                $form_settings Form settings.
	 * @return array<string,string> Label => link.
	 */
	private static function upload_lines( array $fields, array $form_settings ) {
		$targets = Raynet_Elementor_Forms::field_targets( $form_settings );
		$noted   = Raynet_Elementor_Forms::id_list( $form_settings, Raynet_Elementor_Forms::NOTICE_KEY );
		$lines   = array();

		foreach ( $fields as $id => $field ) {
			$value = trim( sanitize_textarea_field( $field['value'] ) );

			if ( 'upload' !== $field['type'] || '' === $value ) {
				continue;
			}

			$attached = isset( $targets[ $id ] ) && Raynet_Elementor_Forms::TARGET_ATTACH === $targets[ $id ];

			if ( ! $attached && ! in_array( (string) $id, $noted, true ) ) {
				continue;
			}

			$label = '' !== trim( $field['title'] ) ? sanitize_text_field( $field['title'] ) : (string) $id;
			$lines = Raynet_Elementor_Lead_Builder::merge_lines( $lines, array( $label => mb_substr( $value, 0, 1000 ) ) );
		}

		return $lines;
	}

	/**
	 * The values the spam rules read, as stored: before any sanitizing.
	 *
	 * Each comes from the field the mapping points at. A whole name mapped
	 * to fullName is split the way collect_values() splits it, so the first
	 * and last name are not the same raw string twice.
	 *
	 * @param array<string,array<string,string>> $fields Fields from fields_for().
	 * @param array<string,string>               $map    Current mapping, attribute => field id.
	 * @return array<string,string> Raynet_Submission_Spam_Score::VALUE_KEYS => raw value.
	 */
	private static function score_values( array $fields, array $map ) {
		$raw = static function ( $remote ) use ( $fields, $map ) {
			if ( ! isset( $map[ $remote ], $fields[ $map[ $remote ] ] ) || 'password' === $fields[ $map[ $remote ] ]['type'] ) {
				return '';
			}

			return (string) $fields[ $map[ $remote ] ]['value'];
		};

		$values = array();

		foreach ( Raynet_Submission_Spam_Score::VALUE_KEYS as $key ) {
			$values[ $key ] = $raw( $key );
		}

		$whole = trim( (string) preg_replace( '/\s+/u', ' ', $raw( 'fullName' ) ) );

		if ( '' !== $whole ) {
			$split = Raynet_Lead_Form_Definition::split_name( $whole );

			// Same rule as collect_values(): a separately asked part wins,
			// unless it is the same field again.
			foreach ( array( 'firstName', 'lastName' ) as $part ) {
				if ( '' === trim( $values[ $part ] ) || trim( $values[ $part ] ) === $whole ) {
					$values[ $part ] = isset( $split[ $part ] ) ? (string) $split[ $part ] : '';
				}
			}
		}

		return $values;
	}

	/**
	 * Stored values other than the basic attributes, for the spam rules.
	 *
	 * @param array<string,array<string,string>> $fields Fields from fields_for().
	 * @param array<string,string>               $map    Current mapping, attribute => field id.
	 * @return string[] Texts.
	 */
	private static function other_texts( array $fields, array $map ) {
		$basic = array();

		foreach ( $map as $remote => $local ) {
			if ( Raynet_Lead_Form_Definition::is_lead_source( $remote ) ) {
				$basic[] = $local;
			}
		}

		$texts = array();

		foreach ( $fields as $id => $field ) {
			if ( '' === $field['value'] || in_array( (string) $id, $basic, true ) || in_array( $field['type'], Raynet_Elementor_Forms::UNSENDABLE_TYPES, true ) ) {
				continue;
			}

			$texts[] = $field['value'];
		}

		return $texts;
	}

	/**
	 * Tells whether the form keeps the visitor's user agent.
	 *
	 * Elementor's default, which it does not store, is to keep it.
	 *
	 * @param array<string,mixed> $form_settings Form settings.
	 * @return bool True when it does.
	 */
	private static function stores_user_agent( array $form_settings ) {
		return ! isset( $form_settings['submissions_metadata'] ) || in_array( 'user_agent', (array) $form_settings['submissions_metadata'], true );
	}

	/**
	 * Key under which the same message from the same e-mail is a duplicate.
	 *
	 * @param string $email    Lowercased e-mail.
	 * @param string $text_key Result of Raynet_Submission_Spam_Score::text_key().
	 * @return string Key, or empty when either part is missing.
	 */
	private static function duplicate_key( $email, $text_key ) {
		return '' !== $email && '' !== $text_key ? $email . '|' . $text_key : '';
	}

	/**
	 * Adds values and the RAYNET action state to submission records.
	 *
	 * A success row in Elementor's action log makes a submission sent. Without
	 * one, the plugin's own attempt record decides: `unlogged` is sent too,
	 * with the lead id in `lead_id`; any other attempt (pending, unknown,
	 * refused) is failed, with what happened in `log`. Last comes a failure
	 * row in Elementor's log, written by the live action.
	 *
	 * @param array<int,array<string,mixed>> $records Records from the submissions table.
	 * @param int                            $post_id Post the form lives in.
	 * @return array<int,array<string,mixed>> Rows keyed by id, in the records' order.
	 */
	private static function hydrate( array $records, $post_id ) {
		global $wpdb;

		$rows = array();

		foreach ( $records as $record ) {
			// The query's LIKE also lets "12" through for page 123.
			if ( (int) $record['post_id'] !== $post_id && self::edit_post_id( (string) $record['meta'] ) !== $post_id ) {
				continue;
			}

			$rows[ (int) $record['id'] ] = array(
				'id'         => (int) $record['id'],
				'created_at' => (string) $record['created_at'],
				'referer'    => (string) $record['referer'],
				'ip'         => (string) $record['user_ip'],
				'user_agent' => (string) $record['user_agent'],
				'state'      => self::STATE_NEW,
				'log'        => '',
				'lead_id'    => 0,
				'values'     => array(),
			);
		}

		if ( empty( $rows ) ) {
			return $rows;
		}

		$ids    = array_keys( $rows );
		$in     = $wpdb->prepare( implode( ',', array_fill( 0, count( $ids ), '%d' ) ), $ids );
		$values = self::table( 'e_submissions_values' );
		$log    = self::table( 'e_submissions_actions_log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The id list is prepared above; table names come from $wpdb->prefix.
		$stored  = (array) $wpdb->get_results( "SELECT submission_id, `key`, value FROM {$values} WHERE submission_id IN ({$in}) ORDER BY id ASC", ARRAY_A );
		$entries = (array) $wpdb->get_results( $wpdb->prepare( "SELECT submission_id, status, log FROM {$log} WHERE action_name = %s AND submission_id IN ({$in}) ORDER BY id ASC", Raynet_Elementor_Forms::ACTION_NAME ), ARRAY_A );
		// phpcs:enable

		foreach ( $stored as $value ) {
			$rows[ (int) $value['submission_id'] ]['values'][ (string) $value['key'] ] = (string) $value['value'];
		}

		$failures = array();

		foreach ( $entries as $entry ) {
			$id = (int) $entry['submission_id'];

			if ( 'success' === $entry['status'] ) {
				$rows[ $id ]['state'] = self::STATE_SENT;
			} else {
				$failures[ $id ] = (string) $entry['log'];
			}
		}

		foreach ( $failures as $id => $text ) {
			if ( self::STATE_SENT !== $rows[ $id ]['state'] ) {
				$rows[ $id ]['state'] = self::STATE_FAILED;
				$rows[ $id ]['log']   = $text;
			}
		}

		foreach ( self::attempts( $ids ) as $id => $attempt ) {
			if ( self::STATE_SENT === $rows[ $id ]['state'] ) {
				continue;
			}

			if ( self::ATTEMPT_UNLOGGED === $attempt['outcome'] ) {
				$rows[ $id ]['state']   = self::STATE_SENT;
				$rows[ $id ]['log']     = '';
				$rows[ $id ]['lead_id'] = $attempt['lead_id'];
				continue;
			}

			$rows[ $id ]['state'] = self::STATE_FAILED;
			$rows[ $id ]['log']   = self::attempt_text( $attempt );
		}

		return $rows;
	}

	/**
	 * Attempt records of some submissions, in one query.
	 *
	 * Read with SQL rather than get_option(): one query for a whole batch,
	 * and never a stale object cache.
	 *
	 * @param int[] $ids Submission ids.
	 * @return array<int,array{outcome:string,message:string,lead_id:int}> Records by submission id; malformed ones are left out.
	 */
	private static function attempts( array $ids ) {
		global $wpdb;

		$names = array();

		foreach ( $ids as $id ) {
			$names[] = self::ATTEMPT_PREFIX . (int) $id;
		}

		if ( empty( $names ) ) {
			return array();
		}

		$in = $wpdb->prepare( implode( ',', array_fill( 0, count( $names ), '%s' ) ), $names );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The name list is prepared above.
		$records  = (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$in})", ARRAY_A );
		$outcomes = array( self::ATTEMPT_PENDING, self::ATTEMPT_UNKNOWN, self::ATTEMPT_REFUSED, self::ATTEMPT_UNLOGGED );
		$attempts = array();

		foreach ( $records as $record ) {
			$value = maybe_unserialize( $record['option_value'] );

			if ( ! is_array( $value ) || ! isset( $value['outcome'] ) || ! in_array( $value['outcome'], $outcomes, true ) ) {
				continue;
			}

			$attempts[ (int) substr( (string) $record['option_name'], strlen( self::ATTEMPT_PREFIX ) ) ] = array(
				'outcome' => (string) $value['outcome'],
				'message' => isset( $value['message'] ) && is_scalar( $value['message'] ) ? (string) $value['message'] : '',
				'lead_id' => isset( $value['lead_id'] ) ? (int) $value['lead_id'] : 0,
			);
		}

		return $attempts;
	}

	/**
	 * What an unconfirmed attempt says on the import screen.
	 *
	 * @param array{outcome:string,message:string} $attempt Record from attempts().
	 * @return string Text, unescaped.
	 */
	private static function attempt_text( array $attempt ) {
		if ( self::ATTEMPT_REFUSED === $attempt['outcome'] ) {
			/* translators: %s: reason RAYNET gave. */
			return sprintf( __( 'při předchozím importu RAYNET odmítl: %s', 'raynet-lead-api-integration' ), $attempt['message'] );
		}

		$text = __( 'při předchozím importu: výsledek neznámý', 'raynet-lead-api-integration' );

		return '' === $attempt['message'] ? $text : $text . ' (' . $attempt['message'] . ')';
	}

	/**
	 * SQL condition for the submissions of one form, outside the trash.
	 *
	 * Strict on purpose: a duplicated page keeps its widget ids, and its
	 * submissions must not be imported with this form's mapping. The page id
	 * in `meta` is matched as a number or a string, since Elementor saves
	 * what the request carried; hydrate() checks the match exactly.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return string Prepared condition.
	 */
	private static function match_clause( $post_id, $widget_id ) {
		global $wpdb;

		return $wpdb->prepare(
			'element_id = %s AND ( post_id = %d OR meta LIKE %s OR meta LIKE %s ) AND status <> %s',
			$widget_id,
			$post_id,
			'%' . $wpdb->esc_like( '"edit_post_id":' . $post_id ) . '%',
			'%' . $wpdb->esc_like( '"edit_post_id":"' . $post_id . '"' ) . '%',
			'trash'
		);
	}

	/**
	 * What a cached index must have been built from.
	 *
	 * Carries the plugin version: an update may change the spam rules, and
	 * verdicts judged by the old ones must not be served from the cache.
	 *
	 * @param array<int,mixed> $parts   Generation, form settings, config, submission count.
	 * @param string|null      $version Plugin version; RAYNET_LEAD_VERSION when left out.
	 * @return string Fingerprint.
	 */
	public static function fingerprint( array $parts, $version = null ) {
		if ( null === $version ) {
			$version = defined( 'RAYNET_LEAD_VERSION' ) ? RAYNET_LEAD_VERSION : '';
		}

		$parts[] = (string) $version;

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Number of submissions of a form outside the trash, for the cache fingerprint.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return int Count.
	 */
	private static function count_submissions( $post_id, $widget_id ) {
		global $wpdb;

		$table = self::table( 'e_submissions' );
		$match = self::match_clause( $post_id, $widget_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- The condition is prepared; the table name comes from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$match}" );
	}

	/**
	 * The page a submission was sent from, as Elementor noted it in `meta`.
	 *
	 * @param string $meta JSON.
	 * @return int Post id, or 0.
	 */
	private static function edit_post_id( $meta ) {
		$data = json_decode( $meta, true );

		if ( ! is_array( $data ) || ! isset( $data['edit_post_id'] ) || ! is_scalar( $data['edit_post_id'] ) || ! ctype_digit( (string) $data['edit_post_id'] ) ) {
			return 0;
		}

		return (int) $data['edit_post_id'];
	}

	/**
	 * Reformats a stored local date.
	 *
	 * @param string $created_at Date as Elementor stores it, `Y-m-d H:i:s`.
	 * @param string $format     PHP date format.
	 * @return string Formatted date, or empty when the stored one is unreadable.
	 */
	private static function local_date( $created_at, $format ) {
		$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $created_at );

		return false === $date ? '' : $date->format( $format );
	}

	/**
	 * The RAYNET submit action, which Elementor's action log is written for.
	 *
	 * @return Raynet_Elementor_Form_Action Action.
	 */
	private static function action() {
		if ( ! class_exists( 'Raynet_Elementor_Form_Action', false ) ) {
			require_once RAYNET_LEAD_PATH . 'includes/elementor/class-raynet-elementor-form-action.php';
		}

		return new Raynet_Elementor_Form_Action();
	}

	/**
	 * Name of the transient holding one form's index.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return string Transient name.
	 */
	private static function cache_key( $post_id, $widget_id ) {
		return self::CACHE_PREFIX . md5( $post_id . ':' . $widget_id );
	}

	/**
	 * Full name of one of Elementor's submission tables.
	 *
	 * @param string $name Table name without the prefix.
	 * @return string Table name.
	 */
	private static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . $name;
	}
}
