<?php
/**
 * Import screen: stored Elementor submissions of one form, judged for spam,
 * sent to RAYNET one by one on the administrator's say.
 *
 * Shown inside the "Elementor formuláře" screen when the URL carries
 * `import=<post_id>:<widget_id>`, the way `mapovat=` shows the mapping.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the import screen and answers its AJAX requests.
 */
class Raynet_Elementor_Import_Admin {

	/**
	 * AJAX action that imports one submission.
	 */
	const AJAX_ACTION = 'raynet_elm_import_one';

	/**
	 * Rows per page the screen offers; the middle one is the default.
	 */
	const PER_PAGE = array( 50, 100, 200 );

	/**
	 * Rows per page when the URL asks for none or for another number.
	 */
	const DEFAULT_PER_PAGE = 100;

	/**
	 * Longest tag the screen sends.
	 */
	const TAG_LENGTH = 60;

	/**
	 * Hooks the AJAX handler and the screen's script.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_import_one' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Link to the import screen of one form.
	 *
	 * @param array<string,mixed> $form Row from Raynet_Elementor_Forms::scan().
	 * @return string URL.
	 */
	public static function url( array $form ) {
		return add_query_arg(
			array(
				'page'   => Raynet_Elementor_Forms_Admin::PAGE,
				'import' => $form['post_id'] . ':' . $form['widget_id'],
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Reads a form key, "<post_id>:<widget_id>".
	 *
	 * Elementor's widget ids are short alphanumeric strings. Anything else is
	 * refused here, so a crafted key never reaches the page or its script.
	 *
	 * @param string $key Key from the request.
	 * @return array{0:int,1:string}|null Post id and widget id, or null.
	 */
	public static function parse_key( $key ) {
		if ( ! is_string( $key ) || ! preg_match( '/^(\d+):([A-Za-z0-9_-]+)$/', $key, $parts ) ) {
			return null;
		}

		return array( (int) $parts[1], $parts[2] );
	}

	/**
	 * Loads the screen's script on the import screen only.
	 *
	 * The stylesheet comes with the forms screen (Raynet_Lead_Admin).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$key  = isset( $_GET['import'] ) ? sanitize_text_field( wp_unslash( $_GET['import'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( Raynet_Elementor_Forms_Admin::PAGE !== $page || null === self::parse_key( $key ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'raynet-elementor-import',
			RAYNET_LEAD_URL . 'assets/js/raynet-elementor-import.js',
			array( 'jquery' ),
			RAYNET_LEAD_VERSION,
			true
		);

		wp_localize_script(
			'raynet-elementor-import',
			'raynetElementorImport',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Raynet_Elementor_Forms_Admin::NONCE ),
				'form'      => $key,
				/* translators: %d: number of submissions. */
				'sendLabel' => __( 'Odeslat zaškrtnuté do RAYNETu (%d)', 'raynet-lead-api-integration' ),
				/* translators: %d: number of submissions. */
				'confirm'   => __( 'Odeslat %d odeslání do RAYNETu?', 'raynet-lead-api-integration' ),
				/* translators: 1: submissions done, 2: submissions in all. */
				'progress'  => __( '%1$d / %2$d', 'raynet-lead-api-integration' ),
				/* translators: 1: sent, 2: failed, 3: unknown outcome. */
				'summary'   => __( 'Hotovo: odesláno %1$d, selhalo %2$d, výsledek neznámý %3$d.', 'raynet-lead-api-integration' ),
				/* translators: %d: lead id. */
				'sent'      => __( 'V RAYNETu, lead %d', 'raynet-lead-api-integration' ),
				'unknown'   => __( 'výsledek neznámý, ověřte v RAYNETu, než to zkusíte znovu', 'raynet-lead-api-integration' ),
				'failed'    => __( 'Odeslání selhalo.', 'raynet-lead-api-integration' ),
			)
		);
	}

	/**
	 * Imports one submission: the screen calls this once per ticked row.
	 *
	 * @return void
	 */
	public static function handle_import_one() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'raynet_import_forbidden',
					'message' => __( 'Nemáte oprávnění.', 'raynet-lead-api-integration' ),
				),
				403
			);
		}

		if ( false === check_ajax_referer( Raynet_Elementor_Forms_Admin::NONCE, '_wpnonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'raynet_import_nonce',
					'message' => __( 'Platnost stránky vypršela. Načtěte ji znovu.', 'raynet-lead-api-integration' ),
				),
				403
			);
		}

		$key        = isset( $_POST['form'] ) ? sanitize_text_field( wp_unslash( $_POST['form'] ) ) : '';
		$submission = isset( $_POST['submission'] ) ? absint( wp_unslash( $_POST['submission'] ) ) : 0;
		$tag        = isset( $_POST['tag'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['tag'] ) ), 0, self::TAG_LENGTH ) : '';

		$parts = self::parse_key( $key );

		if ( null === $parts ) {
			wp_send_json_error(
				array(
					'code'    => 'raynet_import_no_form',
					'message' => __( 'Formulář nebyl nalezen.', 'raynet-lead-api-integration' ),
				),
				400
			);
		}

		$result = Raynet_Elementor_Submissions::import( $parts[0], $parts[1], $submission, $tag );

		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$error = array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);

			// The lead exists although the row could not be marked: the screen
			// must show its number and must not offer the row again.
			if ( is_array( $data ) && isset( $data['lead_id'] ) ) {
				$error['lead_id'] = (int) $data['lead_id'];
			}

			wp_send_json_error( $error, 400 );
		}

		wp_send_json_success( array( 'lead_id' => (int) $result['lead_id'] ) );
	}

	/**
	 * Renders the import screen of one form.
	 *
	 * @param string $key "post_id:widget_id".
	 * @return void
	 */
	public static function render( $key ) {
		$back = add_query_arg( array( 'page' => Raynet_Elementor_Forms_Admin::PAGE ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap raynet-import-screen">
			<h1><?php esc_html_e( 'Import odeslání', 'raynet-lead-api-integration' ); ?></h1>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Zpět na přehled formulářů', 'raynet-lead-api-integration' ); ?></a></p>
			<?php self::body( (string) $key ); ?>
		</div>
		<?php
	}

	/**
	 * Everything below the heading: either the reason the import cannot run,
	 * or the table.
	 *
	 * @param string $key "post_id:widget_id".
	 * @return void
	 */
	private static function body( $key ) {
		if ( ! Raynet_Elementor_Submissions::available() ) {
			self::notice( 'error', __( 'Import potřebuje aktivní Elementor Pro s uloženými odesláními. Elementor Pro teď aktivní není, nebo na webu chybí jeho tabulka odeslání.', 'raynet-lead-api-integration' ) );
			return;
		}

		$settings = null;
		$parts    = self::parse_key( $key );

		if ( null !== $parts ) {
			list( $post_id, $widget_id ) = $parts;

			$settings = Raynet_Elementor_Forms::form_settings( $post_id, $widget_id );
		}

		// An atomic form has no import yet; a hand-built key is "not found".
		if ( null === $settings || Raynet_Elementor_Forms::is_atomic( $settings ) ) {
			self::notice( 'error', __( 'Formulář nebyl nalezen. Stránka ho možná už neobsahuje.', 'raynet-lead-api-integration' ) );
			return;
		}

		$name = isset( $settings['form_name'] ) && '' !== (string) $settings['form_name'] ? (string) $settings['form_name'] : __( 'bez názvu', 'raynet-lead-api-integration' );
		?>
		<h2>
			<?php echo esc_html( $name ); ?>
			<span class="raynet-import__where">(<?php echo esc_html( Raynet_Elementor_Forms::location_label( $post_id ) . ': ' . get_the_title( $post_id ) ); ?>)</span>
		</h2>
		<?php

		// Mapping is enough; the action need not be switched on for a one-off import.
		if ( ! Raynet_Elementor_Forms::maps_contact( $settings ) ) {
			$mapping = add_query_arg(
				array(
					'page'    => Raynet_Elementor_Forms_Admin::PAGE,
					'mapovat' => $key,
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Formulář zatím nemá namapovaný e-mail ani telefon, takže z jeho odeslání lead nevznikne.', 'raynet-lead-api-integration' ); ?>
					<a href="<?php echo esc_url( $mapping ); ?>"><?php esc_html_e( 'Namapovat pole', 'raynet-lead-api-integration' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		$configured = Raynet_Lead_Settings::is_configured();

		if ( ! $configured ) {
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Plugin není nastavený: chybí přihlašovací údaje k RAYNETu, takže odeslání zatím nejde poslat.', 'raynet-lead-api-integration' ); ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => Raynet_Lead_Admin::PAGE ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Otevřít nastavení', 'raynet-lead-api-integration' ); ?></a>
				</p>
			</div>
			<?php
		}

		$index = Raynet_Elementor_Submissions::index( $post_id, $widget_id );

		if ( empty( $index ) ) {
			echo '<p>' . esc_html__( 'Formulář nemá žádná uložená odeslání.', 'raynet-lead-api-integration' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$tab      = isset( $_GET['zalozka'] ) ? sanitize_key( wp_unslash( $_GET['zalozka'] ) ) : '';
		$per_page = isset( $_GET['na_stranku'] ) ? absint( wp_unslash( $_GET['na_stranku'] ) ) : 0;
		$page     = isset( $_GET['strana'] ) ? absint( wp_unslash( $_GET['strana'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$tabs     = self::tabs();
		$tab      = isset( $tabs[ $tab ] ) ? $tab : Raynet_Elementor_Submissions::TAB_REVIEW;
		$per_page = in_array( $per_page, self::PER_PAGE, true ) ? $per_page : self::DEFAULT_PER_PAGE;
		$page     = max( 1, $page );

		$by_tab   = array_fill_keys( array_keys( $tabs ), array() );
		$verdicts = array_fill_keys( array( Raynet_Submission_Spam_Score::VERDICT_OK, Raynet_Submission_Spam_Score::VERDICT_SUSPECT, Raynet_Submission_Spam_Score::VERDICT_SPAM ), 0 );

		foreach ( $index as $id => $item ) {
			$by_tab[ $item['tab'] ][] = (int) $id;

			if ( in_array( $item['tab'], array( Raynet_Elementor_Submissions::TAB_REVIEW, Raynet_Elementor_Submissions::TAB_SPAM ), true ) && isset( $verdicts[ $item['verdict'] ] ) ) {
				$verdicts[ $item['verdict'] ]++;
			}
		}

		$base = add_query_arg(
			array(
				'page'       => Raynet_Elementor_Forms_Admin::PAGE,
				'import'     => $key,
				'zalozka'    => $tab,
				'na_stranku' => $per_page,
			),
			admin_url( 'admin.php' )
		);
		// A page past the end shows nothing; the offset is only computed for a real page.
		$ids  = $page <= (int) ceil( count( $by_tab[ $tab ] ) / $per_page ) ? array_slice( $by_tab[ $tab ], ( $page - 1 ) * $per_page, $per_page ) : array();
		$rows = Raynet_Elementor_Submissions::load( $post_id, $widget_id, $ids );
		$pick = in_array( $tab, array( Raynet_Elementor_Submissions::TAB_REVIEW, Raynet_Elementor_Submissions::TAB_SPAM ), true );

		self::summary( count( $index ), count( $by_tab[ Raynet_Elementor_Submissions::TAB_SENT ] ), count( $by_tab[ Raynet_Elementor_Submissions::TAB_BLOCKED ] ), $verdicts );
		?>
		<nav class="nav-tab-wrapper raynet-import__tabs">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( array( 'zalozka' => $slug, 'strana' => 1 ), $base ) ); ?>"
					class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"
					<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>
				><?php echo esc_html( sprintf( '%s (%d)', $label, count( $by_tab[ $slug ] ) ) ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $pick ) : ?>
			<p class="raynet-import__tag">
				<label for="raynet-import-tag"><?php esc_html_e( 'Štítek pro importované leady:', 'raynet-lead-api-integration' ); ?></label>
				<input type="text" id="raynet-import-tag" class="regular-text" maxlength="<?php echo (int) self::TAG_LENGTH; ?>" value="<?php echo esc_attr( Raynet_Elementor_Submissions::DEFAULT_TAG ); ?>" />
				<span class="description"><?php esc_html_e( 'Podle štítku importované leady v RAYNETu najdete. Prázdné pole štítek nepřidá.', 'raynet-lead-api-integration' ); ?></span>
			</p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="raynet-import__empty">
				<?php
				echo esc_html(
					empty( $by_tab[ $tab ] )
						? __( 'Na této záložce nejsou žádná odeslání.', 'raynet-lead-api-integration' )
						: __( 'Na této stránce nejsou žádná odeslání.', 'raynet-lead-api-integration' )
				);
				?>
			</p>
			<?php self::pagination( $base, $page, $per_page, count( $by_tab[ $tab ] ) ); ?>
			<?php
			return;
		endif;
		?>

		<table class="wp-list-table widefat raynet-import" data-per-page="<?php echo (int) $per_page; ?>">
			<thead>
				<tr>
					<td class="raynet-import__check">
						<?php if ( $pick ) : ?>
							<input type="checkbox" id="raynet-import-all" aria-label="<?php esc_attr_e( 'Zaškrtnout / odškrtnout vše na stránce', 'raynet-lead-api-integration' ); ?>" title="<?php esc_attr_e( 'Zaškrtnout / odškrtnout vše na stránce', 'raynet-lead-api-integration' ); ?>" />
						<?php endif; ?>
					</td>
					<th><?php esc_html_e( 'Odesláno', 'raynet-lead-api-integration' ); ?></th>
					<th><?php esc_html_e( 'Poptávka', 'raynet-lead-api-integration' ); ?></th>
					<th><?php esc_html_e( 'Hodnocení', 'raynet-lead-api-integration' ); ?></th>
					<th><?php esc_html_e( 'Výsledek', 'raynet-lead-api-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $id ) : ?>
					<?php
					if ( isset( $rows[ $id ] ) ) {
						self::row( $rows[ $id ], $index[ $id ], $settings, $pick );
					}
					?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="raynet-import__footer">
			<?php if ( $pick && $configured ) : ?>
				<p>
					<button type="button" id="raynet-import-send" class="button button-primary">
						<?php
						printf(
							/* translators: %d: number of submissions. */
							esc_html__( 'Odeslat zaškrtnuté do RAYNETu (%d)', 'raynet-lead-api-integration' ),
							count(
								array_filter(
									$ids,
									static function ( $id ) use ( $index, $rows ) {
										return isset( $rows[ $id ] ) && self::preselected( $rows[ $id ], $index[ $id ] );
									}
								)
							)
						);
						?>
					</button>
					<span id="raynet-import-progress" class="raynet-import__progress" aria-live="polite"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Odešlou se zaškrtnuté řádky na této stránce, po jednom. Zavřít stránku uprostřed nevadí: co se odeslalo, je označené, zbytek zůstane k posouzení.', 'raynet-lead-api-integration' ); ?></p>
			<?php endif; ?>
			<?php self::pagination( $base, $page, $per_page, count( $by_tab[ $tab ] ) ); ?>
		</div>
		<?php
	}

	/**
	 * Tabs of the screen, slug => label.
	 *
	 * @return array<string,string> Tabs.
	 */
	private static function tabs() {
		return array(
			Raynet_Elementor_Submissions::TAB_REVIEW  => __( 'V pořádku + podezřelé', 'raynet-lead-api-integration' ),
			Raynet_Elementor_Submissions::TAB_SPAM    => __( 'Spam', 'raynet-lead-api-integration' ),
			Raynet_Elementor_Submissions::TAB_SENT    => __( 'Už v RAYNETu', 'raynet-lead-api-integration' ),
			Raynet_Elementor_Submissions::TAB_BLOCKED => __( 'Nelze importovat', 'raynet-lead-api-integration' ),
		);
	}

	/**
	 * The counts above the tabs.
	 *
	 * @param int               $total    Submissions of the form.
	 * @param int               $sent     Already in RAYNET.
	 * @param int               $blocked  Cannot be imported.
	 * @param array<string,int> $verdicts Verdict => count, over the rows to review.
	 * @return void
	 */
	private static function summary( $total, $sent, $blocked, array $verdicts ) {
		$parts = array(
			/* translators: %s: number of submissions. */
			sprintf( __( 'Uloženo %s odeslání', 'raynet-lead-api-integration' ), number_format_i18n( $total ) ),
			/* translators: %s: number of submissions. */
			sprintf( __( '%s už v RAYNETu', 'raynet-lead-api-integration' ), number_format_i18n( $sent ) ),
			/* translators: %s: number of submissions. */
			sprintf( __( '%s k posouzení', 'raynet-lead-api-integration' ), number_format_i18n( $total - $sent - $blocked ) ),
		);

		if ( $blocked > 0 ) {
			/* translators: %s: number of submissions. */
			$parts[] = sprintf( __( '%s nelze importovat', 'raynet-lead-api-integration' ), number_format_i18n( $blocked ) );
		}
		?>
		<p class="raynet-import__summary">
			<strong><?php echo esc_html( implode( ' · ', $parts ) ); ?></strong><br />
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: ok, 2: suspect, 3: spam, all numbers of submissions. */
					__( 'v pořádku %1$s · podezřelé %2$s · spam %3$s', 'raynet-lead-api-integration' ),
					number_format_i18n( $verdicts[ Raynet_Submission_Spam_Score::VERDICT_OK ] ),
					number_format_i18n( $verdicts[ Raynet_Submission_Spam_Score::VERDICT_SUSPECT ] ),
					number_format_i18n( $verdicts[ Raynet_Submission_Spam_Score::VERDICT_SPAM ] )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Whether a row starts ticked.
	 *
	 * The verdict comes from the cached index; the state is read fresh, so a
	 * row sent from another tab since the index was cached is never offered.
	 *
	 * @param array<string,mixed> $row  Stored submission from load().
	 * @param array<string,mixed> $item Its entry in index().
	 * @return bool True for a clean enquiry not yet tried.
	 */
	private static function preselected( array $row, array $item ) {
		return Raynet_Elementor_Submissions::TAB_REVIEW === $item['tab']
			&& Raynet_Submission_Spam_Score::VERDICT_OK === $item['verdict']
			&& Raynet_Elementor_Submissions::STATE_NEW === $row['state'];
	}

	/**
	 * One submission: the row and, hidden below it, its detail.
	 *
	 * Whether it can be ticked follows its state as load() read it now, not
	 * the cached index.
	 *
	 * @param array<string,mixed> $row      Stored submission from load().
	 * @param array<string,mixed> $item     Its entry in index().
	 * @param array<string,mixed> $settings Form settings.
	 * @param bool                $pick     Whether the tab lets rows be ticked.
	 * @return void
	 */
	private static function row( array $row, array $item, array $settings, $pick ) {
		$id       = (int) $row['id'];
		$fields   = Raynet_Elementor_Submissions::fields_for( $row, $settings );
		$form     = new Raynet_Lead_Form();
		$prepared = Raynet_Elementor_Submissions::prepare( $row, $settings, Raynet_Elementor_Submissions::DEFAULT_TAG );
		// A row RAYNET would refuse has no lead to preview; its values are
		// still collected once for the summary.
		$payload  = is_wp_error( $prepared ) ? null : $form->preview_payload( $prepared['values'], $prepared['settings'], $prepared['context'] );
		$values   = is_wp_error( $prepared ) ? $form->collect_values( Raynet_Elementor_Lead_Builder::mapped_values( $fields, $settings )['basic'] ) : $prepared['values'];
		$who      = array_filter(
			array(
				trim( $values['firstName'] . ' ' . $values['lastName'] ),
				$values['companyName'],
				$values['email'],
				$values['phone'],
			),
			'strlen'
		);
		$text     = trim( preg_replace( '/\s+/u', ' ', $values['message'] ) );
		$text     = mb_strlen( $text ) > 140 ? mb_substr( $text, 0, 140 ) . '…' : $text;
		$state    = (string) $row['state'];
		$box      = $pick && Raynet_Elementor_Submissions::STATE_SENT !== $state;

		if ( Raynet_Elementor_Submissions::STATE_SENT === $state ) {
			$item['tab'] = Raynet_Elementor_Submissions::TAB_SENT;
		}
		?>
		<tr class="raynet-import__row raynet-import--<?php echo esc_attr( $item['verdict'] ); ?>" data-submission="<?php echo (int) $id; ?>">
			<th scope="row" class="raynet-import__check">
				<?php if ( $box ) : ?>
					<input type="checkbox" name="submissions[]" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: submission id. */ __( 'Vybrat odeslání %d', 'raynet-lead-api-integration' ), $id ) ); ?>" <?php checked( self::preselected( $row, $item ) ); ?> />
				<?php endif; ?>
			</th>
			<td class="raynet-import__date"><?php echo esc_html( self::date( $row['created_at'] ) ); ?></td>
			<td>
				<strong><?php echo esc_html( implode( ' · ', $who ) ); ?></strong>
				<?php if ( '' !== $text ) : ?>
					<br /><span class="raynet-import__message">„<?php echo esc_html( $text ); ?>“</span>
				<?php endif; ?>
			</td>
			<td>
				<?php self::verdict( $item ); ?>
				<?php if ( ! empty( $row['lead_id'] ) ) : ?>
					<br /><span class="raynet-elm__warn">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: lead id. */
								__( 'lead %d — v Elementoru se nepodařilo označit', 'raynet-lead-api-integration' ),
								(int) $row['lead_id']
							)
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( Raynet_Elementor_Submissions::STATE_FAILED === $state ) : ?>
					<br /><span class="raynet-elm__warn">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: error stored by the failed attempt. */
								__( 'tehdy selhalo: „%s“ — ověřte v RAYNETu, že lead neexistuje', 'raynet-lead-api-integration' ),
								$row['log']
							)
						);
						?>
					</span>
				<?php endif; ?>
				<br /><button type="button" class="button-link raynet-import__toggle" data-toggle="<?php echo (int) $id; ?>" aria-expanded="false" aria-controls="raynet-import-detail-<?php echo (int) $id; ?>"><?php esc_html_e( 'detail', 'raynet-lead-api-integration' ); ?></button>
			</td>
			<td class="raynet-import__result"></td>
		</tr>
		<tr class="raynet-import__detail" id="raynet-import-detail-<?php echo (int) $id; ?>" data-detail="<?php echo (int) $id; ?>" hidden>
			<td></td>
			<td colspan="4"><?php self::detail( $row, $fields, is_wp_error( $prepared ) ? $prepared->get_error_message() : $payload ); ?></td>
		</tr>
		<?php
	}

	/**
	 * The verdict of a row, with its reasons.
	 *
	 * @param array<string,mixed> $item Entry in index().
	 * @return void
	 */
	private static function verdict( array $item ) {
		if ( Raynet_Elementor_Submissions::TAB_SENT === $item['tab'] ) {
			echo '<span class="raynet-elm__on">' . esc_html__( 'Už v RAYNETu', 'raynet-lead-api-integration' ) . '</span>';
			return;
		}

		if ( Raynet_Elementor_Submissions::TAB_BLOCKED === $item['tab'] ) {
			echo '<span class="raynet-elm__warn">' . esc_html( sprintf( /* translators: %s: reason. */ __( 'Nelze importovat: %s', 'raynet-lead-api-integration' ), $item['block'] ) ) . '</span>';
			return;
		}

		$labels  = array(
			Raynet_Submission_Spam_Score::VERDICT_OK      => __( 'V pořádku', 'raynet-lead-api-integration' ),
			Raynet_Submission_Spam_Score::VERDICT_SUSPECT => __( 'Podezřelé', 'raynet-lead-api-integration' ),
			Raynet_Submission_Spam_Score::VERDICT_SPAM    => __( 'Spam', 'raynet-lead-api-integration' ),
		);
		$label   = isset( $labels[ $item['verdict'] ] ) ? $labels[ $item['verdict'] ] : (string) $item['verdict'];
		$reasons = array();

		foreach ( (array) $item['reasons'] as $reason ) {
			$reasons[] = self::reason( $reason );
		}

		$reasons = array_filter( $reasons, 'strlen' );

		echo '<span class="raynet-import__verdict">' . esc_html( $label ) . '</span>';

		if ( ! empty( $reasons ) ) {
			echo ': ' . esc_html( implode( ', ', $reasons ) );
		}
	}

	/**
	 * Words for one spam signal.
	 *
	 * A raynet_import_spam_score filter may hand back reasons in any shape:
	 * a plain string, or an id the screen does not know, is shown as it is.
	 *
	 * @param mixed $reason Reason from the spam score: id, strength, count.
	 * @return string Text, unescaped.
	 */
	private static function reason( $reason ) {
		if ( is_scalar( $reason ) ) {
			return (string) $reason;
		}

		if ( ! is_array( $reason ) ) {
			return '';
		}

		$id    = isset( $reason['id'] ) && is_scalar( $reason['id'] ) ? (string) $reason['id'] : '';
		$count = isset( $reason['count'] ) && is_scalar( $reason['count'] ) ? (int) $reason['count'] : 0;

		switch ( $id ) {
			case 'link_short':
				return __( 'odkaz ve jméně nebo jiném krátkém poli', 'raynet-lead-api-integration' );
			case 'markup':
				return __( 'HTML nebo BBCode značky', 'raynet-lead-api-integration' );
			case 'foreign_script':
				return __( 'text v jiném písmu než latinka', 'raynet-lead-api-integration' );
			case 'same_text':
				/* translators: %d: number of senders. */
				return sprintf( __( 'stejný text %d×', 'raynet-lead-api-integration' ), $count );
			case 'links_many':
				return __( 'více odkazů ve zprávě', 'raynet-lead-api-integration' );
			case 'link_one':
				return __( 'odkaz ve zprávě', 'raynet-lead-api-integration' );
			case 'keywords':
				return __( 'klíčová slova spamu', 'raynet-lead-api-integration' );
			case 'same_ip':
				/* translators: %d: number of submissions. */
				return sprintf( __( 'stejná IP adresa %d×', 'raynet-lead-api-integration' ), $count );
			case 'odd_name':
				return __( 'nevěrohodné jméno', 'raynet-lead-api-integration' );
			case 'bot_agent':
				return __( 'strojový prohlížeč', 'raynet-lead-api-integration' );
			case 'bad_phone':
				return __( 'nevěrohodný telefon', 'raynet-lead-api-integration' );
			case 'home_phone':
				return __( 'telefon mimo povolené předvolby', 'raynet-lead-api-integration' );
			case 'disposable_email':
				return __( 'jednorázový e-mail', 'raynet-lead-api-integration' );
			case 'short_message':
				return __( 'velmi krátká zpráva', 'raynet-lead-api-integration' );
			case 'no_words':
				return __( 'zpráva bez slov', 'raynet-lead-api-integration' );
			case 'gibberish':
				return __( 'zpráva nedává smysl', 'raynet-lead-api-integration' );
			case 'one_word':
				return __( 'jednoslovná zpráva', 'raynet-lead-api-integration' );
			case 'message_is_name':
				return __( 'zpráva jen opakuje jméno nebo kontakt', 'raynet-lead-api-integration' );
			case 'duplicate':
				return __( 'duplicitní odeslání', 'raynet-lead-api-integration' );
		}

		// A signal added through the raynet_import_spam_score filter.
		return $id;
	}

	/**
	 * Everything stored with a submission, and the lead it would make.
	 *
	 * @param array<string,mixed>                $row     Stored submission.
	 * @param array<string,array<string,string>> $fields  Fields from fields_for().
	 * @param array<string,mixed>|string         $payload What preview_payload() returns, or why there is no lead.
	 * @return void
	 */
	private static function detail( array $row, array $fields, $payload ) {
		?>
		<div class="raynet-import__columns">
			<div>
				<h4><?php esc_html_e( 'Uložené hodnoty', 'raynet-lead-api-integration' ); ?></h4>
				<dl class="raynet-import__values">
					<?php foreach ( $fields as $field ) : ?>
						<?php
						// A password is never shown, as it is never sent.
						if ( 'password' === $field['type'] ) {
							continue;
						}
						?>
						<dt><?php echo esc_html( '' !== $field['title'] ? $field['title'] : $field['id'] ); ?></dt>
						<dd>
							<?php echo nl2br( esc_html( $field['value'] ) ); ?>
							<?php if ( 'number' === $field['type'] && '' !== trim( $field['value'] ) ) : ?>
								<br /><span class="raynet-elm__warn"><?php esc_html_e( 'Elementor uložil jen celé číslo; úvodní nuly a desetinná část se nedochovaly', 'raynet-lead-api-integration' ); ?></span>
							<?php endif; ?>
						</dd>
					<?php endforeach; ?>
					<dt><?php esc_html_e( 'IP adresa', 'raynet-lead-api-integration' ); ?></dt>
					<dd><?php echo esc_html( $row['ip'] ); ?></dd>
					<dt><?php esc_html_e( 'Prohlížeč', 'raynet-lead-api-integration' ); ?></dt>
					<dd><?php echo esc_html( $row['user_agent'] ); ?></dd>
					<dt><?php esc_html_e( 'Odesláno ze stránky', 'raynet-lead-api-integration' ); ?></dt>
					<dd><?php echo esc_html( $row['referer'] ); ?></dd>
				</dl>
			</div>
			<div>
				<h4><?php esc_html_e( 'Náhled leadu', 'raynet-lead-api-integration' ); ?></h4>
				<?php if ( is_array( $payload ) ) : ?>
					<?php self::preview( $payload ); ?>
				<?php else : ?>
					<p class="raynet-elm__warn"><?php echo esc_html( (string) $payload ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The lead exactly as import() would send it: attributes, then the note.
	 *
	 * @param array<string,mixed> $payload Result of Raynet_Lead_Form::preview_payload().
	 * @return void
	 */
	private static function preview( array $payload ) {
		$note = isset( $payload['notice'] ) ? (string) $payload['notice'] : '';

		unset( $payload['notice'] );
		?>
		<dl class="raynet-import__values">
			<?php foreach ( self::flatten( $payload ) as $path => $value ) : ?>
				<dt><?php echo esc_html( self::attribute_label( $path ) ); ?></dt>
				<dd><?php echo esc_html( self::attribute_value( $path, $value ) ); ?></dd>
			<?php endforeach; ?>
			<dt><?php esc_html_e( 'Poznámka', 'raynet-lead-api-integration' ); ?></dt>
			<dd class="raynet-import__note"><?php echo esc_html( $note ); ?></dd>
		</dl>
		<p class="description"><?php esc_html_e( 'Náhled počítá s výchozím štítkem importu; odešle se štítek, který bude v poli nad tabulkou.', 'raynet-lead-api-integration' ); ?></p>
		<?php
	}

	/**
	 * Nested payload keys as dotted paths: contactInfo.email, customFields.X.
	 *
	 * @param array<string,mixed> $data   Payload part.
	 * @param string              $prefix Path so far.
	 * @return array<string,mixed> Path => scalar value.
	 */
	private static function flatten( array $data, $prefix = '' ) {
		$flat = array();

		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) && ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				$flat = array_merge( $flat, self::flatten( $value, $path ) );
			} else {
				$flat[ $path ] = $value;
			}
		}

		return $flat;
	}

	/**
	 * Human label of one payload path.
	 *
	 * @param string $path Dotted path.
	 * @return string Label, unescaped.
	 */
	private static function attribute_label( $path ) {
		$labels = array(
			'topic'               => __( 'Předmět', 'raynet-lead-api-integration' ),
			'priority'            => __( 'Priorita', 'raynet-lead-api-integration' ),
			'leadDate'            => __( 'Datum leadu', 'raynet-lead-api-integration' ),
			'leadPerson'          => __( 'Typ leadu', 'raynet-lead-api-integration' ),
			'firstName'           => __( 'Jméno', 'raynet-lead-api-integration' ),
			'lastName'            => __( 'Příjmení', 'raynet-lead-api-integration' ),
			'companyName'         => __( 'Společnost', 'raynet-lead-api-integration' ),
			'contactInfo.email'   => __( 'E-mail', 'raynet-lead-api-integration' ),
			'contactInfo.tel1'    => __( 'Telefon', 'raynet-lead-api-integration' ),
			'address.street'      => __( 'Ulice', 'raynet-lead-api-integration' ),
			'address.city'        => __( 'Město', 'raynet-lead-api-integration' ),
			'address.zipCode'     => __( 'PSČ', 'raynet-lead-api-integration' ),
			'category'            => __( 'Kategorie (ID)', 'raynet-lead-api-integration' ),
			'leadPhase'           => __( 'Stav leadu (ID)', 'raynet-lead-api-integration' ),
			'contactSource'       => __( 'Zdroj kontaktu (ID)', 'raynet-lead-api-integration' ),
			'owner'               => __( 'Vlastník leadu', 'raynet-lead-api-integration' ),
			'securityLevel'       => __( 'Bezpečnostní úroveň (ID)', 'raynet-lead-api-integration' ),
			'tags'                => __( 'Štítky', 'raynet-lead-api-integration' ),
			'notificationMessage' => __( 'Text notifikace', 'raynet-lead-api-integration' ),
		);

		if ( isset( $labels[ $path ] ) ) {
			return $labels[ $path ];
		}

		if ( 0 === strpos( $path, 'customFields.' ) ) {
			$name   = substr( $path, strlen( 'customFields.' ) );
			$custom = Raynet_Lead_Fields::custom();

			return isset( $custom[ $name ]['label'] ) ? $custom[ $name ]['label'] : $name;
		}

		return html_entity_decode( Raynet_Elementor_Forms::target_label( $path ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Human form of one payload value.
	 *
	 * @param string $path  Dotted path.
	 * @param mixed  $value Value.
	 * @return string Text, unescaped.
	 */
	private static function attribute_value( $path, $value ) {
		if ( 'leadPerson' === $path ) {
			return $value ? __( 'Fyzická osoba', 'raynet-lead-api-integration' ) : __( 'Firma', 'raynet-lead-api-integration' );
		}

		if ( 'priority' === $path ) {
			$priorities = array(
				'MINOR'    => __( 'Nízká', 'raynet-lead-api-integration' ),
				'DEFAULT'  => __( 'Běžná', 'raynet-lead-api-integration' ),
				'CRITICAL' => __( 'Kritická', 'raynet-lead-api-integration' ),
			);

			return isset( $priorities[ $value ] ) ? $priorities[ $value ] : (string) $value;
		}

		if ( 'owner' === $path ) {
			$names = Raynet_Lead_Users::names();

			return isset( $names[ (int) $value ] ) ? $names[ (int) $value ] : (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'ano', 'raynet-lead-api-integration' ) : __( 'ne', 'raynet-lead-api-integration' );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Page links and the rows-per-page choice.
	 *
	 * @param string $base     URL of the current tab.
	 * @param int    $page     Current page, from 1.
	 * @param int    $per_page Rows per page.
	 * @param int    $total    Rows in the tab.
	 * @return void
	 */
	private static function pagination( $base, $page, $per_page, $total ) {
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		// Past the end the table says the page is empty; the links stay real.
		$page = min( max( 1, (int) $page ), $pages );
		?>
		<div class="raynet-import__pages">
			<?php if ( $pages > 1 ) : ?>
				<span class="raynet-import__page-links">
					<?php if ( $page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'strana', min( $page - 1, $pages ), $base ) ); ?>" aria-label="<?php esc_attr_e( 'Předchozí stránka', 'raynet-lead-api-integration' ); ?>">&lsaquo;</a>
					<?php endif; ?>
					<?php foreach ( self::page_numbers( $page, $pages ) as $number ) : ?>
						<?php if ( null === $number ) : ?>
							<span class="raynet-import__gap">&hellip;</span>
						<?php elseif ( $number === $page ) : ?>
							<strong aria-current="page"><?php echo (int) $number; ?></strong>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( 'strana', $number, $base ) ); ?>"><?php echo (int) $number; ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( $page < $pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'strana', $page + 1, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Další stránka', 'raynet-lead-api-integration' ); ?>">&rsaquo;</a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<span class="raynet-import__per-page">
				<?php esc_html_e( 'Na stránku:', 'raynet-lead-api-integration' ); ?>
				<?php foreach ( self::PER_PAGE as $size ) : ?>
					<?php if ( $size === $per_page ) : ?>
						<strong aria-current="true"><?php echo (int) $size; ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'na_stranku' => $size, 'strana' => 1 ), $base ) ); ?>"><?php echo (int) $size; ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Page numbers to link: the first, the last, and two either side of the
	 * current one, with null where pages are left out.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Number of pages.
	 * @return array<int,int|null> Numbers in order, null for a gap.
	 */
	public static function page_numbers( $page, $pages ) {
		// A page from the URL can be anything; range() over it must stay small.
		$pages  = max( 1, (int) $pages );
		$page   = min( max( 1, (int) $page ), $pages );
		$wanted = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) );
		$wanted = array_filter(
			$wanted,
			static function ( $number ) use ( $pages ) {
				return $number >= 1 && $number <= $pages;
			}
		);

		sort( $wanted );

		$numbers  = array();
		$previous = 0;

		foreach ( $wanted as $number ) {
			if ( $number > $previous + 1 ) {
				$numbers[] = null;
			}

			$numbers[] = $number;
			$previous  = $number;
		}

		return $numbers;
	}

	/**
	 * A stored local date as the screen shows it.
	 *
	 * @param string $created_at `Y-m-d H:i:s`.
	 * @return string Date such as "12. 3. 2025 14:10", or the stored text.
	 */
	private static function date( $created_at ) {
		$date = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $created_at );

		return false === $date ? (string) $created_at : $date->format( 'j. n. Y H:i' );
	}

	/**
	 * Prints an admin notice.
	 *
	 * @param string $type    notice-* modifier: error, warning, info.
	 * @param string $message Text.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}
}
