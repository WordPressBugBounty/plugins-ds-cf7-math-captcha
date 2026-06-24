<?php
/**
 * Anti-spam security layer (Phase 1).
 *
 * Adds honeypot detection, a minimum form-completion-time check, per-IP rate
 * limiting and a failed-captcha lockout. Every piece of state lives in an
 * auto-expiring transient — there are no custom database tables, no cookies,
 * no fingerprinting, no external API calls and no user tracking, keeping the
 * plugin GDPR friendly and WordPress.org compliant.
 *
 * Spam-type detections (honeypot, lockout, rate limit) are reported through
 * Contact Form 7's native spam mechanism (the `wpcf7_spam` filter) so the
 * submission is logged as spam, the mail is blocked and the visitor only ever
 * sees the form's generic spam message — internal reasons are never exposed.
 *
 * @package DS_CF7_Math_Captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSCF7_Math_Captcha_Security {

	/**
	 * Prefix shared by every honeypot input name.
	 */
	const HP_PREFIX = 'dscf7_hp_';

	/**
	 * Prefix of the signed-token field name (used to detect our captcha).
	 */
	const TOKEN_PREFIX = 'dscf7_token-';

	/**
	 * Transient key prefixes (a hashed IP is appended to each).
	 */
	const RL_PREFIX   = 'dscf7_rl_';
	const FAIL_PREFIX = 'dscf7_fail_';
	const LOCK_PREFIX = 'dscf7_lock_';

	/**
	 * Main plugin reference.
	 *
	 * @var DSCF7_Math_Captcha
	 */
	private $plugin;

	/**
	 * Ensures a single submission is only counted once toward the rate limit
	 * even when a form contains more than one captcha tag.
	 *
	 * @var bool
	 */
	private $rate_counted = false;

	/**
	 * Constructor.
	 *
	 * @param DSCF7_Math_Captcha $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'wpcf7_spam', 'dscf7_spam_check', 9, 2 );
	}

	/* --------------------------------------------------------------------- *
	 *  CLIENT IP (privacy friendly — hashed before use, never stored raw)
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the client IP. Only REMOTE_ADDR is trusted by default because
	 * proxy headers (X-Forwarded-For etc.) are trivially spoofable; sites
	 * behind a known proxy can adjust this via the filter.
	 *
	 * @return string
	 */
	public function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filter the client IP used for rate limiting and lockout.
		 *
		 * @param string $ip The detected REMOTE_ADDR value.
		 */
		return (string) apply_filters( 'dscf7_client_ip', $ip );
	}

	/**
	 * Build a transient key from a prefix and the hashed client IP. The IP is
	 * passed through wp_hash() so no raw address is ever written to storage.
	 *
	 * @param string $prefix Key prefix.
	 * @return string
	 */
	private function ip_key( $prefix ) {
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			$ip = 'unknown';
		}
		return $prefix . wp_hash( $ip );
	}

	/* --------------------------------------------------------------------- *
	 *  CAPTCHA / HONEYPOT DETECTION
	 * --------------------------------------------------------------------- */

	/**
	 * Honeypot field name for a given captcha field.
	 *
	 * @param string $field_name Captcha field name.
	 * @return string
	 */
	public static function honeypot_name( $field_name ) {
		return self::HP_PREFIX . $field_name;
	}

	/**
	 * Whether the current request belongs to a form that uses our captcha.
	 *
	 * @return bool
	 */
	public function form_has_captcha() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST ) ) {
			return false;
		}
		foreach ( array_keys( $_POST ) as $key ) {
			if ( 0 === strpos( (string) $key, self::TOKEN_PREFIX ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return false;
	}

	/**
	 * Whether any honeypot field was filled in (a strong bot signal).
	 *
	 * @return bool
	 */
	public function honeypot_tripped() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST ) ) {
			return false;
		}
		foreach ( $_POST as $key => $value ) {
			if ( 0 !== strpos( (string) $key, self::HP_PREFIX ) ) {
				continue;
			}
			$flat = is_array( $value ) ? implode( '', $value ) : (string) $value;
			if ( '' !== trim( wp_unslash( $flat ) ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return false;
	}

	/* --------------------------------------------------------------------- *
	 *  MINIMUM COMPLETION TIME
	 * --------------------------------------------------------------------- */

	/**
	 * Whether the form was submitted faster than a human plausibly could.
	 *
	 * The render time is derived from the HMAC-signed expiry (expiry minus the
	 * token TTL), so the timestamp cannot be forged client-side.
	 *
	 * @param int $expiry Signed expiry timestamp from the token.
	 * @param int $ttl    Token time-to-live used at generation.
	 * @return bool
	 */
	public function too_fast( $expiry, $ttl ) {
		/**
		 * Filter the minimum number of seconds a genuine submission must take.
		 *
		 * @param int $seconds Default 3 seconds.
		 */
		$threshold = (int) apply_filters( 'dscf7_min_completion_time', 3 );

		if ( $threshold < 1 ) {
			return false;
		}

		$render_time = (int) $expiry - (int) $ttl;
		$elapsed     = time() - $render_time;

		return $elapsed < $threshold;
	}

	/* --------------------------------------------------------------------- *
	 *  RATE LIMITING (per IP, fixed window, transient based)
	 * --------------------------------------------------------------------- */

	/**
	 * Count the current submission toward the rate limit (once per request).
	 *
	 * @return void
	 */
	public function note_attempt() {
		if ( $this->rate_counted ) {
			return;
		}
		$this->rate_counted = true;

		$window = (int) apply_filters( 'dscf7_rate_limit_window', 5 * MINUTE_IN_SECONDS );
		if ( $window < 1 ) {
			return;
		}

		$this->bump_window( $this->ip_key( self::RL_PREFIX ), $window );
	}

	/**
	 * Whether the current IP has exceeded the allowed attempts.
	 *
	 * @return bool
	 */
	public function rate_limited() {
		$limit = (int) apply_filters( 'dscf7_rate_limit_attempts', 5 );
		if ( $limit < 1 ) {
			return false;
		}
		return $this->peek_window( $this->ip_key( self::RL_PREFIX ) ) > $limit;
	}

	/* --------------------------------------------------------------------- *
	 *  FAILED-CAPTCHA LOCKOUT (per IP, transient based)
	 * --------------------------------------------------------------------- */

	/**
	 * Whether the current IP is locked out after too many failed captchas.
	 *
	 * @return bool
	 */
	public function is_locked() {
		return false !== get_transient( $this->ip_key( self::LOCK_PREFIX ) );
	}

	/**
	 * Record a failed captcha attempt and trigger a lockout once the failure
	 * threshold is reached.
	 *
	 * @return void
	 */
	public function note_failure() {
		$limit = (int) apply_filters( 'dscf7_failed_limit', 5 );
		if ( $limit < 1 ) {
			return;
		}

		/**
		 * Filter how long (seconds) an IP stays locked out after too many
		 * failed captcha attempts.
		 *
		 * @param int $seconds Default 30 minutes.
		 */
		$lockout = (int) apply_filters( 'dscf7_failed_lockout', 30 * MINUTE_IN_SECONDS );
		$lockout = max( 1, $lockout );

		$count = $this->bump_window( $this->ip_key( self::FAIL_PREFIX ), $lockout );

		if ( $count >= $limit ) {
			set_transient( $this->ip_key( self::LOCK_PREFIX ), 1, $lockout );
			delete_transient( $this->ip_key( self::FAIL_PREFIX ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 *  CF7 NATIVE SPAM HANDLING
	 * --------------------------------------------------------------------- */

	/**
	 * Flag honeypot / lockout / rate-limit hits as spam through CF7. Runs only
	 * for submissions that use our captcha and never reveals which check fired.
	 *
	 * @param bool   $spam       Current spam flag.
	 * @param object $submission CF7 submission (WPCF7_Submission).
	 * @return bool
	 */
	public function spam_check( $spam, $submission = null ) {
		if ( $spam || ! $this->form_has_captcha() ) {
			return $spam;
		}

		if ( $this->honeypot_tripped() ) {
			$this->log_spam( $submission, 'Honeypot field was filled in.' );
			return true;
		}

		if ( $this->is_locked() ) {
			$this->log_spam( $submission, 'IP temporarily locked after repeated failed captchas.' );
			return true;
		}

		if ( $this->rate_limited() ) {
			$this->log_spam( $submission, 'Submission rate limit exceeded.' );
			return true;
		}

		return $spam;
	}

	/**
	 * Add an internal spam log entry (visible only in CF7/Flamingo, never to
	 * the visitor).
	 *
	 * @param object $submission CF7 submission.
	 * @param string $reason     Internal reason.
	 * @return void
	 */
	private function log_spam( $submission, $reason ) {
		if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log(
				array(
					'agent'  => 'ds-cf7-math-captcha',
					'reason' => $reason,
				)
			);
		}
	}

	/* --------------------------------------------------------------------- *
	 *  TRANSIENT WINDOW HELPERS
	 * --------------------------------------------------------------------- */

	/**
	 * Increment a fixed-window counter and return the new count. The window
	 * start is stored inside the value so the original expiry is preserved
	 * across increments (the counter is not extended on every hit).
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Window length in seconds.
	 * @return int
	 */
	private function bump_window( $key, $window ) {
		$now    = time();
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || empty( $bucket['reset'] ) || (int) $bucket['reset'] <= $now ) {
			$bucket = array(
				'count' => 0,
				'reset' => $now + max( 1, (int) $window ),
			);
		}

		$bucket['count'] = (int) $bucket['count'] + 1;
		set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );

		return (int) $bucket['count'];
	}

	/**
	 * Read the current count of a fixed-window counter without incrementing.
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private function peek_window( $key ) {
		$now    = time();
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || empty( $bucket['reset'] ) || (int) $bucket['reset'] <= $now ) {
			return 0;
		}

		return (int) $bucket['count'];
	}
}
