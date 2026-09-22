<?php
/**
 * Admin screen: settings form, connection test and code list browser.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the settings page with the WordPress Settings API.
 */
class Raynet_Lead_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'raynet-lead-integration';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_raynet_lead_test_connection', array( $this, 'handle_test_connection' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( RAYNET_LEAD_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Adds the settings page to the admin menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'RAYNET Lead API', 'raynet-lead-api-integration' ),
			__( 'RAYNET CRM', 'raynet-lead-api-integration' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-groups',
			58
		);

		/*
		 * add_menu_page() does not register a submenu for the page it creates.
		 * Without one, the forms list is this menu's only child, and
		 * wp-admin/includes/menu.php rewrites the parent's slug to that first
		 * child: the settings page then disappears from the menu and answers
		 * "Sorry, you are not allowed to access this page."
		 */
		add_submenu_page(
			self::PAGE,
			__( 'RAYNET Lead API', 'raynet-lead-api-integration' ),
			__( 'Nastavení', 'raynet-lead-api-integration' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a "Nastavení" link to the plugin row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[] Links with the settings link prepended.
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Nastavení', 'raynet-lead-api-integration' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Registers the single settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			Raynet_Lead_Settings::GROUP,
			Raynet_Lead_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Raynet_Lead_Settings', 'sanitize' ),
				'default'           => Raynet_Lead_Settings::defaults(),
			)
		);
	}

	/**
	 * Loads the admin script on the settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// The hook suffix depends on whether the parent slug survived the rewrite
		// described in add_menu(), so match on the page instead.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.

		if ( self::PAGE !== $page ) {
			return;
		}

		wp_enqueue_style(
			'raynet-lead-admin',
			RAYNET_LEAD_URL . 'assets/css/raynet-lead-admin.css',
			array(),
			RAYNET_LEAD_VERSION
		);

		wp_enqueue_script(
			'raynet-lead-admin',
			RAYNET_LEAD_URL . 'assets/js/raynet-lead-admin.js',
			array(),
			RAYNET_LEAD_VERSION,
			true
		);

		wp_localize_script(
			'raynet-lead-admin',
			'raynetLeadAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'raynet_lead_test_connection' ),
				'testing' => __( 'Testuji spojení…', 'raynet-lead-api-integration' ),
				'failed'  => __( 'Test spojení se nezdařil.', 'raynet-lead-api-integration' ),
				'updateNonce'   => wp_create_nonce( 'raynet_lead_check_update' ),
				'checking'      => __( 'Zjišťuji…', 'raynet-lead-api-integration' ),
				'checkFailed'   => __( 'Kontrolu se nepodařilo provést.', 'raynet-lead-api-integration' ),
				'goToPlugins'   => __( 'Přejít na Pluginy', 'raynet-lead-api-integration' ),
			)
		);
	}

	/**
	 * Tests the stored credentials against GET /security/info.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'raynet-lead-api-integration' ) ), 403 );
		}

		check_ajax_referer( 'raynet_lead_test_connection', 'nonce' );

		$client = Raynet_Lead_Api_Client::from_settings();
		$info   = $client->get_account_info();

		if ( is_wp_error( $info ) ) {
			wp_send_json_error( array( 'message' => $info->get_error_message() ) );
		}

		$lists = array();

		foreach ( array(
			'leadCategory/'  => __( 'Kategorie leadu (category)', 'raynet-lead-api-integration' ),
			'leadPhase/'     => __( 'Stav leadu (leadPhase)', 'raynet-lead-api-integration' ),
			'contactSource/' => __( 'Zdroj kontaktu (contactSource)', 'raynet-lead-api-integration' ),
		) as $endpoint => $label ) {
			$rows = $client->get_code_list( $endpoint );

			if ( ! is_wp_error( $rows ) && ! empty( $rows ) ) {
				$lists[ $label ] = $rows;
			}
		}

		delete_option( 'raynet_lead_last_error' );

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: 1: user name, 2: instance name. */
					__( 'Spojení funguje. Přihlášen jako %1$s, instance %2$s.', 'raynet-lead-api-integration' ),
					isset( $info['username'] ) ? $info['username'] : '?',
					isset( $info['X-Instance-Name'] ) ? $info['X-Instance-Name'] : '?'
				),
				'codeLists' => $lists,
			)
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = Raynet_Lead_Settings::all();
		$updates    = ( new Raynet_Lead_Updater() )->status();
		$option     = Raynet_Lead_Settings::OPTION;
		$last_error = get_option( 'raynet_lead_last_error' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RAYNET Lead API Integration', 'raynet-lead-api-integration' ); ?></h1>

			<?php if ( ! Raynet_Lead_Settings::is_configured() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Připojení zatím není kompletní. Vyplňte e-mail, API klíč a název instance.', 'raynet-lead-api-integration' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Poslední chyba API:', 'raynet-lead-api-integration' ); ?></strong>
						<?php echo esc_html( (string) $last_error['message'] ); ?>
						<?php if ( ! empty( $last_error['time'] ) ) : ?>
							<em>(<?php echo esc_html( wp_date( 'j. n. Y H:i', (int) $last_error['time'] ) ); ?>)</em>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: shortcode. */
					esc_html__( 'Formulář vložíte do stránky zkratkou %s.', 'raynet-lead-api-integration' ),
					'<code>[raynet_lead_form]</code>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( Raynet_Lead_Settings::GROUP ); ?>

				<h2><?php esc_html_e( 'Připojení k RAYNET CRM', 'raynet-lead-api-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="raynet-region"><?php esc_html_e( 'Region API', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<select id="raynet-region" name="<?php echo esc_attr( $option ); ?>[region]">
								<?php foreach ( Raynet_Lead_Settings::regions() as $key => $url ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['region'], $key ); ?>>
										<?php echo esc_html( $url ); ?>
									</option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $settings['region'], 'custom' ); ?>>
									<?php esc_html_e( 'Vlastní adresa…', 'raynet-lead-api-integration' ); ?>
								</option>
							</select>
							<p class="description"><?php esc_html_e( 'Vyberte doménu, na které běží vaše instance RAYNET CRM.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-custom-url"><?php esc_html_e( 'Vlastní adresa API', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="raynet-custom-url" name="<?php echo esc_attr( $option ); ?>[custom_api_url]" value="<?php echo esc_attr( $settings['custom_api_url'] ); ?>" placeholder="https://app.raynet.cz/api/v2/" />
							<p class="description"><?php esc_html_e( 'Použije se jen při volbě „Vlastní adresa“. Zadejte základ API včetně /api/v2/.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-username"><?php esc_html_e( 'Uživatelské jméno', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-username" name="<?php echo esc_attr( $option ); ?>[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'E-mail uživatele, na kterého je API klíč vydaný.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-api-key"><?php esc_html_e( 'API klíč', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="raynet-api-key" name="<?php echo esc_attr( $option ); ?>[api_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $settings['api_key'] ? __( 'Uložen — ponechte prázdné pro zachování', 'raynet-lead-api-integration' ) : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Klíč se nikdy nezobrazuje zpět. Prázdné pole znamená „ponechat stávající“.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-instance-name"><?php esc_html_e( 'Název instance', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-instance-name" name="<?php echo esc_attr( $option ); ?>[instance_name]" value="<?php echo esc_attr( $settings['instance_name'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Z adresy https://app.raynet.cz/mujraynet/ je to část „mujraynet“. Posílá se v hlavičce X-Instance-Name.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-instance-id"><?php esc_html_e( 'ID instance', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-instance-id" name="<?php echo esc_attr( $option ); ?>[instance_id]" value="<?php echo esc_attr( $settings['instance_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Volitelné. Má přednost před názvem instance a posílá se jako X-Instance-Id.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-timeout"><?php esc_html_e( 'Timeout (s)', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="number" min="5" max="60" id="raynet-timeout" name="<?php echo esc_attr( $option ); ?>[timeout]" value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test spojení', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<button type="button" class="button" id="raynet-test-connection"><?php esc_html_e( 'Otestovat spojení', 'raynet-lead-api-integration' ); ?></button>
							<div id="raynet-test-result" class="raynet-test-result"></div>
							<p class="description"><?php esc_html_e( 'Test se provádí proti uloženému nastavení — nejdřív změny uložte. Po úspěchu vypíše i ID číselníků.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Výchozí hodnoty leadu', 'raynet-lead-api-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="raynet-priority"><?php esc_html_e( 'Priorita', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<select id="raynet-priority" name="<?php echo esc_attr( $option ); ?>[priority]">
								<?php foreach ( Raynet_Lead_Settings::priorities() as $priority ) : ?>
									<option value="<?php echo esc_attr( $priority ); ?>" <?php selected( $settings['priority'], $priority ); ?>><?php echo esc_html( $priority ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-default-topic"><?php esc_html_e( 'Výchozí předmět', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-default-topic" name="<?php echo esc_attr( $option ); ?>[default_topic]" value="<?php echo esc_attr( $settings['default_topic'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Použije se, když formulář nemá pole „Předmět“ ani atribut topic.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-notice-prefix"><?php esc_html_e( 'Poznámka k leadu', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<textarea id="raynet-notice-prefix" class="large-text" rows="3" name="<?php echo esc_attr( $option ); ?>[notice_prefix]"><?php echo esc_textarea( $settings['notice_prefix'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Text se vloží před zprávu od návštěvníka, např. „Přidáno z webu example.cz“.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fyzická osoba', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[lead_person]" value="1" <?php checked( $settings['lead_person'], 1 ); ?> />
								<?php esc_html_e( 'Zakládat leady jako fyzickou osobu (leadPerson).', 'raynet-lead-api-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Když návštěvník vyplní pole Společnost, lead se založí jako firma bez ohledu na tuto volbu.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<?php
					$numeric_fields = array(
						'category'       => array( __( 'Kategorie (ID)', 'raynet-lead-api-integration' ), __( 'ID z číselníku LeadCategory. 0 = neposílat.', 'raynet-lead-api-integration' ) ),
						'lead_phase'     => array( __( 'Stav leadu (ID)', 'raynet-lead-api-integration' ), __( 'ID z číselníku LeadPhase. 0 = neposílat.', 'raynet-lead-api-integration' ) ),
						'contact_source' => array( __( 'Zdroj kontaktu (ID)', 'raynet-lead-api-integration' ), __( 'ID z číselníku ContactSource. 0 = neposílat.', 'raynet-lead-api-integration' ) ),
						'owner'          => array( __( 'Vlastník (ID)', 'raynet-lead-api-integration' ), __( 'ID kontaktní osoby, která je zároveň uživatelem. 0 = neposílat.', 'raynet-lead-api-integration' ) ),
						'security_level' => array( __( 'Bezpečnostní úroveň (ID)', 'raynet-lead-api-integration' ), __( '0 = použije se výchozí úroveň instance.', 'raynet-lead-api-integration' ) ),
					);

					foreach ( $numeric_fields as $key => $meta ) :
						?>
						<tr>
							<th scope="row"><label for="raynet-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td>
								<input type="number" min="0" id="raynet-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" />
								<p class="description"><?php echo esc_html( $meta[1] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="raynet-tags"><?php esc_html_e( 'Štítky', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-tags" name="<?php echo esc_attr( $option ); ?>[tags]" value="<?php echo esc_attr( $settings['tags'] ); ?>" placeholder="web,poptavka" />
							<p class="description"><?php esc_html_e( 'Seznam štítků oddělených čárkou.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-notify-emails"><?php esc_html_e( 'Notifikace z RAYNETu', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="raynet-notify-emails" name="<?php echo esc_attr( $option ); ?>[notify_emails]" value="<?php echo esc_attr( $settings['notify_emails'] ); ?>" />
							<p class="description"><?php esc_html_e( 'E-maily oddělené čárkou. RAYNET jim pošle upozornění na nový lead.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Chování formuláře', 'raynet-lead-api-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="raynet-success-message"><?php esc_html_e( 'Hláška po odeslání', 'raynet-lead-api-integration' ); ?></label></th>
						<td><input type="text" class="large-text" id="raynet-success-message" name="<?php echo esc_attr( $option ); ?>[success_message]" value="<?php echo esc_attr( $settings['success_message'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-error-message"><?php esc_html_e( 'Hláška při chybě', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="raynet-error-message" name="<?php echo esc_attr( $option ); ?>[error_message]" value="<?php echo esc_attr( $settings['error_message'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Návštěvníkovi se nikdy nezobrazí technický detail chyby — ten jde do logu.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-redirect-url"><?php esc_html_e( 'Přesměrovat po odeslání', 'raynet-lead-api-integration' ); ?></label></th>
						<td><input type="url" class="regular-text" id="raynet-redirect-url" name="<?php echo esc_attr( $option ); ?>[redirect_url]" value="<?php echo esc_attr( $settings['redirect_url'] ); ?>" placeholder="https://" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Souhlas se zpracováním', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the forms list. */
									esc_html__( 'Souhlas se od verze 2.1 nastavuje na každém formuláři zvlášť, jako pole. Najdete ho v %s.', 'raynet-lead-api-integration' ),
									'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Raynet_Lead_Form_Post_Type::POST_TYPE ) ) . '">'
										. esc_html__( 'Formulářích', 'raynet-lead-api-integration' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ochrana proti spamu', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[honeypot_enabled]" value="1" <?php checked( $settings['honeypot_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Skryté pole (honeypot).', 'raynet-lead-api-integration' ); ?>
							</label>
							<p>
								<label for="raynet-min-fill"><?php esc_html_e( 'Minimální doba vyplňování (s):', 'raynet-lead-api-integration' ); ?></label>
								<input type="number" min="0" max="120" id="raynet-min-fill" name="<?php echo esc_attr( $option ); ?>[min_fill_seconds]" value="<?php echo esc_attr( (string) $settings['min_fill_seconds'] ); ?>" />
							</p>
							<p>
								<label for="raynet-throttle"><?php esc_html_e( 'Pauza mezi odesláními z jedné IP (s):', 'raynet-lead-api-integration' ); ?></label>
								<input type="number" min="0" max="3600" id="raynet-throttle" name="<?php echo esc_attr( $option ); ?>[throttle_seconds]" value="<?php echo esc_attr( (string) $settings['throttle_seconds'] ); ?>" />
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="raynet-fallback-email"><?php esc_html_e( 'Záložní e-mail', 'raynet-lead-api-integration' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="raynet-fallback-email" name="<?php echo esc_attr( $option ); ?>[fallback_email]" value="<?php echo esc_attr( $settings['fallback_email'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Když RAYNET lead nepřijme, pošle se obsah formuláře na tuto adresu, aby se poptávka neztratila.', 'raynet-lead-api-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Aktualizace', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[updates_enabled]" value="1" <?php checked( $settings['updates_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Nabízet aktualizace pluginu z GitHubu.', 'raynet-lead-api-integration' ); ?>
							</label>

							<p class="raynet-update-status">
								<?php
								printf(
									/* translators: %s: version number. */
									esc_html__( 'Nainstalovaná verze: %s', 'raynet-lead-api-integration' ),
									'<strong>' . esc_html( $updates['current'] ) . '</strong>'
								);
								?>
								<?php if ( '' !== $updates['latest'] ) : ?>
									&nbsp;&middot;&nbsp;
									<?php
									printf(
										/* translators: %s: version number. */
										esc_html__( 'Poslední vydaná: %s', 'raynet-lead-api-integration' ),
										'<strong>' . esc_html( $updates['latest'] ) . '</strong>'
									);
									?>
								<?php endif; ?>
							</p>

							<p>
								<button type="button" class="button" id="raynet-check-update"><?php esc_html_e( 'Zkontrolovat aktualizace', 'raynet-lead-api-integration' ); ?></button>
								<span id="raynet-update-result" class="raynet-update-result"></span>
							</p>

							<p class="description">
								<?php esc_html_e( 'Aktualizace se stahují z vydaných verzí na GitHubu. Samotnou instalaci spustíte na stránce Pluginy, kde lze zapnout i automatickou aktualizaci.', 'raynet-lead-api-integration' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Údržba', 'raynet-lead-api-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[log_errors]" value="1" <?php checked( $settings['log_errors'], 1 ); ?> />
								<?php esc_html_e( 'Zapisovat chyby API do PHP logu.', 'raynet-lead-api-integration' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'], 1 ); ?> />
								<?php esc_html_e( 'Smazat všechna data pluginu při odinstalaci.', 'raynet-lead-api-integration' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
