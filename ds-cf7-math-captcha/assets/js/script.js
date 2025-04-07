;(function ($) {
	'use strict'
	const refreshCaptcha = ($container, tagName, showLoading = true) => {
	  $.ajax({
		type: 'POST',
		url: ajax_object.ajax_url,
		data: {
		  action: 'dscf7_refreshcaptcha',
		  tagname: tagName,
		  ds_cf7_nonce: ajax_object.nonce
		},
		beforeSend: () => {
		  if (showLoading) {
			$container.find('.dscf7_captcha_reload_icon').show()
			$container.find('.dscf7_captcha_icon').hide()
		  }
		},
		success: response => {
		  $container.html(response)
		  $container.find('.dscf7_captcha_reload_icon').hide()
		  $container.find('.dscf7_captcha_icon').show()
		  $container
			.find('.dscf7_refresh_captcha')
			.attr('href', 'javascript:void(0);')
		},
		error: (xhr, status, error) => {
		  console.error('Captcha refresh failed:', error)
		}
	  })
	}
	$(document).on('click', '.dscf7_refresh_captcha', function (e) {
	  e.preventDefault()
	  const $captchaWrapper = $(this).closest('.dscf7captcha')
	  const tagName = $(this).attr('id')
	  refreshCaptcha($captchaWrapper, tagName, true)
	})
  })(jQuery)
  