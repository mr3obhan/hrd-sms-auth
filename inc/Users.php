<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

class Users {

	public function __construct() {

	}

	/**
	 * بررسی وجود کاربر بر اساس شماره موبایل. شماره ممکن است در متای hrd_phone یا
	 * متای صورتحساب/ارسال ووکامرس یا نام کاربری ذخیره شده باشد.
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

		// بعد: hrd_phone
		$users = get_users( [
			'meta_key'   => 'hrd_phone',
			'meta_value' => $mobile,
			'number'     => 1,
			'fields'     => [ 'ID' ],
		] );
		if ( ! empty( $users ) ) {
			return (int) $users[0]->ID;
		}

		// در صورت تمایل: متاهای ووکامرس، حتی اگر ووکامرس نصب نباشد هم امن است
		foreach ( [ 'billing_phone', 'shipping_phone' ] as $mk ) {
			$users = get_users( [
				'meta_key'   => $mk,
				'meta_value' => $mobile,
				'number'     => 1,
				'fields'     => [ 'ID' ],
			] );
			if ( ! empty( $users ) ) {
				return (int) $users[0]->ID;
			}
		}

		return false;
	}

	public static function hrd_sms_create_user( $mobile, $arg = [] ): int|\WP_Error {
		// Setup Parameter
		$defaults = [
			'user_login'   => $mobile,
			'user_pass'    => wp_generate_password( 12, true, true ),
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => '',
			'role'         => 'subscriber'
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
