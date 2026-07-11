<?php
/**
 * Standalone FULLSCREEN login page (served at the configured login slug).
 *
 * Deliberately does NOT load the theme header/footer/sidebar — it renders its
 * own minimal document so the page shows only the login form (plus the
 * back-to-home control). wp_head()/wp_footer() still fire so the widget's
 * enqueued assets and nonce print correctly.
 */

namespace HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

// No admin bar / theme chrome on the login screen.
add_filter( 'show_admin_bar', '__return_false' );

Front::enqueue();

if ( ! headers_sent() ) {
	nocache_headers();
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( SettingsService::get( 'login_title' ) ?: get_bloginfo( 'name' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="hrd-login-body">
	<?php echo Front::render( true ); // server-rendered shell, already escaped ?>
	<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
