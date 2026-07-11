<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend integration: registers the lightweight (vanilla-JS) login widget,
 * renders its server-side shell, and exposes it through [hrd_sms_login].
 *
 * Assets load only where the widget renders — the shortcode and the custom
 * login page — never site-wide. Appearance comes from SettingsService and is
 * injected as scoped, escaped inline CSS.
 */
class Front {

	const HANDLE = 'hrd-sms-auth';
	const FONT_HANDLE = 'hrd-sms-auth-font';

	/** 'login' (default) or 'verify' (post-login mobile verification). */
	public static string $page_mode = 'login';

	private static bool $enqueued = false;

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
		add_shortcode( 'hrd_sms_login', [ $this, 'shortcode' ] );
	}

	public static function register_assets(): void {
		wp_register_style(
			self::HANDLE,
			HRD_Auth_Sms::$plugin_url . '/assets/css/style.css',
			[],
			HRD_Auth_Sms::$plugin_version
		);

		wp_register_script(
			self::HANDLE,
			HRD_Auth_Sms::$plugin_url . '/assets/js/login.js',
			[],
			HRD_Auth_Sms::$plugin_version,
			true
		);

		// Persian webfont, loaded only on the login widget when enabled.
		wp_register_style(
			self::FONT_HANDLE,
			'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap',
			[],
			null
		);
	}

	/**
	 * Enqueue + localise + theme the widget. Safe to call multiple times.
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			self::register_assets();
		}

		if ( SettingsService::get( 'load_font' ) ) {
			wp_enqueue_style( self::FONT_HANDLE );
		}

		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, SettingsService::inline_css() );

		$verify = self::$page_mode === 'verify';
		$forms  = $verify ? [ 'mobile' ] : LoginMode::forms();

		$data = [
			'api'        => esc_url_raw( rest_url( 'hrd-sms-auth/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'homeUrl'    => esc_url_raw( home_url( '/' ) ),
			'timer'      => Config::expiration_seconds(),
			'codeLength' => Config::code_length(),
			'mode'       => $verify ? 'verify' : 'login',
			'forms'      => $forms,
			// Sent as 1/0 (wp_localize_script stringifies booleans, so a bare
			// false would arrive as '' and read as truthy on the JS side).
			'collectProfile' => ( ! $verify && SettingsService::get( 'collect_profile' ) ) ? 1 : 0,
			'text'       => $verify
				? [ 'title' => 'تأیید شماره موبایل', 'subtitle' => 'برای ادامه، شمارهٔ موبایل خود را وارد و تأیید کنید.' ]
				: [
					'title'         => SettingsService::get( 'login_title' ),
					'subtitle'      => SettingsService::get( 'login_subtitle' ),
					'emailSubtitle' => 'با ایمیل یا نام کاربری و رمز عبور وارد شوید.',
				],
		];

		// Admin-only debug: confirms the mode actually reaches the frontend.
		if ( current_user_can( 'manage_options' ) ) {
			$data['debug'] = [
				'login_mode' => LoginMode::mode(),
				'page_mode'  => self::$page_mode,
				'forms'      => $forms,
			];
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[hrd-sms-auth] login_mode=' . LoginMode::mode() . ' page_mode=' . self::$page_mode . ' forms=' . implode( ',', $forms ) );
		}

		wp_enqueue_script( self::HANDLE );
		wp_localize_script( self::HANDLE, 'hrdSms', $data );
	}

	/**
	 * Shortcode: render the widget inline (non-fullscreen).
	 */
	public function shortcode( $atts = [] ): string {
		return self::render( false );
	}

	/**
	 * Build the widget shell: brand above the card, an empty card the JS mounts
	 * its four screens into ([data-hrd-sms-auth]), and the legal footer. All
	 * server-rendered text is escaped.
	 *
	 * @param bool $fullscreen Whether this is the dedicated fullscreen page.
	 */
	public static function render( bool $fullscreen = false ): string {
		self::enqueue();

		$classes = 'hrd-auth' . ( $fullscreen ? ' hrd-auth--fullscreen' : '' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="hrd-shell">
				<?php echo self::brand(); // escaped ?>

				<div class="hrd-card" data-hrd-sms-auth dir="rtl">
					<noscript>
						<p class="hrd-noscript">برای ورود با شماره موبایل، لطفاً جاوااسکریپت مرورگر خود را فعال کنید.</p>
					</noscript>
				</div>

				<?php echo self::legal(); // escaped ?>

				<?php if ( current_user_can( 'manage_options' ) ) :
					$debug_forms = self::$page_mode === 'verify' ? [ 'mobile' ] : LoginMode::forms(); ?>
					<div class="hrd-debug">
						HRD debug · login_mode=<?php echo esc_html( LoginMode::mode() ); ?>
						· page=<?php echo esc_html( self::$page_mode ); ?>
						· forms=<?php echo esc_html( implode( ',', $debug_forms ) ); ?>
						<span style="opacity:.7">(only you see this)</span>
					</div>
				<?php endif; ?>
			</div>

			<?php echo self::back_button(); // escaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Brand block (logo/icon mark + name) shown above the card.
	 */
	private static function brand(): string {
		if ( ! SettingsService::get( 'show_logo' ) ) {
			return '';
		}

		$name = SettingsService::get( 'brand_name' );
		$name = $name !== '' ? $name : get_bloginfo( 'name' );

		if ( SettingsService::get( 'logo_url' ) ) {
			$mark = sprintf(
				'<span class="hrd-brand-mark hrd-brand-mark--img"><img src="%s" alt="%s"></span>',
				esc_url( SettingsService::get( 'logo_url' ) ),
				esc_attr( $name )
			);
		} else {
			$mark = '<span class="hrd-brand-mark" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
				. '<path d="M12 2.5 4 6v5.5c0 4.6 3.2 8.4 8 9.9 4.8-1.5 8-5.3 8-9.9V6l-8-3.5Z" fill="currentColor" opacity=".25"/>'
				. '<path d="M9 12.3l2.1 2.1L15 10.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
				. '</svg></span>';
		}

		return '<div class="hrd-brand">' . $mark . '<b class="hrd-brand-name">' . esc_html( $name ) . '</b></div>';
	}

	/**
	 * Legal footer with optional terms/privacy links.
	 */
	private static function legal(): string {
		if ( ! SettingsService::get( 'show_legal' ) ) {
			return '';
		}

		$terms   = SettingsService::get( 'terms_url' );
		$privacy = SettingsService::get( 'privacy_url' );

		$terms_link   = $terms !== ''
			? '<a href="' . esc_url( $terms ) . '">قوانین و مقررات</a>'
			: '<span>قوانین و مقررات</span>';
		$privacy_link = $privacy !== ''
			? '<a href="' . esc_url( $privacy ) . '">حریم خصوصی</a>'
			: '<span>حریم خصوصی</span>';

		return '<p class="hrd-legal">با ادامه، ' . $terms_link . ' و ' . $privacy_link . ' را می‌پذیرید.</p>';
	}

	/**
	 * Optional "back to homepage" control (off by default).
	 */
	private static function back_button(): string {
		if ( ! SettingsService::get( 'back_button_show' ) ) {
			return '';
		}

		$text = SettingsService::get( 'back_button_text' );

		return sprintf(
			'<a class="hrd-back-home" data-position="%s" href="%s">%s</a>',
			esc_attr( SettingsService::get( 'back_button_position' ) ),
			esc_url( SettingsService::back_button_url() ),
			esc_html( $text !== '' ? $text : 'بازگشت به صفحه اصلی' )
		);
	}
}

new Front();
