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
	 * Token time-to-live in seconds.
	 */
	const TOKEN_TTL = 600;

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
	 * Register CF7 tag. (Unchanged — shortcode compatibility preserved.)
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

	/* --------------------------------------------------------------------- *
	 *  SECURITY HELPERS
	 * --------------------------------------------------------------------- */

	/**
	 * Per-site signing key (random secret + auth salt). Never exposed to client.
	 *
	 * @return string
	 */
	private function signing_key() {
		$key = get_option( 'dscf7_signing_key' );

		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			// Autoload "no" — not exposed via front-end option dumps.
			add_option( 'dscf7_signing_key', $key, '', 'no' );
		}

		return $key . wp_salt( 'auth' );
	}

	/**
	 * HMAC signature over salt|expiry|answer. The answer is never sent to the
	 * browser; only this signature travels inside the token.
	 *
	 * @param int|string $answer Correct answer.
	 * @param string     $salt   Per-captcha random salt.
	 * @param int        $expiry Unix expiry timestamp.
	 * @return string
	 */
	private function sign( $answer, $salt, $expiry ) {
		$message = $salt . '|' . (int) $expiry . '|' . (string) (int) $answer;
		return hash_hmac( 'sha256', $message, $this->signing_key() );
	}

	/**
	 * Build a signed, opaque token: salt.expiry.signature
	 *
	 * @param int|string $answer Correct answer.
	 * @param string     $salt   Salt.
	 * @param int        $expiry Expiry.
	 * @return string
	 */
	private function make_token( $answer, $salt, $expiry ) {
		return $salt . '.' . (int) $expiry . '.' . $this->sign( $answer, $salt, $expiry );
	}

	/**
	 * Generate a fresh challenge.
	 *
	 * @return array
	 */
	private function generate_challenge() {
		$ops = array( '+', '-', 'x' );
		$op  = $ops[ wp_rand( 0, 2 ) ];
		$a   = wp_rand( 1, 9 );
		$b   = wp_rand( 1, 9 );

		// Ensure non-negative subtraction so the numeric input filter stays valid.
		if ( '-' === $op && $b > $a ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}

		switch ( $op ) {
			case '+':
				$answer = $a + $b;
				break;
			case 'x':
				$answer = $a * $b;
				break;
			default:
				$answer = $a - $b;
				break;
		}

		$symbol = ( 'x' === $op ) ? '×' : $op;
		$salt   = bin2hex( random_bytes( 8 ) );
		$expiry = time() + self::TOKEN_TTL;
		$token  = $this->make_token( $answer, $salt, $expiry );

		$question = sprintf(
			/* translators: 1: First number, 2: math operator symbol, 3: second number. */
			__( 'What is %1$d %2$s %3$d ?', 'ds-cf7-math-captcha' ),
			$a,
			$symbol,
			$b
		);

		// Only the math expression (numbers, operator and "?") is rendered as SVG.
		// The surrounding "What is" wording stays as normal, translatable text.
		$math = sprintf( '%1$d %2$s %3$d ?', $a, $symbol, $b );

		return array(
			'a'        => $a,
			'b'        => $b,
			'op'       => $op,
			'symbol'   => $symbol,
			'answer'   => $answer,
			'salt'     => $salt,
			'expiry'   => $expiry,
			'token'    => $token,
			'question' => $question,
			'math'     => $math,
		);
	}

	/* --------------------------------------------------------------------- *
	 *  SVG RENDERER
	 * --------------------------------------------------------------------- */

	/**
	 * Render a short text (the math expression) as a readable, lightly-noised
	 * inline SVG. Width is sized to the content so there is no empty space.
	 *
	 * @param string $text Text to draw (e.g. "2 × 6 ?").
	 * @param string $aria Accessible label (defaults to $text).
	 * @return string Safe SVG markup.
	 */
	private function render_svg( $text, $aria = '' ) {
		if ( '' === $aria ) {
			$aria = $text;
		}

		$height = 56;

		// Lay out the glyphs first so we can size the SVG to the content.
		// Each glyph gets its own rotation, baseline jitter and size for stronger
		// distortion while staying easy for humans to read.
		$noise_colors = array( '#9a9a9a', '#b5b5b5', '#a8b4c2', '#c2a8a8' );
		$chars        = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$x            = 10;
		$glyphs       = '';
		foreach ( $chars as $char ) {
			if ( ' ' === $char ) {
				$x += 7;
				continue;
			}
			$cy        = 34 + wp_rand( -6, 6 );
			$angle     = wp_rand( -28, 28 );
			$font_size = wp_rand( 22, 30 );
			$glyphs   .= '<text x="' . $x . '" y="' . $cy . '" ';
			$glyphs   .= 'transform="rotate(' . $angle . ' ' . $x . ' ' . $cy . ')" ';
			$glyphs   .= 'font-family="Arial, Helvetica, sans-serif" font-size="' . $font_size . '" font-weight="700" ';
			$glyphs   .= 'fill="#222">' . esc_html( $char ) . '</text>';
			$x        += 15;
		}

		$width = max( 60, $x + 10 );

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" ';
		$svg .= 'width="' . $width . '" height="' . $height . '" role="img" ';
		$svg .= 'aria-label="' . esc_attr( $aria ) . '" class="dscf7_svg" preserveAspectRatio="xMidYMid meet">';

		// Background.
		$svg .= '<rect width="100%" height="100%" fill="#f7f7f7"/>';

		// Line noise (several thin lines, varied colour and thickness).
		$lines = max( 5, (int) ( $width / 18 ) );
		for ( $i = 0; $i < $lines; $i++ ) {
			$x1     = wp_rand( 0, $width );
			$y1     = wp_rand( 0, $height );
			$x2     = wp_rand( 0, $width );
			$y2     = wp_rand( 0, $height );
			$stroke = $noise_colors[ wp_rand( 0, count( $noise_colors ) - 1 ) ];
			$swidth = wp_rand( 10, 18 ) / 10; // 1.0 - 1.8
			$svg   .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="' . $stroke . '" stroke-width="' . $swidth . '"/>';
		}

		// Dot noise scaled to width, with varied radius and colour.
		$dots = max( 14, (int) ( $width / 5 ) );
		for ( $i = 0; $i < $dots; $i++ ) {
			$cx     = wp_rand( 0, $width );
			$cy     = wp_rand( 0, $height );
			$radius = wp_rand( 5, 16 ) / 10; // 0.5 - 1.6
			$fill   = $noise_colors[ wp_rand( 0, count( $noise_colors ) - 1 ) ];
			$svg   .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" fill="' . $fill . '"/>';
		}

		// Glyphs drawn last so the digits stay legible on top of all the noise.
		$svg .= $glyphs;

		$svg .= '</svg>';

		return $svg;
	}

	/* --------------------------------------------------------------------- *
	 *  SHARED MARKUP (used by handler + AJAX refresh)
	 * --------------------------------------------------------------------- */

	/**
	 * Build the captcha container markup.
	 *
	 * @param string $tag_name     Field name.
	 * @param string $custom_class Extra CSS classes.
	 * @param array  $challenge    Challenge data from generate_challenge().
	 * @return string
	 */
	private function render_container( $tag_name, $custom_class, $challenge ) {
		$base_classes  = 'wpcf7-form-control wpcf7-text';
		$input_classes = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;

		$name       = esc_attr( $tag_name );
		$token      = esc_attr( $challenge['token'] );
		// Only the math expression is drawn as SVG; aria-label uses the math only
		// so the visible "What is" text is not announced twice by screen readers.
		$svg        = $this->render_svg( $challenge['math'] );
		$nonce      = wp_create_nonce( 'ds_cf7_nonce' );
		$answer_lbl = esc_attr__( 'Type the answer to the math question shown above', 'ds-cf7-math-captcha' );

		$icon_url   = esc_url( DSCF7_PLUGIN_URL . 'assets/img/icons8-refresh-30.png' );
		$reload_url = esc_url( DSCF7_PLUGIN_URL . 'assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );

		// Wrap the SVG inside the translatable "What is %s" wording, keeping the
		// label words as normal text and only the math as an image.
		/* translators: %s: the math problem (numbers and operator) shown as an image. */
		$tpl       = __( 'What is %s', 'ds-cf7-math-captcha' );
		$tpl_parts = explode( '%s', $tpl, 2 );
		$question_html  = '' !== $tpl_parts[0] ? '<span class="dscf7_question_text">' . esc_html( $tpl_parts[0] ) . '</span>' : '';
		$question_html .= '<span class="dscf7_svg_wrap">' . $svg . '</span>';
		if ( isset( $tpl_parts[1] ) && '' !== $tpl_parts[1] ) {
			$question_html .= '<span class="dscf7_question_text">' . esc_html( $tpl_parts[1] ) . '</span>';
		}

		$html = '<div class="dscf7-captcha-container" data-name="' . $name . '">';

		// Signed token — the ONLY server-trusted value. No operands, no action, no answer.
		$html .= '<input type="hidden" class="dscf7_token" name="dscf7_token-' . $name . '" value="' . $token . '" />';

		$html .= '<div class="dscf7-question-container">';
		$html .= '<span class="dscf7_lt">';
		$html .= $question_html;
		$html .= '<a href="javascript:void(0)" id="' . $name . '" class="dscf7_refresh_captcha" aria-label="' . esc_attr__( 'Refresh captcha', 'ds-cf7-math-captcha' ) . '">';
		$html .= '<img class="dscf7_captcha_icon" src="' . $icon_url . '" alt="' . esc_attr__( 'Refresh icon', 'ds-cf7-math-captcha' ) . '"/>';
		$html .= '<img class="dscf7_captcha_reload_icon" src="' . $reload_url . '" alt="' . esc_attr__( 'Refreshing captcha', 'ds-cf7-math-captcha' ) . '" style="display:none; width:30px" />';
		$html .= '</a>';
		$html .= '</span>';
		$html .= '</div>';

		$html .= '<div class="dscf7-answer-container">';
		$html .= '<label for="' . $name . '-input" class="screen-reader-text">' . esc_html( $challenge['question'] ) . '</label>';
		$html .= '<span class="wpcf7-form-control-wrap" data-name="' . $name . '">';
		$html .= '<input type="text" id="' . $name . '-input" aria-label="' . $answer_lbl . '" inputmode="numeric" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . $name . '" placeholder="' . esc_attr__( 'Type your answer', 'ds-cf7-math-captcha' ) . '" oninput="this.value = this.value.replace(/[^0-9]/g, \'\');">';
		$html .= '</span>';
		$html .= '<input type="hidden" name="ds_cf7_nonce" value="' . esc_attr( $nonce ) . '">';
		$html .= '</div>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Extract custom class:* options from a CF7 tag.
	 *
	 * @param object $tag CF7 tag.
	 * @return string
	 */
	private function classes_from_tag( $tag ) {
		$custom = array();
		foreach ( (array) $tag->options as $option ) {
			if ( 0 === strpos( $option, 'class:' ) ) {
				$custom[] = esc_attr( substr( $option, 6 ) );
			}
		}
		return implode( ' ', $custom );
	}

	/* --------------------------------------------------------------------- *
	 *  RENDER (CF7 tag handler)
	 * --------------------------------------------------------------------- */

	/**
	 * Render captcha HTML.
	 *
	 * @param object $tag CF7 tag.
	 * @return string
	 */
	public function captcha_handler( $tag ) {
		$this->plugin->load_textdomain();

		$challenge    = $this->generate_challenge();
		$custom_class = $this->classes_from_tag( $tag );

		return $this->render_container( $tag->name, $custom_class, $challenge );
	}

	/* --------------------------------------------------------------------- *
	 *  VALIDATION
	 * --------------------------------------------------------------------- */

	/**
	 * Validate captcha field — server NEVER trusts browser math values.
	 *
	 * @param WPCF7_Validation $result Validation result.
	 * @param object           $tag    CF7 tag.
	 * @return WPCF7_Validation
	 */
	public function captcha_validation( $result, $tag ) {
		$this->plugin->load_textdomain();

		if ( 'dscf7captcha' !== $tag->basetype ) {
			return $result;
		}

		// Note on nonce verification: Contact Form 7 is the form processor for this
		// submission and runs this filter only for a genuine CF7 post. The integrity
		// of the captcha is enforced by the signed HMAC token validated below (it is
		// tamper-proof, time-limited and single-use), so the submitted values are read
		// without an extra nonce gate — a nonce check here would needlessly break
		// Contact Form 7's support for cached pages and varying login states.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$user_answer = isset( $_POST[ $tag->name ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ $tag->name ] ) ) )
			: '';

		$token = isset( $_POST[ 'dscf7_token-' . $tag->name ] )
			? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_token-' . $tag->name ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $user_answer ) {
			$result->invalidate( $tag, esc_html__( 'Please enter captcha.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		list( $salt, $expiry, $sig ) = $parts;
		$salt   = preg_replace( '/[^a-f0-9]/', '', $salt );
		$expiry = (int) $expiry;

		// Expiry check — fail safe on expired captchas.
		if ( $expiry < time() ) {
			$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		// Replay protection — token may only be consumed once.
		$replay_key = 'dscf7_used_' . md5( $token );
		if ( false !== get_transient( $replay_key ) ) {
			$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		// Integrity + correctness: recompute the signature using the USER answer.
		// If it matches the token signature, the answer is correct AND untampered.
		$expected = $this->sign( $user_answer, $salt, $expiry );

		if ( ! hash_equals( $expected, (string) $sig ) ) {
			$result->invalidate( $tag, esc_html__( 'Incorrect captcha!', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		// Mark consumed for the remaining lifetime of the token.
		set_transient( $replay_key, 1, max( 1, $expiry - time() ) );

		return $result;
	}

	/* --------------------------------------------------------------------- *
	 *  ASSETS
	 * --------------------------------------------------------------------- */

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

		wp_register_style( 'dscf7-math-captcha-style', esc_url( DSCF7_PLUGIN_URL . 'assets/css/style.css' ), array(), DSCF7_VERSION, false );
		wp_enqueue_style( 'dscf7-math-captcha-style' );
	}

	/* --------------------------------------------------------------------- *
	 *  AJAX REFRESH
	 * --------------------------------------------------------------------- */

	/**
	 * AJAX callback to refresh captcha. Generates a fresh question, answer,
	 * token, SVG and expiry, then returns the rebuilt container.
	 *
	 * @return void
	 */
	public function refreshcaptcha_callback() {
		$this->plugin->load_textdomain();

		$nonce = isset( $_POST['ds_cf7_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['ds_cf7_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'ds_cf7_nonce' ) ) {
			echo esc_html__( 'Nonce verification failed', 'ds-cf7-math-captcha' );
			exit;
		}

		$tag_name = isset( $_POST['tagname'] )
			? sanitize_text_field( wp_unslash( $_POST['tagname'] ) )
			: '';

		$custom_class = isset( $_POST['custom_class'] )
			? esc_attr( sanitize_text_field( wp_unslash( $_POST['custom_class'] ) ) )
			: '';

		$challenge = $this->generate_challenge();
		$html      = $this->render_container( $tag_name, $custom_class, $challenge );

		$allowed_html = array(
			'div'    => array(
				'class'     => array(),
				'data-name' => array(),
			),
			'span'   => array(
				'class'     => array(),
				'data-name' => array(),
			),
			'a'      => array(
				'href'       => array(),
				'id'         => array(),
				'class'      => array(),
				'aria-label' => array(),
			),
			'label'  => array(
				'for'   => array(),
				'class' => array(),
			),
			'input'  => array(
				'type'        => array(),
				'name'        => array(),
				'id'          => array(),
				'class'       => array(),
				'value'       => array(),
				'size'        => array(),
				'aria-label'  => array(),
				'inputmode'   => array(),
				'placeholder' => array(),
				'oninput'     => array(),
			),
			'img'    => array(
				'class' => array(),
				'src'   => array(),
				'alt'   => array(),
				'style' => array(),
			),
			'svg'    => array(
				'xmlns'               => array(),
				'viewbox'             => array(),
				'width'               => array(),
				'height'              => array(),
				'role'                => array(),
				'aria-label'          => array(),
				'class'               => array(),
				'preserveaspectratio' => array(),
			),
			'rect'   => array(
				'width'  => array(),
				'height' => array(),
				'fill'   => array(),
			),
			'line'   => array(
				'x1'           => array(),
				'y1'           => array(),
				'x2'           => array(),
				'y2'           => array(),
				'stroke'       => array(),
				'stroke-width' => array(),
			),
			'text'   => array(
				'x'           => array(),
				'y'           => array(),
				'transform'   => array(),
				'font-family' => array(),
				'font-size'   => array(),
				'font-weight' => array(),
				'fill'        => array(),
			),
			'circle' => array(
				'cx'   => array(),
				'cy'   => array(),
				'r'    => array(),
				'fill' => array(),
			),
		);

		echo wp_kses( $html, $allowed_html );
		exit;
	}
}
