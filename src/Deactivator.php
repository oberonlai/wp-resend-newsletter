<?php
/**
 * Plugin Deactivator
 *
 * Handles plugin deactivation tasks.
 *
 * @package WpResendNewsletter
 */

namespace WpResendNewsletter;

use WpResendNewsletter\Application\BroadcastSender;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivator class.
 *
 * This class handles all tasks that need to run during plugin deactivation.
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * This method is called when the plugin is deactivated.
	 * Add your deactivation logic here, such as:
	 * - Clearing scheduled cron jobs
	 * - Flushing rewrite rules
	 * - Cleaning up transients
	 *
	 * Note: Do NOT delete database tables or options here.
	 * Users may want to reactivate the plugin later.
	 * Use an uninstall.php file for complete cleanup.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clear scheduled cron jobs.
		self::clear_scheduled_events();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clear transients.
		self::clear_transients();

		// Trigger deactivation hook for other plugins/themes to hook into.
		do_action( 'wprn_deactivated' );
	}

	/**
	 * Clear scheduled cron events.
	 *
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		BroadcastSender::clear_cron();
	}

	/**
	 * Clear plugin transients.
	 *
	 * @return void
	 */
	private static function clear_transients(): void {
		// phpcs:disable Squiz.PHP.CommentedOutCode.Found, Squiz.Commenting.InlineComment.InvalidEndChar
		// Example: Delete plugin transients.
		// delete_transient( 'wprn_cache' );
		// phpcs:enable Squiz.PHP.CommentedOutCode.Found, Squiz.Commenting.InlineComment.InvalidEndChar
	}
}
