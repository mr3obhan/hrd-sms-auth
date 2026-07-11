<?php

namespace HRD_Auth_Sms\Services;

use HRD_Auth_Sms\Config;
use HRD_Auth_Sms\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the sms.ir "verify" (template) endpoint.
 *
 * Success is determined by parsing the provider response body, NOT only the
 * HTTP status code. sms.ir returns `{ "status": 1, "message": "...", ... }`
 * where status === 1 means the message was accepted.
 */
class SmsIrGateway {

	const ENDPOINT = 'https://api.sms.ir/v1/send/verify';

	/**
	 * Send a verification code.
	 *
	 * @return array{success:bool,message:string} `message` carries a provider
	 *               detail useful for admins/logging. Callers MUST NOT surface
	 *               it to end users.
	 */
	public static function send_verify( string $mobile, $code ): array {
		$api_key     = Config::sms_api_key();
		$template_id = Config::sms_template_id();

		if ( $api_key === '' || $template_id === '' ) {
			return [
				'success' => false,
				'message' => 'SMS gateway is not configured (missing API key or template id).',
			];
		}

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 15,
			'headers' => [
				'X-API-KEY'    => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'Mobile'     => $mobile,
				'TemplateId' => $template_id,
				'Parameters' => self::build_parameters( (string) $code ),
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => 'SMS request failed: ' . $response->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		// sms.ir signals success with status === 1 in the JSON body.
		$provider_status = is_array( $body ) && isset( $body['status'] ) ? (int) $body['status'] : null;

		if ( $status === 200 && $provider_status === 1 ) {
			return [ 'success' => true, 'message' => 'sent' ];
		}

		$detail = is_array( $body ) && ! empty( $body['message'] )
			? (string) $body['message']
			: 'HTTP ' . $status;

		return [
			'success' => false,
			'message' => 'SMS provider rejected the request: ' . $detail,
		];
	}

	/**
	 * Build the sms.ir Parameters array from the admin-defined template
	 * parameters, substituting the supported placeholders into each value.
	 *
	 * Placeholders: {code}, {domain}, {site_name}, {site_url}
	 *
	 * @return array<int,array{Name:string,Value:string}>
	 */
	private static function build_parameters( string $code ): array {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		$replacements = [
			'{code}'      => $code,
			'{domain}'    => $host,
			'{site}'      => $host,
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
		];

		$built = [];
		foreach ( SettingsService::get( 'sms_parameters' ) as $param ) {
			$name = (string) ( $param['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$built[] = [
				'Name'  => $name,
				'Value' => strtr( (string) ( $param['value'] ?? '' ), $replacements ),
			];
		}

		// Never send an empty parameter set.
		if ( empty( $built ) ) {
			$built[] = [ 'Name' => 'CODE', 'Value' => $code ];
		}

		return $built;
	}
}
