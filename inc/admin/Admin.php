<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings screen.
 *
 * One option (`hrd_sms_auth_options`), one form, client-side tabs. Every field
 * is always submitted (panels are shown/hidden with CSS/JS), so switching tabs
 * never drops other tabs' values. All values pass through
 * SettingsService::sanitize() on save and are escaped on output here.
 */
class Admin {

	const PAGE = 'hrd-sms-auth-settings';
	const GROUP = 'hrd_sms_auth_options_group';

	/** tab key => label. The section page-slug is "hrd-{key}". */
	private array $tabs = [
		'general'     => 'عمومی',
		'sms'         => 'سرویس پیامک',
		'security'    => 'کد و امنیت',
		'redirects'   => 'تغییر مسیرها',
		'woocommerce' => 'ووکامرس',
		'design'      => 'طراحی و ظاهر',
		'tools'       => 'ابزارها',
	];

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		add_action( 'show_user_profile', [ $this, 'show_mobile_field' ] );
		add_action( 'edit_user_profile', [ $this, 'show_mobile_field' ] );
		add_action( 'personal_options_update', [ $this, 'save_mobile_field' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_mobile_field' ] );
	}

	/* ----------------------------- Assets ----------------------------- */

	public function enqueue_assets( $hook ): void {
		if ( $hook !== 'toplevel_page_' . self::PAGE ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'hrd-sms-auth-admin',
			HRD_Auth_Sms::$plugin_url . '/assets/admin/admin.js',
			[ 'jquery', 'wp-color-picker' ],
			HRD_Auth_Sms::$plugin_version,
			true
		);

		wp_localize_script( 'hrd-sms-auth-admin', 'smsAuthAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'hrd_sms_auth_nonce' ),
			'media'   => [ 'title' => 'انتخاب تصویر', 'button' => 'استفاده از این تصویر' ],
		] );

		wp_add_inline_style( 'wp-color-picker', $this->admin_css() );
	}

	private function admin_css(): string {
		return '.hrd-tab-panel{display:none}.hrd-tab-panel.is-active{display:block}'
			. '.hrd-settings .nav-tab{cursor:pointer}'
			. '.hrd-media-preview{display:block;max-width:160px;max-height:80px;margin-top:8px}';
	}

	/* ----------------------------- Menu ------------------------------- */

	public function add_menu(): void {
		add_menu_page(
			'تنظیمات ورود/ثبت نام همراکت',
			'ورود/ثبت نام همراکت',
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ],
			'dashicons-smartphone'
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap hrd-settings">
			<h1>تنظیمات ورود/ثبت نام همراکت</h1>
			<h2 class="nav-tab-wrapper hrd-tabs">
				<?php $first = true;
				foreach ( $this->tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $first ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php $first = false; endforeach; ?>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<?php $first = true;
				foreach ( $this->tabs as $key => $label ) : ?>
					<div class="hrd-tab-panel <?php echo $first ? 'is-active' : ''; ?>" data-panel="<?php echo esc_attr( $key ); ?>">
						<?php do_settings_sections( 'hrd-' . $key ); ?>
					</div>
					<?php $first = false; endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* -------------------------- Registration -------------------------- */

	public function register_settings(): void {
		register_setting( self::GROUP, SettingsService::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ SettingsService::class, 'sanitize' ],
		] );

		// --- General ---
		add_settings_section( 'general', '', null, 'hrd-general' );
		$this->field( 'general', 'login_mode', 'حالت ورود', fn() => $this->login_mode_field() );
		$this->field( 'general', 'brand_name', 'نام برند', fn() => $this->text( 'brand_name', get_bloginfo( 'name' ), 'خالی بگذارید تا نام سایت استفاده شود.' ) );
		$this->field( 'general', 'login_title', 'عنوان فرم ورود', fn() => $this->text( 'login_title' ) );
		$this->field( 'general', 'login_subtitle', 'زیرعنوان فرم ورود', fn() => $this->text( 'login_subtitle' ) );
		$this->field( 'general', 'show_logo', 'نمایش لوگو/برند', fn() => $this->checkbox( 'show_logo', 'لوگو و نام برند بالای کارت نمایش داده شود' ) );
		$this->field( 'general', 'logo_url', 'آدرس لوگو', fn() => $this->media( 'logo_url' ) );
		$this->field( 'general', 'logo_width', 'عرض لوگو (px)', fn() => $this->number( 'logo_width', 20, 600, 'در صورت انتخاب لوگو، فقط تصویر لوگو (بدون نام برند) با این عرض نمایش داده می‌شود.' ) );
		$this->field( 'general', 'login_slug', 'نشانی صفحه ورود', fn() => $this->text( 'login_slug', 'hrd-login', 'پس از تغییر، پیوندهای یکتا یک‌بار به‌روزرسانی می‌شوند.' ) );
		$this->field( 'general', 'show_legal', 'نمایش متن قوانین', fn() => $this->checkbox( 'show_legal', 'فوتر «قوانین و حریم خصوصی» نمایش داده شود' ) );
		$this->field( 'general', 'terms_url', 'لینک قوانین و مقررات', fn() => $this->text( 'terms_url', '', 'خالی بگذارید تا فقط متن بدون لینک نمایش داده شود.' ) );
		$this->field( 'general', 'privacy_url', 'لینک حریم خصوصی', fn() => $this->text( 'privacy_url' ) );

		// --- SMS provider ---
		add_settings_section( 'sms', '', fn() => print( '<p>اطلاعات سرویس پیامک sms.ir را وارد کنید.</p>' ), 'hrd-sms' );
		$this->field( 'sms', 'sms_ir_api_key', 'کلید API (sms.ir)', fn() => $this->text( 'sms_ir_api_key' ) );
		$this->field( 'sms', 'sms_ir_template_id', 'شناسه قالب پیامک', fn() => $this->text( 'sms_ir_template_id' ) );
		$this->field( 'sms', 'sms_parameters', 'پارامترهای قالب پیامک', fn() => $this->params() );

		// --- OTP & security ---
		add_settings_section( 'security', '', null, 'hrd-security' );
		$this->field( 'security', 'code_length', 'تعداد رقم کد تأیید', fn() => $this->number( 'code_length', Config::OTP_MIN_LENGTH, Config::OTP_MAX_LENGTH, sprintf( 'بین %d تا %d رقم.', Config::OTP_MIN_LENGTH, Config::OTP_MAX_LENGTH ) ) );
		$this->field( 'security', 'code_expiration_time', 'زمان اعتبار کد (دقیقه)', fn() => $this->number( 'code_expiration_time', 1, 60 ) );
		$this->field( 'security', 'enable_attempt_limit', 'محدودیت تلاش', fn() => $this->checkbox( 'enable_attempt_limit', 'فعال باشد (محافظت سخت‌گیرانه همیشه برقرار است)' ) );
		$this->field( 'security', 'attempt_limit_duration', 'مدت محدودیت (دقیقه)', fn() => $this->number( 'attempt_limit_duration', 1, 1440 ), 'hrd-attempt-field' );
		$this->field( 'security', 'max_attempt_limit', 'حداکثر تلاش مجاز', fn() => $this->number( 'max_attempt_limit', 1, 50 ), 'hrd-attempt-field' );
		$this->field( 'security', 'collect_profile', 'دریافت کد ملی و تاریخ تولد', fn() => $this->checkbox( 'collect_profile', 'هنگام ثبت‌نام کاربر جدید، کد ملی و تاریخ تولد دریافت و اعتبارسنجی شود' ) );

		// --- Redirects ---
		add_settings_section( 'redirects', '', fn() => print( '<p>نشانی‌های بازگشت (redirect_to) به‌صورت امن و فقط داخل همین دامنه پردازش می‌شوند.</p>' ), 'hrd-redirects' );
		$this->field( 'redirects', 'disable_wp_login', 'غیرفعال‌سازی wp-login.php', fn() => $this->checkbox( 'disable_wp_login', 'هدایت ورود وردپرس به صفحه ورود سفارشی' ) );

		// --- WooCommerce ---
		add_settings_section( 'woocommerce', '', null, 'hrd-woocommerce' );
		$this->field( 'woocommerce', 'disable_wc_login', 'غیرفعال‌سازی فرم ورود ووکامرس', fn() => $this->checkbox( 'disable_wc_login', 'هدایت ورود ووکامرس به صفحه ورود سفارشی' ) );
		add_settings_section( 'wc_tools', 'ابزار همگام‌سازی', [ $this, 'tool_wc_sync' ], 'hrd-woocommerce' );

		// --- Design / Appearance ---
		add_settings_section( 'design_bg', 'پس‌زمینه صفحه', null, 'hrd-design' );
		$this->field( 'design_bg', 'bg_type', 'نوع پس‌زمینه', fn() => $this->select( 'bg_type', [ 'gradient' => 'گرادینت', 'solid' => 'رنگ ثابت', 'image' => 'تصویر' ] ) );
		$this->field( 'design_bg', 'bg_color', 'رنگ پس‌زمینه', fn() => $this->color( 'bg_color' ), 'hrd-bg-solid hrd-bg-image' );
		$this->field( 'design_bg', 'bg_gradient_start', 'رنگ شروع گرادینت', fn() => $this->color( 'bg_gradient_start' ), 'hrd-bg-gradient' );
		$this->field( 'design_bg', 'bg_gradient_end', 'رنگ پایان گرادینت', fn() => $this->color( 'bg_gradient_end' ), 'hrd-bg-gradient' );
		$this->field( 'design_bg', 'bg_image_url', 'تصویر پس‌زمینه', fn() => $this->media( 'bg_image_url' ), 'hrd-bg-image' );

		add_settings_section( 'design_card', 'کارت فرم', null, 'hrd-design' );
		$this->field( 'design_card', 'card_bg_color', 'رنگ پس‌زمینه کارت', fn() => $this->color( 'card_bg_color' ) );
		$this->field( 'design_card', 'card_width', 'عرض کارت (px)', fn() => $this->number( 'card_width', 300, 720 ) );
		$this->field( 'design_card', 'card_radius', 'گردی گوشه‌ها (px)', fn() => $this->number( 'card_radius', 0, 40 ) );
		$this->field( 'design_card', 'card_shadow', 'سایه کارت', fn() => $this->checkbox( 'card_shadow', 'نمایش سایه' ) );

		add_settings_section( 'design_colors', 'رنگ‌ها', null, 'hrd-design' );
		$this->field( 'design_colors', 'primary_color', 'رنگ اصلی', fn() => $this->color( 'primary_color' ) );
		$this->field( 'design_colors', 'button_bg_color', 'رنگ دکمه', fn() => $this->color( 'button_bg_color' ) );
		$this->field( 'design_colors', 'button_text_color', 'رنگ متن دکمه', fn() => $this->color( 'button_text_color' ) );
		$this->field( 'design_colors', 'input_border_color', 'رنگ کادر ورودی', fn() => $this->color( 'input_border_color' ) );
		$this->field( 'design_colors', 'input_focus_color', 'رنگ فوکوس ورودی', fn() => $this->color( 'input_focus_color' ) );
		$this->field( 'design_colors', 'text_color', 'رنگ متن', fn() => $this->color( 'text_color' ) );
		$this->field( 'design_colors', 'error_color', 'رنگ خطا', fn() => $this->color( 'error_color' ) );
		$this->field( 'design_colors', 'success_color', 'رنگ موفقیت', fn() => $this->color( 'success_color' ) );

		add_settings_section( 'design_back', 'دکمه بازگشت به خانه', null, 'hrd-design' );
		$this->field( 'design_back', 'back_button_show', 'نمایش دکمه بازگشت', fn() => $this->checkbox( 'back_button_show', 'نمایش داده شود' ) );
		$this->field( 'design_back', 'back_button_text', 'متن دکمه', fn() => $this->text( 'back_button_text' ) );
		$this->field( 'design_back', 'back_button_url', 'نشانی دکمه', fn() => $this->text( 'back_button_url', home_url( '/' ), 'خالی بگذارید تا به صفحه اصلی برود.' ) );
		$this->field( 'design_back', 'back_button_position', 'موقعیت دکمه', fn() => $this->select( 'back_button_position', [ 'top-right' => 'بالا راست', 'top-left' => 'بالا چپ', 'bottom-right' => 'پایین راست', 'bottom-left' => 'پایین چپ' ] ) );

		add_settings_section( 'design_advanced', 'پیشرفته', null, 'hrd-design' );
		$this->field( 'design_advanced', 'load_font', 'فونت فارسی (Vazirmatn)', fn() => $this->checkbox( 'load_font', 'بارگذاری فونت فقط در صفحهٔ ورود' ) );
		$this->field( 'design_advanced', 'custom_css', 'CSS سفارشی', fn() => $this->textarea( 'custom_css', 'برای کاربران حرفه‌ای. تگ‌های HTML مجاز نیستند.' ) );

		// --- Tools ---
		add_settings_section( 'tools', 'ارسال پیامک تستی', [ $this, 'tool_test_sms' ], 'hrd-tools' );
		add_settings_section( 'tools_migration', 'مهاجرت از افزونهٔ Digits', [ $this, 'tool_digits_migration' ], 'hrd-tools' );
	}

	/**
	 * Login-mode selector with an explanation of every option.
	 */
	private function login_mode_field(): void {
		$this->select( 'login_mode', [
			'mobile_only'       => 'فقط موبایل (پیامک)',
			'email_only'        => 'فقط ایمیل و رمز عبور',
			'email_mobile'      => 'ایمیل و موبایل (هر دو)',
			'email_then_verify' => 'ورود با ایمیل، سپس تأیید اجباری موبایل',
			'force_verify_old'  => 'اجبار تأیید موبایل فقط برای کاربران قدیمی',
		] );
		echo '<div class="description" style="margin-top:8px;line-height:2">'
			. '<strong>فقط موبایل:</strong> ورود/ثبت‌نام تنها با پیامک (پیش‌فرض).<br>'
			. '<strong>فقط ایمیل:</strong> ورود استاندارد وردپرس/ووکامرس؛ موبایل اجباری نیست.<br>'
			. '<strong>ایمیل و موبایل:</strong> هر دو روش فعال؛ موبایل اختیاری.<br>'
			. '<strong>ورود با ایمیل، سپس تأیید موبایل:</strong> کاربر با ایمیل وارد می‌شود و اگر شمارهٔ تأییدشده نداشته باشد، به مرحلهٔ تأیید موبایل هدایت می‌شود.<br>'
			. '<strong>اجبار برای کاربران قدیمی:</strong> فقط کاربرانی که <em>قبل از نصب این افزونه</em> ثبت‌نام کرده‌اند باید موبایل خود را تأیید کنند.<br>'
			. '<em>مدیران هرگز مسدود نمی‌شوند.</em>'
			. '</div>';
	}

	/**
	 * Digits migration tool (dry-run → apply → revert).
	 */
	public function tool_digits_migration(): void {
		if ( ! \HRD_Auth_Sms\DigitsMigration::digits_detected() ) {
			echo '<p>داده‌ای از افزونهٔ Digits روی این سایت یافت نشد.</p>';
			return;
		}
		?>
		<p>ابتدا «پیش‌نمایش» را بزنید تا بدون تغییر، آمار مهاجرت را ببینید.</p>
		<p>
			<label><input type="checkbox" id="hrd_mig_overwrite"> بازنویسی شماره‌های موجود (با تأیید صریح)</label><br>
			<label><input type="checkbox" id="hrd_mig_mark"> علامت‌گذاری شماره‌های منتقل‌شده به‌عنوان «تأییدشده» (فقط اگر به دادهٔ Digits اطمینان دارید)</label>
		</p>
		<p>
			<button type="button" class="button" id="hrd_mig_preview">پیش‌نمایش (آزمایشی)</button>
			<button type="button" class="button button-primary" id="hrd_mig_apply">اجرای مهاجرت</button>
			<button type="button" class="button button-link-delete" id="hrd_mig_revert">بازگردانی (Revert)</button>
		</p>
		<div id="hrd_mig_result" style="margin-top:10px;white-space:pre-line"></div>
		<?php
	}

	/**
	 * Register a settings field, optionally tagging its row with a CSS class
	 * (used by admin.js to show/hide conditional rows).
	 */
	private function field( string $section, string $key, string $label, callable $cb, string $row_class = '' ): void {
		$page = 'hrd-' . $this->section_page( $section );
		$args = $row_class ? [ 'class' => $row_class ] : [];
		add_settings_field( $key, esc_html( $label ), $cb, $page, $section, $args );
	}

	/**
	 * Map a section id to its tab page slug. Sections are named after their tab
	 * except the few extra sections inside the design and woocommerce tabs.
	 */
	private function section_page( string $section ): string {
		if ( str_starts_with( $section, 'design' ) ) {
			return 'design';
		}
		if ( str_starts_with( $section, 'tools' ) ) {
			return 'tools';
		}
		if ( $section === 'wc_tools' ) {
			return 'woocommerce';
		}
		return $section;
	}

	/* ------------------------- Field renderers ------------------------ */

	private function name( string $key ): string {
		return SettingsService::OPTION . '[' . $key . ']';
	}

	private function text( string $key, string $placeholder = '', string $desc = '' ): void {
		printf(
			'<input type="text" id="hrd-field-%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s" />',
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) SettingsService::get( $key ) ),
			esc_attr( $placeholder )
		);
		if ( $desc ) {
			printf( '<p class="description">%s</p>', esc_html( $desc ) );
		}
	}

	private function number( string $key, int $min, int $max, string $desc = '' ): void {
		printf(
			'<input type="number" name="%1$s" value="%2$s" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) SettingsService::get( $key ) ),
			$min,
			$max
		);
		if ( $desc ) {
			printf( '<p class="description">%s</p>', esc_html( $desc ) );
		}
	}

	private function checkbox( string $key, string $label ): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $this->name( $key ) ),
			checked( 1, (int) SettingsService::get( $key ), false ),
			esc_html( $label )
		);
	}

	private function color( string $key ): void {
		$defaults = SettingsService::defaults();
		printf(
			'<input type="text" class="hrd-color-field" name="%1$s" value="%2$s" data-default-color="%3$s" />',
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) SettingsService::get( $key ) ),
			esc_attr( (string) ( $defaults[ $key ] ?? '' ) )
		);
	}

	private function select( string $key, array $options ): void {
		printf( '<select name="%s">', esc_attr( $this->name( $key ) ) );
		$current = SettingsService::get( $key );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	private function media( string $key ): void {
		$value = (string) SettingsService::get( $key );
		printf(
			'<input type="text" id="hrd-field-%1$s" name="%2$s" value="%3$s" class="regular-text" />
			 <button type="button" class="button hrd-media-upload" data-target="#hrd-field-%1$s">انتخاب تصویر</button>',
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			esc_url( $value )
		);
		if ( $value ) {
			printf( '<img src="%s" class="hrd-media-preview" alt="" />', esc_url( $value ) );
		}
	}

	/**
	 * Repeatable SMS template parameters (name/value rows).
	 */
	private function params(): void {
		$base = SettingsService::OPTION . '[sms_parameters]';
		$rows = SettingsService::get( 'sms_parameters' );

		echo '<table class="widefat hrd-params" style="max-width:560px;margin-bottom:8px"><thead><tr>';
		echo '<th>نام پارامتر</th><th>مقدار</th><th style="width:40px"></th>';
		echo '</tr></thead><tbody class="hrd-params-body">';
		$i = 0;
		foreach ( $rows as $row ) {
			echo $this->param_row( $base, 'r' . $i, (string) ( $row['name'] ?? '' ), (string) ( $row['value'] ?? '' ) );
			$i++;
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button hrd-param-add">افزودن پارامتر</button></p>';
		echo '<p class="description">نام پارامتر باید دقیقاً با پارامتر تعریف‌شده در قالب sms.ir یکی باشد (مثلاً <code>CODE</code>، <code>VERIFICATIONCODE</code> یا <code>OTP_CODE</code>).<br>'
			. 'جای‌گذاری‌های مجاز در «مقدار»: <code>{code}</code> کد تأیید، <code>{domain}</code> دامنهٔ سایت، <code>{site_name}</code> نام سایت، <code>{site_url}</code> آدرس سایت.</p>';

		echo '<script type="text/html" id="hrd-param-tpl">' . $this->param_row( $base, '__i__', '', '' ) . '</script>';
	}

	private function param_row( string $base, string $index, string $name, string $value ): string {
		return sprintf(
			'<tr class="hrd-param-row">'
			. '<td><input type="text" name="%1$s[%2$s][name]" value="%3$s" class="regular-text" placeholder="CODE"></td>'
			. '<td><input type="text" name="%1$s[%2$s][value]" value="%4$s" class="regular-text" placeholder="{code}" dir="ltr"></td>'
			. '<td><button type="button" class="button-link hrd-param-remove" aria-label="حذف">&times;</button></td>'
			. '</tr>',
			esc_attr( $base ),
			esc_attr( $index ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	private function textarea( string $key, string $desc = '' ): void {
		printf(
			'<textarea name="%1$s" rows="8" class="large-text code" dir="ltr">%2$s</textarea>',
			esc_attr( $this->name( $key ) ),
			esc_textarea( (string) SettingsService::get( $key ) )
		);
		if ( $desc ) {
			printf( '<p class="description">%s</p>', esc_html( $desc ) );
		}
	}

	/* ----------------------------- Tools ------------------------------ */

	public function tool_test_sms(): void {
		?>
		<p>برای بررسی عملکرد ارسال، شماره موبایل را وارد کرده و دکمه را بزنید.</p>
		<input type="text" id="test_mobile" placeholder="09123456789" style="width:250px;direction:ltr;">
		<button type="button" class="button button-primary" id="send_test_sms">ارسال پیامک تستی</button>
		<p id="test_sms_result" style="margin-top:10px;"></p>
		<?php
	}

	public function tool_wc_sync(): void {
		?>
		<p>درج شمارهٔ موبایل کاربران (که نام‌کاربری آن‌ها شماره است) در فیلدهای صورتحساب/ارسال ووکامرس.</p>
		<button type="button" class="button button-secondary" id="sync_wc_users">همگام‌سازی با ووکامرس</button>
		<p id="sync_wc_users_result" style="margin-top:10px;"></p>
		<?php
	}

	/* ----------------------- User profile field ----------------------- */

	public function show_mobile_field( $user ): void {
		$mobile = get_user_meta( $user->ID, 'hrd_phone', true );
		?>
		<h3>شماره موبایل ثبت‌نام</h3>
		<table class="form-table">
			<tr>
				<th><label for="hrd_phone">شماره موبایل</label></th>
				<td>
					<input type="text" name="hrd_phone" id="hrd_phone"
						   value="<?php echo esc_attr( $mobile ); ?>"
						   class="regular-text" <?php disabled( ! empty( $mobile ) ); ?> />
					<?php if ( empty( $mobile ) ) : ?>
						<p class="description">شماره موبایل کاربر (مثلاً 09121234567).</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_mobile_field( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( isset( $_POST['hrd_phone'] ) ) {
			update_user_meta( $user_id, 'hrd_phone', sanitize_text_field( wp_unslash( $_POST['hrd_phone'] ) ) );
		}
	}
}

new Admin();
