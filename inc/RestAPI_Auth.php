<?php

namespace HRD_Auth_Sms;

use HRD_Auth_Sms\Services\OtpService;
use HRD_Auth_Sms\Services\EmailAuth;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The single public API surface for the OTP login flow.
 *
 * Endpoints:
 *   POST /wp-json/hrd-sms-auth/v1/send-code
 *   POST /wp-json/hrd-sms-auth/v1/verify-code
 *
 * Both require a valid `wp_rest` nonce (X-WP-Nonce header). All business logic
 * lives in OtpService.
 */
class RestAPI_Auth {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'hrd-sms-auth/v1', '/send-code', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'send_code' ],
			'permission_callback' => [ $this, 'check_rest_nonce' ],
			'args'                => [
				'mobile' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( 'hrd-sms-auth/v1', '/nonce', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_nonce' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'hrd-sms-auth/v1', '/verify-code', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_code' ],
			'permission_callback' => [ $this, 'check_rest_nonce' ],
			'args'                => [
				'mobile'        => [ 'type' => 'string', 'required' => true ],
				'code'          => [ 'type' => 'string', 'required' => false ],
				'reg_token'     => [ 'type' => 'string', 'required' => false ],
				'first_name'    => [ 'type' => 'string', 'required' => false ],
				'last_name'     => [ 'type' => 'string', 'required' => false ],
				'national_code' => [ 'type' => 'string', 'required' => false ],
				'birth_date'    => [ 'type' => 'string', 'required' => false ],
			],
		] );

		// Email / username + password login from the custom page.
		register_rest_route( 'hrd-sms-auth/v1', '/login', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login_email' ],
			'permission_callback' => [ $this, 'check_rest_nonce' ],
			'args'                => [
				'user'        => [ 'type' => 'string', 'required' => true ],
				'password'    => [ 'type' => 'string', 'required' => true ],
				'remember'    => [ 'type' => 'boolean', 'required' => false ],
				'redirect_to' => [ 'type' => 'string', 'required' => false ],
			],
		] );

		// Post-login mobile verification (attaches a mobile to the current user).
		register_rest_route( 'hrd-sms-auth/v1', '/attach-mobile', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'attach_mobile' ],
			'permission_callback' => [ $this, 'check_logged_in_nonce' ],
			'args'                => [
				'mobile' => [ 'type' => 'string', 'required' => true ],
				'code'   => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	/**
	 * Validate the REST nonce for these unauthenticated endpoints.
	 */
	public function check_rest_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'hrd-sms-auth' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Permission for the attach-mobile endpoint: a logged-in user with a valid nonce.
	 */
	public function check_logged_in_nonce( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Login required.', 'hrd-sms-auth' ), [ 'status' => 401 ] );
		}

		return $this->check_rest_nonce( $request );
	}

	public function attach_mobile( WP_REST_Request $request ): WP_REST_Response {
		$result = OtpService::attach_mobile(
			get_current_user_id(),
			$request->get_param( 'mobile' ),
			$request->get_param( 'code' )
		);

		return $this->respond( $result );
	}

	public function login_email( WP_REST_Request $request ): WP_REST_Response {
		$result = EmailAuth::login(
			$request->get_param( 'user' ),
			$request->get_param( 'password' ),
			(bool) $request->get_param( 'remember' ),
			(string) $request->get_param( 'redirect_to' )
		);

		return $this->respond( $result );
	}

	public function send_code( WP_REST_Request $request ): WP_REST_Response {
		$result = OtpService::send( $request->get_param( 'mobile' ) );

		return $this->respond( $result );
	}

	public function get_nonce(): WP_REST_Response {
		return new WP_REST_Response( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ], 200 );
	}

	public function verify_code( WP_REST_Request $request ): WP_REST_Response {
		$result = OtpService::verify(
			$request->get_param( 'mobile' ),
			$request->get_param( 'code' ),
			[
				'first_name'    => (string) $request->get_param( 'first_name' ),
				'last_name'     => (string) $request->get_param( 'last_name' ),
				'national_code' => (string) $request->get_param( 'national_code' ),
				'birth_date'    => (string) $request->get_param( 'birth_date' ),
				'reg_token'     => (string) $request->get_param( 'reg_token' ),
			]
		);

		return $this->respond( $result );
	}

	/**
	 * Map an OtpService result to a REST response.
	 */
	private function respond( array $result ): WP_REST_Response {
		$payload = array_merge(
			[ 'success' => $result['ok'], 'message' => $result['message'] ],
			$result['data']
		);

		return new WP_REST_Response( $payload, $result['status'] );
	}
}

new RestAPI_Auth();
