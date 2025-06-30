(function ($) {
    "use strict";

    // CAPTCHA Refresh Functionality
    function refreshCaptcha($button, showSpinner = false) {
        const $captchaContainer = $button.closest(".dscf7-captcha-container");
        const captchaId = $button.attr("id");
        // Get the input field and extract the custom class
        const $inputField = $captchaContainer.find('input[type="text"][name="' + captchaId + '"]');
        const customClass = $inputField.attr('class').replace('wpcf7-form-control wpcf7-text wpcf7-validates-as-required', '').trim();

        $.ajax({
            type: "POST",
            url: ajax_object.ajax_url,
            data: {
                action: "dscf7_refreshcaptcha",
                tagname: captchaId,
                ds_cf7_nonce: ajax_object.nonce,
                custom_class: customClass // Include the custom class in the AJAX request
            },
            beforeSend: function () {
                if (showSpinner) {
                    $captchaContainer.find(".dscf7_captcha_reload_icon").show();
                    $captchaContainer.find(".dscf7_captcha_icon").hide();
                } else {
                    // Explicitly hide the loader during silent refresh
                    $captchaContainer.find(".dscf7_captcha_reload_icon").hide();
                    $captchaContainer.find(".dscf7_captcha_icon").show();
                }
            },
            success: function (response) {
                if (response === 'Nonce verification failed') {
                    alert('Security check failed. Please refresh the page and try again.');
                    return;
                }

                const $newCaptcha = $('<div>').html(response);
                const $newQuestion = $newCaptcha.find('.dscf7_lt').html();
                const $newHidden1 = $newCaptcha.find('input[name^="dscf7_hidden_val1"]').val();
                const $newHidden2 = $newCaptcha.find('input[name^="dscf7_hidden_val2"]').val();
                const $newAction = $newCaptcha.find('input[name^="dscf7_hidden_action"]').val();
                const $newLabel = $newCaptcha.find('label').text();
                const $newInputId = $newCaptcha.find('input[type="text"]').attr('id');
                const $newAriaLabel = $newCaptcha.find('input[type="text"]').attr('aria-label');

                // Update the question part
                $captchaContainer.find('.dscf7_lt').html($newQuestion);

                // Update hidden fields
                $captchaContainer.find('input[name^="dscf7_hidden_val1"]').val($newHidden1);
                $captchaContainer.find('input[name^="dscf7_hidden_val2"]').val($newHidden2);
                $captchaContainer.find('input[name^="dscf7_hidden_action"]').val($newAction);

                // Update accessibility attributes
                $captchaContainer.find('label').text($newLabel);
                $inputField.attr('id', $newInputId);
                $inputField.attr('aria-label', $newAriaLabel);

                // Clear the user input field
                $inputField.val("");
            },
            error: function (xhr, status, error) {
                alert('Failed to refresh CAPTCHA. Please try again later.');
            },
            complete: function () {
                if (showSpinner) {
                    $captchaContainer.find(".dscf7_captcha_reload_icon").hide();
                    $captchaContainer.find(".dscf7_captcha_icon").show();
                }
                $button.attr("href", "javascript:void(0);");
            }
        });
    }

    // Global reusable function
    function updateShortcode($classInput, $tagInput, baseShortcode) {
        const classValue = $classInput.val().trim();
        let classAttr = '';

        if (classValue) {
            const classes = classValue.split(/\s+/).filter(cls => cls.length > 0);
            classAttr = classes.map(cls => ' class:' + cls).join('');
        }

        $tagInput.val(baseShortcode + classAttr + ']');
    }

    // Manual refresh
    $(document).on("click", ".dscf7_refresh_captcha", function (e) {
        e.preventDefault();
        refreshCaptcha($(this), true); // true = show spinner
    });

    // After form submit (valid or invalid), refresh CAPTCHA silently without affecting error messages
    document.addEventListener('wpcf7submit', function (event) {
        const $form = $(event.target);
        $form.find(".dscf7_refresh_captcha").each(function () {
            refreshCaptcha($(this), false); // false = no spinner
        });
    }, false);

    // Tag Generator Shortcode Update Functionality
    $(document).ready(function () {
        const $classInput = $('input[name="classname"]');
        if ($classInput.length) {
            const $controlBox = $classInput.closest('.control-box');
            const $insertBox = $controlBox.siblings('.insert-box');
            const $tagInput = $insertBox.find('input.tag');

            if ($tagInput.length) {
                const baseShortcodeMatch = $tagInput.val().match(/\[dscf7captcha\s+([^\s\]]+)/);
                const baseShortcode = baseShortcodeMatch ? '[dscf7captcha ' + baseShortcodeMatch[1] : $tagInput.val();

                // Bind input event using reusable function
                $classInput.on('input', function () {
                    updateShortcode($classInput, $tagInput, baseShortcode);
                });

                // Initial run
                updateShortcode($classInput, $tagInput, baseShortcode);
            }
        }
    });

})(jQuery);