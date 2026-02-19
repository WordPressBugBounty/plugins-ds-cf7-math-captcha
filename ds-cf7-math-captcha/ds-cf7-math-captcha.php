<?php
/*
Plugin Name: DS CF7 Math Captcha
Version: 3.1.0
Author: Dotsquares WPTeam
Author URI: https://www.dotsquares.com
Description: Protect Contact Form 7 forms from spam with a lightweight math captcha, AJAX refresh support, and multilingual compatibility.
Text Domain: ds-cf7-math-captcha
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSCF7_VERSION', '3.1.0' );
define( 'DSCF7_REQUIRED_WP_VERSION', '6.5' );
define( 'DSCF7_PLUGIN', __FILE__ );
define( 'DSCF7_PLUGIN_BASENAME', plugin_basename( DSCF7_PLUGIN ) );
define( 'DSCF7_PLUGIN_NAME', trim( dirname( DSCF7_PLUGIN_BASENAME ), '/' ) );
define( 'DSCF7_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DSCF7_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once DSCF7_PLUGIN_DIR . 'includes/class-dscf7-math-captcha.php';
require_once DSCF7_PLUGIN_DIR . 'includes/class-dscf7-math-captcha-frontend.php';
require_once DSCF7_PLUGIN_DIR . 'includes/class-dscf7-math-captcha-admin.php';

/**
 * Bootstrap plugin instance.
 *
 * @return DSCF7_Math_Captcha
 */
function dscf7_math_captcha() {
	return DSCF7_Math_Captcha::instance();
}

dscf7_math_captcha(); 

/*
 * Backward-compatible wrappers for legacy function names.
 */
function dscf7_load_textdomain() {
	dscf7_math_captcha()->load_textdomain();
}

function dscf7_deactivate_on_cf7_deactivation() {
	dscf7_math_captcha()->admin->deactivate_on_cf7_deactivation();
}

function dscf7_plugin_contact_form7_notice() {
	dscf7_math_captcha()->admin->plugin_contact_form7_notice();
}

function dscf7_wpcf7_messages_callback( $messages ) {
	return dscf7_math_captcha()->admin->wpcf7_messages_callback( $messages );
}

function dscf7_capctha() {
	dscf7_math_captcha()->frontend->capctha();
}

function dscf7_captcha_validation( $result, $tag ) {
	return dscf7_math_captcha()->frontend->captcha_validation( $result, $tag );
}

function dscf7_captcha_handler( $tag ) {
	return dscf7_math_captcha()->frontend->captcha_handler( $tag );
}

function dscf7_ajaxify_scripts() {
	dscf7_math_captcha()->frontend->ajaxify_scripts();
}

function dscf7_admin_style() {
	dscf7_math_captcha()->admin->admin_style();
}

function dscf7_refreshcaptcha_callback( $tag = '' ) {
	dscf7_math_captcha()->frontend->refreshcaptcha_callback( $tag );
}

function dscf7_wpcf7_add_tag_generator_dsmathcaptcha() {
	dscf7_math_captcha()->admin->add_tag_generator_dsmathcaptcha();
}

function dscf7_wpcf7_tag_generator_dsmathcaptcha( $contact_form, $args = '' ) {
	dscf7_math_captcha()->admin->tag_generator_dsmathcaptcha( $contact_form, $args );
}
