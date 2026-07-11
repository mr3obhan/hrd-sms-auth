<?php

/**
 * Plugin Name: SMS Login & Register
 * Description: افزونه ثبت‌نام و ورود با شماره موبایل برای وردپرس و ووکامرس
 * Version: 2.7.0
 * Author: HRD Dev Team
 * Author URI: https://hamrocket.com
 * Text Domain: hrd-sms-auth
 */

defined('ABSPATH') || exit;

class HRD_Auth_Sms
{

	/**
	 * Minimum PHP version required
	 *
	 * @var string
	 */
	private $min_php = '8.0.0';

	/**
	 * URL to this plugin's directory.
	 *
	 * @type string
	 * @status Core
	 */
	public static string $plugin_url;

	/**
	 * Path to this plugin's directory.
	 *
	 * @type string
	 * @status Core
	 */
	public static string $plugin_path;

	/**
	 * Path to this plugin's directory.
	 *
	 * @type string
	 * @status Core
	 */
	public static string $plugin_version;

	/**
	 * Options plugin.
	 *
	 * @type string
	 * @status Core
	 */
	public static $plugin_options;

	/**
	 * Plugin instance.
	 *
	 * @see get_instance()
	 * @status Core
	 */
	protected static $_instance = null;

	/**
	 * Access this plugin’s working instance
	 *
	 * @wp-hook plugins_loaded
	 * @return  object of this class
	 * @since   2012.09.13
	 */
	public static function instance()
	{
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * constructor.
	 */
	public function __construct()
	{
		// Check required PHP version
		if (version_compare(PHP_VERSION, $this->min_php, '<')) {
			add_action('admin_notices', [$this, 'php_version_notice']);

			return;
		}

		// Define plugin constants
		$this->define_constants();

		// Include necessary files
		$this->includes();

		// Setup hooks
		$this->init_hooks();

		// Plugin loaded hook
		do_action('hrd_sms_auth_loaded');
	}

	/**
	 * Define plugin constants
	 */
	public function define_constants()
	{

		$plugin_data = get_file_data(__FILE__, [
			'Version' => 'Version',
		]);

		/*
		 * Set Plugin Version
		 */
		self::$plugin_version = $plugin_data['Version'];

		/*
         * Get Plugin Option
         */
		$stored_options = get_option('hrd_sms_auth_options');
		if (!is_array($stored_options)) {
			$stored_options = [];
		}
		self::$plugin_options = $stored_options;

		/*
		 * Set Plugin Url
		 */
		self::$plugin_url = plugins_url('', __FILE__);

		/*
		 * Set Plugin Path
		 */
		self::$plugin_path = plugin_dir_path(__FILE__);

		/*
		 * Record the first time the plugin ran (used by the "force verify old
		 * users" login mode). Set lazily so existing installs get a value too.
		 */
		if (!get_option('hrd_sms_auth_installed_at')) {
			update_option('hrd_sms_auth_installed_at', time(), false);
		}
	}

	public function includes()
	{
		$inc = dirname(__FILE__) . '/inc/';

		// Foundation
		include_once $inc . 'Config.php';
		include_once $inc . 'SettingsService.php';
		include_once $inc . 'Helper.php';

		// Service layer (single source of truth for the OTP flow)
		include_once $inc . 'Services/RateLimiter.php';
		include_once $inc . 'Services/SmsIrGateway.php';
		include_once $inc . 'Services/OtpService.php';
		include_once $inc . 'Services/EmailAuth.php';

		// Domain + controllers
		include_once $inc . 'Users.php';
		include_once $inc . 'RestAPI_Auth.php';
		include_once $inc . 'Ajax.php';
		include_once $inc . 'Front.php';
		include_once $inc . 'LoginControl.php';
		include_once $inc . 'LoginMode.php';
		include_once $inc . 'Migration.php';
		include_once $inc . 'admin/Admin.php';
	}

	public function init_hooks()
	{
		// Register rewrite + flush ONCE on activation so the login slug never 404s.
		register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);

		// Only flush rewrite rules on deactivation. Settings are NEVER deleted
		// here — real cleanup happens in uninstall.php (true uninstall only).
		register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);
	}

	/**
	 * Activation: ensure the custom login rewrite rule exists, then flush.
	 */
	public static function on_activate()
	{
		if (class_exists('\\HRD_Auth_Sms\\LoginControl')) {
			(new \HRD_Auth_Sms\LoginControl())->add_custom_rewrite();
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: just drop the rewrite rules. No data is removed.
	 */
	public static function on_deactivate()
	{
		flush_rewrite_rules();
	}

	/**
	 * Show PHP version error
	 */
	public function php_version_notice()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$error = 'Your installed PHP Version is: ' . PHP_VERSION . '. ';
		$error .= 'The WP Plugin plugin requires PHP version ' . $this->min_php . ' or greater.';
?>
		<div class="error">
			<p><?php echo esc_html($error); ?></p>
		</div>
<?php
	}

	/**
	 * Safe access to plugin options with fallback
	 */
	public static function get_option_value($key, $default = null)
	{
		return is_array(self::$plugin_options) && isset(self::$plugin_options[$key])
			? self::$plugin_options[$key]
			: $default;
	}
}

function hrd_sms_auth()
{
	return HRD_Auth_Sms::instance();
}

// Global for backwards compatibility.
$GLOBALS['hrd-sms-auth'] = hrd_sms_auth();
