/**
 * HRD SMS Auth — dependency-free login/registration widget.
 *
 * Four screens (mobile → OTP → profile → success) rendered into the
 * server-side card shell ([data-hrd-sms-auth]). Talks only to the REST API.
 *
 * Security: account existence is NOT revealed at the mobile step. New users are
 * sent to the profile screen only AFTER a correct OTP (server replies
 * needs_registration), which keeps the OTP valid for the final submit.
 */
(function () {
	'use strict';
	if (typeof window.hrdSms === 'undefined') { return; }

	var cfg = window.hrdSms;
	var TEXT = cfg.text || {};
	var CODE_LENGTH = Math.max(4, parseInt(cfg.codeLength, 10) || 6);
	var TIMER_SECONDS = Math.max(30, parseInt(cfg.timer, 10) || 120);
	var VERIFY_MODE = cfg.mode === 'verify';
	// wp_localize_script stringifies scalars, so collectProfile arrives as '1'/'0'.
	var COLLECT_PROFILE = !VERIFY_MODE && (cfg.collectProfile === 1 || cfg.collectProfile === '1' || cfg.collectProfile === true);

	var FA = '۰۱۲۳۴۵۶۷۸۹';
	var toEn = function (s) { return String(s == null ? '' : s).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); }).replace(/[^\d]/g, ''); };
	var toFa = function (s) { return String(s).replace(/\d/g, function (d) { return FA[+d]; }); };

	var SVG = {
		phone: '<svg viewBox="0 0 24 24" fill="none"><rect x="6" y="2.5" width="12" height="19" rx="3" stroke="currentColor" stroke-width="1.6"/><line x1="10.5" y1="18.5" x2="13.5" y2="18.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		id: '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="3" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="11" r="2" stroke="currentColor" stroke-width="1.6"/><path d="M13.5 10h4M13.5 13.5h4M5.5 15.5c.6-1.3 2-1.7 3-1.7s2.4.4 3 1.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		err: '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5v5.5M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		back: '<svg viewBox="0 0 24 24" fill="none"><path d="M15 5l-7 7 7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		check: '<svg viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
	};

	function validMobile(m) { return /^09\d{9}$/.test(m); }
	function validNationalId(code) {
		if (!/^\d{10}$/.test(code)) { return false; }
		if (/^(\d)\1{9}$/.test(code)) { return false; }
		var sum = 0;
		for (var i = 0; i < 9; i++) { sum += +code[i] * (10 - i); }
		var r = sum % 11, c = +code[9];
		return r < 2 ? c === r : c === (11 - r);
	}
	function errHtml() { return '<div class="hrd-err">' + SVG.err + '<span class="hrd-err-msg"></span></div>'; }
	function safeRedirectTarget() {
		var origin = window.location.origin;
		function check(u) { if (!u) { return null; } try { var p = new URL(u, origin); return p.origin === origin ? p.href : null; } catch (e) { return null; } }
		var param = new URLSearchParams(window.location.search).get('redirect_to');
		return check(param) || check(document.referrer) || (cfg.homeUrl || origin + '/');
	}
	function api(path, payload) {
		return fetch(cfg.api + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(payload)
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, status: res.status, data: d }; });
		}).catch(function () { return { ok: false, status: 0, data: { message: 'خطا در ارتباط با سرور. اتصال اینترنت را بررسی کنید.' } }; });
	}

	var FORMS = Array.isArray(cfg.forms) && cfg.forms.length ? cfg.forms : ['mobile'];

	function Widget(mount) {
		this.mount = mount;
		this.pane = mount; // forms render here; replaced by a sub-pane when tabbed
		this.state = { mobile: '', code: '', timerId: null, remain: 0 };

		if (VERIFY_MODE) {
			this.renderMobile();        // post-login mobile verification
		} else {
			this.renderRoot();          // login mode decides the form(s)
		}
	}
	var P = Widget.prototype;

	// Build the chooser: a single form, or tabs when more than one is enabled.
	P.renderRoot = function () {
		var self = this;
		if (FORMS.length > 1) {
			var labels = { mobile: 'ورود با موبایل', email: 'ورود با ایمیل' };
			var tabs = '';
			FORMS.forEach(function (f) { tabs += '<button type="button" class="hrd-tab" data-form="' + f + '">' + (labels[f] || f) + '</button>'; });
			this.pane.innerHTML = '<div class="hrd-tabs" role="tablist">' + tabs + '</div><div data-hrd-pane></div>';
			this.pane = this.mount.querySelector('[data-hrd-pane]');
			this.tabs = Array.prototype.slice.call(this.mount.querySelectorAll('.hrd-tab'));
			this.tabs.forEach(function (t) { t.addEventListener('click', function () { self.activateForm(t.getAttribute('data-form')); }); });
			this.activateForm(FORMS[0]);
		} else {
			this.pane = this.mount;
			this.renderForm(FORMS[0]);
		}
	};
	P.activateForm = function (name) {
		if (this.tabs) { this.tabs.forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-form') === name); }); }
		this.stopTimer();
		this.renderForm(name);
	};
	P.renderForm = function (name) { if (name === 'email') { this.renderEmail(); } else { this.renderMobile(); } };

	P.q = function (s) { return this.pane.querySelector(s); };
	P.busy = function (btn, on) { if (!btn) { return; } btn.classList.toggle('is-loading', on); btn.disabled = on; };
	P.fieldError = function (fieldSel, msg) {
		var f = this.q(fieldSel); if (!f) { return; }
		f.classList.add('is-invalid');
		var m = f.querySelector('.hrd-err-msg'); if (m) { m.textContent = msg; }
		var c = f.querySelector('.hrd-control'); if (c) { c.classList.add('is-bad'); }
	};
	P.clearError = function (fieldSel) {
		var f = this.q(fieldSel); if (!f) { return; }
		f.classList.remove('is-invalid');
		var c = f.querySelector('.hrd-control'); if (c) { c.classList.remove('is-bad'); }
	};
	P.stopTimer = function () { if (this.state.timerId) { clearInterval(this.state.timerId); this.state.timerId = null; } };

	/* ---------------- Screen 1: mobile ---------------- */
	P.renderMobile = function () {
		this.stopTimer();
		this.pane.innerHTML =
			'<section class="hrd-screen">' +
				'<div class="hrd-head">' +
					'<div class="hrd-step"><span class="hrd-step-dot"></span>ورود یا ثبت نام</div>' +
					'<h1 class="hrd-h1">' + esc(TEXT.title || 'به حساب خود وارد شوید') + '</h1>' +
					'<p class="hrd-lead">' + esc(TEXT.subtitle || 'شماره موبایل خود را وارد کنید.') + '</p>' +
				'</div>' +
				'<div class="hrd-field" data-field="mobile">' +
					'<label class="hrd-field-label" for="hrd-mobile">شماره موبایل</label>' +
					'<div class="hrd-control">' +
						'<span class="hrd-control-pre">' + SVG.phone + '+۹۸</span>' +
						'<input id="hrd-mobile" class="hrd-input hrd-ltr" type="tel" inputmode="numeric" placeholder="۰۹۱۲۳۴۵۶۷۸۹" maxlength="13" autocomplete="tel">' +
					'</div>' + errHtml() +
				'</div>' +
				'<button class="hrd-btn" data-act="send"><span class="hrd-spin"></span><span class="hrd-btn-label">ادامه</span></button>' +
			'</section>';

		var self = this, input = this.q('#hrd-mobile'), btn = this.q('[data-act="send"]');
		input.addEventListener('input', function () { input.value = toFa(toEn(input.value).slice(0, 11)); self.clearError('[data-field="mobile"]'); });
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });
		btn.addEventListener('click', function () { self.send(toEn(input.value), btn); });
		input.focus();
	};

	P.send = function (mobile, btn) {
		var self = this;
		if (!mobile) { return this.fieldError('[data-field="mobile"]', 'وارد کردن شماره موبایل الزامی است.'); }
		if (!validMobile(mobile)) { return this.fieldError('[data-field="mobile"]', 'شماره موبایل باید با ۰۹ شروع شده و ۱۱ رقم باشد.'); }
		this.state.mobile = mobile;
		this.busy(btn, true);
		api('/send-code', { mobile: mobile }).then(function (res) {
			self.busy(btn, false);
			if (res.ok) { self.renderOtp(); }
			else { self.fieldError('[data-field="mobile"]', (res.data && res.data.message) || 'ارسال کد ناموفق بود.'); }
		});
	};

	/* ---------------- Screen 2: OTP ---------------- */
	P.renderOtp = function () {
		var boxes = '';
		for (var i = 0; i < CODE_LENGTH; i++) {
			boxes += '<input type="text" inputmode="numeric" maxlength="1" aria-label="رقم ' + toFa(i + 1) + '">';
		}
		this.pane.innerHTML =
			'<section class="hrd-screen">' +
				'<button class="hrd-back" type="button" data-act="to-mobile" aria-label="بازگشت">' + SVG.back + '</button>' +
				'<div class="hrd-head">' +
					'<div class="hrd-step"><span class="hrd-step-dot"></span>تأیید شماره</div>' +
					'<h1 class="hrd-h1">کد تأیید را وارد کنید</h1>' +
					'<p class="hrd-lead">کد ' + toFa(CODE_LENGTH) + ' رقمی به این شماره ارسال شد:</p>' +
					'<div class="hrd-phone-tag"><span class="hrd-num">' + toFa(this.state.mobile) + '</span><button type="button" data-act="to-mobile">ویرایش</button></div>' +
				'</div>' +
				'<div class="hrd-otp">' + boxes + '</div>' +
				'<div class="hrd-err hrd-otp-err">' + SVG.err + '<span class="hrd-err-msg"></span></div>' +
				'<div class="hrd-resend">' +
					'<span class="hrd-resend-wait">ارسال مجدد کد تا <span class="hrd-timer"></span></span>' +
					'<button type="button" class="hrd-resend-btn" disabled>ارسال مجدد کد</button>' +
				'</div>' +
				'<button class="hrd-btn" data-act="verify" style="margin-top:20px"><span class="hrd-spin"></span><span class="hrd-btn-label">تأیید و ورود</span></button>' +
			'</section>';

		var self = this;
		this.otpInputs = Array.prototype.slice.call(this.pane.querySelectorAll('.hrd-otp input'));
		this.otpBox = this.q('.hrd-otp');
		this.bindOtp();
		this.pane.querySelectorAll('[data-act="to-mobile"]').forEach(function (b) { b.addEventListener('click', function () { self.stopTimer(); self.renderMobile(); }); });
		this.q('[data-act="verify"]').addEventListener('click', function () { self.verify(); });
		this.q('.hrd-resend-btn').addEventListener('click', function (e) { self.resend(e.target); });
		this.startTimer();
		this.otpInputs[0].focus();
	};

	P.bindOtp = function () {
		var self = this, inputs = this.otpInputs;
		inputs.forEach(function (inp, idx) {
			inp.addEventListener('input', function (e) {
				self.clearOtpErr();
				var v = toEn(e.target.value);
				if (v.length > 1) { self.distribute(v, idx); return; }
				e.target.value = v ? toFa(v) : '';
				e.target.classList.toggle('is-filled', !!v);
				if (v && idx < inputs.length - 1) { inputs[idx + 1].focus(); }
				if (self.otpValue().length === CODE_LENGTH) { self.verify(); }
			});
			inp.addEventListener('keydown', function (e) {
				if (e.key === 'Backspace' && !e.target.value && idx > 0) { inputs[idx - 1].focus(); inputs[idx - 1].value = ''; inputs[idx - 1].classList.remove('is-filled'); }
				if (e.key === 'ArrowLeft' && idx < inputs.length - 1) { inputs[idx + 1].focus(); }
				if (e.key === 'ArrowRight' && idx > 0) { inputs[idx - 1].focus(); }
			});
			inp.addEventListener('paste', function (e) { e.preventDefault(); self.distribute(toEn((e.clipboardData || window.clipboardData).getData('text')), idx); });
		});
	};
	P.distribute = function (digits, from) {
		var inputs = this.otpInputs;
		digits = digits.slice(0, inputs.length - from);
		for (var i = 0; i < digits.length; i++) { inputs[from + i].value = toFa(digits[i]); inputs[from + i].classList.add('is-filled'); }
		inputs[Math.min(from + digits.length, inputs.length - 1)].focus();
		if (this.otpValue().length === CODE_LENGTH) { this.verify(); }
	};
	P.otpValue = function () { return this.otpInputs.map(function (i) { return toEn(i.value); }).join(''); };
	P.clearOtpErr = function () { var e = this.q('.hrd-otp-err'); if (e) { e.style.display = 'none'; } this.otpBox.classList.remove('is-bad'); };
	P.showOtpErr = function (msg) {
		var e = this.q('.hrd-otp-err'); e.querySelector('.hrd-err-msg').textContent = msg; e.style.display = 'flex';
		this.otpBox.classList.add('is-bad'); var self = this; setTimeout(function () { self.otpBox.classList.remove('is-bad'); }, 420);
	};

	P.verify = function () {
		var self = this, code = this.otpValue(), btn = this.q('[data-act="verify"]');
		if (code.length < CODE_LENGTH) { return this.showOtpErr('کد ' + toFa(CODE_LENGTH) + ' رقمی را کامل وارد کنید.'); }
		if (this.state.verifying) { return; }
		this.state.verifying = true;
		this.state.code = code;
		this.busy(btn, true);

		// Verify mode (logged-in user) attaches the mobile; login mode signs in.
		var endpoint = VERIFY_MODE ? '/attach-mobile' : '/verify-code';
		api(endpoint, { mobile: this.state.mobile, code: code }).then(function (res) {
			self.state.verifying = false;
			self.busy(btn, false);
			if (res.ok) { self.stopTimer(); return self.renderSuccess(false); }
			if (!VERIFY_MODE && res.data && res.data.needs_registration) { self.stopTimer(); return self.renderProfile(); }
			self.showOtpErr((res.data && res.data.message) || 'کد وارد شده صحیح نیست.');
			self.otpInputs.forEach(function (i) { i.value = ''; i.classList.remove('is-filled'); });
			self.otpInputs[0].focus();
		});
	};

	/* ---------------- resend timer ---------------- */
	P.fmt = function (s) { return toFa(String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0')); };
	P.startTimer = function () {
		var self = this; this.stopTimer(); this.state.remain = TIMER_SECONDS;
		var wait = this.q('.hrd-resend-wait'), btn = this.q('.hrd-resend-btn'), timer = this.q('.hrd-timer');
		timer.textContent = this.fmt(this.state.remain); wait.style.display = 'inline'; btn.style.display = 'none'; btn.disabled = true;
		this.state.timerId = setInterval(function () {
			self.state.remain--; timer.textContent = self.fmt(self.state.remain);
			if (self.state.remain <= 0) { self.stopTimer(); wait.style.display = 'none'; btn.style.display = 'inline'; btn.disabled = false; }
		}, 1000);
	};
	P.resend = function (btn) {
		var self = this; btn.disabled = true; btn.textContent = 'در حال ارسال…';
		api('/send-code', { mobile: this.state.mobile }).then(function (res) {
			btn.textContent = 'ارسال مجدد کد';
			if (res.ok) {
				self.clearOtpErr();
				self.otpInputs.forEach(function (i) { i.value = ''; i.classList.remove('is-filled'); });
				self.otpInputs[0].focus(); self.startTimer();
			} else { self.showOtpErr((res.data && res.data.message) || 'ارسال مجدد ناموفق بود.'); btn.disabled = false; }
		});
	};

	/* ---------------- Screen 3: profile (new users) ---------------- */
	P.renderProfile = function () {
		var profileFields = '';
		if (COLLECT_PROFILE) {
			profileFields =
				'<div class="hrd-field" data-field="birth">' +
					'<label class="hrd-field-label">تاریخ تولد (شمسی)</label>' +
					'<div class="hrd-jdate">' +
						'<div class="hrd-sel"><select data-b="year"><option value="" selected>سال</option></select></div>' +
						'<div class="hrd-sel"><select data-b="month"><option value="" selected>ماه</option></select></div>' +
						'<div class="hrd-sel"><select data-b="day"><option value="" selected>روز</option></select></div>' +
					'</div>' + errHtml() +
				'</div>' +
				'<div class="hrd-field" data-field="nid">' +
					'<label class="hrd-field-label" for="hrd-nid">کد ملی</label>' +
					'<div class="hrd-control"><span class="hrd-control-pre">' + SVG.id + '</span>' +
						'<input id="hrd-nid" class="hrd-input hrd-ltr" type="text" inputmode="numeric" placeholder="کد ملی ۱۰ رقمی" maxlength="10" autocomplete="off"></div>' +
					errHtml() +
				'</div>';
		}
		this.pane.innerHTML =
			'<section class="hrd-screen">' +
				'<button class="hrd-back" type="button" data-act="to-otp" aria-label="بازگشت">' + SVG.back + '</button>' +
				'<div class="hrd-head">' +
					'<div class="hrd-step"><span class="hrd-step-dot"></span>تکمیل ثبت نام</div>' +
					'<h1 class="hrd-h1">اطلاعات خود را وارد کنید</h1>' +
					'<p class="hrd-lead">برای ساخت حساب جدید، مشخصات زیر را تکمیل کنید.</p>' +
				'</div>' +
				'<div class="hrd-row2">' +
					'<div class="hrd-field" data-field="fname"><label class="hrd-field-label" for="hrd-fname">نام</label><div class="hrd-control"><input id="hrd-fname" class="hrd-input" type="text" placeholder="نام" autocomplete="given-name"></div>' + errHtml() + '</div>' +
					'<div class="hrd-field" data-field="lname"><label class="hrd-field-label" for="hrd-lname">نام خانوادگی</label><div class="hrd-control"><input id="hrd-lname" class="hrd-input" type="text" placeholder="نام خانوادگی" autocomplete="family-name"></div>' + errHtml() + '</div>' +
				'</div>' + profileFields +
				'<button class="hrd-btn" data-act="register"><span class="hrd-spin"></span><span class="hrd-btn-label">تکمیل ثبت نام و ورود</span></button>' +
			'</section>';

		var self = this;
		this.q('[data-act="to-otp"]').addEventListener('click', function () { self.renderOtp(); });
		['fname', 'lname'].forEach(function (f) { self.q('#hrd-' + f).addEventListener('input', function () { self.clearError('[data-field="' + f + '"]'); }); });
		if (COLLECT_PROFILE) {
			this.buildDates();
			var nid = this.q('#hrd-nid');
			nid.addEventListener('input', function () { nid.value = toFa(toEn(nid.value).slice(0, 10)); self.clearError('[data-field="nid"]'); });
		}
		this.q('[data-act="register"]').addEventListener('click', function (e) { self.register(e.currentTarget); });
		this.q('#hrd-fname').focus();
	};

	P.buildDates = function () {
		var months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
		var ySel = this.q('[data-b="year"]'), mSel = this.q('[data-b="month"]'), dSel = this.q('[data-b="day"]');
		var nowJy = new Date().getFullYear() - 621;
		for (var y = nowJy - 12; y >= 1320; y--) { ySel.insertAdjacentHTML('beforeend', '<option value="' + y + '">' + toFa(y) + '</option>'); }
		months.forEach(function (m, i) { mSel.insertAdjacentHTML('beforeend', '<option value="' + (i + 1) + '">' + m + '</option>'); });
		var self = this;
		function maxDay() { var m = +mSel.value; if (!m) { return 31; } return m <= 6 ? 31 : 30; }
		function fillDays() {
			var cur = dSel.value; dSel.innerHTML = '<option value="" selected>روز</option>';
			for (var d = 1; d <= maxDay(); d++) { dSel.insertAdjacentHTML('beforeend', '<option value="' + d + '">' + toFa(d) + '</option>'); }
			if (cur && +cur <= maxDay()) { dSel.value = cur; }
			ph(dSel);
		}
		function ph(s) { s.classList.toggle('is-placeholder', s.value === ''); }
		[ySel, mSel, dSel].forEach(function (s) { s.addEventListener('change', function () { ph(s); self.clearError('[data-field="birth"]'); }); });
		mSel.addEventListener('change', fillDays);
		fillDays(); ph(ySel); ph(mSel); ph(dSel);
		this._dates = { ySel: ySel, mSel: mSel, dSel: dSel };
	};

	P.register = function (btn) {
		var self = this, ok = true;
		var first = this.q('#hrd-fname').value.trim(), last = this.q('#hrd-lname').value.trim();
		if (!first) { this.fieldError('[data-field="fname"]', 'الزامی'); ok = false; }
		if (!last) { this.fieldError('[data-field="lname"]', 'الزامی'); ok = false; }

		var payload = { mobile: this.state.mobile, code: this.state.code, first_name: first, last_name: last };

		if (COLLECT_PROFILE) {
			var d = this._dates;
			if (!d.ySel.value || !d.mSel.value || !d.dSel.value) { this.fieldError('[data-field="birth"]', 'تاریخ تولد را کامل انتخاب کنید.'); ok = false; }
			var nid = toEn(this.q('#hrd-nid').value);
			if (!nid) { this.fieldError('[data-field="nid"]', 'وارد کردن کد ملی الزامی است.'); ok = false; }
			else if (nid.length !== 10) { this.fieldError('[data-field="nid"]', 'کد ملی باید دقیقاً ۱۰ رقم باشد.'); ok = false; }
			else if (!validNationalId(nid)) { this.fieldError('[data-field="nid"]', 'کد ملی وارد شده معتبر نیست.'); ok = false; }
			if (ok) {
				payload.national_code = nid;
				payload.birth_date = d.ySel.value + '/' + ('0' + d.mSel.value).slice(-2) + '/' + ('0' + d.dSel.value).slice(-2);
			}
		}
		if (!ok) { return; }

		this.busy(btn, true);
		api('/verify-code', payload).then(function (res) {
			self.busy(btn, false);
			if (res.ok) { return self.renderSuccess(true); }
			if (res.status === 410) { self.renderMobile(); return; }
			var field = res.data && res.data.field ? res.data.field : 'nid';
			self.fieldError('[data-field="' + (field === 'national_code' ? 'nid' : field) + '"]', (res.data && res.data.message) || 'ثبت نام ناموفق بود.');
		});
	};

	/* ---------------- Screen 4: success ---------------- */
	P.renderSuccess = function (isNew) {
		this.pane.innerHTML =
			'<section class="hrd-screen"><div class="hrd-success">' +
				'<div class="hrd-check">' + SVG.check + '</div>' +
				'<h2>' + (isNew ? 'ثبت نام موفق' : 'ورود موفق') + '</h2>' +
				'<p>' + (isNew ? 'حساب شما با موفقیت ساخته شد. خوش آمدید!' : 'به حساب کاربری خود وارد شدید. در حال انتقال…') + '</p>' +
				'<button class="hrd-btn" data-act="finish"><span class="hrd-btn-label">ورود به داشبورد</span></button>' +
			'</div></section>';
		var target = safeRedirectTarget();
		this.q('[data-act="finish"]').addEventListener('click', function () { window.location.assign(target); });
		setTimeout(function () { window.location.assign(target); }, 1600);
	};

	/* ---------------- Email / password form ---------------- */
	P.renderEmail = function () {
		this.stopTimer();
		this.pane.innerHTML =
			'<section class="hrd-screen">' +
				'<div class="hrd-head">' +
					'<div class="hrd-step"><span class="hrd-step-dot"></span>ورود با ایمیل</div>' +
					'<h1 class="hrd-h1">' + esc(TEXT.title || 'به حساب خود وارد شوید') + '</h1>' +
					'<p class="hrd-lead">' + esc(TEXT.emailSubtitle || 'با ایمیل یا نام کاربری و رمز عبور وارد شوید.') + '</p>' +
				'</div>' +
				'<div class="hrd-field" data-field="user"><label class="hrd-field-label" for="hrd-user">ایمیل یا نام کاربری</label><div class="hrd-control"><input id="hrd-user" class="hrd-input hrd-ltr" type="text" autocomplete="username"></div>' + errHtml() + '</div>' +
				'<div class="hrd-field" data-field="pass"><label class="hrd-field-label" for="hrd-pass">رمز عبور</label><div class="hrd-control"><input id="hrd-pass" class="hrd-input hrd-ltr" type="password" autocomplete="current-password"></div>' + errHtml() + '</div>' +
				'<label class="hrd-remember"><input type="checkbox" id="hrd-remember" checked> مرا به خاطر بسپار</label>' +
				'<button class="hrd-btn" data-act="login"><span class="hrd-spin"></span><span class="hrd-btn-label">ورود</span></button>' +
				'<p class="hrd-formmsg" data-msg aria-live="polite"></p>' +
			'</section>';

		var self = this, btn = this.q('[data-act="login"]');
		['user', 'pass'].forEach(function (f) { self.q('#hrd-' + f).addEventListener('input', function () { self.clearError('[data-field="' + f + '"]'); }); });
		this.q('#hrd-pass').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });
		btn.addEventListener('click', function () { self.emailLogin(btn); });
		this.q('#hrd-user').focus();
	};

	P.emailLogin = function (btn) {
		var self = this;
		var user = this.q('#hrd-user').value.trim();
		var pass = this.q('#hrd-pass').value;
		var remember = this.q('#hrd-remember').checked;
		var msg = this.q('[data-msg]');
		var ok = true;
		if (!user) { this.fieldError('[data-field="user"]', 'وارد کردن ایمیل یا نام کاربری الزامی است.'); ok = false; }
		if (!pass) { this.fieldError('[data-field="pass"]', 'وارد کردن رمز عبور الزامی است.'); ok = false; }
		if (!ok) { return; }

		this.busy(btn, true);
		if (msg) { msg.textContent = ''; msg.className = 'hrd-formmsg'; }

		var params = new URLSearchParams(window.location.search);
		api('/login', { user: user, password: pass, remember: remember ? 1 : 0, redirect_to: params.get('redirect_to') || '' }).then(function (res) {
			self.busy(btn, false);
			if (res.ok) {
				if (msg) { msg.textContent = 'ورود موفقیت‌آمیز بود. در حال انتقال…'; msg.className = 'hrd-formmsg is-ok'; }
				window.location.assign((res.data && res.data.redirect) || safeRedirectTarget());
				return;
			}
			if (msg) { msg.textContent = (res.data && res.data.message) || 'ورود ناموفق بود.'; msg.className = 'hrd-formmsg is-err'; }
		});
	};

	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

	function init() {
		document.querySelectorAll('[data-hrd-sms-auth]').forEach(function (node) {
			if (!node.dataset.hrdInit) { node.dataset.hrdInit = '1'; new Widget(node); }
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
