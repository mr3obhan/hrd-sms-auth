<?php

namespace HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless phone/IP helpers shared across the plugin.
 *
 * Rate-limiting now lives in Services\RateLimiter; this class only holds the
 * pure input-normalisation utilities.
 */
class Helper {

	/**
	 * Convert Persian/Arabic digits to ASCII digits.
	 */
	public static function hrd_convert_to_english_digits( string $value ): string {
		$persian_digits = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ];
		$english_digits = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];

		return str_replace( $persian_digits, $english_digits, $value );
	}

	/**
	 * Return the client IP in a simple, sanitised way.
	 */
	public static function hrd_user_ip(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_AR_REAL_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];

		$ip = '';
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( str_contains( $raw, ',' ) ) {
					$parts = explode( ',', $raw );
					$raw   = trim( $parts[0] );
				}
				$clean = filter_var( $raw, FILTER_VALIDATE_IP );
				if ( $clean !== false ) {
					$ip = $clean;
					break;
				}
			}
		}

		if ( $ip === '' ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '0.0.0.0';
		}

		return (string) apply_filters( 'hrd_client_ip', $ip );
	}

	/**
	 * Validate an Iranian mobile number (expects 09XXXXXXXXX).
	 *
	 * @return array{success:bool,text:string}
	 */
	public static function hrd_sanitize_phone( $mobile ): array {
		$mobile = trim( self::hrd_convert_to_english_digits( (string) $mobile ) );

		if ( empty( $mobile ) ) {
			return [ 'success' => false, 'text' => 'لطفا شماره موبایل را وارد نمائید.' ];
		}

		if ( ! is_numeric( $mobile ) ) {
			return [ 'success' => false, 'text' => 'شماره همراه تنها شامل ارقام است.' ];
		}

		if ( strlen( $mobile ) !== 11 ) {
			return [ 'success' => false, 'text' => 'شماره باید ۱۱ رقم باشد.' ];
		}

		if ( ! str_starts_with( $mobile, '09' ) ) {
			return [ 'success' => false, 'text' => 'شماره همراه باید با ۰۹ شروع شود.' ];
		}

		return [ 'success' => true, 'text' => '' ];
	}

	/**
	 * Normalise an Iranian mobile number to the canonical 09XXXXXXXXX form.
	 * Accepts 0912…, +98912…, 0098912…, 98912…, 912… (10 digits).
	 *
	 * @return string|false Canonical number, or false if it cannot be normalised.
	 */
	public static function hrd_normalize_mobile( $mobile ) {
		$m = self::hrd_convert_to_english_digits( (string) $mobile );
		$m = preg_replace( '/[^\d+]/', '', $m );

		if ( strpos( $m, '+98' ) === 0 ) {
			$m = '0' . substr( $m, 3 );
		} elseif ( strpos( $m, '0098' ) === 0 ) {
			$m = '0' . substr( $m, 4 );
		} elseif ( strpos( $m, '98' ) === 0 && strlen( $m ) >= 12 ) {
			$m = '0' . substr( $m, 2 );
		} elseif ( preg_match( '/^9\d{9}$/', $m ) ) {
			$m = '0' . $m;
		}

		return preg_match( '/^09\d{9}$/', $m ) ? $m : false;
	}

	/**
	 * Validate an Iranian national ID (کد ملی) using its checksum.
	 */
	public static function valid_national_id( string $code ): bool {
		$code = preg_replace( '/\D/', '', self::hrd_convert_to_english_digits( $code ) );

		if ( strlen( $code ) !== 10 || preg_match( '/^(\d)\1{9}$/', $code ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $code[ $i ] * ( 10 - $i );
		}
		$r = $sum % 11;
		$c = (int) $code[9];

		return $r < 2 ? $c === $r : $c === ( 11 - $r );
	}

	/** Validate the Jalali YYYY/MM/DD shape and month/day ranges. */
	public static function valid_birth_date( string $date ): bool {
		if ( ! preg_match( '/^(13\d{2}|14\d{2})\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $date, $m ) ) {
			return false;
		}
		$month = (int) $m[2];
		$day   = (int) $m[3];
		$year  = (int) $m[1];
		$latest_year = (int) gmdate( 'Y' ) - 621 - 12;

		return $year >= 1320 && $year <= $latest_year && $day <= ( $month <= 6 ? 31 : 30 );
	}
}
