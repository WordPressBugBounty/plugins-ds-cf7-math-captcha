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
	 * HMAC signature over salt|expiry|fieldid|answer. The answer is never sent
	 * to the browser; only this signature travels inside the token. Binding the
	 * randomized field id into the signature means the answer field name cannot
	 * be swapped or hard-coded by a bot.
	 *
	 * @param int|string $answer  Correct answer.
	 * @param string     $salt    Per-captcha random salt.
	 * @param int        $expiry  Unix expiry timestamp.
	 * @param string     $fieldid Randomized answer field id.
	 * @return string
	 */
	private function sign( $answer, $salt, $expiry, $fieldid ) {
		$message = $salt . '|' . (int) $expiry . '|' . (string) $fieldid . '|' . (string) (int) $answer;
		return hash_hmac( 'sha256', $message, $this->signing_key() );
	}

	/**
	 * Build a signed, opaque token: salt.expiry.fieldid.signature
	 *
	 * @param int|string $answer  Correct answer.
	 * @param string     $salt    Salt.
	 * @param int        $expiry  Expiry.
	 * @param string     $fieldid Randomized answer field id.
	 * @return string
	 */
	private function make_token( $answer, $salt, $expiry, $fieldid ) {
		return $salt . '.' . (int) $expiry . '.' . $fieldid . '.' . $this->sign( $answer, $salt, $expiry, $fieldid );
	}

	/**
	 * Enabled challenge type keys, filterable. Only known built-in keys are
	 * honoured so the filter can never inject unsafe generators.
	 *
	 * @return array
	 */
	private function challenge_types( $level = 'medium' ) {
		// All implemented types. The word-problem types are kept available but
		// are OFF by default: they expose the operation as plain text and only
		// the number as an image, so by default the captcha uses pure arithmetic
		// where the whole expression is rendered as an unreadable image.
		$known = array( 'add', 'sub', 'mul', 'double', 'half', 'next', 'prev' );

		// Default pool depends on the configured difficulty level: Easy keeps to
		// addition/subtraction, Medium/Hard add multiplication.
		$default = ( 'easy' === $level )
			? array( 'add', 'sub' )
			: array( 'add', 'sub', 'mul' );

		/**
		 * Filter the enabled captcha challenge types. Defaults follow the
		 * configured difficulty level. Word-problem types (double, half, next,
		 * prev) can be opted into by returning them here.
		 *
		 * @param array  $types Array of type keys.
		 * @param string $level Configured difficulty level.
		 */
		$types = (array) apply_filters( 'dscf7_challenge_types', $default, $level );
		$types = array_values( array_intersect( $known, $types ) );

		if ( empty( $types ) ) {
			$types = $default;
		}

		return $types;
	}

	/**
	 * Operand ranges for a difficulty level.
	 *
	 * @param string $level easy | medium | hard.
	 * @return array { min, max (add/sub), mmin, mmax (multiply) }
	 */
	private function level_ranges( $level ) {
		switch ( $level ) {
			case 'easy':
				return array(
					'min'  => 1,
					'max'  => 5,
					'mmin' => 1,
					'mmax' => 5,
				);
			case 'hard':
				return array(
					'min'  => 10,
					'max'  => 50,
					'mmin' => 2,
					'mmax' => 12,
				);
			case 'medium':
			default:
				return array(
					'min'  => 1,
					'max'  => 9,
					'mmin' => 1,
					'mmax' => 9,
				);
		}
	}

	/**
	 * Build a single challenge of a given type. Each returns a numeric answer,
	 * the string drawn as the OCR-protected SVG, and a translatable visible
	 * template containing a single %s placeholder for that SVG.
	 *
	 * @param string $type  Challenge type key.
	 * @param string $level Difficulty level (easy|medium|hard).
	 * @return array { answer, svg, template }
	 */
	private function build_challenge( $type, $level = 'medium' ) {
		$r = $this->level_ranges( $level );

		switch ( $type ) {
			case 'sub':
				$a = wp_rand( $r['min'], $r['max'] );
				$b = wp_rand( $r['min'], $r['max'] );
				if ( $b > $a ) {
					$tmp = $a;
					$a   = $b;
					$b   = $tmp;
				}
				return array(
					'answer'   => $a - $b,
					'svg'      => sprintf( '%1$d - %2$d ?', $a, $b ),
					/* translators: %s: a math expression shown as an image. */
					'template' => __( 'What is %s', 'ds-cf7-math-captcha' ),
				);

			case 'mul':
				$a = wp_rand( $r['mmin'], $r['mmax'] );
				$b = wp_rand( $r['mmin'], $r['mmax'] );
				return array(
					'answer'   => $a * $b,
					'svg'      => sprintf( '%1$d × %2$d ?', $a, $b ),
					/* translators: %s: a math expression shown as an image. */
					'template' => __( 'What is %s', 'ds-cf7-math-captcha' ),
				);

			case 'double':
				$n = wp_rand( 1, 20 );
				return array(
					'answer'   => $n * 2,
					'svg'      => (string) $n,
					/* translators: %s: a number shown as an image. */
					'template' => __( 'What is double of %s ?', 'ds-cf7-math-captcha' ),
				);

			case 'half':
				$n = wp_rand( 1, 10 ) * 2; // Always even so the answer is a whole number.
				return array(
					'answer'   => $n / 2,
					'svg'      => (string) $n,
					/* translators: %s: a number shown as an image. */
					'template' => __( 'What is half of %s ?', 'ds-cf7-math-captcha' ),
				);

			case 'next':
				$n = wp_rand( 1, 98 );
				return array(
					'answer'   => $n + 1,
					'svg'      => (string) $n,
					/* translators: %s: a number shown as an image. */
					'template' => __( 'What is the number after %s ?', 'ds-cf7-math-captcha' ),
				);

			case 'prev':
				$n = wp_rand( 2, 99 );
				return array(
					'answer'   => $n - 1,
					'svg'      => (string) $n,
					/* translators: %s: a number shown as an image. */
					'template' => __( 'What is the number before %s ?', 'ds-cf7-math-captcha' ),
				);

			case 'add':
			default:
				$a = wp_rand( $r['min'], $r['max'] );
				$b = wp_rand( $r['min'], $r['max'] );
				return array(
					'answer'   => $a + $b,
					'svg'      => sprintf( '%1$d + %2$d ?', $a, $b ),
					/* translators: %s: a math expression shown as an image. */
					'template' => __( 'What is %s', 'ds-cf7-math-captcha' ),
				);
		}
	}

	/**
	 * Generate a fresh challenge (random type, salt, expiry, randomized field
	 * id and signed token).
	 *
	 * @return array
	 */
	private function generate_challenge() {
		$settings = DSCF7_Math_Captcha_Admin::get_settings();
		$level    = $settings['level'];

		$types = $this->challenge_types( $level );
		$type  = $types[ wp_rand( 0, count( $types ) - 1 ) ];

		$parts  = $this->build_challenge( $type, $level );
		$answer = (int) $parts['answer'];
		$math   = (string) $parts['svg'];

		$salt    = bin2hex( random_bytes( 8 ) );
		$fieldid = 'dscf7c_' . bin2hex( random_bytes( 4 ) );
		$expiry  = time() + self::TOKEN_TTL;
		$token   = $this->make_token( $answer, $salt, $expiry, $fieldid );

		// Accessible question text with the real number(s) (announced to screen
		// readers); the visible challenge shows the number(s) as an SVG image.
		$question = sprintf( $parts['template'], $math );

		return array(
			'answer'   => $answer,
			'salt'     => $salt,
			'fieldid'  => $fieldid,
			'expiry'   => $expiry,
			'token'    => $token,
			'template' => $parts['template'],
			'question' => $question,
			'math'     => $math,
		);
	}

	/* --------------------------------------------------------------------- *
	 *  SVG RENDERER
	 * --------------------------------------------------------------------- */

	/**
	 * Vector path data ("d" attribute) for a single glyph, drawn in a local
	 * ~26×44 box. Digits use a seven-segment layout; operators are simple
	 * strokes. Each call jitters the coordinates a little so the same digit
	 * never produces an identical path string (defeating exact-match lookups).
	 *
	 * No glyph is ever emitted as readable <text>; only geometry is produced,
	 * so the answer cannot be scraped from the SVG DOM.
	 *
	 * @param string $char Single character.
	 * @return string SVG path data, or '' for an unsupported glyph.
	 */
	private function glyph_path( $char ) {
		// Seven-segment endpoints: a top, b top-right, c bottom-right, d bottom,
		// e bottom-left, f top-left, g middle.
		$x0 = 4;
		$x1 = 22;
		$y0 = 3;
		$ym = 22;
		$y1 = 41;

		$seg = array(
			'a' => array( $x0, $y0, $x1, $y0 ),
			'b' => array( $x1, $y0, $x1, $ym ),
			'c' => array( $x1, $ym, $x1, $y1 ),
			'd' => array( $x0, $y1, $x1, $y1 ),
			'e' => array( $x0, $ym, $x0, $y1 ),
			'f' => array( $x0, $y0, $x0, $ym ),
			'g' => array( $x0, $ym, $x1, $ym ),
		);

		$digits = array(
			'0' => 'abcdef',
			'1' => 'bc',
			'2' => 'abged',
			'3' => 'abgcd',
			'4' => 'fgbc',
			'5' => 'afgcd',
			'6' => 'afgecd',
			'7' => 'abc',
			'8' => 'abcdefg',
			'9' => 'abcdfg',
		);

		if ( isset( $digits[ $char ] ) ) {
			$d     = '';
			$parts = str_split( $digits[ $char ] );
			foreach ( $parts as $s ) {
				list( $ax, $ay, $bx, $by ) = $seg[ $s ];
				$d .= 'M' . ( $ax + wp_rand( -1, 1 ) ) . ',' . ( $ay + wp_rand( -1, 1 ) );
				$d .= ' L' . ( $bx + wp_rand( -1, 1 ) ) . ',' . ( $by + wp_rand( -1, 1 ) ) . ' ';
			}
			return trim( $d );
		}

		switch ( $char ) {
			case '+':
				return 'M13,8 L13,36 M3,22 L23,22';
			case '-':
				return 'M3,22 L23,22';
			case '×':
				return 'M4,6 L22,38 M22,6 L4,38';
			case '?':
				return 'M4,12 Q13,2 22,11 Q22,19 13,23 L13,30 M13,37 L13,39';
			default:
				return '';
		}
	}

	/**
	 * Render the challenge as an inline SVG built entirely from <use> elements
	 * that reference jittered vector-path <defs> with randomized ids. There are
	 * no <text> nodes, so the characters cannot be read from the DOM; a reader
	 * must visually interpret the rendered, distorted, noised shapes. Each glyph
	 * is independently translated, rotated, skewed, scaled and re-coloured.
	 *
	 * @param string $text Text to draw (e.g. "2 × 6 ?").
	 * @param string $aria Accessible label (defaults to $text).
	 * @return string Safe SVG markup.
	 */
	private function render_svg( $text, $aria = '' ) {
		if ( '' === $aria ) {
			$aria = $text;
		}

		$height       = 56;
		$noise_colors = array( '#9a9a9a', '#b5b5b5', '#a8b4c2', '#c2a8a8' );
		$glyph_colors = array( '#1d1d1d', '#222222', '#2b2b2b', '#1a2433', '#2a1a1a' );
		$chars        = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		$defs = '';
		$uses = '';
		$x    = 12;

		foreach ( $chars as $char ) {
			if ( ' ' === $char ) {
				$x += wp_rand( 10, 16 );
				continue;
			}

			$d = $this->glyph_path( $char );
			if ( '' === $d ) {
				$x += wp_rand( 14, 20 );
				continue;
			}

			// Random def id so the <use> reference reveals nothing about the glyph.
			$gid   = 'g' . bin2hex( random_bytes( 4 ) );
			$defs .= '<path id="' . $gid . '" d="' . $d . '"/>';

			$yoff   = 6 + wp_rand( -5, 5 );
			$angle  = wp_rand( -22, 22 );
			$skew   = wp_rand( -10, 10 );
			$scale  = wp_rand( 85, 110 ) / 100;
			$stroke = $glyph_colors[ wp_rand( 0, count( $glyph_colors ) - 1 ) ];
			$swidth = wp_rand( 4, 6 );

			// Position, then rotate/skew/scale about the local glyph centre.
			$transform = 'translate(' . $x . ',' . $yoff . ') rotate(' . $angle . ' 13 22) skewX(' . $skew . ') scale(' . $scale . ')';

			$uses .= '<use href="#' . $gid . '" xlink:href="#' . $gid . '" transform="' . $transform . '" ';
			$uses .= 'fill="none" stroke="' . $stroke . '" stroke-width="' . $swidth . '" stroke-linecap="round" stroke-linejoin="round"/>';

			// Variable advance so character pitch is not constant.
			$x += wp_rand( 24, 30 );
		}

		$width = max( 60, $x + 12 );

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ';
		$svg .= 'viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" role="img" ';
		$svg .= 'aria-label="' . esc_attr( $aria ) . '" class="dscf7_svg" preserveAspectRatio="xMidYMid meet">';

		$svg .= '<defs>' . $defs . '</defs>';

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

		// Glyph <use> references drawn last so they stay legible over the noise.
		$svg .= $uses;

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
		// The stable "dscf7_answer" class lets the JS locate the answer input
		// regardless of its randomized name attribute.
		$base_classes  = 'wpcf7-form-control wpcf7-text dscf7_answer';
		$input_classes = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;

		$name       = esc_attr( $tag_name );
		$fieldid    = esc_attr( $challenge['fieldid'] );
		$token      = esc_attr( $challenge['token'] );

		/**
		 * Whether to expose the actual challenge (its numbers/operation) to
		 * assistive technology. Default false (secure): the answer is NEVER put
		 * in the DOM as text — neither the SVG aria-label nor the screen-reader
		 * label contains it — so a bot cannot scrape it. Set true to restore a
		 * fully screen-reader-solvable challenge (the question text returns to
		 * the DOM, at the cost of being machine-readable).
		 *
		 * @param bool $accessible Default false.
		 */
		$accessible = (bool) apply_filters( 'dscf7_accessible_challenge', false );

		// The glyphs always render the real number(s); only the TEXT alternative
		// is generic unless accessible mode is explicitly enabled.
		$svg_aria   = $accessible ? $challenge['question'] : __( 'Math captcha image', 'ds-cf7-math-captcha' );
		$sr_label   = $accessible ? $challenge['question'] : __( 'Solve the math problem shown in the image to continue.', 'ds-cf7-math-captcha' );
		$svg        = $this->render_svg( $challenge['math'], $svg_aria );
		$nonce      = wp_create_nonce( 'ds_cf7_nonce' );

		// Visual style theme selected on the DS Math Captcha settings page.
		$settings   = DSCF7_Math_Captcha_Admin::get_settings();
		$style_class = 'dscf7-style-' . $settings['style'];
		$answer_lbl = esc_attr__( 'Type the answer to the math question shown above', 'ds-cf7-math-captcha' );

		$icon_url   = esc_url( DSCF7_PLUGIN_URL . 'assets/img/icons8-refresh-30.png' );
		$reload_url = esc_url( DSCF7_PLUGIN_URL . 'assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );

		// Wrap the SVG inside the challenge's translatable wording (e.g.
		// "What is %s" or "What is double of %s ?"), keeping the words as normal
		// text and only the number(s) as an image.
		$tpl       = $challenge['template'];
		$tpl_parts = explode( '%s', $tpl, 2 );
		$question_html  = '' !== $tpl_parts[0] ? '<span class="dscf7_question_text">' . esc_html( $tpl_parts[0] ) . '</span>' : '';
		$question_html .= '<span class="dscf7_svg_wrap">' . $svg . '</span>';
		if ( isset( $tpl_parts[1] ) && '' !== $tpl_parts[1] ) {
			$question_html .= '<span class="dscf7_question_text">' . esc_html( $tpl_parts[1] ) . '</span>';
		}

		$html = '<div class="dscf7-captcha-container ' . esc_attr( $style_class ) . '" data-name="' . $name . '">';

		// Signed token — the ONLY server-trusted value. No operands, no action,
		// no answer. The randomized field id is bound into its signature.
		$html .= '<input type="hidden" class="dscf7_token" name="dscf7_token-' . $name . '" value="' . $token . '" />';

		// Human-interaction flag. JS flips it to "1" on the first mouse/touch/
		// key event. Enforcement is opt-in via the dscf7_require_interaction
		// filter so JS-disabled and assistive-technology users are never blocked
		// by default.
		$html .= '<input type="hidden" class="dscf7_interaction" name="dscf7_interaction-' . $name . '" value="0" />';

		// Honeypot — hidden off-screen via CSS (NOT display:none so bots still
		// see it), removed from the tab order and the accessibility tree. A
		// human never fills it; any value flags the submission as spam.
		$hp_name  = esc_attr( DSCF7_Math_Captcha_Security::honeypot_name( $tag_name ) );
		$hp_label = esc_html__( 'Leave this field empty', 'ds-cf7-math-captcha' );
		$html .= '<div class="dscf7-hp-wrap" aria-hidden="true">';
		$html .= '<label for="' . $hp_name . '">' . $hp_label . '</label>';
		$html .= '<input type="text" id="' . $hp_name . '" name="' . $hp_name . '" tabindex="-1" autocomplete="off" value="" />';
		$html .= '</div>';

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
		// The visible answer field uses the randomized field id for both its id
		// and name; the wrapper keeps data-name set to the stable CF7 tag name
		// so Contact Form 7 attaches any validation tip to the right control.
		$html .= '<label for="' . $fieldid . '" class="screen-reader-text">' . esc_html( $sr_label ) . '</label>';
		$html .= '<span class="wpcf7-form-control-wrap" data-name="' . $name . '">';
		$html .= '<input type="text" id="' . $fieldid . '" aria-label="' . $answer_lbl . '" inputmode="numeric" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . $fieldid . '" placeholder="' . esc_attr__( 'Type your answer', 'ds-cf7-math-captcha' ) . '" oninput="this.value = this.value.replace(/[^0-9]/g, \'\');">';
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

		// Count every submission that reaches our captcha toward the per-IP
		// rate limit. Honeypot, lockout and rate-limit hits are flagged as spam
		// through Contact Form 7's native spam mechanism (see the security
		// class); this validation filter only counts attempts and enforces the
		// answer, timing and replay rules.
		$this->plugin->security->note_attempt();

		// Human-interaction enforcement is opt-in. When enabled, require the
		// JS-set interaction flag; JS-disabled and assistive-technology users
		// are never blocked by default. A generic message hides the check.
		if ( apply_filters( 'dscf7_require_interaction', false ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$interaction = isset( $_POST[ 'dscf7_interaction-' . $tag->name ] )
				? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_interaction-' . $tag->name ] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( '1' !== $interaction ) {
				$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
				return $result;
			}
		}

		// Note on nonce verification: Contact Form 7 is the form processor for this
		// submission and runs this filter only for a genuine CF7 post. The integrity
		// of the captcha is enforced by the signed HMAC token validated below (it is
		// tamper-proof, time-limited and single-use), so the submitted values are read
		// without an extra nonce gate — a nonce check here would needlessly break
		// Contact Form 7's support for cached pages and varying login states.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$token = isset( $_POST[ 'dscf7_token-' . $tag->name ] )
			? sanitize_text_field( wp_unslash( $_POST[ 'dscf7_token-' . $tag->name ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$parts = explode( '.', $token );

		// Token format: salt.expiry.fieldid.signature (four parts).
		if ( 4 !== count( $parts ) ) {
			$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		list( $salt, $expiry, $fieldid, $sig ) = $parts;
		$salt    = preg_replace( '/[^a-f0-9]/', '', $salt );
		$fieldid = preg_replace( '/[^a-z0-9_]/', '', $fieldid );
		$expiry  = (int) $expiry;

		// The answer is posted under the randomized field id carried in (and
		// signed by) the token, not under the CF7 tag name.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$user_answer = ( '' !== $fieldid && isset( $_POST[ $fieldid ] ) )
			? trim( sanitize_text_field( wp_unslash( $_POST[ $fieldid ] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $user_answer ) {
			$result->invalidate( $tag, esc_html__( 'Please enter captcha.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		// Expiry check — fail safe on expired captchas.
		if ( $expiry < time() ) {
			$result->invalidate( $tag, esc_html__( 'Captcha expired. Please refresh and try again.', 'ds-cf7-math-captcha' ) );
			return $result;
		}

		// Minimum completion time — reject submissions finished implausibly
		// fast. The render time is derived from the HMAC-signed expiry, so it
		// cannot be forged client-side. A generic message keeps the timing
		// check hidden from bots.
		if ( $this->plugin->security->too_fast( $expiry, self::TOKEN_TTL ) ) {
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
		$expected = $this->sign( $user_answer, $salt, $expiry, $fieldid );

		if ( ! hash_equals( $expected, (string) $sig ) ) {
			// Record the failure; repeated failures trigger a per-IP lockout
			// enforced through CF7's spam mechanism.
			$this->plugin->security->note_failure();
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

		// Apply the chosen custom border colour for the "Bordered" style. Added
		// as one inline rule (sanitized hex) so no per-element style attribute or
		// extra wp_kses handling is needed.
		$settings = DSCF7_Math_Captcha_Admin::get_settings();
		$color    = sanitize_hex_color( $settings['border_color'] );
		if ( $color ) {
			wp_add_inline_style(
				'dscf7-math-captcha-style',
				'.dscf7-captcha-container.dscf7-style-bordered{border-color:' . $color . '}'
			);
		}
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

		echo wp_kses( $html, $this->allowed_captcha_html() );
		exit;
	}

	/**
	 * Allowed HTML/SVG used by wp_kses when echoing captcha markup (AJAX refresh
	 * and the admin preview). Shared so both paths stay in sync.
	 *
	 * @return array
	 */
	public function allowed_captcha_html() {
		return array(
			'div'    => array(
				'class'       => array(),
				'id'          => array(),
				'data-name'   => array(),
				'aria-hidden' => array(),
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
				'type'         => array(),
				'name'         => array(),
				'id'           => array(),
				'class'        => array(),
				'value'        => array(),
				'size'         => array(),
				'aria-label'   => array(),
				'inputmode'    => array(),
				'placeholder'  => array(),
				'oninput'      => array(),
				'tabindex'     => array(),
				'autocomplete' => array(),
				'disabled'     => array(),
			),
			'img'    => array(
				'class' => array(),
				'src'   => array(),
				'alt'   => array(),
				'style' => array(),
			),
			'svg'    => array(
				'xmlns'               => array(),
				'xmlns:xlink'         => array(),
				'viewbox'             => array(),
				'width'               => array(),
				'height'              => array(),
				'role'                => array(),
				'aria-label'          => array(),
				'class'               => array(),
				'preserveaspectratio' => array(),
			),
			'defs'   => array(),
			'path'   => array(
				'id'              => array(),
				'd'               => array(),
				'fill'            => array(),
				'stroke'          => array(),
				'stroke-width'    => array(),
				'stroke-linecap'  => array(),
				'stroke-linejoin' => array(),
				'transform'       => array(),
			),
			'use'    => array(
				'href'            => array(),
				'xlink:href'      => array(),
				'transform'       => array(),
				'fill'            => array(),
				'stroke'          => array(),
				'stroke-width'    => array(),
				'stroke-linecap'  => array(),
				'stroke-linejoin' => array(),
				'x'               => array(),
				'y'               => array(),
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
			'circle' => array(
				'cx'   => array(),
				'cy'   => array(),
				'r'    => array(),
				'fill' => array(),
			),
		);
	}

	/**
	 * Render a standalone sample SVG (used by the admin settings preview).
	 *
	 * @param string $text Expression to draw.
	 * @return string
	 */
	public function sample_svg( $text ) {
		return $this->render_svg( $text, esc_attr__( 'Math captcha image', 'ds-cf7-math-captcha' ) );
	}
}
