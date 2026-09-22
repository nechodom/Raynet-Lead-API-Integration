<?php
/**
 * The builder screen: metaboxes, saving and the live preview.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for editing a form.
 */
class Raynet_Lead_Form_Builder_Admin {

	/**
	 * Nonce action guarding the metabox save.
	 */
	const NONCE_ACTION = 'raynet_form_save';

	/**
	 * Nonce action guarding the preview endpoint.
	 */
	const PREVIEW_NONCE = 'raynet_form_preview';

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Raynet_Lead_Form_Post_Type::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_raynet_form_preview', array( $this, 'handle_preview' ) );

		add_filter( 'manage_' . Raynet_Lead_Form_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Raynet_Lead_Form_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	/**
	 * Registers the metaboxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'raynet-form-builder',
			__( 'Pole formuláře', 'raynet-lead-api-integration' ),
			array( $this, 'render_builder' ),
			Raynet_Lead_Form_Post_Type::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'raynet-form-embed',
			__( 'Vložení do stránky', 'raynet-lead-api-integration' ),
			array( $this, 'render_embed' ),
			Raynet_Lead_Form_Post_Type::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'raynet-form-lead',
			__( 'Nastavení leadu', 'raynet-lead-api-integration' ),
			array( $this, 'render_lead' ),
			Raynet_Lead_Form_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Loads the builder assets on the form edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || Raynet_Lead_Form_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'raynet-form-builder',
			RAYNET_LEAD_URL . 'assets/css/raynet-form-builder.css',
			array(),
			RAYNET_LEAD_VERSION
		);

		wp_enqueue_script(
			'raynet-form-builder',
			RAYNET_LEAD_URL . 'assets/js/raynet-form-builder.js',
			array(),
			RAYNET_LEAD_VERSION,
			true
		);

		$sources = array();

		foreach ( Raynet_Lead_Form_Definition::catalogue() as $source => $meta ) {
			$sources[] = array(
				'source' => $source,
				'label'  => $meta['label'],
				'type'   => Raynet_Lead_Form_Definition::type_for_source( $source ),
			);
		}

		wp_localize_script(
			'raynet-form-builder',
			'raynetFormBuilder',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( self::PREVIEW_NONCE ),
				'sources'      => $sources,
				'customTypes'  => array(
					array( 'value' => 'text', 'label' => __( 'Jednořádkový text', 'raynet-lead-api-integration' ) ),
					array( 'value' => 'textarea', 'label' => __( 'Víceřádkový text', 'raynet-lead-api-integration' ) ),
					array( 'value' => 'select', 'label' => __( 'Výběr z možností', 'raynet-lead-api-integration' ) ),
					array( 'value' => 'checkbox', 'label' => __( 'Zaškrtávátko', 'raynet-lead-api-integration' ) ),
				),
				'i18n'         => array(
					'customOption'  => __( '— Vlastní pole —', 'raynet-lead-api-integration' ),
					'addField'      => __( 'Přidat', 'raynet-lead-api-integration' ),
					'label'         => __( 'Popisek', 'raynet-lead-api-integration' ),
					'placeholder'   => __( 'Placeholder', 'raynet-lead-api-integration' ),
					'help'          => __( 'Nápověda pod polem', 'raynet-lead-api-integration' ),
					'width'         => __( 'Šířka', 'raynet-lead-api-integration' ),
					'widthFull'     => __( 'Celá šířka', 'raynet-lead-api-integration' ),
					'widthHalf'     => __( 'Poloviční', 'raynet-lead-api-integration' ),
					'required'      => __( 'Povinné pole', 'raynet-lead-api-integration' ),
					'remove'        => __( 'Odebrat pole', 'raynet-lead-api-integration' ),
					'moveUp'        => __( 'Posunout nahoru', 'raynet-lead-api-integration' ),
					'moveDown'      => __( 'Posunout dolů', 'raynet-lead-api-integration' ),
					'type'          => __( 'Typ pole', 'raynet-lead-api-integration' ),
					'options'       => __( 'Možnosti, jedna na řádek', 'raynet-lead-api-integration' ),
					'customLabel'   => __( 'Vlastní pole', 'raynet-lead-api-integration' ),
					'consentNote'   => __( 'Text u zaškrtávátka. Odkazy jsou povolené. Datum souhlasu se zapíše do poznámky leadu.', 'raynet-lead-api-integration' ),
					'customNote'    => __( 'Hodnota se zapíše do poznámky leadu pod tímto popiskem.', 'raynet-lead-api-integration' ),
					/* translators: %s: name of the RAYNET lead attribute the field is mapped to. */
					'mappedNote'    => __( 'Jde do RAYNETu jako %s.', 'raynet-lead-api-integration' ),
					'empty'         => __( 'Formulář zatím nemá žádné pole. Přidejte první níže.', 'raynet-lead-api-integration' ),
					'previewFailed' => __( 'Náhled se nepodařilo načíst. Editace polí funguje dál.', 'raynet-lead-api-integration' ),
					'noContact'     => __( 'Formulář nemá e-mail ani telefon. RAYNET takový lead nepřijme.', 'raynet-lead-api-integration' ),
					'dragHint'      => __( 'Přetažením změníte pořadí', 'raynet-lead-api-integration' ),
				),
			)
		);
	}

	/**
	 * Renders the field list and the preview.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public function render_builder( $post ) {
		$fields = Raynet_Lead_Form_Post_Type::get_fields( $post->ID );

		if ( empty( $fields ) && 'auto-draft' === $post->post_status ) {
			$fields = Raynet_Lead_Form_Definition::default_fields();
		}

		wp_nonce_field( self::NONCE_ACTION, 'raynet_form_nonce' );
		?>
		<input
			type="hidden"
			id="raynet-form-fields-json"
			name="raynet_form_fields_json"
			value="<?php echo esc_attr( (string) wp_json_encode( $fields ) ); ?>"
		/>

		<div class="raynet-builder">
			<div class="raynet-builder__main">
				<p class="raynet-builder__hint"><?php esc_html_e( 'Přetažením změníte pořadí. Kliknutím na pole ho rozbalíte.', 'raynet-lead-api-integration' ); ?></p>
				<div class="raynet-builder__list" id="raynet-builder-list"></div>

				<div class="raynet-builder__add">
					<label for="raynet-builder-add"><?php esc_html_e( 'Přidat pole:', 'raynet-lead-api-integration' ); ?></label>
					<select id="raynet-builder-add"></select>
					<button type="button" class="button" id="raynet-builder-add-button"><?php esc_html_e( 'Přidat', 'raynet-lead-api-integration' ); ?></button>
				</div>
			</div>

			<div class="raynet-builder__aside">
				<h3 class="raynet-builder__aside-title"><?php esc_html_e( 'Náhled', 'raynet-lead-api-integration' ); ?></h3>
				<p class="raynet-builder__aside-note"><?php esc_html_e( 'Vykresluje server stejným kódem jako ostrý formulář.', 'raynet-lead-api-integration' ); ?></p>
				<div class="raynet-builder__preview" id="raynet-builder-preview"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the shortcode box.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public function render_embed( $post ) {
		$slug      = $post->post_name ? $post->post_name : (string) $post->ID;
		$shortcode = '[raynet_lead_form id="' . $slug . '"]';
		$is_default = Raynet_Lead_Form_Post_Type::default_id() === (int) $post->ID;
		?>
		<p><code class="raynet-builder__shortcode"><?php echo esc_html( $shortcode ); ?></code></p>
		<p class="description"><?php esc_html_e( 'Zkratku vložte do stránky nebo příspěvku.', 'raynet-lead-api-integration' ); ?></p>

		<p>
			<label>
				<input type="checkbox" name="raynet_form_is_default" value="1" <?php checked( $is_default ); ?> />
				<?php esc_html_e( 'Výchozí formulář', 'raynet-lead-api-integration' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Výchozí formulář obslouží zkratku [raynet_lead_form] bez atributu id.', 'raynet-lead-api-integration' ); ?></p>
		<?php
	}

	/**
	 * Renders the per-form lead settings.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public function render_lead( $post ) {
		$lead = Raynet_Lead_Form_Post_Type::get_lead_settings( $post->ID );
		$name = 'raynet_form_lead';

		$inherit = __( 'Zdědit z nastavení', 'raynet-lead-api-integration' );
		?>
		<p>
			<label for="raynet-lead-topic"><strong><?php esc_html_e( 'Předmět leadu', 'raynet-lead-api-integration' ); ?></strong></label>
			<input type="text" class="widefat" id="raynet-lead-topic" name="<?php echo esc_attr( $name ); ?>[topic]" value="<?php echo esc_attr( $lead['topic'] ); ?>" />
		</p>

		<p>
			<label for="raynet-lead-priority"><strong><?php esc_html_e( 'Priorita', 'raynet-lead-api-integration' ); ?></strong></label>
			<select class="widefat" id="raynet-lead-priority" name="<?php echo esc_attr( $name ); ?>[priority]">
				<option value=""><?php echo esc_html( $inherit ); ?></option>
				<?php
				$priorities = array(
					'MINOR'    => __( 'Nízká', 'raynet-lead-api-integration' ),
					'DEFAULT'  => __( 'Běžná', 'raynet-lead-api-integration' ),
					'CRITICAL' => __( 'Kritická', 'raynet-lead-api-integration' ),
				);

				foreach ( $priorities as $value => $text ) :
					?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $lead['priority'], $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="raynet-lead-person"><strong><?php esc_html_e( 'Typ leadu', 'raynet-lead-api-integration' ); ?></strong></label>
			<select class="widefat" id="raynet-lead-person" name="<?php echo esc_attr( $name ); ?>[lead_person]">
				<option value=""><?php echo esc_html( $inherit ); ?></option>
				<option value="1" <?php selected( $lead['lead_person'], '1' ); ?>><?php esc_html_e( 'Fyzická osoba', 'raynet-lead-api-integration' ); ?></option>
				<option value="0" <?php selected( $lead['lead_person'], '0' ); ?>><?php esc_html_e( 'Firma', 'raynet-lead-api-integration' ); ?></option>
			</select>
		</p>

		<p>
			<label for="raynet-lead-notice"><strong><?php esc_html_e( 'Předpona poznámky', 'raynet-lead-api-integration' ); ?></strong></label>
			<textarea class="widefat" rows="2" id="raynet-lead-notice" name="<?php echo esc_attr( $name ); ?>[notice_prefix]"><?php echo esc_textarea( $lead['notice_prefix'] ); ?></textarea>
		</p>

		<hr />
		<p class="description"><?php esc_html_e( 'Číselníky RAYNET. Nula znamená zdědit z nastavení pluginu.', 'raynet-lead-api-integration' ); ?></p>

		<?php
		$numeric = array(
			'category'       => __( 'Kategorie (ID)', 'raynet-lead-api-integration' ),
			'lead_phase'     => __( 'Stav leadu (ID)', 'raynet-lead-api-integration' ),
			'contact_source' => __( 'Zdroj kontaktu (ID)', 'raynet-lead-api-integration' ),
			'owner'          => __( 'Vlastník (ID)', 'raynet-lead-api-integration' ),
			'security_level' => __( 'Bezpečnostní úroveň (ID)', 'raynet-lead-api-integration' ),
		);

		foreach ( $numeric as $key => $text ) :
			?>
			<p>
				<label for="raynet-lead-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $text ); ?></label>
				<input type="number" min="0" class="widefat" id="raynet-lead-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $lead[ $key ] ); ?>" />
			</p>
		<?php endforeach; ?>

		<p>
			<label for="raynet-lead-tags"><?php esc_html_e( 'Štítky', 'raynet-lead-api-integration' ); ?></label>
			<input type="text" class="widefat" id="raynet-lead-tags" name="<?php echo esc_attr( $name ); ?>[tags]" value="<?php echo esc_attr( $lead['tags'] ); ?>" />
		</p>

		<p>
			<label for="raynet-lead-notify"><?php esc_html_e( 'Notifikační e-maily', 'raynet-lead-api-integration' ); ?></label>
			<input type="text" class="widefat" id="raynet-lead-notify" name="<?php echo esc_attr( $name ); ?>[notify_emails]" value="<?php echo esc_attr( $lead['notify_emails'] ); ?>" />
		</p>

		<hr />

		<p>
			<label for="raynet-lead-success"><?php esc_html_e( 'Hláška po odeslání', 'raynet-lead-api-integration' ); ?></label>
			<input type="text" class="widefat" id="raynet-lead-success" name="<?php echo esc_attr( $name ); ?>[success_message]" value="<?php echo esc_attr( $lead['success_message'] ); ?>" />
		</p>

		<p>
			<label for="raynet-lead-redirect"><?php esc_html_e( 'Přesměrovat po odeslání', 'raynet-lead-api-integration' ); ?></label>
			<input type="url" class="widefat" id="raynet-lead-redirect" name="<?php echo esc_attr( $name ); ?>[redirect_url]" value="<?php echo esc_attr( $lead['redirect_url'] ); ?>" placeholder="https://" />
		</p>
		<?php
	}

	/**
	 * Stores the builder's state.
	 *
	 * @param int     $post_id Form id.
	 * @param WP_Post $post    Form.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			// An autosave posts no metabox fields, and saving them would wipe the form.
			return;
		}

		$nonce = isset( $_POST['raynet_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['raynet_form_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['raynet_form_fields_json'] ) ) {
			$json   = (string) wp_unslash( $_POST['raynet_form_fields_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the definition class.
			$decoded = json_decode( $json, true );

			Raynet_Lead_Form_Post_Type::save_fields( $post_id, is_array( $decoded ) ? $decoded : array() );
		}

		if ( isset( $_POST['raynet_form_lead'] ) && is_array( $_POST['raynet_form_lead'] ) ) {
			Raynet_Lead_Form_Post_Type::save_lead_settings(
				$post_id,
				wp_unslash( $_POST['raynet_form_lead'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the definition class.
			);
		}

		$is_default = ! empty( $_POST['raynet_form_is_default'] );

		if ( $is_default ) {
			Raynet_Lead_Form_Post_Type::set_default( $post_id );
		} elseif ( Raynet_Lead_Form_Post_Type::default_id() === (int) $post_id ) {
			Raynet_Lead_Form_Post_Type::set_default( 0 );
		}
	}

	/**
	 * Renders the preview for the builder.
	 *
	 * @return void
	 */
	public function handle_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'raynet-lead-api-integration' ) ), 403 );
		}

		check_ajax_referer( self::PREVIEW_NONCE, 'nonce' );

		$json    = isset( $_POST['fields'] ) ? (string) wp_unslash( $_POST['fields'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the definition class.
		$decoded = json_decode( $json, true );
		$fields  = Raynet_Lead_Form_Definition::sanitize_fields( is_array( $decoded ) ? $decoded : array() );

		// The preview carries no nonce, timestamp or honeypot, and its controls
		// are disabled: it is a picture of the form, not a working one. It sits
		// inside the post edit form, where a required field would block Update
		// and a named one would be saved with the post.
		wp_send_json_success(
			array( 'html' => Raynet_Lead_Form_Renderer::render_fields( $fields, 'raynet-preview', true ) )
		);
	}

	/**
	 * Adds the shortcode and field count to the forms list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string> Columns.
	 */
	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['raynet_shortcode'] = __( 'Zkratka', 'raynet-lead-api-integration' );
				$new['raynet_fields']    = __( 'Polí', 'raynet-lead-api-integration' );
			}
		}

		return $new;
	}

	/**
	 * Fills the custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Form id.
	 * @return void
	 */
	public function column( $column, $post_id ) {
		if ( 'raynet_shortcode' === $column ) {
			$post = get_post( $post_id );
			$slug = $post && $post->post_name ? $post->post_name : (string) $post_id;

			echo '<code>[raynet_lead_form id="' . esc_html( $slug ) . '"]</code>';

			if ( Raynet_Lead_Form_Post_Type::default_id() === (int) $post_id ) {
				echo ' <span class="raynet-builder__badge">' . esc_html__( 'Výchozí', 'raynet-lead-api-integration' ) . '</span>';
			}
		}

		if ( 'raynet_fields' === $column ) {
			echo (int) count( Raynet_Lead_Form_Post_Type::get_fields( $post_id ) );
		}
	}
}
