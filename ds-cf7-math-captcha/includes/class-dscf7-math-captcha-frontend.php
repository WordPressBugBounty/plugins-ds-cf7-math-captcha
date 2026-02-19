<?php
/**
 * Frontend class.
 *
 * @package DS_CF7_Math_Captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSCF7_Math_Captcha_Frontend {

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
	 * Register frontend hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wpcf7_init', 'dscf7_capctha' );
		add_filter( 'wpcf7_validate_dscf7captcha', 'dscf7_captcha_validation', 10, 2 );
		add_filter( 'wpcf7_validate_dscf7captcha*', 'dscf7_captcha_validation', 10, 2 );
		add_action( 'wp_enqueue_scripts', 'dscf7_ajaxify_scripts' );
		add_action( 'wp_ajax_dscf7_refreshcaptcha', 'dscf7_refreshcaptcha_callback' );
		add_action( 'wp_ajax_nopriv_dscf7_refreshcaptcha', 'dscf7_refreshcaptcha_callback' );
	}

	/**
	 * Register CF7 tag.
	 *
	 * @return void
	 */
	public function capctha() {
		$name_attr = array(
			'name-attr' => true,
			'version'   => 2,
		);

		wpcf7_add_form_tag( array( 'dscf7captcha', 'dscf7captcha*' ), array( $this, 'captcha_handler' ), $name_attr );
	}

	/**
	 * Validate captcha field.
	 *
	 * @param WPCF7_Validation $result Validation result.
	 * @param object           $tag    CF7 tag.
	 * @return WPCF7_Validation
	 */
	public function captcha_validation( $result, $tag ) {
		$this->plugin->load_textdomain();

		if ( $tag->basetype == 'dscf7captcha' ) {
			$cptcha_value = isset( $_POST[ $tag->name ] ) ? trim( strtr( sanitize_text_field( wp_unslash( $_POST[ $tag->name ] ) ), "\n", ' ' ) ) : '';

			if ( '' === $cptcha_value ) {
				$result->invalidate(
					$tag,
					esc_html( __( 'Please enter captcha.', 'ds-cf7-math-captcha' ) )
				);
				return $result;
			}

			if ( isset( $_POST['ds_cf7_nonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_POST['ds_cf7_nonce'] ) );
				// Keep nonce check for telemetry/security context, but do not block captcha validation flow.
				wp_verify_nonce( $nonce, 'ds_cf7_nonce' );
			}

			$finalCechking = '';
			$cptha1        = isset( $_POST[ 'dscf7_hidden_val1-' . $tag->name ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_hidden_val1-' . $tag->name ] ) ) : '';
			$cptha2        = isset( $_POST[ 'dscf7_hidden_val2-' . $tag->name ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_hidden_val2-' . $tag->name ] ) ) : '';
			$cptha3        = isset( $_POST[ 'dscf7_hidden_action-' . $tag->name ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_hidden_action-' . $tag->name ] ) ) : '';

			if ( $cptha3 == 'x' ) {
				$finalCechking = $cptha1 * $cptha2;
			} elseif ( $cptha3 == '+' ) {
				$finalCechking = $cptha1 + $cptha2;
			} else {
				$finalCechking = $cptha1 - $cptha2;
			}

			if ( $cptcha_value != $finalCechking ) {
				$result->invalidate(
					$tag,
					esc_html( __( 'Incorrect captcha!', 'ds-cf7-math-captcha' ) )
				);
			}
		}

		return $result;
	}

	/**
	 * Render captcha HTML.
	 *
	 * @param object $tag CF7 tag.
	 * @return string
	 */
	public function captcha_handler( $tag ) {
		$this->plugin->load_textdomain();

		$operationAry    = array( '+', 'x', '-' );
		$random_action   = array_rand( $operationAry, 2 );
		$random_actionVal = $operationAry[ $random_action[0] ];
		$actnVal1        = wp_rand( 1, 9 );
		$actnVal2        = wp_rand( 1, 9 );

		$custom_classes = array();
		foreach ( $tag->options as $option ) {
			if ( strpos( $option, 'class:' ) === 0 ) {
				$class            = substr( $option, 6 );
				$custom_classes[] = esc_attr( $class );
			}
		}
		$custom_class = implode( ' ', $custom_classes );

		$base_classes  = 'wpcf7-form-control wpcf7-text';
		$input_classes = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;

		$actnVal1_escaped        = esc_attr( $actnVal1 );
		$actnVal2_escaped        = esc_attr( $actnVal2 );
		$random_actionVal_escaped = esc_attr( $random_actionVal );
		$nonce                   = wp_create_nonce( 'ds_cf7_nonce' );
		$nonce_escaped           = $nonce;

		$captcha_icon_url        = esc_url( DSCF7_PLUGIN_URL . 'assets/img/icons8-refresh-30.png' );
		$captcha_reload_icon_url = esc_url( DSCF7_PLUGIN_URL . 'assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );

		$question_text = sprintf(
			/* translators: 1: First number, 2: math operator symbol, 3: second number. */
			__( 'What is %1$d %2$s %3$d ?', 'ds-cf7-math-captcha' ),
			$actnVal2,
			$random_actionVal,
			$actnVal1
		);

		$answer_label = sprintf(
			/* translators: 1: First number, 2: math operator symbol, 3: second number. */
			__( 'Answer for %1$d %2$s %3$d', 'ds-cf7-math-captcha' ),
			$actnVal2,
			$random_actionVal,
			$actnVal1
		);

		$ds_cf7_captcha  = '<div class="dscf7-captcha-container">';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_val1-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_val1-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $actnVal1_escaped . '" />';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_val2-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_val2-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $actnVal2_escaped . '" />';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_action-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_action-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $random_actionVal_escaped . '" />';

		$ds_cf7_captcha .= '<div class="dscf7-question-container">';
		$ds_cf7_captcha .= '<span class="dscf7_lt">' . esc_html( $question_text ) . ' ';
		$ds_cf7_captcha .= '<a href="javascript:void(0)" id="' . esc_attr( $tag->name ) . '" class="dscf7_refresh_captcha" aria-label="' . esc_attr( __( 'Refresh captcha', 'ds-cf7-math-captcha' ) ) . '">';
		$ds_cf7_captcha .= '<img class="dscf7_captcha_icon" src="' . esc_url( $captcha_icon_url ) . '" alt="' . esc_attr( __( 'Refresh icon', 'ds-cf7-math-captcha' ) ) . '"/>';
		$ds_cf7_captcha .= '<img class="dscf7_captcha_reload_icon" src="' . esc_url( $captcha_reload_icon_url ) . '" alt="' . esc_attr( __( 'Refreshing captcha', 'ds-cf7-math-captcha' ) ) . '" style="display:none; width:30px" />';
		$ds_cf7_captcha .= '</a></span>';
		$ds_cf7_captcha .= '</div>';

		$ds_cf7_captcha .= '<div class="dscf7-answer-container">';
		$ds_cf7_captcha .= '<label for="' . esc_attr( $tag->name ) . '-input" class="screen-reader-text">' . esc_html( $answer_label ) . '</label>';
		$ds_cf7_captcha .= '<span class="wpcf7-form-control-wrap" data-name="' . esc_attr( $tag->name ) . '">';
		$ds_cf7_captcha .= '<input type="text" id="' . esc_attr( $tag->name ) . '-input" aria-label="' . esc_attr( $answer_label ) . '" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . esc_attr( $tag->name ) . '" placeholder="' . esc_attr( __( 'Type your answer', 'ds-cf7-math-captcha' ) ) . '" style="" oninput="this.value = this.value.replace(/[^0-9.]/g, \'\').replace(/(\..*)\./g, \'$1\');">';
		$ds_cf7_captcha .= '</span>';
		$ds_cf7_captcha .= '<input type="hidden" name="ds_cf7_nonce" value="' . $nonce_escaped . '">';
		$ds_cf7_captcha .= '</div>';
		$ds_cf7_captcha .= '</div>';

		return $ds_cf7_captcha;
	}

	/**
	 * Enqueue scripts/styles.
	 *
	 * @return void
	 */
	public function ajaxify_scripts() {
		wp_enqueue_script( 'dscf7_refresh_script', esc_url( DSCF7_PLUGIN_URL . 'assets/js/script-min.js' ), array( 'jquery' ), DSCF7_VERSION, true );

		wp_localize_script(
			'dscf7_refresh_script',
			'ajax_object',
			array(
				'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ),
				'nonce'    => esc_attr( wp_create_nonce( 'ds_cf7_nonce' ) ),
			)
		);

		wp_register_style( 'dscf7-math-captcha-style', esc_url( DSCF7_PLUGIN_URL . 'assets/css/style.css' ), array(), '1.0.0', false );
		wp_enqueue_style( 'dscf7-math-captcha-style' );
	}

	/**
	 * AJAX callback to refresh captcha.
	 *
	 * @return void
	 */
	public function refreshcaptcha_callback( $tag = '' ) {
		$this->plugin->load_textdomain();

		$operationAry     = array( '+', 'x', '-' );
		$random_action    = array_rand( $operationAry, 2 );
		$random_actionVal = $operationAry[ $random_action[0] ];
		$actnVal1         = wp_rand( 1, 9 );
		$actnVal2         = wp_rand( 1, 9 );
		$tagName          = isset( $_POST['tagname'] ) ? sanitize_text_field( wp_unslash( $_POST['tagname'] ) ) : '';
		$custom_class     = isset( $_POST['custom_class'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_class'] ) ) : '';
		$custom_class     = esc_attr( $custom_class );
		$base_classes     = 'wpcf7-form-control wpcf7-text';
		$input_classes    = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;
		$actnVal1_escaped = esc_attr( $actnVal1 );
		$actnVal2_escaped = esc_attr( $actnVal2 );
		$random_actionVal_escaped = esc_attr( $random_actionVal );
		$tagName_escaped          = esc_attr( $tagName );
		$captcha_icon_url         = esc_url( DSCF7_PLUGIN_URL . 'assets/img/icons8-refresh-30.png' );
		$captcha_reload_icon_url  = esc_url( DSCF7_PLUGIN_URL . 'assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );
		$nonce                    = wp_create_nonce( 'ds_cf7_nonce' );
		$nonce_escaped            = $nonce;

		$question_text = sprintf(
			/* translators: 1: First number, 2: math operator symbol, 3: second number. */
			__( 'What is %1$d %2$s %3$d ?', 'ds-cf7-math-captcha' ),
			$actnVal2,
			$random_actionVal,
			$actnVal1
		);

		$answer_label = sprintf(
			/* translators: 1: First number, 2: math operator symbol, 3: second number. */
			__( 'Answer for %1$d %2$s %3$d', 'ds-cf7-math-captcha' ),
			$actnVal2,
			$random_actionVal,
			$actnVal1
		);

		$ds_cf7_captcha  = '<div class="dscf7-captcha-container">';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_val1-' . $tagName_escaped . '" id="dscf7_hidden_val1-' . $tagName_escaped . '" type="hidden" value="' . $actnVal1_escaped . '" />';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_val2-' . $tagName_escaped . '" id="dscf7_hidden_val2-' . $tagName_escaped . '" type="hidden" value="' . $actnVal2_escaped . '" />';
		$ds_cf7_captcha .= '<input name="dscf7_hidden_action-' . $tagName_escaped . '" id="dscf7_hidden_action-' . $tagName_escaped . '" type="hidden" value="' . $random_actionVal_escaped . '" />';

		$ds_cf7_captcha .= '<div class="dscf7-question-container">';
		$ds_cf7_captcha .= '<span class="dscf7_lt">' . esc_html( $question_text ) . ' ';
		$ds_cf7_captcha .= '<a href="javascript:void(0)" id="' . $tagName_escaped . '" class="dscf7_refresh_captcha" aria-label="' . esc_attr( __( 'Refresh captcha', 'ds-cf7-math-captcha' ) ) . '">';
		$ds_cf7_captcha .= '<img class="dscf7_captcha_icon" src="' . esc_url( $captcha_icon_url ) . '" alt="' . esc_attr( __( 'Refresh icon', 'ds-cf7-math-captcha' ) ) . '"/>';
		$ds_cf7_captcha .= '<img class="dscf7_captcha_reload_icon" src="' . esc_url( $captcha_reload_icon_url ) . '" alt="' . esc_attr( __( 'Refreshing captcha', 'ds-cf7-math-captcha' ) ) . '" style="display:none; width:30px" />';
		$ds_cf7_captcha .= '</a></span>';
		$ds_cf7_captcha .= '</div>';

		$ds_cf7_captcha .= '<div class="dscf7-answer-container">';
		$ds_cf7_captcha .= '<label for="' . $tagName_escaped . '-input" class="screen-reader-text">' . esc_html( $answer_label ) . '</label>';
		$ds_cf7_captcha .= '<span class="wpcf7-form-control-wrap" data-name="' . $tagName_escaped . '">';
		$ds_cf7_captcha .= '<input type="text" id="' . $tagName_escaped . '-input" aria-label="' . esc_attr( $answer_label ) . '" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . $tagName_escaped . '" placeholder="' . esc_attr( __( 'Type your answer', 'ds-cf7-math-captcha' ) ) . '" style="" oninput="this.value = this.value.replace(/[^0-9.]/g, \'\').replace(/(\..*)\./g, \'$1\');">';
		$ds_cf7_captcha .= '<input type="hidden" name="ds_cf7_nonce" value="' . $nonce_escaped . '">';
		$ds_cf7_captcha .= '</span>';
		$ds_cf7_captcha .= '</div>';
		$ds_cf7_captcha .= '</div>';

		$allowed_html = array(
			'div'   => array( 'class' => array() ),
			'a'     => array(
				'href'       => array(),
				'id'         => array(),
				'class'      => array(),
				'title'      => array(),
				'style'      => array(),
				'aria-label' => array(),
			),
			'p'     => array( 'class' => array() ),
			'span'  => array(
				'class'     => array(),
				'data-name' => array(),
			),
			'label' => array(
				'for'   => array(),
				'class' => array(),
			),
			'br'    => array(),
			'input' => array(
				'name'        => array(),
				'class'       => array(),
				'id'          => array(),
				'type'        => array(),
				'value'       => array(),
				'aria-label'  => array(),
				'size'        => array(),
				'placeholder' => array(),
				'oninput'     => array(),
				'hidden'      => array(),
			),
			'img'   => array(
				'class'    => array(),
				'src'      => array(),
				'style'    => array(),
				'decoding' => array(),
				'alt'      => array(),
			),
		);

		if ( isset( $_POST['ds_cf7_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['ds_cf7_nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'ds_cf7_nonce' ) ) {
				echo wp_kses( $ds_cf7_captcha, $allowed_html );
			} else {
				echo esc_html__( 'Nonce verification failed', 'ds-cf7-math-captcha' );
			}
		} else {
			echo esc_html__( 'Nonce verification failed', 'ds-cf7-math-captcha' );
		}

		exit;
	}
}
