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
	 * Prefix of the transient holding one form's index.
	 */
	const CACHE_PREFIX = 'raynet_import_idx_';

	/**
	 * How long an index stays cached, in seconds.
	 */
	const CACHE_TTL = 600;

	/**
	 * Tag added to every imported lead unless the screen says otherwise.
	 */
	const DEFAULT_TAG = 'import-web';

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
	 * Submissions not yet in RAYNET, per form, for the whole site.
	 *
	 * A submission counts under its `post_id` and, when that differs, under
	 * the page it was sent from (`meta.edit_post_id`), because the scanner
	 * finds a form either in the page or in the template it lives in.
	 *
	 * @return array<string,int> "post_id:element_id" => count.
	 */
	public static function pending_counts() {
		if ( ! self::available() ) {
			return array();
		}

		global $wpdb;

		$submissions = self::table( 'e_submissions' );
		$log         = self::table( 'e_submissions_actions_log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names come from $wpdb->prefix and constants.
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.post_id, s.element_id, s.meta, COUNT(*) AS n FROM {$submissions} s
				WHERE s.status <> %s
				AND NOT EXISTS ( SELECT 1 FROM {$log} l WHERE l.submission_id = s.id AND l.action_name = %s AND l.status = %s )
				GROUP BY s.post_id, s.element_id, s.meta",
				'trash',
				Raynet_Elementor_Forms::ACTION_NAME,
				'success'
			),
			ARRAY_A
		);
		// phpcs:enable

		$counts = array();

		foreach ( (array) $groups as $group ) {
			$element = (string) $group['element_id'];
			$posts   = array( (int) $group['post_id'], self::edit_post_id( (string) $group['meta'] ) );

			foreach ( array_unique( array_filter( $posts ) ) as $post_id ) {
				$key            = $post_id . ':' . $element;
				$counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + (int) $group['n'];
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

		self::each_batch(
			$post_id,
			$widget_id,
			static function ( array $rows ) use ( $form_settings, &$senders, &$ip_counts, &$first ) {
				foreach ( $rows as $row ) {
					$values   = self::assemble( $row, $form_settings )['values'];
					$email    = strtolower( $values['email'] );
					$ip       = trim( (string) $row['ip'] );
					$text_key = Raynet_Submission_Spam_Score::text_key( $values['message'] );
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
		$dup_key  = self::duplicate_key( strtolower( $values['email'] ), Raynet_Submission_Spam_Score::text_key( $values['message'] ) );
		$first_of = isset( $stats['first_of'] ) && is_array( $stats['first_of'] ) ? $stats['first_of'] : array();
		$map      = Raynet_Elementor_Forms::current_map( $form_settings );

		$submission = array(
			'values'            => array_intersect_key( $values, array_flip( Raynet_Submission_Spam_Score::VALUE_KEYS ) ),
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
		 * @param array<string,mixed> $result     Verdict and reasons from Raynet_Submission_Spam_Score::score().
		 * @param array<string,mixed> $submission What was scored: mapped values, other texts, IP, user agent, flags.
		 * @param array<string,mixed> $row        Stored submission.
		 */
		$result  = apply_filters( 'raynet_import_spam_score', $result, $submission, $row );
		$verdict = is_array( $result ) && isset( $result['verdict'] ) ? (string) $result['verdict'] : Raynet_Submission_Spam_Score::VERDICT_OK;
		$reasons = is_array( $result ) && isset( $result['reasons'] ) && is_array( $result['reasons'] ) ? array_values( $result['reasons'] ) : array();

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
	 * submissions change.
	 *
	 * @param int    $post_id   Post the form lives in.
	 * @param string $widget_id Form widget id.
	 * @return array<int,array<string,mixed>> Submission id => classify() result.
	 */
	public static function index( $post_id, $widget_id ) {
		if ( ! self::available() ) {
			return array();
		}

		$post_id       = (int) $post_id;
		$widget_id     = (string) $widget_id;
		$form_settings = Raynet_Elementor_Forms::form_settings( $post_id, $widget_id );

		if ( null === $form_settings ) {
			return array();
		}

		/**
		 * Filters the spam scoring config used by the import screen.
		 *
		 * @param array<string,mixed> $config Thresholds, word lists, allowed scripts and disabled signals.
		 */
		$config      = (array) apply_filters( 'raynet_import_spam_config', Raynet_Submission_Spam_Score::default_config() );
		$fingerprint = md5( wp_json_encode( array( $form_settings, $config, self::count_submissions( $post_id, $widget_id ) ) ) );
		$cache_key   = self::cache_key( $post_id, $widget_id );
		$cached      = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['fingerprint'], $cached['items'] ) && $fingerprint === $cached['fingerprint'] ) {
			return (array) $cached['items'];
		}

		$stats = self::stats( $post_id, $widget_id, $form_settings );
		$items = array();

		self::each_batch(
			$post_id,
			$widget_id,
			static function ( array $rows ) use ( $form_settings, $stats, $config, &$items ) {
				foreach ( $rows as $id => $row ) {
					$items[ (int) $id ] = self::classify( $row, $form_settings, $stats, $config );
				}
			}
		);

		set_transient(
			$cache_key,
			array(
				'fingerprint' => $fingerprint,
				'items'       => $items,
			),
			self::CACHE_TTL
		);

		return $items;
	}

	/**
	 * Marks one submission as sent in the cached index.
	 *
	 * @param int    $post_id       Post the form lives in.
	 * @param string $widget_id     Form widget id.
	 * @param int    $submission_id Submission id.
	 * @return void
	 */
	public static function mark_sent( $post_id, $widget_id, $submission_id ) {
		$cache_key = self::cache_key( (int) $post_id, (string) $widget_id );
		$cached    = get_transient( $cache_key );

		if ( ! is_array( $cached ) || ! isset( $cached['items'][ (int) $submission_id ] ) ) {
			return;
		}

		$cached['items'][ (int) $submission_id ]['state']     = self::STATE_SENT;
		$cached['items'][ (int) $submission_id ]['tab']       = self::TAB_SENT;
		$cached['items'][ (int) $submission_id ]['preselect'] = false;

		set_transient( $cache_key, $cached, self::CACHE_TTL );
	}

	/**
	 * Sends one stored submission to RAYNET as a lead.
	 *
	 * Everything is checked again against the database, not the cached index:
	 * the form, that the submission belongs to it and is not in the trash, and
	 * that it is not in RAYNET already — two open tabs must not send it twice.
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

		if ( null === $form_settings || ! Raynet_Elementor_Forms::maps_contact( $form_settings ) ) {
			return new WP_Error( 'raynet_import_no_form', __( 'Formulář nebyl nalezen nebo nemá namapovaný e-mail ani telefon.', 'raynet-lead-api-integration' ) );
		}

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

		$form   = new Raynet_Lead_Form();
		$result = $form->submit_lead( $prepared['values'], $prepared['settings'], $prepared['context'] );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			return new WP_Error(
				'raynet_api_error',
				is_array( $data ) && isset( $data['diagnostic'] ) ? (string) $data['diagnostic'] : $result->get_error_message()
			);
		}

		$lead_id = isset( $result['lead_id'] ) ? (int) $result['lead_id'] : 0;
		$query   = call_user_func( array( self::QUERY_CLASS, 'get_instance' ) );

		// The action log is how a submission is known to be in RAYNET. The
		// success counter stays: the action may not have been on the form then.
		$query->add_action_log(
			$submission_id,
			self::action(),
			constant( self::QUERY_CLASS . '::ACTIONS_LOG_STATUS_SUCCESS' ),
			sprintf(
				/* translators: %d: lead id. */
				__( 'Importováno, lead %d', 'raynet-lead-api-integration' ),
				$lead_id
			)
		);

		self::mark_sent( $post_id, $widget_id, $submission_id );

		return array( 'lead_id' => $lead_id );
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

		return $rows;
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
