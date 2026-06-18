(function ($) {
	"use strict";

	/**
	 * Refresh a single captcha block via AJAX.
	 *
	 * @param {jQuery}  $link    The clicked refresh anchor (or the anchor in a block).
	 * @param {boolean} spinner  Whether to show the loading spinner.
	 */
	function refreshCaptcha($link, spinner) {
		var $container = $link.closest(".dscf7-captcha-container");
		var tagName = $link.attr("id");
		var $answer = $container.find('input[type="text"][name="' + tagName + '"]');
		var customClass = ($answer.attr("class") || "")
			.replace("wpcf7-form-control wpcf7-text wpcf7-validates-as-required", "")
			.replace("wpcf7-form-control wpcf7-text", "")
			.trim();

		$.ajax({
			type: "POST",
			url: ajax_object.ajax_url,
			data: {
				action: "dscf7_refreshcaptcha",
				tagname: tagName,
				ds_cf7_nonce: ajax_object.nonce,
				custom_class: customClass
			},
			beforeSend: function () {
				if (spinner) {
					$container.find(".dscf7_captcha_reload_icon").show();
					$container.find(".dscf7_captcha_icon").hide();
				}
			},
			success: function (response) {
				if ("Nonce verification failed" === response) {
					window.alert("Security check failed. Please refresh the page and try again.");
					return;
				}

				var $new = $("<div>").html(response);

				// Swap the SVG question, the signed token, the nonce and the label.
				$container.find(".dscf7_svg_wrap").html($new.find(".dscf7_svg_wrap").html());
				$container.find(".dscf7_token").val($new.find(".dscf7_token").val());
				$container.find('input[name="ds_cf7_nonce"]').val($new.find('input[name="ds_cf7_nonce"]').val());
				$container.find("label.screen-reader-text").text($new.find("label.screen-reader-text").text());

				// Sync answer-input id / aria-label and clear it.
				var newId = $new.find('input[type="text"]').attr("id");
				var newAria = $new.find('input[type="text"]').attr("aria-label");
				$answer.attr("id", newId).attr("aria-label", newAria).val("");
			},
			error: function () {
				window.alert("Failed to refresh CAPTCHA. Please try again later.");
			},
			complete: function () {
				if (spinner) {
					$container.find(".dscf7_captcha_reload_icon").hide();
					$container.find(".dscf7_captcha_icon").show();
				}
			}
		});
	}

	/**
	 * Tag-generator helper: build the shortcode with class:* options.
	 */
	function buildShortcode($input, $tagField, base) {
		var value = $input.val().trim();
		var classes = "";
		if (value) {
			classes = value
				.split(/\s+/)
				.filter(function (item) { return item.length > 0; })
				.map(function (item) { return " class:" + item; })
				.join("");
		}
		$tagField.val(base + classes + "]");
	}

	// Manual refresh click.
	$(document).on("click", ".dscf7_refresh_captcha", function (e) {
		e.preventDefault();
		refreshCaptcha($(this), true);
	});

	// Refresh after a CF7 submit so the next attempt gets a fresh token.
	document.addEventListener("wpcf7submit", function (e) {
		$(e.target).find(".dscf7_refresh_captcha").each(function () {
			refreshCaptcha($(this), false);
		});
	}, false);

	$(document).ready(function () {
		// Optional: mint a per-visitor token on cached pages.
		$(".dscf7-captcha-container[data-dscf7-refresh-on-load]").each(function () {
			var $link = $(this).find(".dscf7_refresh_captcha").first();
			if ($link.length) {
				refreshCaptcha($link, false);
			}
		});

		// Tag-generator live shortcode building (admin).
		var $classField = $('input[name="classname"]');
		if ($classField.length) {
			var $tagField = $classField.closest(".control-box").siblings(".insert-box").find("input.tag");
			if ($tagField.length) {
				var match = $tagField.val().match(/\[dscf7captcha\s+([^\s\]]+)/);
				var base = match ? "[dscf7captcha " + match[1] : $tagField.val();
				$classField.on("input", function () {
					buildShortcode($classField, $tagField, base);
				});
				buildShortcode($classField, $tagField, base);
			}
		}
	});
})(jQuery);
