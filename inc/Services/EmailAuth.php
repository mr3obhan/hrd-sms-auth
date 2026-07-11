<?php

namespace HRD_Auth_Sms\Services;

use HRD_Auth_Sms\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Email / username + password authentication for the custom login page.
 *
 * Uses core wp_authenticate() (so it honours all auth plugins/filters), sets
 * the auth cookie, and fires `wp_login` so integrations (WooCommerce sessions,
 * etc.) initialise. Returns a generic error for every failure so it never
 * reveals whether an account exists, and is protected by a per-IP rate limit.
 */
class EmailAuth {

	public static function login( $user_raw, $password_raw, $remember = false, $redirect_to = '' ): array {
		// Brute-force protection (always on, independent of OTP settings).
		if ( ! RateLimiter::email_login_allowed() ) {
			return self::result( false, 429, 'تلاش بیش از حد مجاز است. لطفاً چند دقیقه بعد دوباره امتحان کنید.' );
		}

		$user_login = sanitize_text_field( (string) $user_raw );
		$password   = (string) $password_raw; // never sanitise/alter a password

		if ( $user_login === '' || $password === '' ) {
			RateLimiter::record_email_fail();

			return self::result( false, 400, 'نام کاربری/ایمیل و رمز عبور را وارد کنید.' );
		}

		// wp_authenticate() resolves both usernames and email addresses.
		$user = wp_authenticate( $user_login, $password );

		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			RateLimiter::record_email_fail();

			// Generic message — do not disclose which part was wrong.
			return self::result( false, 401, 'نام کاربری یا رمز عبور نادرست است.' );
		}

		RateLimiter::clear_email_fail();

		$remember = (bool) $remember;
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember );
		// Let WordPress / WooCommerce run their post-login hooks.
		do_action( 'wp_login', $user->user_login, $user );

		return self::result( true, 200, 'ورود موفقیت‌آمیز بود.', [
			'redirect' => self::redirect_url( $user, (string) $redirect_to ),
		] );
	}

	/**
	 * Safe, same-origin redirect target after a successful email login.
	 */
	private static function redirect_url( \WP_User $user, string $redirect_to ): string {
		$fallback = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'dashboard' )
			: home_url( '/' );

		if ( $redirect_to !== '' ) {
			$safe = wp_validate_redirect( esc_url_raw( $redirect_to ), '' );
			if ( $safe ) {
				$fallback = $safe;
			}
		}

		// Respect WordPress's own login_redirect filter for consistency.
		return (string) apply_filters( 'login_redirect', $fallback, $redirect_to, $user );
	}

	private static function result( bool $ok, int $status, string $message, array $data = [] ): array {
		return [ 'ok' => $ok, 'status' => $status, 'message' => $message, 'data' => $data ];
	}
}
