/* HRD SMS Auth — admin settings UI (tabs, color pickers, media, tools). */
jQuery(function ($) {
	'use strict';

	/* ---- Tabs ---- */
	var $tabs = $('.hrd-tabs .nav-tab');
	var $panels = $('.hrd-tab-panel');

	function activate(tab) {
		$tabs.removeClass('nav-tab-active').filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');
		$panels.removeClass('is-active').filter('[data-panel="' + tab + '"]').addClass('is-active');
	}

	$tabs.on('click', function (e) {
		e.preventDefault();
		activate($(this).data('tab'));
	});

	/* ---- Color pickers ---- */
	if ($.fn.wpColorPicker) {
		$('.hrd-color-field').wpColorPicker();
	}

	/* ---- Media uploader ---- */
	$('.hrd-media-upload').on('click', function (e) {
		e.preventDefault();
		var $input = $($(this).data('target'));
		if (!$input.length || typeof wp === 'undefined' || !wp.media) { return; }

		var frame = wp.media({
			title: (window.smsAuthAdmin && smsAuthAdmin.media.title) || 'Select image',
			button: { text: (window.smsAuthAdmin && smsAuthAdmin.media.button) || 'Use image' },
			multiple: false
		});
		frame.on('select', function () {
			var url = frame.state().get('selection').first().toJSON().url;
			$input.val(url).trigger('change');
			var $preview = $input.siblings('.hrd-media-preview');
			if (!$preview.length) {
				$preview = $('<img class="hrd-media-preview" alt="" />').insertAfter($input.nextAll('.hrd-media-upload').first());
			}
			$preview.attr('src', url);
		});
		frame.open();
	});

	/* ---- Conditional rows: attempt limit ---- */
	var $limitToggle = $('input[name="hrd_sms_auth_options[enable_attempt_limit]"]');
	function toggleLimit() {
		$('.hrd-attempt-field').toggle($limitToggle.is(':checked'));
	}
	if ($limitToggle.length) { toggleLimit(); $limitToggle.on('change', toggleLimit); }

	/* ---- Conditional rows: background type ---- */
	var $bgType = $('select[name="hrd_sms_auth_options[bg_type]"]');
	function toggleBg() {
		$('.hrd-bg-solid, .hrd-bg-gradient, .hrd-bg-image').hide();
		$('.hrd-bg-' + $bgType.val()).show();
	}
	if ($bgType.length) { toggleBg(); $bgType.on('change', toggleBg); }

	/* ---- SMS template parameters (repeatable rows) ---- */
	$(document).on('click', '.hrd-param-add', function () {
		var tpl = $('#hrd-param-tpl').html();
		if (!tpl) { return; }
		$('.hrd-params-body').append(tpl.replace(/__i__/g, 'n' + Date.now()));
	});
	$(document).on('click', '.hrd-param-remove', function () {
		$(this).closest('tr').remove();
	});

	/* ---- Tool: Digits migration ---- */
	function migReport(s) {
		if (typeof s.reverted !== 'undefined') {
			return 'بازگردانی انجام شد. تعداد کاربران بازگردانده‌شده: ' + s.reverted;
		}
		return 'کل شماره‌های یافت‌شده: ' + s.total +
			'\nقابل مهاجرت: ' + s.migratable +
			'\nدارای شماره از قبل: ' + s.already +
			'\nنامعتبر: ' + s.invalid +
			'\nتداخل (تکراری/متعلق به کاربر دیگر): ' + s.conflicts +
			(typeof s.migrated !== 'undefined' ? '\nمنتقل‌شده: ' + s.migrated : '');
	}

	function migRun(action, extra, confirmMsg) {
		if (confirmMsg && !window.confirm(confirmMsg)) { return; }
		var $r = $('#hrd_mig_result');
		$r.text('در حال پردازش...');
		$.post(smsAuthAdmin.ajaxUrl, $.extend({ action: action, nonce: smsAuthAdmin.nonce }, extra || {}))
			.done(function (res) {
				$r.text(res && res.success ? migReport(res.data) : ((res.data && res.data.message) || 'خطا در عملیات.'));
			})
			.fail(function () { $r.text('خطا در ارتباط با سرور.'); });
	}

	$('#hrd_mig_preview').on('click', function () { migRun('hrd_digits_preview'); });
	$('#hrd_mig_apply').on('click', function () {
		migRun('hrd_digits_apply', {
			overwrite: $('#hrd_mig_overwrite').is(':checked') ? 1 : 0,
			mark_verified: $('#hrd_mig_mark').is(':checked') ? 1 : 0
		}, 'مهاجرت انجام شود؟ قبل از تغییر، نسخهٔ پشتیبان از شماره‌های فعلی گرفته می‌شود.');
	});
	$('#hrd_mig_revert').on('click', function () {
		migRun('hrd_digits_revert', {}, 'شماره‌ها از نسخهٔ پشتیبان بازگردانده شوند؟');
	});

	/* ---- Tool: test SMS ---- */
	$('#send_test_sms').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#test_sms_result').text('در حال ارسال...');
		$.post(smsAuthAdmin.ajaxUrl, {
			action: 'admin_send_test_sms',
			mobile: $('#test_mobile').val(),
			nonce: smsAuthAdmin.nonce
		}, function (res) {
			$('#test_sms_result').text(res.data && res.data.message ? res.data.message : '');
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- Tool: WooCommerce sync ---- */
	$('#sync_wc_users').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#sync_wc_users_result').text('در حال پردازش...');
		$.post(smsAuthAdmin.ajaxUrl, {
			action: 'sync_woocommerce_users',
			nonce: smsAuthAdmin.nonce
		}, function (res) {
			$('#sync_wc_users_result').text(res.data && res.data.message ? res.data.message : '');
		}).always(function () { $btn.prop('disabled', false); });
	});
});
