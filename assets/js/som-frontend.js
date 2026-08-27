(function($) {
	'use strict';

	$(document).ready(function() {
		var $form = $('#som_onboarding_form');
		if (!$form.length) return;

		var $gpsStatus = $('#som_gps_status_msg');
		var $dupWarning = $('#som_duplicate_warning');
		var $dupList = $('#som_duplicate_list');
		var $msg = $('#som_form_message');
		var debounceTimer = null;

		// Check if protocol is HTTP on custom domain
		var isSecure = window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

		// 1. GPS Geolocation Handler
		$('#som_btn_get_location').on('click', function(e) {
			e.preventDefault();
			if (!navigator.geolocation) {
				showGpsStatus(somConfig.i18n.locationError, 'error');
				return;
			}

			showGpsStatus(somConfig.i18n.locationFetching, 'info');

			navigator.geolocation.getCurrentPosition(
				function(position) {
					var lat = position.coords.latitude;
					var lng = position.coords.longitude;
					var acc = position.coords.accuracy;

					$('#som_f_latitude').val(lat.toFixed(6));
					$('#som_f_longitude').val(lng.toFixed(6));
					$('#som_f_gps_accuracy').val(acc.toFixed(1));

					showGpsStatus(somConfig.i18n.locationSuccess + ' (' + acc.toFixed(1) + 'm)', 'success');
				},
				function(err) {
					var errMsg = err.message || '';
					if (errMsg.indexOf('secure origins') !== -1 || !isSecure) {
						showGpsStatus('⚠️ Browser GPS auto-capture requires HTTPS or localhost on domain "nearmart.local". You can type or paste Latitude & Longitude directly into the fields below.', 'info');
					} else {
						showGpsStatus(somConfig.i18n.locationError + ' (' + errMsg + ')', 'error');
					}
				},
				{
					enableHighAccuracy: true,
					timeout: 10000,
					maximumAge: 0
				}
			);
		});

		function showGpsStatus(text, type) {
			$gpsStatus.removeClass('success error info').addClass(type).text(text).show();
		}

		// 2. Photo Upload Preview
		$('#som_f_shop_photo').on('change', function(e) {
			var file = e.target.files[0];
			if (file) {
				var reader = new FileReader();
				reader.onload = function(evt) {
					$('#som_photo_img').attr('src', evt.target.result);
					$('#som_photo_preview_container').show();
				};
				reader.readAsDataURL(file);
			}
		});

		$('#som_remove_photo').on('click', function(e) {
			e.preventDefault();
			$('#som_f_shop_photo').val('');
			$('#som_photo_img').attr('src', '');
			$('#som_photo_preview_container').hide();
		});

		// 3. Live Duplicate Check
		$('#som_f_phone, #som_f_shop_name').on('keyup input', function() {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(checkDuplicates, 600);
		});

		function checkDuplicates() {
			var phone = $('#som_f_phone').val().trim();
			var shopName = $('#som_f_shop_name').val().trim();
			var address = $('#som_f_address').val().trim();

			if (!phone && !shopName) {
				$dupWarning.hide();
				return;
			}

			$.ajax({
				url: somConfig.ajaxUrl,
				type: 'POST',
				data: {
					action: 'som_check_duplicate',
					nonce: somConfig.nonce,
					phone: phone,
					shop_name: shopName,
					address: address
				},
				success: function(response) {
					if (response.success && response.data.has_duplicate) {
						var html = '';
						$.each(response.data.matches, function(idx, match) {
							html += '<div class="som-duplicate-item">';
							html += '<strong>' + escapeHtml(match.title) + '</strong> ';
							if (match.owner) html += ' - Owner: ' + escapeHtml(match.owner);
							if (match.phone) html += ' - Phone: ' + escapeHtml(match.phone);
							html += ' <em>(' + escapeHtml(match.reason) + ')</em>';
							html += '</div>';
						});
						$dupList.html(html);
						$dupWarning.show();
					} else {
						$dupWarning.hide();
					}
				}
			});
		}

		function escapeHtml(str) {
			return str ? $('<div>').text(str).html() : '';
		}

		// 4. Form Submission Handler
		$form.on('submit', function(e) {
			e.preventDefault();
			var $btn = $('#som_btn_submit');
			$btn.prop('disabled', true).text(somConfig.i18n.submitting);
			$msg.hide();

			var formData = new FormData(this);
			formData.append('action', 'som_submit_shop');
			formData.append('nonce', somConfig.nonce);

			$.ajax({
				url: somConfig.ajaxUrl,
				type: 'POST',
				data: formData,
				contentType: false,
				processData: false,
				success: function(response) {
					$btn.prop('disabled', false).html('🚀 Register Shop');
					if (response.success) {
						$msg.removeClass('error').addClass('success').text(response.data.message).show();
						$form[0].reset();
						$('#som_photo_preview_container').hide();
						$gpsStatus.hide();
						$dupWarning.hide();
						window.scrollTo({ top: $form.offset().top - 40, behavior: 'smooth' });
					} else {
						$msg.removeClass('success').addClass('error').text(response.data.message || 'Error saving shop.').show();
					}
				},
				error: function() {
					$btn.prop('disabled', false).html('🚀 Register Shop');
					$msg.removeClass('success').addClass('error').text('Server error. Please try again.').show();
				}
			});
		});
	});
})(jQuery);