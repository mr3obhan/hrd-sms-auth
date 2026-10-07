<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the custom login page: rewrite rule, login takeover and safe redirects.
 *
 * All redirect targets coming from the request are passed through
 * wp_validate_redirect()/wp_safe_redirect() so an attacker cannot use
 * `redirect_to` to bounce users to an external host (open redirect).
 */
class LoginControl {

	public static string $slug = 'hrd-login';

	public function __construct() {
		self::$slug = Config::login_slug();

		add_action( 'init', [ $this, 'add_custom_rewrite' ] );
		add_action( 'init', [ $this, 'maybe_redirect_login_pages' ] );
		add_action( 'template_redirect', [ $this, 'redirect_woocommerce_login_page' ] );
		add_action( 'template_redirect', [ $this, 'load_custom_login_template' ] );

		add_filter( 'login_redirect', [ $this, 'force_redirect_to_param' ], 10, 3 );
		add_filter( 'woocommerce_login_redirect', [ $this, 'force_redirect_to_param_wc' ], 10, 2 );

		// After logout, go to the homepage instead of wp-login.php.
		add_filter( 'logout_redirect', [ $this, 'force_logout_redirect' ], 10, 3 );
		add_filter( 'woocommerce_logout_default_redirect_url', [ $this, 'force_wc_logout_redirect' ] );

		add_action( 'update_option_hrd_sms_auth_options', [ $this, 'maybe_flag_rewrite_flush' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_perform_flush' ] );
	}

	/**
	 * Resolve a safe local redirect target from the request, falling back to home.
	 */
	private function safe_redirect_target( string $fallback = '' ): string {
		$fallback = $fallback ?: home_url( '/' );

		if ( empty( $_REQUEST['redirect_to'] ) ) {
			return $fallback;
		}

		$requested = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );

		// wp_validate_redirect() returns the fallback for any off-site host.
		return wp_validate_redirect( $requested, $fallback );
	}

	public function force_redirect_to_param( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			return $this->safe_redirect_target( $redirect_to );
		}

		return $redirect_to;
	}

	public function force_redirect_to_param_wc( $redirect, $user ) {
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			return $this->safe_redirect_target( $redirect );
		}

		return $redirect;
	}

	/**
	 * Send users to the homepage after logout (never wp-login.php). An explicit,
	 * same-origin redirect_to is still honoured if one was requested.
	 */
	public function force_logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! empty( $requested_redirect_to ) ) {
			$safe = wp_validate_redirect( $requested_redirect_to, '' );
			if ( $safe ) {
				return $safe;
			}
		}

		return home_url( '/' );
	}

	/**
	 * WooCommerce logout fallback → homepage.
	 */
	public function force_wc_logout_redirect( $redirect ) {
		return home_url( '/' );
	}

	/**
	 * Block wp-login.php and send guests to the custom login page.
	 */
	public function maybe_redirect_login_pages(): void {
		if ( is_user_logged_in() || ! HRD_Auth_Sms::get_option_value( 'disable_wp_login', false ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Never block the POST handler itself (logout/postpass actions rely on it).
		if ( strpos( $request_uri, 'wp-login.php' ) !== false && ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'GET' ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
			if ( in_array( $action, [ 'logout', 'postpass', 'resetpass', 'rp' ], true ) ) {
				return;
			}

			wp_safe_redirect( home_url( '/' . self::$slug ) );
			exit;
		}
	}

	public function redirect_woocommerce_login_page(): void {
		if ( is_user_logged_in() || ! HRD_Auth_Sms::get_option_value( 'disable_wc_login', false ) ) {
			return;
		}

		$login_url = home_url( '/' . self::$slug );

		// If checkout page is accessed by guests, only bounce if guest checkout is disabled
		// and never bounce from the order confirmation (Thank You) or order-pay pages.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
				|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) ) {
				return;
			}

			// Respect WooCommerce guest checkout option
			if ( 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ) ) {
				return;
			}

			$login_url = add_query_arg( 'redirect_to', wc_get_checkout_url(), $login_url );
			wp_safe_redirect( $login_url );
			exit;
		}

		// Replace the WooCommerce my-account login with the custom page.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			if ( ! empty( $_GET['redirect_to'] ) ) {
				$target    = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' );
				$login_url = $target ? add_query_arg( 'redirect_to', $target, $login_url ) : $login_url;
			}
			wp_safe_redirect( $login_url );
			exit;
		}
	}

	public function add_custom_rewrite(): void {
		add_rewrite_tag( '%hrd_sms_login_page%', '1' );
		add_rewrite_rule( '^' . self::$slug . '/?$', 'index.php?hrd_sms_login_page=1', 'top' );
	}

	public function load_custom_login_template(): void {
		if ( get_query_var( 'hrd_sms_login_page' ) !== '1' ) {
			return;
		}

		if ( is_user_logged_in() ) {
			// Logged-in users who still owe a mobile verification get the
			// verification screen; everyone else is sent on their way.
			if ( LoginMode::requires_verification( get_current_user_id() ) ) {
				Front::$page_mode = 'verify';
				include HRD_Auth_Sms::$plugin_path . 'inc/templates/login-form.php';
				exit;
			}

			$fallback = function_exists( 'wc_get_account_endpoint_url' )
				? wc_get_account_endpoint_url( 'dashboard' )
				: home_url( '/' );

			wp_safe_redirect( $this->safe_redirect_target( $fallback ) );
			exit;
		}

		include HRD_Auth_Sms::$plugin_path . 'inc/templates/login-form.php';
		exit;
	}

	public function maybe_flag_rewrite_flush( $old, $new ): void {
		if ( ( $old['login_slug'] ?? '' ) !== ( $new['login_slug'] ?? '' ) ) {
			update_option( '_hrd_needs_rewrite_flush', 1 );
		}
	}

	public function maybe_perform_flush(): void {
		if ( get_option( '_hrd_needs_rewrite_flush' ) ) {
			self::$slug = Config::login_slug();
			$this->add_custom_rewrite();
			flush_rewrite_rules();
			delete_option( '_hrd_needs_rewrite_flush' );
		}
	}
}

new LoginControl();
