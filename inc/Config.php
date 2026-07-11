<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for plugin defaults and validated option access.
 *
 * Every default value (OTP length, expiry, resend timer, attempt limits) lives
 * here so the backend, the REST API and the frontend all read the same numbers.
 */
class Config {

	/** Minimum/maximum OTP length we will ever generate or accept. */
	const OTP_MIN_LENGTH = 5;
	const OTP_MAX_LENGTH = 8;
	const OTP_DEFAULT_LENGTH = 5;

	/** Default OTP validity in minutes. */
	const DEFAULT_EXPIRATION_MINUTES = 2;

	/** Admin-configurable (soft) attempt limit defaults. */
	const DEFAULT_ATTEMPT_LIMIT = 5;
	const DEFAULT_ATTEMPT_DURATION = 10; // minutes

	/**
	 * Hard ceilings that ALWAYS apply, even when the admin disables the soft
	 * attempt limit. These exist purely to make brute-force impractical.
	 */
	const HARD_SEND_MAX = 10;       // max OTP requests per window
	const HARD_VERIFY_MAX = 10;     // max verify attempts per window
	const HARD_WINDOW_MINUTES = 60; // rolling window for the hard ceilings

	/** Transient key prefixes. */
	const CODE_PREFIX = 'hrd_sms_code_';

	/**
	 * Validated OTP length, clamped into the supported range.
	 */
	public static function code_length(): int {
		$length = (int) HRD_Auth_Sms::get_option_value( 'code_length', self::OTP_DEFAULT_LENGTH );

		return max( self::OTP_MIN_LENGTH, min( self::OTP_MAX_LENGTH, $length ) );
	}

	/**
	 * OTP / resend timer length in seconds.
	 */
	public static function expiration_seconds(): int {
		$minutes = (int) HRD_Auth_Sms::get_option_value( 'code_expiration_time', self::DEFAULT_EXPIRATION_MINUTES );
		$minutes = max( 1, $minutes );

		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether the admin-configurable (soft) attempt limit is enabled.
	 */
	public static function soft_limit_enabled(): bool {
		// Stored as 1/0 (legacy installs may hold 'on'); treat any non-empty as enabled.
		return ! empty( HRD_Auth_Sms::get_option_value( 'enable_attempt_limit', 1 ) );
	}

	public static function soft_attempt_max(): int {
		return max( 1, (int) HRD_Auth_Sms::get_option_value( 'max_attempt_limit', self::DEFAULT_ATTEMPT_LIMIT ) );
	}

	public static function soft_attempt_duration_seconds(): int {
		$minutes = max( 1, (int) HRD_Auth_Sms::get_option_value( 'attempt_limit_duration', self::DEFAULT_ATTEMPT_DURATION ) );

		return $minutes * MINUTE_IN_SECONDS;
	}

	public static function sms_api_key(): string {
		return (string) HRD_Auth_Sms::get_option_value( 'sms_ir_api_key', '' );
	}

	public static function sms_template_id(): string {
		return (string) HRD_Auth_Sms::get_option_value( 'sms_ir_template_id', '' );
	}

	/**
	 * Sanitised custom login slug.
	 */
	public static function login_slug(): string {
		return sanitize_title( HRD_Auth_Sms::get_option_value( 'login_slug', 'hrd-login' ) ) ?: 'hrd-login';
	}
}
