<?php
/**
 * Plugin Name:       WP Resend Newsletter
 * Plugin URI:        https://codotx.com
 * Description:       In-site subscriber list, campaigns, and queued email sending via Resend API (resend/resend-php). Batch ≤50 recipients/request. Confirm/unsubscribe + bounce/complaint webhooks. MVP: no drag-drop designer, no global wp_mail replacement.
 * Version:           0.3.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Oberon Lai
 * Author URI:        https://codotx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-resend-newsletter
 * Domain Path:       /languages
 * Network:           false
 *
 * @package WpResendNewsletter
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_RESEND_NEWSLETTER_VERSION', '0.3.2' );
define( 'WP_RESEND_NEWSLETTER_PLUGIN_FILE', __FILE__ );
define( 'WP_RESEND_NEWSLETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RESEND_NEWSLETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_RESEND_NEWSLETTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'WPRN_RESEND_BATCH_MAX' ) ) {
	define( 'WPRN_RESEND_BATCH_MAX', 50 );
}

$wprn_autoload = WP_RESEND_NEWSLETTER_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $wprn_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP Resend Newsletter requires Composer dependencies. Run composer install in the plugin directory.', 'wp-resend-newsletter' );
			echo '</p></div>';
		}
	);
	return;
}
require_once $wprn_autoload;

use WpResendNewsletter\Activator;
use WpResendNewsletter\Bootstrap;
use WpResendNewsletter\Deactivator;

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Bootstrap::get_instance();
	}
);
