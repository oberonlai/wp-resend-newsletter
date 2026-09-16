<?php
/**
 * Plugin Activator
 *
 * Handles plugin activation tasks.
 *
 * @package WpResendNewsletter
 */

namespace WpResendNewsletter;

use WpResendNewsletter\Application\BroadcastSender;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;
use WpResendNewsletter\Frontend\CampaignViewPage;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator class.
 *
 * This class handles all tasks that need to run during plugin activation.
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * This method is called when the plugin is activated.
	 * Add your activation logic here, such as:
	 * - Creating database tables
	 * - Setting default options
	 * - Scheduling cron jobs
	 * - Flushing rewrite rules
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Check minimum PHP version.
		if ( version_compare( phpversion(), '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_RESEND_NEWSLETTER_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'This plugin requires PHP 8.1 or higher.', 'wp-resend-newsletter' ),
				esc_html__( 'Plugin Activation Error', 'wp-resend-newsletter' ),
				array( 'back_link' => true )
			);
		}

		// Check minimum WordPress version.
		if ( version_compare( get_bloginfo( 'version' ), '6.5', '<' ) ) {
			deactivate_plugins( plugin_basename( WP_RESEND_NEWSLETTER_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'This plugin requires WordPress 6.5 or higher.', 'wp-resend-newsletter' ),
				esc_html__( 'Plugin Activation Error', 'wp-resend-newsletter' ),
				array( 'back_link' => true )
			);
		}

		// Set default options.
		self::set_default_options();

		// Create database tables if needed.
		self::create_tables();

		// Schedule cron jobs if needed.
		self::schedule_events();

		// Register campaign view rewrites, then flush.
		CampaignViewPage::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( CampaignViewPage::REWRITE_VERSION_OPTION, CampaignViewPage::REWRITE_VERSION );

		// Store plugin version for future upgrades.
		update_option( 'wprn_version', Bootstrap::VERSION );

		// Trigger activation hook for other plugins/themes to hook into.
		do_action( 'wprn_activated' );
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'from_email'     => '',
			'from_name'      => '',
			'api_key'        => '',
			'segment_id'     => '',
			'webhook_secret' => '',
		);

		if ( false === get_option( 'wprn_settings' ) ) {
			// Secrets live here — never autoload onto every front-end request.
			add_option( 'wprn_settings', $defaults, '', false );
		} else {
			// Ensure existing installs stop autoloading the secrets option.
			wp_set_option_autoload( 'wprn_settings', false );
		}
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		SubscribersTable::create_table();
		CampaignsTable::create_table();
		SendJobsTable::create_table();
		DeliveryEventsTable::create_table();
		TagsTable::create_table();
		SubscriberTagTable::create_table();
	}

	/**
	 * Schedule cron events.
	 *
	 * @return void
	 */
	private static function schedule_events(): void {
		// Ensure custom interval exists before wp_schedule_event (Bootstrap may not have run yet).
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval 60s set in BroadcastSender::register_cron_interval().
		add_filter( 'cron_schedules', array( BroadcastSender::class, 'register_cron_interval' ) );
		BroadcastSender::schedule_cron();
	}
}
