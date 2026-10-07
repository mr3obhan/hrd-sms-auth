<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

class Users {

	public function __construct() {

	}

	/**
	 * بررسی وجود کاربر بر اساس شماره موبایل تأییدشده. اطلاعات صورتحساب و ارسال
	 * ووکامرس عمداً منبع احراز هویت نیستند؛ آن فیلدها توسط کاربر قابل ویرایش‌اند.
	 * اگر کاربری یافت شود، شناسهٔ کاربر بازگردانده می‌شود.
	 *
	 * @param string $mobile شماره موبایل مورد جستجو
	 * @return int|false شناسهٔ کاربر یا false در صورت عدم وجود
	 */
	public static function hrd_sms_user_exist( $mobile ) {
		$mobile = trim( $mobile );
		if ( empty( $mobile ) ) return false;

		// اول نام کاربری برابر با شماره موبایل
		$user = get_user_by( 'login', $mobile );
		if ( $user && $user->ID ) {
			return (int) $user->ID;
		}

		// بعد: فقط hrd_phoneای که واقعاً با OTP تأیید شده است.
		$users = get_users( [
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => 'hrd_phone', 'value' => $mobile ],
				[ 'key' => 'hrd_mobile_verified', 'compare' => 'EXISTS' ],
			],
			'number' => 1,
			'fields' => [ 'ID' ],
		] );
		if ( ! empty( $users ) ) {
			return (int) $users[0]->ID;
		}

		return false;
	}

	/**
	 * Return whether a verified login number belongs to another account.
	 * An unverified hrd_phone is profile/contact data, not ownership proof.
	 */
	public static function mobile_owned_by_other( string $mobile, int $user_id = 0 ): bool {
		$user = get_user_by( 'login', $mobile );
		if ( $user && (int) $user->ID !== $user_id ) {
			return true;
		}

		$owners = get_users( [
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => 'hrd_phone', 'value' => $mobile ],
				[ 'key' => 'hrd_mobile_verified', 'compare' => 'EXISTS' ],
			],
			'number' => 2,
			'fields' => [ 'ID' ],
		] );
		foreach ( $owners as $owner ) {
			if ( (int) $owner->ID !== $user_id ) {
				return true;
			}
		}

		return false;
	}

	/** Detect multiple verified identities before authentication chooses one. */
	public static function mobile_has_conflict( string $mobile ): bool {
		$ids  = [];
		$user = get_user_by( 'login', $mobile );
		if ( $user ) {
			$ids[ (int) $user->ID ] = true;
		}

		$owners = get_users( [
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => 'hrd_phone', 'value' => $mobile ],
				[ 'key' => 'hrd_mobile_verified', 'compare' => 'EXISTS' ],
			],
			'number' => 3,
			'fields' => [ 'ID' ],
		] );
		foreach ( $owners as $owner ) {
			$ids[ (int) $owner->ID ] = true;
		}

		return count( $ids ) > 1;
	}

	public static function national_code_owned( string $national_code ): bool {
		return ! empty( get_users( [
			'meta_key'   => 'hrd_national_code',
			'meta_value' => $national_code,
			'number'     => 1,
			'fields'     => [ 'ID' ],
		] ) );
	}

	public static function hrd_sms_create_user( $mobile, $arg = [] ): int|\WP_Error {
		// Setup Parameter
		$host           = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site.local';
		$host           = preg_replace( '/^www\./i', '', $host );
		$fallback_email = $mobile . '@' . $host;
		$user_email     = (string) apply_filters( 'hrd_sms_default_user_email', $fallback_email, $mobile );

		if ( $user_email !== '' && email_exists( $user_email ) ) {
			$user_email = $mobile . '.' . wp_rand( 100, 999 ) . '@' . $host;
		}
		$defaults = [
			'user_login'   => $mobile,
			'user_pass'    => wp_generate_password( 12, true, true ),
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => '',
			'role'         => 'subscriber',
			'user_email'   => sanitize_email( $user_email ),
		];
		$args     = wp_parse_args( $arg, $defaults );

		// create user
		$user_id = wp_insert_user( $args );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// ذخیره شماره موبایل در متاها
		update_user_meta( $user_id, 'hrd_phone', $mobile );

		// در صورت وجود ووکامرس، این متاها هم پر شوند (اگر نبود هم مشکلی ایجاد نمی‌شود)
		update_user_meta( $user_id, 'billing_phone', $mobile );
		update_user_meta( $user_id, 'shipping_phone', $mobile );
		if ( ! empty( $args['user_email'] ) ) {
			update_user_meta( $user_id, 'billing_email', $args['user_email'] );
		}

		$first_name = trim( $args['first_name'] ?? '' );
		$last_name  = trim( $args['last_name'] ?? '' );

		if ( $first_name !== '' ) {
			update_user_meta( $user_id, 'billing_first_name', $first_name );
			update_user_meta( $user_id, 'shipping_first_name', $first_name );
		}

		if ( $last_name !== '' ) {
			update_user_meta( $user_id, 'billing_last_name', $last_name );
			update_user_meta( $user_id, 'shipping_last_name', $last_name );
		}

		return (int) $user_id;
	}

}
