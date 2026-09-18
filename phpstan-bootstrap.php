<?php
/**
 * PHPStan bootstrap — plugin constants (main file also loads Composer/WP).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

if ( ! defined( 'WP_RESEND_NEWSLETTER_VERSION' ) ) {
	define( 'WP_RESEND_NEWSLETTER_VERSION', '0.0.0' );
}
if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_FILE' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_FILE', __DIR__ . '/wp-resend-newsletter.php' );
}
if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_DIR' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_URL' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_URL', 'http://example.org/wp-content/plugins/wp-resend-newsletter/' );
}
if ( ! defined( 'WP_RESEND_NEWSLETTER_PLUGIN_BASENAME' ) ) {
	define( 'WP_RESEND_NEWSLETTER_PLUGIN_BASENAME', 'wp-resend-newsletter/wp-resend-newsletter.php' );
}
