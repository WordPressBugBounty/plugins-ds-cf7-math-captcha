<?php
/**
 * Admin class.
 *
 * @package DS_CF7_Math_Captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSCF7_Math_Captcha_Admin {

	/**
	 * Main plugin class reference.
	 *
	 * @var DSCF7_Math_Captcha
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param DSCF7_Math_Captcha $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', 'dscf7_deactivate_on_cf7_deactivation' );
		add_filter( 'wpcf7_messages', 'dscf7_wpcf7_messages_callback' );
		add_action( 'admin_enqueue_scripts', 'dscf7_admin_style' );
		add_action( 'wpcf7_admin_init', 'dscf7_wpcf7_add_tag_generator_dsmathcaptcha', 65, 0 );

		// DS Math Captcha settings page (under the Contact menu).
		add_action( 'admin_menu', 'dscf7_admin_menu', 99 );
		add_action( 'admin_init', 'dscf7_register_settings' );
		add_action( 'admin_enqueue_scripts', 'dscf7_settings_assets' );
	}

	/**
	 * Deactivate plugin if CF7 missing.
	 *
	 * @return void
	 */
	public function deactivate_on_cf7_deactivation() {
		if ( ! class_exists( 'WPCF7' ) ) {
			add_action( 'admin_notices', 'dscf7_plugin_contact_form7_notice' );

			if ( is_plugin_active( 'ds-cf7-math-captcha/ds-cf7-math-captcha.php' ) ) {
				if ( isset( $_GET['activate'] ) ) {
					if (
						! isset( $_REQUEST['_wpnonce'] ) ||
						! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'activate-plugin_' . plugin_basename( DSCF7_PLUGIN ) )
					) {
						wp_die( esc_html__( 'Security check failed.', 'ds-cf7-math-captcha' ) );
					}
				}

				deactivate_plugins( plugin_basename( DSCF7_PLUGIN ) );

				if ( isset( $_GET['activate'] ) && current_user_can( 'activate_plugins' ) ) {
					unset( $_GET['activate'] );
				}
			}
		}
	}

	/**
	 * Show admin notice.
	 *
	 * @return void
	 */
	public function plugin_contact_form7_notice() {
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php echo esc_html( __( 'Warning: DS CF7 Math Captcha plugin requires Contact Form 7 to be installed and active.', 'ds-cf7-math-captcha' ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Override CF7 messages.
	 *
	 * @param array $messages Messages array.
	 * @return array
	 */
	public function wpcf7_messages_callback( $messages ) {
		$this->plugin->load_textdomain();

		$current_form = WPCF7_ContactForm::get_current();

		if ( $current_form ) {
			$form_id = $current_form->id();
			$custom_value = get_post_meta( $form_id, '_messages', true );
			$translated_incorrect = esc_html__( 'Incorrect captcha!', 'ds-cf7-math-captcha' );
			$translated_required = esc_html__( 'Please enter captcha.', 'ds-cf7-math-captcha' );
			$default_incorrect = 'Incorrect captcha!';
			$default_required = 'Please enter captcha.';

			$dynami_err_message = isset( $custom_value['invalid_letters_digits'] ) ? trim( (string) $custom_value['invalid_letters_digits'] ) : '';
			$please_enter_capthca = isset( $custom_value['invalid_letters'] ) ? trim( (string) $custom_value['invalid_letters'] ) : '';

			if ( '' === $dynami_err_message || $default_incorrect === $dynami_err_message ) {
				$dynami_err_message = $translated_incorrect;
			} else {
				$dynami_err_message = esc_html( $dynami_err_message );
			}

			if ( '' === $please_enter_capthca || $default_required === $please_enter_capthca ) {
				$please_enter_capthca = $translated_required;
			} else {
				$please_enter_capthca = esc_html( $please_enter_capthca );
			}

			if ( ! empty( $dynami_err_message ) ) {
				$messages['invalid_letters_digits'] = array(
					'description' => $translated_incorrect,
					'default'     => $dynami_err_message,
				);
			}
			if ( ! empty( $please_enter_capthca ) ) {
				$messages['invalid_letters'] = array(
					'description' => $translated_required,
					'default'     => $please_enter_capthca,
				);
			}

			// Message shown when the signed captcha token is missing or expired.
			$messages['dscf7_captcha_expired'] = array(
				'description' => esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ),
				'default'     => esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ),
			);
		}

		return $messages;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @return void
	 */
	public function admin_style() {
		wp_enqueue_style( 'dscf7-admin-style', esc_url( DSCF7_PLUGIN_URL . 'assets/css/admin-style.css' ), array(), '1.0.0', 'all' );
		wp_enqueue_script( 'dscf7_refresh_script', esc_url( DSCF7_PLUGIN_URL . 'assets/js/script-min.js' ), array( 'jquery' ), DSCF7_VERSION, true );

		wp_localize_script(
			'dscf7_refresh_script',
			'ajax_object',
			array(
				'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ),
				'nonce'    => esc_attr( wp_create_nonce( 'ds_cf7_nonce' ) ),
			)
		);
	}

	/**
	 * Register tag generator.
	 *
	 * @return void
	 */
	public function add_tag_generator_dsmathcaptcha() {
		$tag_generator = WPCF7_TagGenerator::get_instance();
		$tag_generator->add(
			'dscf7captcha',
			esc_html( __( 'Math Captcha', 'ds-cf7-math-captcha' ) ),
			array( $this, 'tag_generator_dsmathcaptcha' ),
			array( 'version' => '2' )
		);
	}

	/**
	 * Tag generator UI.
	 *
	 * @param object $contact_form Contact form object.
	 * @param array  $args         Arguments.
	 * @return void
	 */
	public function tag_generator_dsmathcaptcha( $contact_form, $args = '' ) {
		$args = wp_parse_args( $args, array() );
		$type = isset( $args['id'] ) ? $args['id'] : '';
		$tag_name = isset( $args['content'] ) ? (string) $args['content'] : 'dscf7captcha';
		$tag_name = preg_replace( '/^tag-generator-panel-/', '', $tag_name );
		$tag_name = sanitize_key( $tag_name );
		if ( empty( $tag_name ) ) {
			$tag_name = 'dscf7captcha';
		}

		if ( 'dscf7captcha' == $type ) {
			$description = esc_html( __( 'Copy the given shortcode in the form.', 'ds-cf7-math-captcha' ) );
		}
		?>
		<div class="control-box">
			<fieldset>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $args['content'] . '-name' ); ?>"><?php echo esc_html( __( 'Name', 'ds-cf7-math-captcha' ) ); ?></label>
							</th>
							<td>
								<input type="text" name="name" class="tg-name oneline" readonly="readonly" id="<?php echo esc_attr( $args['content'] . '-name' ); ?>" value="<?php echo esc_attr( $tag_name ); ?>"/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>"><?php echo esc_html( __( 'Class', 'ds-cf7-math-captcha' ) ); ?></label>
							</th>
							<td>
								<input type="text" name="classname" class="class oneline" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>" value=""/>
								<p class="description"><?php echo esc_html( $description ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>
		<div class="insert-box">
			<input type="text" name="<?php echo esc_attr( $type ); ?>" class="tag code" readonly="readonly" onfocus="this.select()" value="[dscf7captcha* <?php echo esc_attr( $tag_name ); ?>]" />
			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'ds-cf7-math-captcha' ) ); ?>" />
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 *  SETTINGS PAGE ("DS Math Captcha" under the Contact menu)
	 * --------------------------------------------------------------------- */

	/**
	 * Option name used for all plugin settings (single row, no custom tables).
	 */
	const OPTION_NAME = 'dscf7_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'dscf7-math-captcha';

	/**
	 * Hook suffix returned by add_submenu_page (used to scope asset loading).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function settings_defaults() {
		return array(
			'style'        => 'classic',
			'level'        => 'medium',
			'border_color' => '#2271b1',
		);
	}

	/**
	 * Read merged settings (saved values over defaults). Used by the frontend
	 * and the settings UI.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::settings_defaults() );
	}

	/**
	 * Register the setting with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'dscf7_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::settings_defaults(),
			)
		);
	}

	/**
	 * Whitelist-sanitize submitted settings; unknown values fall back to
	 * defaults so nothing arbitrary is ever stored.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$out    = self::settings_defaults();
		$styles = array( 'classic', 'dark', 'bordered' );
		$levels = array( 'easy', 'medium', 'hard' );

		if ( is_array( $input ) ) {
			if ( isset( $input['style'] ) && in_array( $input['style'], $styles, true ) ) {
				$out['style'] = $input['style'];
			}
			if ( isset( $input['level'] ) && in_array( $input['level'], $levels, true ) ) {
				$out['level'] = $input['level'];
			}
			if ( isset( $input['border_color'] ) ) {
				$color = sanitize_hex_color( $input['border_color'] );
				if ( ! empty( $color ) ) {
					$out['border_color'] = $color;
				}
			}
		}

		return $out;
	}

	/**
	 * Add the settings subpage under the Contact (CF7) menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		$this->page_hook = add_submenu_page(
			'wpcf7',
			esc_html__( 'DS Math Captcha', 'ds-cf7-math-captcha' ),
			esc_html__( 'DS Math Captcha', 'ds-cf7-math-captcha' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue settings-page assets (only on our page) plus the frontend style so
	 * the live preview matches the real captcha.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function settings_assets( $hook ) {
		if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'dscf7-math-captcha-style', esc_url( DSCF7_PLUGIN_URL . 'assets/css/style.css' ), array(), DSCF7_VERSION, false );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'dscf7-admin-settings', esc_url( DSCF7_PLUGIN_URL . 'assets/css/admin-settings.css' ), array( 'dscf7-math-captcha-style' ), DSCF7_VERSION, false );
		wp_enqueue_script( 'dscf7-admin-settings', esc_url( DSCF7_PLUGIN_URL . 'assets/js/admin-settings.js' ), array( 'jquery', 'wp-color-picker' ), DSCF7_VERSION, true );
	}

	/**
	 * Render the settings page (style theme + difficulty level + live preview).
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->plugin->load_textdomain();
		$settings = self::get_settings();

		$styles = array(
			'classic'  => array( esc_html__( 'Classic', 'ds-cf7-math-captcha' ), 'dscf7-swatch-classic' ),
			'dark'     => array( esc_html__( 'Dark', 'ds-cf7-math-captcha' ), 'dscf7-swatch-dark' ),
			'bordered' => array( esc_html__( 'Bordered', 'ds-cf7-math-captcha' ), 'dscf7-swatch-bordered' ),
		);

		$levels = array(
			'easy'   => array( esc_html__( 'Easy', 'ds-cf7-math-captcha' ), esc_html__( 'Addition & subtraction, numbers 1–5.', 'ds-cf7-math-captcha' ) ),
			'medium' => array( esc_html__( 'Medium', 'ds-cf7-math-captcha' ), esc_html__( 'Addition, subtraction & multiplication, numbers 1–9.', 'ds-cf7-math-captcha' ) ),
			'hard'   => array( esc_html__( 'Hard', 'ds-cf7-math-captcha' ), esc_html__( 'Larger numbers (up to 50) and multiplication tables.', 'ds-cf7-math-captcha' ) ),
		);
		?>
		<div class="wrap dscf7-settings-wrap">
			<h1><?php echo esc_html__( 'DS Math Captcha', 'ds-cf7-math-captcha' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Choose how the math captcha looks and how difficult the questions are. Changes apply to every Contact Form 7 form that uses the captcha.', 'ds-cf7-math-captcha' ); ?></p>

			<form action="options.php" method="post">
				<?php settings_fields( 'dscf7_settings_group' ); ?>

				<div class="dscf7-settings-grid">
					<div class="dscf7-settings-col">

						<div class="dscf7-field">
							<h2><?php echo esc_html__( 'Captcha style', 'ds-cf7-math-captcha' ); ?></h2>
							<p class="description"><?php echo esc_html__( 'Pick a visual theme. The preview updates instantly.', 'ds-cf7-math-captcha' ); ?></p>
							<div class="dscf7-style-cards">
								<?php foreach ( $styles as $key => $meta ) : ?>
									<label class="dscf7-style-card<?php echo ( $settings['style'] === $key ) ? ' selected' : ''; ?>" data-style="<?php echo esc_attr( $key ); ?>">
										<input type="radio" name="dscf7_settings[style]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['style'], $key ); ?> />
										<span class="dscf7-style-swatch <?php echo esc_attr( $meta[1] ); ?>"></span>
										<span><?php echo esc_html( $meta[0] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>

							<div class="dscf7-color-row"<?php echo ( 'bordered' === $settings['style'] ) ? '' : ' style="display:none"'; ?>>
								<label for="dscf7-border-color"><?php echo esc_html__( 'Border color', 'ds-cf7-math-captcha' ); ?></label>
								<input type="text" id="dscf7-border-color" class="dscf7-color-field" name="dscf7_settings[border_color]" value="<?php echo esc_attr( $settings['border_color'] ); ?>" data-default-color="#2271b1" />
							</div>
						</div>

						<div class="dscf7-field">
							<h2><?php echo esc_html__( 'Difficulty level', 'ds-cf7-math-captcha' ); ?></h2>
							<p class="description"><?php echo esc_html__( 'Controls the numbers and operations used in the questions.', 'ds-cf7-math-captcha' ); ?></p>
							<div class="dscf7-level-options">
								<?php foreach ( $levels as $key => $meta ) : ?>
									<label>
										<input type="radio" name="dscf7_settings[level]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['level'], $key ); ?> />
										<strong><?php echo esc_html( $meta[0] ); ?></strong>
										<span class="dscf7-level-hint">— <?php echo esc_html( $meta[1] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<?php submit_button(); ?>
					</div>

					<aside class="dscf7-settings-aside">
						<div class="dscf7-preview-box">
							<h2><?php echo esc_html__( 'Live preview', 'ds-cf7-math-captcha' ); ?></h2>
							<?php
							// Build a sample captcha using the same markup/classes as the
							// real one, then escape it with the shared captcha allowlist.
							$svg     = dscf7_math_captcha()->frontend->sample_svg( '6 + 3 ?' );
							$preview = '<div class="dscf7-captcha-container dscf7-style-' . esc_attr( $settings['style'] ) . '" id="dscf7-preview-captcha">';
							$preview .= '<div class="dscf7-question-container"><span class="dscf7_lt">';
							$preview .= '<span class="dscf7_question_text">' . esc_html__( 'What is', 'ds-cf7-math-captcha' ) . '</span>';
							$preview .= '<span class="dscf7_svg_wrap">' . $svg . '</span>';
							$preview .= '</span></div>';
							$preview .= '<div class="dscf7-answer-container"><span class="wpcf7-form-control-wrap">';
							$preview .= '<input type="text" class="wpcf7-form-control wpcf7-text dscf7_answer" placeholder="' . esc_attr__( 'Type your answer', 'ds-cf7-math-captcha' ) . '" disabled="disabled" />';
							$preview .= '</span></div></div>';

							echo wp_kses( $preview, dscf7_math_captcha()->frontend->allowed_captcha_html() );
							?>
							<p class="dscf7-preview-note"><?php echo esc_html__( 'Preview is for appearance only and is not interactive.', 'ds-cf7-math-captcha' ); ?></p>
						</div>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}
}
