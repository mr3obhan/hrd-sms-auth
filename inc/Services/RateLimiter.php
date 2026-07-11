<?php

namespace HRD_Auth_Sms\Services;

use HRD_Auth_Sms\Config;
use HRD_Auth_Sms\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Brute-force protection for OTP send/verify.
 *
 * Two layers are enforced:
 *   1. A "soft" limit the admin can configure and disable.
 *   2. A "hard" ceiling that ALWAYS applies, so disabling the soft limit can
 *      never expose the OTP flow to unlimited brute-force.
 *
 * Counters are keyed by mobile + client IP.
 */
class RateLimiter {

	private static function key( string $type, string $mobile ): string {
		return 'hrd_sms_' . $type . '_' . md5( $mobile . '|' . Helper::hrd_user_ip() );
	}

	/* ---------------- Sending OTP ---------------- */

	/**
	 * @return bool True when another OTP request is allowed.
	 */
	public static function send_allowed( string $mobile ): bool {
		$hard = (int) ( get_transient( self::key( 'send_hard', $mobile ) ) ?: 0 );
		if ( $hard >= Config::HARD_SEND_MAX ) {
			return false;
		}

		if ( Config::soft_limit_enabled() ) {
			$soft = (int) ( get_transient( self::key( 'send_soft', $mobile ) ) ?: 0 );
			if ( $soft >= Config::soft_attempt_max() ) {
				return false;
			}
		}

		return true;
	}

	public static function record_send( string $mobile ): void {
		$hard = (int) ( get_transient( self::key( 'send_hard', $mobile ) ) ?: 0 );
		set_transient( self::key( 'send_hard', $mobile ), $hard + 1, Config::HARD_WINDOW_MINUTES * MINUTE_IN_SECONDS );

		if ( Config::soft_limit_enabled() ) {
			$soft = (int) ( get_transient( self::key( 'send_soft', $mobile ) ) ?: 0 );
			set_transient( self::key( 'send_soft', $mobile ), $soft + 1, Config::soft_attempt_duration_seconds() );
		}
	}

	/* ---------------- Verifying OTP ---------------- */

	public static function verify_allowed( string $mobile ): bool {
		$hard = (int) ( get_transient( self::key( 'verify_hard', $mobile ) ) ?: 0 );
		if ( $hard >= Config::HARD_VERIFY_MAX ) {
			return false;
		}

		if ( Config::soft_limit_enabled() ) {
			$soft = (int) ( get_transient( self::key( 'verify_soft', $mobile ) ) ?: 0 );
			if ( $soft >= Config::soft_attempt_max() ) {
				return false;
			}
		}

		return true;
	}

	public static function record_failed_verify( string $mobile ): void {
		$hard = (int) ( get_transient( self::key( 'verify_hard', $mobile ) ) ?: 0 );
		set_transient( self::key( 'verify_hard', $mobile ), $hard + 1, Config::HARD_WINDOW_MINUTES * MINUTE_IN_SECONDS );

		if ( Config::soft_limit_enabled() ) {
			$soft = (int) ( get_transient( self::key( 'verify_soft', $mobile ) ) ?: 0 );
			set_transient( self::key( 'verify_soft', $mobile ), $soft + 1, Config::soft_attempt_duration_seconds() );
		}
	}

	/**
	 * Clear verify counters after a successful login.
	 */
	public static function clear_verify( string $mobile ): void {
		delete_transient( self::key( 'verify_hard', $mobile ) );
		delete_transient( self::key( 'verify_soft', $mobile ) );
	}

	/* ---------------- Email/password login (per-IP) ---------------- */

	const EMAIL_MAX = 8;            // failed attempts allowed
	const EMAIL_WINDOW_MIN = 15;    // within this many minutes

	private static function email_key(): string {
		return 'hrd_email_fail_' . md5( Helper::hrd_user_ip() );
	}

	public static function email_login_allowed(): bool {
		return (int) ( get_transient( self::email_key() ) ?: 0 ) < self::EMAIL_MAX;
	}

	public static function record_email_fail(): void {
		$count = (int) ( get_transient( self::email_key() ) ?: 0 ) + 1;
		set_transient( self::email_key(), $count, self::EMAIL_WINDOW_MIN * MINUTE_IN_SECONDS );
	}

	public static function clear_email_fail(): void {
		delete_transient( self::email_key() );
	}
}
