<?php
/**
 * Admin screen listing every Elementor form and applying templates to them.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * The "Elementor formuláře" screen.
 */
class Raynet_Elementor_Forms_Admin {

	/**
	 * Page slug.
	 */
	const PAGE = 'raynet-elementor-forms';

	/**
	 * Nonce action shared by the screen's three operations.
	 */
	const NONCE = 'raynet_elementor_forms';

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_raynet_elm_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_raynet_elm_delete_template', array( $this, 'handle_delete_template' ) );
		add_action( 'admin_post_raynet_elm_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_raynet_elm_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_raynet_elm_refresh_fields', array( $this, 'handle_refresh_fields' ) );
	}

	/**
	 * Adds the submenu, but only where Elementor is actually installed.
	 *
	 * @return void
	 */
	public function add_menu() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		add_submenu_page(
			Raynet_Lead_Admin::PAGE,
			__( 'Elementor formuláře', 'raynet-lead-api-integration' ),
			__( 'Elementor formuláře', 'raynet-lead-api-integration' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Before the scan, so a first visit already offers the custom fields.
		Raynet_Lead_Fields::maybe_refresh();

		$forms     = Raynet_Elementor_Forms::scan();
		$templates = Raynet_Elementor_Forms::templates();
		$editing   = isset( $_GET['sablona'] ) ? sanitize_title( wp_unslash( $_GET['sablona'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$lead      = isset( $templates[ $editing ] ) ? $templates[ $editing ]['lead'] : Raynet_Lead_Form_Definition::lead_defaults();
		$name      = isset( $templates[ $editing ] ) ? $templates[ $editing ]['name'] : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Elementor formuláře', 'raynet-lead-api-integration' ); ?></h1>

			<?php $this->notices(); ?>

			<?php if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Elementor Pro není aktivní. Widget Formulář je jen v Pro verzi, takže zde zatím nebude co nastavovat.', 'raynet-lead-api-integration' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Šablona nese nastavení leadu. Při nasazení se zapne akce RAYNET CRM a nenamapovaná pole se doplní odhadem podle popisku a typu; co už je namapované, zůstane. Předchozí stav stránky se zálohuje a jde vrátit.', 'raynet-lead-api-integration' ); ?>
			</p>

			<?php $this->fields_panel(); ?>

			<div class="raynet-elm">
				<div class="raynet-elm__templates">
					<h2><?php esc_html_e( 'Šablony', 'raynet-lead-api-integration' ); ?></h2>

					<?php if ( ! empty( $templates ) ) : ?>
						<ul class="raynet-elm__template-list">
							<?php foreach ( $templates as $slug => $template ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'sablona' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
										<?php echo esc_html( $template['name'] ); ?>
									</a>
									<?php if ( $slug === $editing ) : ?>
										<strong>&larr; <?php esc_html_e( 'upravujete', 'raynet-lead-api-integration' ); ?></strong>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e( 'Zatím nemáte žádnou šablonu.', 'raynet-lead-api-integration' ); ?></p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="raynet_elm_save_template" />
						<?php wp_nonce_field( self::NONCE ); ?>

						<h3><?php echo $editing ? esc_html__( 'Upravit šablonu', 'raynet-lead-api-integration' ) : esc_html__( 'Nová šablona', 'raynet-lead-api-integration' ); ?></h3>

						<p>
							<label for="raynet-tpl-name"><strong><?php esc_html_e( 'Název šablony', 'raynet-lead-api-integration' ); ?></strong></label><br />
							<input type="text" class="regular-text" id="raynet-tpl-name" name="template_name" value="<?php echo esc_attr( $name ); ?>" required />
						</p>

						<?php $this->lead_fields( $lead ); ?>

						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Uložit šablonu', 'raynet-lead-api-integration' ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=raynet_elm_delete_template&sablona=' . $editing ), self::NONCE ) ); ?>">
									<?php esc_html_e( 'Smazat', 'raynet-lead-api-integration' ); ?>
								</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="raynet-elm__forms">
					<h2><?php esc_html_e( 'Nalezené formuláře', 'raynet-lead-api-integration' ); ?></h2>

					<?php if ( empty( $forms ) ) : ?>
						<p><?php esc_html_e( 'Na webu není žádný formulář Elementoru.', 'raynet-lead-api-integration' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="raynet_elm_apply" />
							<?php wp_nonce_field( self::NONCE ); ?>

							<table class="wp-list-table widefat striped">
								<thead>
									<tr>
										<td class="check-column"></td>
										<th><?php esc_html_e( 'Umístění', 'raynet-lead-api-integration' ); ?></th>
										<th><?php esc_html_e( 'Stránka', 'raynet-lead-api-integration' ); ?></th>
										<th><?php esc_html_e( 'Formulář', 'raynet-lead-api-integration' ); ?></th>
										<th><?php esc_html_e( 'Pole', 'raynet-lead-api-integration' ); ?></th>
										<th><?php esc_html_e( 'RAYNET', 'raynet-lead-api-integration' ); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $forms as $form ) : ?>
										<?php $key = $form['post_id'] . ':' . $form['widget_id']; ?>
										<tr<?php echo $form['rendered'] && ! $form['atomic'] ? '' : ' class="raynet-elm__inactive"'; ?>>
											<th class="check-column">
												<input type="checkbox" name="targets[]" value="<?php echo esc_attr( $key ); ?>" <?php disabled( ! $form['rendered'] || $form['atomic'] || '' === $form['widget_id'] ); ?> />
											</th>
											<td><?php echo esc_html( $form['location'] ); ?></td>
											<td>
												<a href="<?php echo esc_url( get_edit_post_link( $form['post_id'] ) ); ?>"><?php echo esc_html( $form['post_title'] ); ?></a>
											</td>
											<td><?php echo esc_html( '' !== $form['form_name'] ? $form['form_name'] : __( 'bez názvu', 'raynet-lead-api-integration' ) ); ?></td>
											<td>
												<?php
												$labels = array();

												foreach ( $form['fields'] as $field ) {
													$labels[] = '' !== $field['label'] ? $field['label'] : $field['id'];
												}

												echo esc_html( implode( ', ', $labels ) );
												?>
											</td>
											<td>
												<?php if ( $form['atomic'] ) : ?>
													<span class="raynet-elm__off"><?php esc_html_e( 'atomový formulář Elementoru 4 — zatím nepodporovaný, RAYNET v něm nejde zapnout', 'raynet-lead-api-integration' ); ?></span>
												<?php elseif ( ! $form['rendered'] ) : ?>
													<span class="raynet-elm__off"><?php esc_html_e( 'nevykresluje se — stránka je přepnutá do editoru WordPressu', 'raynet-lead-api-integration' ); ?></span>
												<?php elseif ( $form['enabled'] ) : ?>
													<span class="raynet-elm__on">
														<?php
														printf(
															/* translators: %d: number of mapped attributes. */
															esc_html__( 'zapnuto, namapováno: %d', 'raynet-lead-api-integration' ),
															(int) $form['mapped']
														);
														?>
													</span>
													<?php if ( ! $form['has_contact'] ) : ?>
														<br /><span class="raynet-elm__warn">&#9888; <?php esc_html_e( 'chybí e-mail i telefon — RAYNET lead nepřijme', 'raynet-lead-api-integration' ); ?></span>
													<?php endif; ?>
												<?php else : ?>
													<span class="raynet-elm__off"><?php esc_html_e( 'vypnuto', 'raynet-lead-api-integration' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<a href="<?php echo esc_url( $form['edit_url'] ); ?>"><?php esc_html_e( 'Otevřít v Elementoru', 'raynet-lead-api-integration' ); ?></a>
												<?php if ( Raynet_Elementor_Forms::has_backup( $form['post_id'] ) ) : ?>
													&nbsp;|&nbsp;
													<?php
													$stale            = Raynet_Elementor_Forms::edited_since( $form['post_id'] );
													$restore_question = $stale
														? __( 'Stránka byla od nasazení upravena v Elementoru. Vrácení o tyto úpravy připraví a vrátí i ostatní formuláře na stránce. Pokračovat?', 'raynet-lead-api-integration' )
														: __( 'Vrátit celou stránku do podoby před nasazením šablony? Týká se všech formulářů na ní.', 'raynet-lead-api-integration' );

													// force is raised only when the page changed, and only after the
													// question was answered, so with scripting off the server refuses
													// a stale rollback and explains instead of overwriting silently.
													$restore_click = 'if ( ! confirm( ' . wp_json_encode( $restore_question ) . ' ) ) { return false; }';

													if ( $stale ) {
														$restore_click .= ' document.getElementById( ' . wp_json_encode( 'raynet-force-' . (int) $form['post_id'] ) . ' ).value = \'1\';';
													}
													?>
													<button
														type="submit"
														form="raynet-restore-<?php echo (int) $form['post_id']; ?>"
														class="button-link raynet-elm__undo"
														onclick="<?php echo esc_attr( $restore_click ); ?>"
													>
														<?php esc_html_e( 'Vrátit stránku zpět', 'raynet-lead-api-integration' ); ?>
														<?php if ( $stale ) : ?>
															<span class="raynet-elm__stale" title="<?php esc_attr_e( 'Stránka byla mezitím upravena', 'raynet-lead-api-integration' ); ?>">&#9888;</span>
														<?php endif; ?>
													</button>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<p class="raynet-elm__apply">
								<label for="raynet-apply-template"><?php esc_html_e( 'Nasadit šablonu:', 'raynet-lead-api-integration' ); ?></label>
								<select id="raynet-apply-template" name="template" required>
									<option value=""><?php esc_html_e( '— vyberte —', 'raynet-lead-api-integration' ); ?></option>
									<?php foreach ( $templates as $slug => $template ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $template['name'] ); ?></option>
									<?php endforeach; ?>
								</select>

								<label>
									<input type="checkbox" name="automap" value="1" checked />
									<?php esc_html_e( 'Doplnit mapování odhadem', 'raynet-lead-api-integration' ); ?>
								</label>

								<button type="submit" class="button button-primary" <?php disabled( empty( $templates ) ); ?>>
									<?php esc_html_e( 'Nasadit na vybrané', 'raynet-lead-api-integration' ); ?>
								</button>
							</p>

							<p class="description">
								<?php esc_html_e( 'Nasazení přepíše nastavení leadu u vybraných formulářů. Mapování, které už formulář má, zůstane. Předchozí podoba stránky se uloží a jde vrátit tlačítkem v tabulce.', 'raynet-lead-api-integration' ); ?>
							</p>
						</form>

						<?php foreach ( array_unique( array_column( $forms, 'post_id' ) ) as $backup_post ) : ?>
							<?php if ( Raynet_Elementor_Forms::has_backup( $backup_post ) ) : ?>
								<form
									id="raynet-restore-<?php echo (int) $backup_post; ?>"
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									class="raynet-elm__hidden-form"
								>
									<input type="hidden" name="action" value="raynet_elm_restore" />
									<input type="hidden" name="post" value="<?php echo (int) $backup_post; ?>" />
									<input type="hidden" id="raynet-force-<?php echo (int) $backup_post; ?>" name="force" value="0" />
									<?php wp_nonce_field( self::NONCE ); ?>
								</form>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Shows which RAYNET fields the mapping offers, with a way to reload them.
	 *
	 * @return void
	 */
	private function fields_panel() {
		$state  = Raynet_Lead_Fields::state();
		$custom = $state['fields'];
		$types  = array(
			'STRING'      => __( 'text', 'raynet-lead-api-integration' ),
			'TEXT'        => __( 'dlouhý text', 'raynet-lead-api-integration' ),
			'HYPERLINK'   => __( 'odkaz', 'raynet-lead-api-integration' ),
			'BIG_DECIMAL' => __( 'číslo', 'raynet-lead-api-integration' ),
			'MONETARY'    => __( 'částka', 'raynet-lead-api-integration' ),
			'PERCENT'     => __( 'procenta', 'raynet-lead-api-integration' ),
			'BOOLEAN'     => __( 'ano/ne', 'raynet-lead-api-integration' ),
			'DATE'        => __( 'datum', 'raynet-lead-api-integration' ),
			'DATETIME'    => __( 'datum a čas', 'raynet-lead-api-integration' ),
			'TIME'        => __( 'čas', 'raynet-lead-api-integration' ),
			'ENUMERATION' => __( 'výběr z číselníku', 'raynet-lead-api-integration' ),
		);
		?>
		<details class="raynet-elm__fields" <?php echo $state['fetched_at'] ? '' : 'open'; ?>>
			<summary>
				<strong><?php esc_html_e( 'Pole z RAYNETu', 'raynet-lead-api-integration' ); ?></strong>
				&mdash;
				<?php
				printf(
					/* translators: 1: number of standard attributes, 2: number of custom fields. */
					esc_html__( 'k mapování: standardní atributy %1$d, vlastní pole %2$d', 'raynet-lead-api-integration' ),
					count( Raynet_Lead_Form_Definition::catalogue() ) - 1 + count( Raynet_Lead_Fields::extended() ),
					count( $custom )
				);
				?>
			</summary>

			<p class="description">
				<?php esc_html_e( 'Vlastní pole leadu se načítají z vaší instance RAYNETu. V Elementoru je najdete v sekci RAYNET CRM → Mapování polí, označená „(vlastní pole)“. Hodnotu, kterou pole nepřijme — nečitelné datum, položku mimo číselník — plugin místo odmítnutí leadu zapíše do poznámky.', 'raynet-lead-api-integration' ); ?>
			</p>

			<?php if ( '' !== $state['error'] ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: error message. */
							esc_html__( 'Poslední načtení selhalo: %s', 'raynet-lead-api-integration' ),
							esc_html( $state['error'] )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $custom ) ) : ?>
				<table class="widefat striped raynet-elm__custom">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pole', 'raynet-lead-api-integration' ); ?></th>
							<th><?php esc_html_e( 'Typ', 'raynet-lead-api-integration' ); ?></th>
							<th><?php esc_html_e( 'Kód v API', 'raynet-lead-api-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $custom as $name => $meta ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $meta['label'] ); ?>
									<?php if ( '' !== $meta['group'] ) : ?>
										<span class="description">(<?php echo esc_html( $meta['group'] ); ?>)</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( isset( $types[ $meta['type'] ] ) ? $types[ $meta['type'] ] : $meta['type'] ); ?>
									<?php if ( ! empty( $meta['enum'] ) ) : ?>
										<span class="description">: <?php echo esc_html( implode( ', ', $meta['enum'] ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $name ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( $state['fetched_at'] ) : ?>
				<p><?php esc_html_e( 'Vaše instance RAYNETu nemá u leadů žádná vlastní pole, která by šla vyplnit z formuláře.', 'raynet-lead-api-integration' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="raynet_elm_refresh_fields" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Načíst pole z RAYNETu znovu', 'raynet-lead-api-integration' ); ?></button>
					<?php if ( $state['fetched_at'] ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: date and time. */
								esc_html__( 'Naposledy načteno %s.', 'raynet-lead-api-integration' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['fetched_at'] ) )
							);
							?>
						</span>
					<?php endif; ?>
				</p>
			</form>
		</details>
		<?php
	}

	/**
	 * Renders the lead settings shared by the template editor.
	 *
	 * @param array<string,mixed> $lead Current values.
	 * @return void
	 */
	private function lead_fields( array $lead ) {
		$inherit = __( 'Zdědit z nastavení pluginu', 'raynet-lead-api-integration' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="raynet-tpl-topic"><?php esc_html_e( 'Předmět leadu', 'raynet-lead-api-integration' ); ?></label></th>
				<td><input type="text" class="regular-text" id="raynet-tpl-topic" name="lead[topic]" value="<?php echo esc_attr( $lead['topic'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="raynet-tpl-priority"><?php esc_html_e( 'Priorita', 'raynet-lead-api-integration' ); ?></label></th>
				<td>
					<select id="raynet-tpl-priority" name="lead[priority]">
						<option value=""><?php echo esc_html( $inherit ); ?></option>
						<?php
						foreach ( array(
							'MINOR'    => __( 'Nízká', 'raynet-lead-api-integration' ),
							'DEFAULT'  => __( 'Běžná', 'raynet-lead-api-integration' ),
							'CRITICAL' => __( 'Kritická', 'raynet-lead-api-integration' ),
						) as $value => $text ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $lead['priority'], $value ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raynet-tpl-person"><?php esc_html_e( 'Typ leadu', 'raynet-lead-api-integration' ); ?></label></th>
				<td>
					<select id="raynet-tpl-person" name="lead[lead_person]">
						<option value=""><?php echo esc_html( $inherit ); ?></option>
						<option value="1" <?php selected( $lead['lead_person'], '1' ); ?>><?php esc_html_e( 'Fyzická osoba', 'raynet-lead-api-integration' ); ?></option>
						<option value="0" <?php selected( $lead['lead_person'], '0' ); ?>><?php esc_html_e( 'Firma', 'raynet-lead-api-integration' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raynet-tpl-notice"><?php esc_html_e( 'Předpona poznámky', 'raynet-lead-api-integration' ); ?></label></th>
				<td><textarea class="large-text" rows="2" id="raynet-tpl-notice" name="lead[notice_prefix]"><?php echo esc_textarea( $lead['notice_prefix'] ); ?></textarea></td>
			</tr>
			<?php
			foreach ( array(
				'category'       => __( 'Kategorie (ID)', 'raynet-lead-api-integration' ),
				'lead_phase'     => __( 'Stav leadu (ID)', 'raynet-lead-api-integration' ),
				'contact_source' => __( 'Zdroj kontaktu (ID)', 'raynet-lead-api-integration' ),
				'owner'          => __( 'Vlastník (ID)', 'raynet-lead-api-integration' ),
				'security_level' => __( 'Bezpečnostní úroveň (ID)', 'raynet-lead-api-integration' ),
			) as $key => $label ) :
				?>
				<tr>
					<th scope="row"><label for="raynet-tpl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="number" min="0" id="raynet-tpl-<?php echo esc_attr( $key ); ?>" name="lead[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $lead[ $key ] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="raynet-tpl-tags"><?php esc_html_e( 'Štítky', 'raynet-lead-api-integration' ); ?></label></th>
				<td><input type="text" class="regular-text" id="raynet-tpl-tags" name="lead[tags]" value="<?php echo esc_attr( $lead['tags'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="raynet-tpl-notify"><?php esc_html_e( 'Notifikační e-maily', 'raynet-lead-api-integration' ); ?></label></th>
				<td><input type="text" class="regular-text" id="raynet-tpl-notify" name="lead[notify_emails]" value="<?php echo esc_attr( $lead['notify_emails'] ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Shows the outcome of the last operation.
	 *
	 * @return void
	 */
	private function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result of a redirect.
		$done   = isset( $_GET['raynet_done'] ) ? sanitize_key( wp_unslash( $_GET['raynet_done'] ) ) : '';
		$count  = isset( $_GET['raynet_count'] ) ? absint( wp_unslash( $_GET['raynet_count'] ) ) : 0;
		$failed = isset( $_GET['raynet_failed'] ) ? absint( wp_unslash( $_GET['raynet_failed'] ) ) : 0;
		$bare   = isset( $_GET['raynet_nocontact'] ) ? absint( wp_unslash( $_GET['raynet_nocontact'] ) ) : 0;
		$error  = isset( $_GET['raynet_error'] ) ? sanitize_key( wp_unslash( $_GET['raynet_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Only codes travel in the query string. A message taken from there
		// would let anyone who can get an administrator to follow a link put
		// their own words inside an official-looking WordPress notice.
		$errors = array(
			'no_name'     => __( 'Šablona musí mít název.', 'raynet-lead-api-integration' ),
			'no_template' => __( 'Vyberte prosím šablonu.', 'raynet-lead-api-integration' ),
			'no_targets'  => __( 'Nevybral jste žádný formulář.', 'raynet-lead-api-integration' ),
			'no_backup'   => __( 'Pro tuto stránku není uložená záloha.', 'raynet-lead-api-integration' ),
			'stale'       => __( 'Stránka byla od nasazení upravena v Elementoru, proto se nic nevrátilo.', 'raynet-lead-api-integration' ),
			'draft'       => __( 'Stránka má v Elementoru neuložený koncept, proto se nic nevrátilo. Otevřete ji v Elementoru, koncept publikujte nebo zahoďte a vrácení zopakujte.', 'raynet-lead-api-integration' ),
			'restore'     => __( 'Vrácení se nezdařilo.', 'raynet-lead-api-integration' ),
			'fields'      => __( 'Pole z RAYNETu se nepodařilo načíst. Podrobnosti jsou v panelu Pole z RAYNETu.', 'raynet-lead-api-integration' ),
		);

		if ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
			return;
		}

		if ( 'applied' === $done ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of forms. */
						_n( 'Šablona nasazena na %d formulář.', 'Šablona nasazena na %d formulářů.', $count, 'raynet-lead-api-integration' ),
						$count
					)
				)
			);

			if ( $failed > 0 ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of forms. */
							_n( '%d formulář se nastavit nepodařilo. Zkontrolujte, jestli stránka pořád obsahuje ten formulář a jestli ji Elementor vykresluje.', '%d formulářů se nastavit nepodařilo. Zkontrolujte, jestli stránky pořád obsahují ty formuláře a jestli je Elementor vykresluje.', $failed, 'raynet-lead-api-integration' ),
							$failed
						)
					)
				);
			}

			if ( $bare > 0 ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of forms. */
							_n( '%d nastavený formulář nemá namapovaný e-mail ani telefon, takže z něj lead nevznikne. Namapujte pole v Elementoru (sekce RAYNET CRM); formulář je v tabulce označený.', '%d nastavených formulářů nemá namapovaný e-mail ani telefon, takže z nich leady nevzniknou. Namapujte pole v Elementoru (sekce RAYNET CRM); formuláře jsou v tabulce označené.', $bare, 'raynet-lead-api-integration' ),
							$bare
						)
					)
				);
			}
		}

		$messages = array(
			'fields'   => __( 'Pole z RAYNETu načtena.', 'raynet-lead-api-integration' ),
			'restored' => __( 'Předchozí podoba stránky byla obnovena.', 'raynet-lead-api-integration' ),
			'saved'    => __( 'Šablona uložena.', 'raynet-lead-api-integration' ),
			'deleted'  => __( 'Šablona smazána.', 'raynet-lead-api-integration' ),
		);

		if ( isset( $messages[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
	}

	/**
	 * Stores a template.
	 *
	 * @return void
	 */
	public function handle_save_template() {
		$this->guard();

		$name = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$lead = isset( $_POST['lead'] ) && is_array( $_POST['lead'] ) ? wp_unslash( $_POST['lead'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the definition class.

		if ( '' === $name ) {
			$this->back( array( 'raynet_error' => 'no_name' ) );
		}

		$slug = Raynet_Elementor_Forms::save_template( $name, $lead );

		$this->back( array( 'raynet_done' => 'saved', 'sablona' => $slug ) );
	}

	/**
	 * Removes a template.
	 *
	 * @return void
	 */
	public function handle_delete_template() {
		$this->guard();

		$slug = isset( $_GET['sablona'] ) ? sanitize_title( wp_unslash( $_GET['sablona'] ) ) : '';

		Raynet_Elementor_Forms::delete_template( $slug );

		$this->back( array( 'raynet_done' => 'deleted' ) );
	}

	/**
	 * Applies a template to the selected forms.
	 *
	 * @return void
	 */
	public function handle_apply() {
		$this->guard();

		$templates = Raynet_Elementor_Forms::templates();
		$slug      = isset( $_POST['template'] ) ? sanitize_title( wp_unslash( $_POST['template'] ) ) : '';

		if ( ! isset( $templates[ $slug ] ) ) {
			$this->back( array( 'raynet_error' => 'no_template' ) );
		}

		$raw = isset( $_POST['targets'] ) && is_array( $_POST['targets'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['targets'] ) )
			: array();

		// One page can hold several forms. Grouping them means the page is read
		// and written once, rather than once per form.
		$pages = array();

		foreach ( $raw as $target ) {
			if ( ! preg_match( '/^(\d+):(.+)$/', $target, $parts ) ) {
				continue;
			}

			$pages[ (int) $parts[1] ][] = $parts[2];
		}

		if ( empty( $pages ) ) {
			$this->back( array( 'raynet_error' => 'no_targets' ) );
		}

		$automap = ! empty( $_POST['automap'] );
		$done    = 0;
		$failed  = 0;
		$bare    = 0;

		foreach ( $pages as $post_id => $widget_ids ) {
			$widget_ids = array_values( array_unique( $widget_ids ) );
			$result     = Raynet_Elementor_Forms::apply( $post_id, $widget_ids, $templates[ $slug ]['lead'], $automap );

			if ( is_wp_error( $result ) ) {
				$failed += count( $widget_ids );
				continue;
			}

			// Counted per form: a form deleted from the page since the table was
			// drawn is a failure, not part of the success count.
			$done   += count( $result );
			$failed += count( array_diff( $widget_ids, $result ) );

			foreach ( Raynet_Elementor_Forms::forms_in_post( $post_id ) as $form ) {
				if ( in_array( $form['widget_id'], $result, true ) && ! $form['has_contact'] ) {
					$bare++;
				}
			}
		}

		$this->back(
			array(
				'raynet_done'      => 'applied',
				'raynet_count'     => $done,
				'raynet_failed'    => $failed,
				'raynet_nocontact' => $bare,
			)
		);
	}

	/**
	 * Fetches the custom field configuration from RAYNET on request.
	 *
	 * @return void
	 */
	public function handle_refresh_fields() {
		$this->guard();

		$result = Raynet_Lead_Fields::refresh();

		$this->back( is_wp_error( $result ) ? array( 'raynet_error' => 'fields' ) : array( 'raynet_done' => 'fields' ) );
	}

	/**
	 * Rolls one page back to the layout it had before the last apply.
	 *
	 * @return void
	 */
	public function handle_restore() {
		$this->guard();

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$force   = ! empty( $_POST['force'] );
		$result  = Raynet_Elementor_Forms::restore( $post_id, $force );

		if ( is_wp_error( $result ) ) {
			$codes = array(
				'raynet_stale_backup'  => 'stale',
				'raynet_pending_draft' => 'draft',
			);
			$code  = isset( $codes[ $result->get_error_code() ] ) ? $codes[ $result->get_error_code() ] : 'restore';

			$this->back( array( 'raynet_error' => $code ) );
		}

		$this->back( array( 'raynet_done' => 'restored' ) );
	}

	/**
	 * Refuses anything that is not a signed request from an administrator.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'raynet-lead-api-integration' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );
	}

	/**
	 * Returns to the screen with a result in the query string.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return void
	 */
	private function back( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => self::PAGE ), $args ),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}
}
