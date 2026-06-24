(function ($) {
	"use strict";

	// Base classes added to the answer input by the server; everything left
	// after stripping these is treated as the user's custom class.
	var BASE_CLASSES = [
		"wpcf7-form-control",
		"wpcf7-text",
		"dscf7_answer",
		"wpcf7-validates-as-required"
	];

	/**
	 * Recover the user's custom classes from the answer input's class list.
	 *
	 * @param {jQuery} $answer The answer input.
	 * @returns {string}
	 */
	function customClassOf($answer) {
		return ($answer.attr("class") || "")
			.split(/\s+/)
			.filter(function (cls) {
				return cls.length > 0 && BASE_CLASSES.indexOf(cls) === -1;
			})
			.join(" ");
	}

	/**
	 * Refresh a single captcha block via AJAX.
	 *
	 * @param {jQuery}  $link    The clicked refresh anchor (or the anchor in a block).
	 * @param {boolean} spinner  Whether to show the loading spinner.
	 */
	function refreshCaptcha($link, spinner) {
		var $container = $link.closest(".dscf7-captcha-container");
		var tagName = $link.attr("id");
		// The answer input is located by its stable class, not its (randomized) name.
		var $answer = $container.find("input.dscf7_answer");
		var customClass = customClassOf($answer);

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
				var $newAnswer = $new.find("input.dscf7_answer");

				// Swap the SVG question, the signed token, the nonce and the label.
				$container.find(".dscf7_svg_wrap").html($new.find(".dscf7_svg_wrap").html());
				$container.find(".dscf7_token").val($new.find(".dscf7_token").val());
				$container.find('input[name="ds_cf7_nonce"]').val($new.find('input[name="ds_cf7_nonce"]').val());
				$container.find("label.screen-reader-text").text($new.find("label.screen-reader-text").text());

				// Sync the randomized name/id and the aria-label onto the answer
				// input, point the label at the new id, and clear the value.
				var newName = $newAnswer.attr("name");
				var newId = $newAnswer.attr("id");
				var newAria = $newAnswer.attr("aria-label");
				if (newName) {
					$answer.attr("name", newName);
				}
				if (newId) {
					$answer.attr("id", newId);
					$container.find("label.screen-reader-text").attr("for", newId);
				}
				$answer.attr("aria-label", newAria).val("");
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

	/**
	 * Human-interaction detection. On the first genuine mouse, touch or key
	 * event, flag every captcha block as "interacted". Degrades gracefully:
	 * with JS off the flag stays "0" and enforcement is opt-in server-side, so
	 * accessibility tools and no-JS users are never blocked by default.
	 */
	function bindInteraction() {
		var marked = false;
		function mark() {
			if (marked) {
				return;
			}
			marked = true;
			$(".dscf7_interaction").val("1");
			document.removeEventListener("mousemove", mark, true);
			document.removeEventListener("touchstart", mark, true);
			document.removeEventListener("keydown", mark, true);
		}
		document.addEventListener("mousemove", mark, true);
		document.addEventListener("touchstart", mark, true);
		document.addEventListener("keydown", mark, true);
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
		bindInteraction();

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
