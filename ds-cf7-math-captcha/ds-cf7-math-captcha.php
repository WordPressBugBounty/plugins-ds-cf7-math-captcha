<?php
/*
Plugin Name: DS CF7 Math Captcha
Version: 3.0.5
Author: Dotsquares WPTeam
Author URI: https://www.dotsquares.com
Description: To prevent spam emails, adding a math captcha is a useful strategy. .
Text Domain: ds-cf7-math-captcha
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define the version number for the DS CF7 Math Captcha plugin
define( 'DSCF7_VERSION', '3.0.5' );

// Define the required WordPress version for the DS CF7 Math Captcha plugin
define( 'DSCF7_REQUIRED_WP_VERSION', '6.5' );

// Define the plugin file path
define( 'DSCF7_PLUGIN', __FILE__ );

// Define the plugin's basename
define( 'DSCF7_PLUGIN_BASENAME', plugin_basename( DSCF7_PLUGIN ) );

// Define the plugin's name
define( 'DSCF7_PLUGIN_NAME', trim( dirname( DSCF7_PLUGIN_BASENAME ), '/' ) );

// Define the plugin's URL
define( 'DSCF7_PLUGIN_URL', WP_CONTENT_URL . '/plugins/'. DSCF7_PLUGIN_NAME );

// Define the plugin's directory path
define( 'DSCF7_PLUGIN_DIR', untrailingslashit( dirname( DSCF7_PLUGIN ) ) );

/** Added init captcha hook */
add_action( 'wpcf7_init', 'dscf7_capctha' );

/** Check validation filter */    
add_filter( 'wpcf7_validate_dscf7captcha', 'dscf7_captcha_validation', 10, 2 );

add_action( 'wp_enqueue_scripts', 'dscf7_ajaxify_scripts' );

/** AJAX callback handler */
add_action( 'wp_ajax_dscf7_refreshcaptcha', 'dscf7_refreshcaptcha_callback' );
add_action( 'wp_ajax_nopriv_dscf7_refreshcaptcha', 'dscf7_refreshcaptcha_callback' );

/** Text domain handler */
add_action( 'init', 'wpcf7sr_load_textdomain' );

/** Deactivate handler hook */
add_action( 'admin_init', 'dscf7_deactivate_on_cf7_deactivation' );

/** Message section dynamic */
add_filter( 'wpcf7_messages', 'dscf7_wpcf7_messages_callback' );

/** Admin style */
add_action( 'admin_enqueue_scripts', 'dscf7_admin_style' );

/**
 * Function to deactivate DS CF7 Math Captcha when Contact Form 7 is deactivated.
 * 
 * This function checks if Contact Form 7 is not installed and the DS CF7 Math Captcha plugin is active,
 * then it adds a notice and deactivates the plugin.
 */
function dscf7_deactivate_on_cf7_deactivation() {
    // Check if Contact Form 7 is not active
    if ( ! class_exists( 'WPCF7' ) ) {
        // Show admin notice
        add_action( 'admin_notices', 'dscf7_plugin_contact_form7_notice' );
        
        // Optional: Deactivate this plugin automatically (only if activating without CF7)
        if ( is_plugin_active( 'ds-cf7-math-captcha/ds-cf7-math-captcha.php' ) ) {
            
            // Check nonce if this is an activation request
            if ( isset( $_GET['activate'] ) ) {
                if (
                    ! isset( $_REQUEST['_wpnonce'] ) ||
                    ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'activate-plugin_' . plugin_basename( __FILE__ ) )
                ) {
                    wp_die( esc_html__( 'Security check failed.', 'ds-cf7-math-captcha' ) );
                }
            }

            deactivate_plugins( plugin_basename( __FILE__ ) );

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe usage during plugin activation
            if ( isset( $_GET['activate'] ) && current_user_can( 'activate_plugins' ) ) {
                unset( $_GET['activate'] );
            }
        }
    }
}

function dscf7_plugin_contact_form7_notice() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php esc_html_e( 'Warning: DS CF7 Math Captcha plugin requires Contact Form 7 to be installed and active.', 'ds-cf7-math-captcha' ); ?></p>
    </div>
    <?php
}

/**
 * Callback function to modify Contact Form 7 error messages.
 *
 * @param array $messages The array of error messages.
 * @return array The modified array of error messages.
 */
function dscf7_wpcf7_messages_callback( $messages ) {
    $current_form = WPCF7_ContactForm::get_current();
    
    if ( $current_form ) {
        $form_id = $current_form->id();
        $custom_value = get_post_meta( $form_id, '_messages', true );
        $dynami_err_message = isset( $custom_value['invalid_letters_digits'] ) && ! empty( $custom_value['invalid_letters_digits'] ) 
            ? esc_html( $custom_value['invalid_letters_digits'] ) 
            : __( 'Incorrect captcha!', 'ds-cf7-math-captcha' );
        
        $please_enter_capthca = isset( $custom_value['invalid_letters'] ) && ! empty( $custom_value['invalid_letters'] ) 
            ? esc_html( $custom_value['invalid_letters'] ) 
            : __( 'Please enter captcha.', 'ds-cf7-math-captcha' );
        
        if ( ! empty( $dynami_err_message ) ) {
            $messages['invalid_letters_digits'] = [
                'description' => esc_html__( 'Incorrect captcha!', 'ds-cf7-math-captcha' ),
                'default'     => $dynami_err_message,
            ];
        }
        if ( ! empty( $please_enter_capthca ) ) {
            $messages['invalid_letters'] = [
                'description' => esc_html__( 'Please enter captcha.', 'ds-cf7-math-captcha' ),
                'default'     => $please_enter_capthca,
            ];
        }
    }

    return $messages;
}

/** Translation load file .po/.mo and language folder */
function wpcf7sr_load_textdomain() {
    load_plugin_textdomain( 'ds-cf7-math-captcha', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    $domain = 'ds-cf7-math-captcha';
    $locale = apply_filters( 'plugin_locale', get_locale(), $domain );
    load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
}

/**
 * Function to add a custom form tag for DS CF7 Math Captcha.
 */
function dscf7_capctha() {
    // Define attributes for the form tag
    $name_attr = array( 
        'name-attr' => true,
        'version' => 2 
    ); 
    
    // Add the custom form tag 'dscf7captcha' using 'dscf7_captcha_handler' as the callback function
    wpcf7_add_form_tag( 'dscf7captcha', 'dscf7_captcha_handler', $name_attr );
}

/**
 * Function to validate DS CF7 Math Captcha.
 *
 * @param object $result The result object.
 * @param object $tag    The tag object.
 * @return object The modified result object.
 */
function dscf7_captcha_validation( $result, $tag ) {
    // Check if the tag type is 'dscf7captcha'
    if ( $tag->type == 'dscf7captcha' ) {
        if (isset($_POST['ds_cf7_nonce'])) {
                $nonce = sanitize_text_field(wp_unslash($_POST['ds_cf7_nonce'])); // Unsplash first, then sanitize
                if ( wp_verify_nonce($nonce, 'ds_cf7_nonce') ) {
                    // Verify nonce after unslashing
                    if ( ! wp_verify_nonce( $nonce, 'ds_cf7_nonce' ) ) {
                        $result->invalidate( $tag, esc_html__( 'Nonce verification failed. Please try again.', 'ds-cf7-math-captcha' ) );
                        return $result; // Stop further processing if nonce is invalid
                    }
                }
            }
        
        // Initialize variables for captcha calculation
        $finalCechking = '';
        $cptha1 = isset( $_POST['dscf7_hidden_val1-' . $tag->name] ) ? sanitize_text_field( wp_unslash( $_POST['dscf7_hidden_val1-' . $tag->name] ) ) : '';
        $cptha2 = isset( $_POST['dscf7_hidden_val2-' . $tag->name] ) ? sanitize_text_field( wp_unslash( $_POST['dscf7_hidden_val2-' . $tag->name] ) ) : '';
        $cptha3 = isset( $_POST['dscf7_hidden_action-' . $tag->name] ) ? sanitize_text_field( wp_unslash( $_POST['dscf7_hidden_action-' . $tag->name] ) ) : '';
        
        // Perform captcha calculation based on the operation
        if ( $cptha3 == 'x' ) { 
            $finalCechking = $cptha1 * $cptha2;
        } elseif ( $cptha3 == '+' ) { 
            $finalCechking = $cptha1 + $cptha2;
        } else {
            $finalCechking = $cptha1 - $cptha2;
        }
        
        // Get the submitted captcha value
        $cptcha_value = isset( $_POST[$tag->name] ) ? trim( strtr( sanitize_text_field( wp_unslash( $_POST[$tag->name] ) ), "\n", " " ) ) : '';
        
        // Get custom error messages
        $custom_messages = apply_filters( 'wpcf7_messages', array() );
        
        // Check if captcha value is empty
        if ( $cptcha_value == '' ) {
            $in_please_captcha_message = ( isset( $custom_messages['invalid_letters']['default'] ) && ! empty( $custom_messages['invalid_letters']['default'] ) ) ? $custom_messages['invalid_letters']['default'] : esc_html__( 'Please enter Captcha.', 'ds-cf7-math-captcha' ); 
            $result->invalidate( $tag, apply_filters( 'dscf7_captcha_required', $in_please_captcha_message ) );
        }
        
        // Check if captcha value is incorrect
        if ( $cptcha_value != '' && $cptcha_value != $finalCechking ) {
            $in_valid_captcha_message = ( isset( $custom_messages['invalid_letters_digits']['default'] ) && ! empty( $custom_messages['invalid_letters_digits']['default'] ) ) ? $custom_messages['invalid_letters_digits']['default'] : esc_html__( 'Incorrect Captcha!', 'ds-cf7-math-captcha' );  
            $result->invalidate( $tag, apply_filters( 'dscf7_captcha_invalidate', $in_valid_captcha_message ) );
        }
    }
    
    // Return the modified result object
    return $result;
}

/**
 * Handler function for the DS CF7 Math Captcha form tag.
 *
 * @param object $tag The tag object.
 * @return string The HTML markup for the captcha.
 */
function dscf7_captcha_handler( $tag ) {
    // Define an array of mathematical operations
    $operationAry = array( '+', 'x', '-' );
    
    // Select a random operation
    $random_action = array_rand( $operationAry, 2 );
    $random_actionVal = $operationAry[$random_action[0]];
    
    // Generate two random values
    $actnVal1 = wp_rand( 1, 9 );
    $actnVal2 = wp_rand( 1, 9 );
    
    // Extract all class attributes from the tag
    $custom_classes = [];
    foreach ( $tag->options as $option ) {
        if ( strpos( $option, 'class:' ) === 0 ) {
            $class = substr( $option, 6 ); // Remove 'class:' prefix
            $custom_classes[] = esc_attr( $class ); // Escape for safety
        }
    }
    $custom_class = implode( ' ', $custom_classes ); // Join classes with a space

    // Base classes for the input field
    $base_classes = 'wpcf7-form-control wpcf7-text wpcf7-validates-as-required';
    // Combine base classes with custom classes (if any)
    $input_classes = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;
    
    // Escape and sanitize data
    $actnVal1_escaped = esc_attr( $actnVal1 );
    $actnVal2_escaped = esc_attr( $actnVal2 );
    $random_actionVal_escaped = esc_attr( $random_actionVal );
    $nonce = wp_create_nonce( 'ds_cf7_nonce' );
    $nonce_escaped = esc_attr( $nonce );
    $captcha_icon_url = esc_url( DSCF7_PLUGIN_URL . '/assets/img/icons8-refresh-30.png' );
    $captcha_reload_icon_url = esc_url( DSCF7_PLUGIN_URL . '/assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );

    // Generate the question text for label and aria-label
    $question_text = esc_attr__('What is ', 'ds-cf7-math-captcha') . $actnVal2 . ' ' . $random_actionVal . ' ' . $actnVal1 . '?';

    $answer_label = esc_attr__('Answer for ', 'ds-cf7-math-captcha') . $actnVal2 . ' ' . $random_actionVal . ' ' . $actnVal1;


    // Build the HTML markup for the captcha with accessibility improvements
    $ds_cf7_captcha = '<div class="dscf7-captcha-container">';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_val1-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_val1-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $actnVal1_escaped . '" />';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_val2-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_val2-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $actnVal2_escaped . '" />';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_action-' . esc_attr( $tag->name ) . '" id="dscf7_hidden_action-' . esc_attr( $tag->name ) . '" type="hidden" value="' . $random_actionVal_escaped . '" />';

    // Question with refresh button
    $ds_cf7_captcha .= '<div class="dscf7-question-container">';
    $ds_cf7_captcha .= '<span class="dscf7_lt">' . esc_html($question_text) . ' ';
    $ds_cf7_captcha .= '<a href="javascript:void(0)" id="' . esc_attr( $tag->name ) . '" class="dscf7_refresh_captcha" aria-label="' . esc_attr__('Refresh captcha', 'ds-cf7-math-captcha') . '">';
    // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
    $ds_cf7_captcha .= '<img class="dscf7_captcha_icon" src="' . esc_url( $captcha_icon_url ) . '" alt="' . esc_attr__('Refresh icon', 'ds-cf7-math-captcha') . '"/>';
    // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
    $ds_cf7_captcha .= '<img class="dscf7_captcha_reload_icon" src="' . esc_url( $captcha_reload_icon_url ) . '" alt="' . esc_attr__('Refreshing captcha', 'ds-cf7-math-captcha') . '" style="display:none; width:30px" />';
    $ds_cf7_captcha .= '</a></span>';
    $ds_cf7_captcha .= '</div>';
    
    // Input field with label
    $ds_cf7_captcha .= '<div class="dscf7-answer-container">';
    $ds_cf7_captcha .= '<label for="' . esc_attr( $tag->name ) . '-input" class="screen-reader-text">' . $answer_label . '</label>';
    $ds_cf7_captcha .= '<span class="wpcf7-form-control-wrap" data-name="' . esc_attr( $tag->name ) . '">';
    $ds_cf7_captcha .= '<input type="text" id="' . esc_attr( $tag->name ) . '-input" aria-label="' . $answer_label . '" aria-invalid="false" aria-required="true" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . esc_attr( $tag->name ) . '" placeholder="' . esc_attr__( 'Type your answer', 'ds-cf7-math-captcha' ) . '" style="" oninput="this.value = this.value.replace(/[^0-9.]/g, \'\').replace(/(\..*)\./g, \'$1\');">';
    $ds_cf7_captcha .= '</span>';
    $ds_cf7_captcha .= '<input type="hidden" name="ds_cf7_nonce" value="' . $nonce_escaped . '">';
    $ds_cf7_captcha .= '</div>';
    $ds_cf7_captcha .= '</div>';

    // Return the HTML markup for the captcha
    return $ds_cf7_captcha;
}
/**
 * Enqueue scripts and styles for DS CF7 Math Captcha plugin.
 */
function dscf7_ajaxify_scripts() {
    // Enqueue JavaScript file for refreshing captcha
    wp_enqueue_script( 'dscf7_refresh_script', esc_url( DSCF7_PLUGIN_URL . '/assets/js/script-min.js' ), array( 'jquery' ), '1.2.0', true );
    
    // Localize the script to make the AJAX URL available in JavaScript
    wp_localize_script( 'dscf7_refresh_script', 'ajax_object', 
        array( 
            'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ), 
            'nonce' => esc_attr( wp_create_nonce( 'ds_cf7_nonce' ) )
        ) 
    );
    
    // Register and enqueue CSS style for the math captcha
    wp_register_style( 'dscf7-math-captcha-style', esc_url( DSCF7_PLUGIN_URL . '/assets/css/style.css' ), array(), '1.0.0', false );
    wp_enqueue_style( 'dscf7-math-captcha-style' );
}

/**
 * Admin Style and Scripts for the DS CF7 Math Captcha to Contact Form 7.
 */
function dscf7_admin_style() {
    // Register and enqueue the admin stylesheet
    wp_enqueue_style( 'dscf7-admin-style', esc_url( DSCF7_PLUGIN_URL . '/assets/css/admin-style.css' ), array(), '1.0.0', 'all' );

    // Enqueue script-min.js for admin (for tag generator functionality)
    wp_enqueue_script( 'dscf7_refresh_script', esc_url( DSCF7_PLUGIN_URL . '/assets/js/script-min.js' ), array( 'jquery' ), '1.2.0', true );

    // Localize the script to make the AJAX URL available in JavaScript
    wp_localize_script( 'dscf7_refresh_script', 'ajax_object', 
        array( 
            'ajax_url' => esc_url( admin_url( 'admin-ajax.php' ) ), 
            'nonce' => esc_attr( wp_create_nonce( 'ds_cf7_nonce' ) )
        ) 
    );
}

/**
 * Callback function to refresh the captcha.
 *
 * @param array $tag The tag array.
 */
function dscf7_refreshcaptcha_callback( $tag ) {
    // Define array of possible mathematical operations
    $operationAry = array( '+', 'x', '-' );

    // Get a random operation
    $random_action = array_rand( $operationAry, 2 );
    $random_actionVal = $operationAry[$random_action[0]];

    // Generate two random values
    $actnVal1 = wp_rand( 1, 9 );
    $actnVal2 = wp_rand( 1, 9 );

    // Get the tag name from the POST data
    $tagName = isset( $_POST['tagname'] ) ? sanitize_text_field( wp_unslash( $_POST['tagname'] ) ) : '';

    // Get the custom class from POST data (passed via AJAX)
    $custom_class = isset( $_POST['custom_class'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_class'] ) ) : '';
    $custom_class = esc_attr( $custom_class );

    // Base classes for the input field
    $base_classes = 'wpcf7-form-control wpcf7-text wpcf7-validates-as-required';
    // Combine base classes with custom class (if any)
    $input_classes = $custom_class ? $base_classes . ' ' . $custom_class : $base_classes;

    // Escape and sanitize data for attributes and URLs
    $actnVal1_escaped = esc_attr( $actnVal1 );
    $actnVal2_escaped = esc_attr( $actnVal2 );
    $random_actionVal_escaped = esc_attr( $random_actionVal );
    $tagName_escaped = esc_attr( $tagName );
    $captcha_icon_url = esc_url( DSCF7_PLUGIN_URL . '/assets/img/icons8-refresh-30.png' );
    $captcha_reload_icon_url = esc_url( DSCF7_PLUGIN_URL . '/assets/img/446bcd468478f5bfb7b4e5c804571392_w200.gif' );
    $nonce = wp_create_nonce( 'ds_cf7_nonce' );
    $nonce_escaped = esc_attr( $nonce );

    // Generate the question text for label and aria-label
    $question_text = esc_attr__('What is', 'ds-cf7-math-captcha') . ' ' . $actnVal2 . ' ' . $random_actionVal . ' ' . $actnVal1 . '?';

    $answer_label = esc_attr__('Answer for', 'ds-cf7-math-captcha') . ' ' . $actnVal2 . ' ' . $random_actionVal . ' ' . $actnVal1;


    // Construct the captcha HTML with accessibility improvements
    $ds_cf7_captcha = '<div class="dscf7-captcha-container">';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_val1-' . $tagName_escaped . '" id="dscf7_hidden_val1-' . $tagName_escaped . '" type="hidden" value="' . $actnVal1_escaped . '" />';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_val2-' . $tagName_escaped . '" id="dscf7_hidden_val2-' . $tagName_escaped . '" type="hidden" value="' . $actnVal2_escaped . '" />';
    $ds_cf7_captcha .= '<input name="dscf7_hidden_action-' . $tagName_escaped . '" id="dscf7_hidden_action-' . $tagName_escaped . '" type="hidden" value="' . $random_actionVal_escaped . '" />';
    
    // Question with refresh button
    $ds_cf7_captcha .= '<div class="dscf7-question-container">';
    $ds_cf7_captcha .= '<span class="dscf7_lt">' . $question_text . ' ';
    $ds_cf7_captcha .= '<a href="javascript:void(0)" id="' . $tagName_escaped . '" class="dscf7_refresh_captcha" aria-label="' . esc_attr__('Refresh captcha', 'ds-cf7-math-captcha') . '">';
    // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
    $ds_cf7_captcha .= '<img class="dscf7_captcha_icon" src="' . esc_url( $captcha_icon_url ) . '" alt="' . esc_attr__('Refresh icon', 'ds-cf7-math-captcha') . '"/>';
    // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
    $ds_cf7_captcha .= '<img class="dscf7_captcha_reload_icon" src="' . esc_url( $captcha_reload_icon_url ) . '" alt="' . esc_attr__('Refreshing captcha', 'ds-cf7-math-captcha') . '" style="display:none; width:30px" />';
    $ds_cf7_captcha .= '</a></span>';
    $ds_cf7_captcha .= '</div>';
    
    // Input field with label
    $ds_cf7_captcha .= '<div class="dscf7-answer-container">';
    $ds_cf7_captcha .= '<label for="' . $tagName_escaped . '-input" class="screen-reader-text">' . $answer_label . '</label>';
    $ds_cf7_captcha .= '<span class="wpcf7-form-control-wrap" data-name="' . $tagName_escaped . '">';
    $ds_cf7_captcha .= '<input type="text" id="' . $tagName_escaped . '-input" aria-label="' . $answer_label . '" aria-invalid="false" aria-required="true" class="' . esc_attr( $input_classes ) . '" size="5" value="" name="' . $tagName_escaped . '" placeholder="' . esc_attr__( 'Type your answer', 'ds-cf7-math-captcha' ) . '" style="" oninput="this.value = this.value.replace(/[^0-9.]/g, \'\').replace(/(\..*)\./g, \'$1\');">';
    $ds_cf7_captcha .= '<input type="hidden" name="ds_cf7_nonce" value="' . $nonce_escaped . '">';
    $ds_cf7_captcha .= '</span>';
    $ds_cf7_captcha .= '</div>';
    $ds_cf7_captcha .= '</div>';

    // Define allowed HTML
    $allowed_html = array(
        'div' => array(
            'class' => array()
        ),
        'a' => array(
            'href'  => array(),
            'id'    => array(),
            'class' => array(),
            'title' => array(),
            'style' => array(),
            'aria-label' => array(),
        ),
        'p' => array(
            'class' => array()
        ),
        'span' => array(
            'class'  => array(),
            'data-name'  => array(),
        ),
        'label' => array(
            'for' => array(),
            'class' => array()
        ),
        'br' => array(),
        'input' => array(
            'name'  => array(),
            'class'  => array(),
            'id'    => array(),
            'type'  => array(),
            'value' => array(),
            'aria-invalid' => array(),
            'aria-required' => array(),
            'aria-label' => array(),
            'size' => array(),
            'placeholder' => array(),
            'oninput' => array(),
            'hidden' => array()
        ),
        'img' => array(
            'class' => array(),
            'src'   => array(),
            'style' => array(),
            'decoding' => array(),
            'alt' => array(),
        ),
    );

    // Check nonce for security
    if ( isset( $_POST['ds_cf7_nonce'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['ds_cf7_nonce'] ) );
        
        // Verify nonce after unslashing
        if ( wp_verify_nonce( $nonce, 'ds_cf7_nonce' ) ) {
            // Correct nonce, process further
            echo wp_kses( $ds_cf7_captcha, $allowed_html );
        } else {
            // Nonce verification failed
            echo 'Nonce verification failed';
        }
    }
    
    exit;
}

/**
 * Adds the tag generator for the DS CF7 Math Captcha to Contact Form 7.
 */
function wpcf7_add_tag_generator_dsmathcaptcha() {
    // Get the instance of the tag generator
    $tag_generator = WPCF7_TagGenerator::get_instance();
    $name_attr = array( 
        // 'name-attr' => true,
        // 'version' => 2 
    );
    // Add the tag generator for the 'dscf7captcha' tag
    $tag_generator->add( 'dscf7captcha', __( 'math-captcha', 'ds-cf7-math-captcha' ), 'wpcf7_tag_generator_dsmathcaptcha', array( 'version' => 2 ) );
}

// Hook the function to the 'wpcf7_admin_init' action with a priority of 65 and no parameters
add_action( 'wpcf7_admin_init', 'wpcf7_add_tag_generator_dsmathcaptcha', 65, 0 );

/**
 * Callback function for generating tag for the DS CF7 Math Captcha.
 *
 * @param object $contact_form The contact form object.
 * @param array  $args         Optional arguments.
 */
function wpcf7_tag_generator_dsmathcaptcha( $contact_form, $args = '' ) {
    // Parse the arguments
    $args = wp_parse_args( $args, array() );
    $type = isset( $args['id'] ) ? $args['id'] : '';

    // Check if the type is 'dscf7captcha'
    if ( 'dscf7captcha' == $type ) {
        // Set the description for the tag generator
        $description = esc_html( sprintf( 'Copy the given shortcode in the form, see %s.', 'ds-cf7-math-captcha' ), 'ds-cf7-math-captcha' );
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
                            <input type="text" name="name" class="tg-name oneline" readonly="readonly" id="<?php echo esc_attr( $args['content'] . '-name' ); ?>" value="<?php echo esc_attr( $args['content'] ); ?>"/>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>"><?php echo esc_html( __( 'Class', 'ds-cf7-math-captcha' ) ); ?></label>
                        </th>
                        <td>
                            <input type="text" name="classname" class="class oneline" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>" value=""/>
                            <p class="description"><?php echo esc_html($description); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </fieldset>
    </div>
    <div class="insert-box">
        <input type="text" name="<?php echo esc_attr( $type ); ?>" class="tag code" readonly="readonly" onfocus="this.select()" value="[dscf7captcha <?php echo esc_attr( $args['content'] ); ?>]" />
        <div class="submitbox">
            <input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'ds-cf7-math-captcha' ) ); ?>" />
        </div>
    </div>
    <?php
}
?>