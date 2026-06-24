(function ($) {
	"use strict";

	var DEFAULT_COLOR = "#2271b1";

	/**
	 * Current border colour from the picker field (falls back to default).
	 *
	 * @returns {string}
	 */
	function currentColor() {
		var val = $(".dscf7-color-field").val();
		return val ? val : DEFAULT_COLOR;
	}

	/**
	 * Push a border colour onto the live preview.
	 *
	 * @param {string} color
	 */
	function updatePreviewBorder(color) {
		$("#dscf7-preview-captcha").css("border-color", color || DEFAULT_COLOR);
	}

	/**
	 * Apply a style theme to the live preview, highlight the selected card and
	 * show the colour option only for the bordered style.
	 *
	 * @param {string} style classic | dark | bordered
	 */
	function applyStyle(style) {
		var $preview = $("#dscf7-preview-captcha");
		$preview
			.removeClass("dscf7-style-classic dscf7-style-dark dscf7-style-bordered")
			.addClass("dscf7-style-" + style);

		$(".dscf7-style-card").removeClass("selected");
		$('.dscf7-style-card[data-style="' + style + '"]').addClass("selected");

		// Custom colour only applies to the bordered theme.
		$(".dscf7-color-row").toggle(style === "bordered");
		if (style === "bordered") {
			updatePreviewBorder(currentColor());
		}
	}

	$(document).on("change", 'input[name="dscf7_settings[style]"]', function () {
		applyStyle($(this).val());
	});

	$(function () {
		// Initialise the WordPress colour picker when available.
		var $field = $(".dscf7-color-field");
		if ($field.length && $.fn.wpColorPicker) {
			$field.wpColorPicker({
				change: function (event, ui) {
					updatePreviewBorder(ui.color.toString());
				},
				clear: function () {
					updatePreviewBorder(DEFAULT_COLOR);
				}
			});
		}

		var selected = $('input[name="dscf7_settings[style]"]:checked').val() || "classic";
		applyStyle(selected);
	});
})(jQuery);
