<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms\Services\OtpService;
use HRD_Auth_Sms\Services\SmsIrGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only admin-ajax endpoints.
 *
 * The public OTP flow lives entirely in the REST API (RestAPI_Auth + OtpService).
 * Only privileged admin tools remain here, each guarded by a capability check
 * and a nonce.
 */
class Ajax {

	public function __construct() {
		$admin_actions = [
			'admin_send_test_sms',
			'sync_woocommerce_users',
		];

		foreach ( $admin_actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, $action ] );
		}
	}

	/**
	 * Shared guard for every admin endpoint.
	 */
	private function guard(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'دسترسی غیرمجاز.', 'hrd-sms-auth' ) ], 403 );
		}
		check_ajax_referer( 'hrd_sms_auth_nonce', 'nonce' );
	}

	/**
	 * Send a test SMS. Admin-only, so provider detail may be surfaced here.
	 */
	public function admin_send_test_sms(): void {
		$this->guard();

		$mobile = Helper::hrd_convert_to_english_digits( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );

		if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
			wp_send_json_error( [ 'message' => 'شماره موبایل وارد شده نامعتبر است.' ] );
		}

		$code   = OtpService::generate_code();
		$result = SmsIrGateway::send_verify( $mobile, $code );

		if ( $result['success'] ) {
			wp_send_json_success( [ 'message' => 'پیامک تستی با کد ' . $code . ' با موفقیت ارسال شد.' ] );
		}

		wp_send_json_error( [ 'message' => 'ارسال پیامک تستی ناموفق بود: ' . $result['message'] ] );
	}

	/**
	 * Backfill WooCommerce billing/shipping phone for users whose login is an
	 * Iranian mobile number. Batched-safe via WP_User_Query field limiting.
	 */
	public function sync_woocommerce_users(): void {
		$this->guard();

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( [ 'message' => 'ووکامرس نصب نیست.' ] );
		}

		$users = get_users( [ 'fields' => [ 'ID', 'user_login' ] ] );
		$count = 0;

		foreach ( $users as $user ) {
			$mobile = $user->user_login;

			if ( ! preg_match( '/^09\d{9}$/', $mobile ) ) {
				continue;
			}

			if ( empty( get_user_meta( $user->ID, 'billing_phone', true ) ) ) {
				update_user_meta( $user->ID, 'billing_phone', $mobile );
				update_user_meta( $user->ID, 'shipping_phone', $mobile );
				$count ++;
			}
		}

		wp_send_json_success( [ 'message' => sprintf( 'تعداد %d کاربر با موفقیت همگام‌سازی شدند.', $count ) ] );
	}
}

new Ajax();
