<?php

namespace HRD_Auth_Sms\Services;

use HRD_Auth_Sms\Config;
use HRD_Auth_Sms\Helper;
use HRD_Auth_Sms\SettingsService;
use HRD_Auth_Sms\Users;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the OTP login/registration flow.
 *
 * Both the REST controller and the admin test tool delegate here, so the send
 * and verify logic exists in exactly one place. Every public method returns a
 * normalised result array:
 *
 *   [ 'ok' => bool, 'status' => int (HTTP), 'message' => string, 'data' => array ]
 *
 * Messages returned to callers are safe to show to end users and never leak
 * provider internals or reveal whether an account exists at send time.
 */
class OtpService {

	/**
	 * Generate a numeric OTP of the configured length.
	 */
	public static function generate_code(): string {
		$length = Config::code_length();
		$min    = (int) str_pad( '1', $length, '0' );
		$max    = (int) str_pad( '9', $length, '9' );

		return (string) wp_rand( $min, $max );
	}

	/**
	 * Step 1 — request an OTP for a mobile number.
	 *
	 * Returns a generic success message regardless of whether the number is
	 * already registered, to prevent account/phone enumeration.
	 */
	public static function send( $mobile_raw ): array {
		$mobile     = Helper::hrd_convert_to_english_digits( sanitize_text_field( (string) $mobile_raw ) );
		$validation = Helper::hrd_sanitize_phone( $mobile );

		if ( empty( $mobile ) || ( $validation['success'] ?? false ) === false ) {
			return self::result( false, 422, $validation['text'] ?: 'شماره موبایل نامعتبر است.' );
		}

		if ( ! RateLimiter::send_allowed( $mobile ) ) {
			return self::result( false, 429, 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.' );
		}

		$code = self::generate_code();

		// Send first; only persist the code once the provider has accepted it.
		$sent = SmsIrGateway::send_verify( $mobile, $code );

		if ( ! $sent['success'] ) {
			self::log( 'send failed for ' . $mobile . ': ' . $sent['message'] );

			return self::result( false, 502, 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.' );
		}

		set_transient( Config::CODE_PREFIX . $mobile, $code, Config::expiration_seconds() );
		RateLimiter::record_send( $mobile );

		return self::result( true, 200, 'کد تأیید به شماره موبایل ارسال شد.', [
			'timer'       => Config::expiration_seconds(),
			'code_length' => Config::code_length(),
		] );
	}

	/**
	 * Step 2 — verify an OTP and log the user in (creating the account on first
	 * login). Account existence is only revealed AFTER a correct OTP, so the
	 * send step gives nothing away.
	 *
	 * When a new user submits a correct code without a name, we respond with
	 * `needs_registration` and intentionally keep the OTP valid so the follow-up
	 * request (carrying the name) can complete without a fresh code.
	 */
	public static function verify( $mobile_raw, $code_raw, array $profile = [] ): array {
		$mobile = Helper::hrd_convert_to_english_digits( sanitize_text_field( (string) $mobile_raw ) );
		$code   = Helper::hrd_convert_to_english_digits( sanitize_text_field( (string) $code_raw ) );
		$code   = preg_replace( '/\D+/', '', $code );

		$validation = Helper::hrd_sanitize_phone( $mobile );
		if ( empty( $mobile ) || ( $validation['success'] ?? false ) === false ) {
			return self::result( false, 422, $validation['text'] ?: 'شماره موبایل نامعتبر است.' );
		}

		$stored_code = get_transient( Config::CODE_PREFIX . $mobile );
		if ( ! $stored_code ) {
			return self::result( false, 410, 'کد منقضی شده است، لطفاً مجدد درخواست دهید.' );
		}

		if ( ! RateLimiter::verify_allowed( $mobile ) ) {
			return self::result( false, 429, 'تلاش بیش از حد مجاز است. لطفاً کمی بعد دوباره امتحان کنید.' );
		}

		if ( ! hash_equals( (string) $stored_code, (string) $code ) ) {
			RateLimiter::record_failed_verify( $mobile );

			return self::result( false, 401, 'کد تأیید نادرست است.' );
		}

		// Correct code from here on.
		$user_id = Users::hrd_sms_user_exist( $mobile );

		if ( ! $user_id ) {
			$registration = self::register_new_user( $mobile, $profile );

			if ( ! $registration['ok'] ) {
				return $registration; // needs_registration / validation error (OTP kept valid)
			}

			$user_id = $registration['user_id'];
		}

		// Success: invalidate the code and clear counters, then sign in.
		delete_transient( Config::CODE_PREFIX . $mobile );
		RateLimiter::clear_verify( $mobile );

		// They proved control of this mobile → mark it verified.
		update_user_meta( $user_id, 'hrd_mobile_verified', time() );
		update_user_meta( $user_id, 'hrd_phone', $mobile );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return self::result( true, 200, 'ورود موفقیت‌آمیز بود.', [ 'user_id' => (int) $user_id ] );
	}

	/**
	 * Attach + verify a mobile number for an ALREADY logged-in user (used by the
	 * post-login mobile-verification gate). Does not create or switch accounts.
	 * Refuses a number already owned by a different account.
	 */
	public static function attach_mobile( int $user_id, $mobile_raw, $code_raw ): array {
		if ( $user_id <= 0 ) {
			return self::result( false, 401, 'برای تأیید شماره باید وارد شده باشید.' );
		}

		$mobile = Helper::hrd_convert_to_english_digits( sanitize_text_field( (string) $mobile_raw ) );
		$code   = preg_replace( '/\D+/', '', Helper::hrd_convert_to_english_digits( sanitize_text_field( (string) $code_raw ) ) );

		$validation = Helper::hrd_sanitize_phone( $mobile );
		if ( empty( $mobile ) || ( $validation['success'] ?? false ) === false ) {
			return self::result( false, 422, $validation['text'] ?: 'شماره موبایل نامعتبر است.' );
		}

		$stored_code = get_transient( Config::CODE_PREFIX . $mobile );
		if ( ! $stored_code ) {
			return self::result( false, 410, 'کد منقضی شده است، لطفاً مجدد درخواست دهید.' );
		}

		if ( ! RateLimiter::verify_allowed( $mobile ) ) {
			return self::result( false, 429, 'تلاش بیش از حد مجاز است. لطفاً کمی بعد دوباره امتحان کنید.' );
		}

		if ( ! hash_equals( (string) $stored_code, (string) $code ) ) {
			RateLimiter::record_failed_verify( $mobile );

			return self::result( false, 401, 'کد تأیید نادرست است.' );
		}

		// Make sure the number isn't already tied to a different account.
		$owner = Users::hrd_sms_user_exist( $mobile );
		if ( $owner && (int) $owner !== $user_id ) {
			return self::result( false, 409, 'این شماره قبلاً برای حساب دیگری ثبت شده است.' );
		}

		delete_transient( Config::CODE_PREFIX . $mobile );
		RateLimiter::clear_verify( $mobile );

		update_user_meta( $user_id, 'hrd_phone', $mobile );
		update_user_meta( $user_id, 'hrd_mobile_verified', time() );
		if ( empty( get_user_meta( $user_id, 'billing_phone', true ) ) ) {
			update_user_meta( $user_id, 'billing_phone', $mobile );
			update_user_meta( $user_id, 'shipping_phone', $mobile );
		}

		return self::result( true, 200, 'شماره موبایل با موفقیت تأیید شد.', [ 'user_id' => $user_id ] );
	}

	/**
	 * Create a brand-new user after a correct OTP, collecting the configured
	 * profile fields. Returns ['ok'=>true,'user_id'=>int] on success, or a full
	 * result() (ok=false) for needs_registration / validation errors. The OTP is
	 * intentionally left valid for those failure cases so the follow-up submit
	 * (carrying the profile) can complete.
	 */
	private static function register_new_user( string $mobile, array $profile ): array {
		$first = sanitize_text_field( (string) ( $profile['first_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $profile['last_name'] ?? '' ) );

		$need_profile = (bool) SettingsService::get( 'collect_profile' );
		$national     = preg_replace( '/\D+/', '', Helper::hrd_convert_to_english_digits( (string) ( $profile['national_code'] ?? '' ) ) );
		$birth_date   = sanitize_text_field( (string) ( $profile['birth_date'] ?? '' ) );

		$missing = ( $first === '' || $last === '' )
			|| ( $need_profile && ( $national === '' || $birth_date === '' ) );

		if ( $missing ) {
			return self::result( false, 422, 'برای تکمیل ثبت نام، اطلاعات خواسته‌شده را وارد کنید.', [
				'needs_registration' => true,
			] );
		}

		if ( $need_profile && ! Helper::valid_national_id( $national ) ) {
			return self::result( false, 422, 'کد ملی وارد شده معتبر نیست.', [ 'field' => 'national_code' ] );
		}

		$user_id = Users::hrd_sms_create_user( $mobile, [
			'user_login'   => $mobile,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
		] );

		if ( is_wp_error( $user_id ) ) {
			self::log( 'user creation failed for ' . $mobile . ': ' . $user_id->get_error_message() );

			return self::result( false, 500, 'خطا در ایجاد کاربر. لطفاً دوباره تلاش کنید.' );
		}

		if ( $national !== '' ) {
			update_user_meta( $user_id, 'hrd_national_code', $national );
		}
		if ( $birth_date !== '' ) {
			update_user_meta( $user_id, 'hrd_birth_date', $birth_date );
		}

		return [ 'ok' => true, 'user_id' => (int) $user_id ];
	}

	private static function result( bool $ok, int $status, string $message, array $data = [] ): array {
		return [
			'ok'      => $ok,
			'status'  => $status,
			'message' => $message,
			'data'    => $data,
		];
	}

	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[hrd-sms-auth] ' . $message );
		}
	}
}
