<?php

namespace HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Login-mode policy + post-login mobile-verification gate.
 *
 * Modes (admin setting `login_mode`):
 *   - mobile_only        : OTP login only (default; unchanged behaviour).
 *   - email_only         : email/password only; mobile never forced.
 *   - email_mobile       : both available; mobile optional.
 *   - email_then_verify  : any user without a verified mobile must verify it
 *                          right after logging in.
 *   - force_verify_old   : only users who registered BEFORE the plugin was
 *                          installed must verify their mobile after login.
 *
 * The gate is deliberately fail-open: administrators are never blocked, it can
 * be switched off with the `hrd_skip_mobile_verification` filter, and it never
 * touches REST/AJAX/cron requests — so it cannot lock anyone out of the site.
 */
class LoginMode {

	const META_VERIFIED = 'hrd_mobile_verified';

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'enforce_verification' ], 1 );
	}

	public static function mode(): string {
		return (string) SettingsService::get( 'login_mode' );
	}

	/**
	 * Which login forms the custom page should show for the current mode.
	 *
	 * @return string[] Ordered subset of ['mobile','email'].
	 */
	public static function forms(): array {
		switch ( self::mode() ) {
			case 'email_only':
			case 'email_then_verify':
				return [ 'email' ];
			case 'email_mobile':
			case 'force_verify_old':
				return [ 'mobile', 'email' ];
			case 'mobile_only':
			default:
				return [ 'mobile' ];
		}
	}

	public static function user_has_verified_mobile( int $user_id ): bool {
		return ! empty( get_user_meta( $user_id, self::META_VERIFIED, true ) );
	}

	/**
	 * Whether the given user must verify a mobile number before continuing.
	 */
	public static function requires_verification( int $user_id ): bool {
		$mode = self::mode();

		// Modes that never force post-login verification.
		if ( in_array( $mode, [ 'mobile_only', 'email_only', 'email_mobile' ], true ) ) {
			return false;
		}

		if ( self::user_has_verified_mobile( $user_id ) ) {
			return false;
		}

		if ( $mode === 'email_then_verify' ) {
			return true;
		}

		if ( $mode === 'force_verify_old' ) {
			$installed = (int) get_option( 'hrd_sms_auth_installed_at' );
			$user      = get_userdata( $user_id );
			if ( ! $installed || ! $user ) {
				return false;
			}
			$registered = strtotime( $user->user_registered . ' UTC' );

			return $registered && $registered < $installed;
		}

		return false;
	}

	/**
	 * Redirect logged-in users who still owe a mobile verification to the
	 * verification page, and block other front-end pages until they finish.
	 */
	public function enforce_verification(): void {
		// Never in wp-admin, or on API/background requests (belt-and-suspenders:
		// template_redirect is front-end only, but stay explicit).
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		/** Allow site owners/devs to disable the gate entirely. */
		if ( apply_filters( 'hrd_skip_mobile_verification', false ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Fail-open: administrators are never locked out (SMS could be down).
		if ( user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		if ( ! self::requires_verification( $user_id ) ) {
			return;
		}

		// Loop guard: if we're already on the login-slug URL — by query var OR by
		// raw path — do NOT redirect again. Without the path check, a site whose
		// rewrite rule isn't active (plain permalinks, or rules not flushed) would
		// redirect forever and the browser would hang on "loading".
		$slug         = Config::login_slug();
		$current_path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( get_query_var( 'hrd_sms_login_page' ) === '1' || $current_path === trim( $slug, '/' ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'action', 'verify-mobile', home_url( '/' . $slug ) ) );
		exit;
	}
}

new LoginMode();
