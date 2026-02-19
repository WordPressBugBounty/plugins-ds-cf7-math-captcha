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
}
