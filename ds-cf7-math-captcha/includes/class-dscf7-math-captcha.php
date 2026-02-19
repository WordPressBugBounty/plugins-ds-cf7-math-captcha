<?php
/**
 * Main plugin class.
 *
 * @package DS_CF7_Math_Captcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSCF7_Math_Captcha {

	/**
	 * Singleton instance.
	 *
	 * @var DSCF7_Math_Captcha|null
	 */
	private static $instance = null;

	/**
	 * Frontend handler.
	 *
	 * @var DSCF7_Math_Captcha_Frontend
	 */
	public $frontend;

	/**
	 * Admin handler.
	 *
	 * @var DSCF7_Math_Captcha_Admin
	 */
	public $admin;

	/**
	 * Get singleton instance.
	 *
	 * @return DSCF7_Math_Captcha
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->frontend = new DSCF7_Math_Captcha_Frontend( $this );
		$this->admin    = new DSCF7_Math_Captcha_Admin( $this );

		$this->register_hooks();
	}

	/**
	 * Register top-level hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', 'dscf7_load_textdomain', 1 );

		$this->frontend->register_hooks();
		$this->admin->register_hooks();
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		$domain = 'ds-cf7-math-captcha';
		$locale = get_option( 'WPLANG' );
		if ( empty( $locale ) ) {
			$locale = 'en_US';
		}
		$mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';

		if ( ! file_exists( $mofile ) ) {
			$mofile = DSCF7_PLUGIN_DIR . 'languages/' . $domain . '-' . $locale . '.mo';
		}

		if ( is_textdomain_loaded( $domain ) ) {
			unload_textdomain( $domain );
		}

		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
		}

	}
}
