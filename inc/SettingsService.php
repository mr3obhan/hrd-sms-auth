<?php

namespace HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every stored setting: defaults, type-aware
 * sanitisation (used as the register_setting() callback) and safe reads.
 *
 * It also builds the scoped, escaped inline CSS that themes the login widget
 * from the Design / Appearance settings.
 */
class SettingsService {

	const OPTION = 'hrd_sms_auth_options';

	/** Allowed values for enum settings. */
	const BG_TYPES = [ 'solid', 'gradient', 'image' ];
	const BACK_POSITIONS = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
	const LOGIN_MODES = [ 'mobile_only', 'email_only', 'email_mobile', 'email_then_verify', 'force_verify_old' ];

	/**
	 * key => type. Drives both sanitisation and safe reads.
	 *
	 * Types: text | textarea_css | int | bool | color | url | slug |
	 *        enum_bg | enum_pos | raw
	 */
	private static function types(): array {
		return [
			// SMS provider
			'sms_ir_api_key'         => 'text',
			'sms_ir_template_id'     => 'text',
			'sms_parameters'         => 'params',

			// OTP & security
			'code_expiration_time'   => 'int',
			'code_length'            => 'int',
			'enable_attempt_limit'   => 'bool',
			'attempt_limit_duration' => 'int',
			'max_attempt_limit'      => 'int',

			// Login mode + redirects / login takeover
			'login_mode'             => 'enum_login',
			'disable_wp_login'       => 'bool',
			'disable_wc_login'       => 'bool',
			'login_slug'             => 'slug',

			// Content
			'brand_name'             => 'text',
			'login_title'            => 'text',
			'login_subtitle'         => 'text',
			'show_logo'              => 'bool',
			'logo_url'               => 'url',
			'logo_width'             => 'int',
			'load_font'              => 'bool',
			'show_legal'             => 'bool',
			'terms_url'              => 'url',
			'privacy_url'            => 'url',
			'collect_profile'        => 'bool',

			// Back-to-home button
			'back_button_show'       => 'bool',
			'back_button_text'       => 'text',
			'back_button_url'        => 'url',
			'back_button_position'   => 'enum_pos',

			// Design / appearance
			'bg_type'                => 'enum_bg',
			'bg_color'               => 'color',
			'bg_gradient_start'      => 'color',
			'bg_gradient_end'        => 'color',
			'bg_image_url'           => 'url',
			'card_bg_color'          => 'color',
			'card_width'             => 'int',
			'card_radius'            => 'int',
			'card_shadow'            => 'bool',
			'primary_color'          => 'color',
			'button_bg_color'        => 'color',
			'button_text_color'      => 'color',
			'input_border_color'     => 'color',
			'input_focus_color'      => 'color',
			'text_color'             => 'color',
			'error_color'            => 'color',
			'success_color'          => 'color',
			'custom_css'             => 'textarea_css',
		];
	}

	/**
	 * Safe defaults. The plugin must render correctly with these alone.
	 */
	public static function defaults(): array {
		return [
			'sms_ir_api_key'         => '',
			'sms_ir_template_id'     => '',
			'sms_parameters'         => [
				[ 'name' => 'CODE', 'value' => '{code}' ],
				[ 'name' => 'OTP_CODE', 'value' => '#{code}' ],
				[ 'name' => 'DOMAIN', 'value' => '@{domain}' ],
			],

			'code_expiration_time'   => Config::DEFAULT_EXPIRATION_MINUTES,
			'code_length'            => Config::OTP_DEFAULT_LENGTH,
			'enable_attempt_limit'   => 1,
			'attempt_limit_duration' => Config::DEFAULT_ATTEMPT_DURATION,
			'max_attempt_limit'      => Config::DEFAULT_ATTEMPT_LIMIT,

			'login_mode'             => 'mobile_only',
			'disable_wp_login'       => 0,
			'disable_wc_login'       => 0,
			'login_slug'             => 'hrd-login',

			'brand_name'             => '',
			'login_title'            => 'به حساب خود وارد شوید',
			'login_subtitle'         => 'شماره موبایل خود را وارد کنید. کد تأیید برای شما پیامک می‌شود.',
			'show_logo'              => 1,
			'logo_url'               => '',
			'logo_width'             => 160,
			'load_font'              => 1,
			'show_legal'             => 1,
			'terms_url'              => '',
			'privacy_url'            => '',
			'collect_profile'        => 1,

			'back_button_show'       => 1,
			'back_button_text'       => 'بازگشت به صفحه اصلی',
			'back_button_url'        => '',
			'back_button_position'   => 'top-right',

			'bg_type'                => 'gradient',
			'bg_color'               => '#f4f5f8',
			'bg_gradient_start'      => '#f5f6f9',
			'bg_gradient_end'        => '#eaecf2',
			'bg_image_url'           => '',
			'card_bg_color'          => '#ffffff',
			'card_width'             => 412,
			'card_radius'            => 22,
			'card_shadow'            => 1,
			'primary_color'          => '#5957d8',
			'button_bg_color'        => '#5957d8',
			'button_text_color'      => '#ffffff',
			'input_border_color'     => '#dadce4',
			'input_focus_color'      => '#5957d8',
			'text_color'             => '#2b2f3b',
			'error_color'            => '#db4f44',
			'success_color'          => '#1fa873',
			'custom_css'             => '',
		];
	}

	/**
	 * Read a single, sanitised setting with a guaranteed safe value.
	 */
	public static function get( string $key ) {
		$opts     = get_option( self::OPTION, [] );
		$opts     = is_array( $opts ) ? $opts : [];
		$defaults = self::defaults();
		$default  = $defaults[ $key ] ?? '';
		$raw      = $opts[ $key ] ?? $default;

		return self::sanitize_value( $key, $raw, $default );
	}

	/**
	 * register_setting() sanitize callback — sanitises the whole option array.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();
		$clean    = [];

		foreach ( self::types() as $key => $type ) {
			$default = $defaults[ $key ] ?? '';

			if ( $type === 'bool' ) {
				// Unchecked checkboxes are simply absent from the POST.
				$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}

			$value         = $input[ $key ] ?? $default;
			$clean[ $key ] = self::sanitize_value( $key, $value, $default );
		}

		return $clean;
	}

	/**
	 * Type-aware sanitiser with a default fallback for invalid values.
	 */
	private static function sanitize_value( string $key, $value, $default ) {
		switch ( self::types()[ $key ] ?? 'text' ) {
			case 'int':
				$value = (int) $value;
				return self::clamp_int( $key, $value, (int) $default );

			case 'bool':
				return empty( $value ) ? 0 : 1;

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ?: $default;

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'slug':
				return sanitize_title( (string) $value ) ?: $default;

			case 'enum_bg':
				return in_array( $value, self::BG_TYPES, true ) ? $value : $default;

			case 'enum_pos':
				return in_array( $value, self::BACK_POSITIONS, true ) ? $value : $default;

			case 'enum_login':
				return in_array( $value, self::LOGIN_MODES, true ) ? $value : $default;

			case 'params':
				return self::sanitize_params( $value );

			case 'textarea_css':
				// Allow CSS, but strip anything that could break out of <style>.
				$css = (string) $value;
				$css = str_replace( [ '<', '>' ], '', $css );
				return trim( wp_strip_all_tags( $css ) );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Sanitise the SMS template parameters (rows of name/value). Names are
	 * limited to the token charset sms.ir accepts; empty-name rows are dropped.
	 * Falls back to the defaults when nothing valid remains.
	 */
	private static function sanitize_params( $rows ): array {
		$clean = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = preg_replace( '/[^A-Za-z0-9_]/', '', sanitize_text_field( $row['name'] ?? '' ) );
				if ( $name === '' ) {
					continue;
				}
				$clean[] = [
					'name'  => $name,
					'value' => sanitize_text_field( $row['value'] ?? '' ),
				];
			}
		}

		return $clean ?: self::defaults()['sms_parameters'];
	}

	/**
	 * Keep numeric settings inside sane bounds.
	 */
	private static function clamp_int( string $key, int $value, int $default ): int {
		switch ( $key ) {
			case 'code_length':
				return max( Config::OTP_MIN_LENGTH, min( Config::OTP_MAX_LENGTH, $value ?: $default ) );
			case 'code_expiration_time':
				return max( 1, min( 60, $value ?: $default ) );
			case 'attempt_limit_duration':
				return max( 1, min( 1440, $value ?: $default ) );
			case 'max_attempt_limit':
				return max( 1, min( 50, $value ?: $default ) );
			case 'card_width':
				return max( 300, min( 720, $value ?: $default ) );
			case 'card_radius':
				return max( 0, min( 40, $value ) );
			case 'logo_width':
				return max( 20, min( 600, $value ?: $default ) );
			default:
				return $value;
		}
	}

	/* ----------------------------------------------------------------- */

	/**
	 * Resolved back-button URL (defaults to the site home).
	 */
	public static function back_button_url(): string {
		$url = self::get( 'back_button_url' );

		return $url !== '' ? $url : home_url( '/' );
	}

	/**
	 * Build the scoped, escaped inline CSS that themes the widget.
	 */
	public static function inline_css(): string {
		$primary   = self::get( 'primary_color' );
		$tokens    = [
			'--hrd-primary'      => $primary,
			'--hrd-btn-bg'       => self::get( 'button_bg_color' ),
			'--hrd-btn-text'     => self::get( 'button_text_color' ),
			'--hrd-card-bg'      => self::get( 'card_bg_color' ),
			'--hrd-card-width'   => self::get( 'card_width' ) . 'px',
			'--hrd-card-radius'  => self::get( 'card_radius' ) . 'px',
			'--hrd-input-border' => self::get( 'input_border_color' ),
			'--hrd-input-focus'  => self::get( 'input_focus_color' ),
			'--hrd-text'         => self::get( 'text_color' ),
			'--hrd-error'        => self::get( 'error_color' ),
			'--hrd-success'      => self::get( 'success_color' ),
		];

		$css = '.hrd-auth{';
		foreach ( $tokens as $name => $value ) {
			$css .= $name . ':' . esc_attr( $value ) . ';';
		}
		// Use the bundled Persian font only when enabled.
		if ( self::get( 'load_font' ) ) {
			$css .= "font-family:'Vazirmatn',Tahoma,Arial,sans-serif;";
		}
		$css .= '}';

		// Card shadow toggle.
		$shadow = self::get( 'card_shadow' )
			? '0 1px 2px rgba(40,40,60,.06), 0 12px 32px -12px rgba(40,50,70,.18)'
			: 'none';
		$css   .= '.hrd-auth .hrd-card{box-shadow:' . $shadow . ';}';

		// Fullscreen page background.
		$css .= '.hrd-auth--fullscreen{background:' . self::page_background() . ';}';

		// Advanced custom CSS (already stripped of angle brackets on save).
		$custom = self::get( 'custom_css' );
		if ( $custom !== '' ) {
			$css .= "\n" . $custom;
		}

		return $css;
	}

	/**
	 * CSS value for the fullscreen background, per the chosen background type.
	 */
	private static function page_background(): string {
		switch ( self::get( 'bg_type' ) ) {
			case 'solid':
				return esc_attr( self::get( 'bg_color' ) );

			case 'image':
				$url = self::get( 'bg_image_url' );
				if ( $url !== '' ) {
					return esc_attr( self::get( 'bg_color' ) ) . ' url(' . esc_url( $url ) . ') center/cover no-repeat fixed';
				}
				return esc_attr( self::get( 'bg_color' ) );

			case 'gradient':
			default:
				return 'linear-gradient(160deg, '
					. esc_attr( self::get( 'bg_gradient_start' ) ) . ' 0%, '
					. esc_attr( self::get( 'bg_gradient_end' ) ) . ' 100%)';
		}
	}
}
