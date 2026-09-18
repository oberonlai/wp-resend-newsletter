<?php
/**
 * Plugin Bootstrap
 *
 * Handles plugin initialization, hooks registration, and dependency loading.
 *
 * @package WpResendNewsletter
 */

namespace WpResendNewsletter;

use WpResendNewsletter\Admin\AdminActions;
use WpResendNewsletter\Admin\CampaignsPage;
use WpResendNewsletter\Admin\Menu;
use WpResendNewsletter\Admin\SettingsPage;
use WpResendNewsletter\Application\BroadcastSender;
use WpResendNewsletter\Blocks\Subscribe\SubscribeBlock;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;
use WpResendNewsletter\Frontend\CampaignArchiveShortcode;
use WpResendNewsletter\Frontend\CampaignViewPage;
use WpResendNewsletter\Frontend\ConfirmPage;
use WpResendNewsletter\Frontend\FooterSubscribe;
use WpResendNewsletter\Rest\AudienceController;
use WpResendNewsletter\Rest\SubscribersController;
use WpResendNewsletter\Rest\WebhooksController;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap class.
 *
 * Main plugin class that initializes the plugin.
 */
class Bootstrap {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '0.3.11';

	/**
	 * Singleton instance.
	 *
	 * @var Bootstrap|null
	 */
	private static ?Bootstrap $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Bootstrap
	 */
	public static function get_instance(): Bootstrap {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Private to enforce singleton pattern.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Load text domain early: Bootstrap already runs on plugins_loaded.
		$this->load_textdomain();
		// Re-load on init (priority 0) before block/script registration.
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		// Schema upgrades for already-active installs.
		// Call directly: Bootstrap boots on plugins_loaded (priority 10), so
		// re-hooking plugins_loaded at priority 5 never fires (already mid-flight).
		SubscribersTable::maybe_upgrade();
		CampaignsTable::maybe_upgrade();
		SendJobsTable::maybe_upgrade();
		DeliveryEventsTable::maybe_upgrade();
		TagsTable::maybe_upgrade();
		SubscriberTagTable::maybe_upgrade();

		// Safety net if something wiped tables after boot (e.g. SQLite quirks).
		add_action( 'init', array( SubscribersTable::class, 'maybe_upgrade' ) );
		add_action( 'init', array( CampaignsTable::class, 'maybe_upgrade' ) );
		add_action( 'init', array( SendJobsTable::class, 'maybe_upgrade' ) );
		add_action( 'init', array( DeliveryEventsTable::class, 'maybe_upgrade' ) );
		add_action( 'init', array( TagsTable::class, 'maybe_upgrade' ) );
		add_action( 'init', array( SubscriberTagTable::class, 'maybe_upgrade' ) );

		// Cron interval + send queue worker.
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval 60s set in BroadcastSender::register_cron_interval().
		add_filter( 'cron_schedules', array( BroadcastSender::class, 'register_cron_interval' ) );
		add_action( BroadcastSender::CRON_HOOK, array( BroadcastSender::class, 'handle_cron' ) );

		// Initialize plugin components.
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Public REST routes (confirm / unsubscribe / subscribe).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Gutenberg subscribe block + browser confirm HTML page.
		add_action( 'init', array( SubscribeBlock::class, 'register' ) );
		ConfirmPage::register();
		FooterSubscribe::register();

		// Public campaign archive shortcode + single-view HTML page.
		add_action( 'init', array( CampaignArchiveShortcode::class, 'register' ) );
		CampaignViewPage::register();

		// Admin-specific hooks.
		if ( is_admin() ) {
			Menu::register();
			CampaignsPage::register();
			AdminActions::register();
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'admin_post_' . SettingsPage::CLEAR_KEY_ACTION, array( SettingsPage::class, 'handle_clear_api_key' ) );
		}
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-resend-newsletter',
			false,
			dirname( plugin_basename( WP_RESEND_NEWSLETTER_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Initialize plugin.
	 *
	 * This method is called on 'plugins_loaded' hook.
	 *
	 * @return void
	 */
	public function init(): void {
		// Reserved for shared boot wiring.
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new SubscribersController();
		$controller->register_routes();

		$webhooks = new WebhooksController();
		$webhooks->register_routes();

		$audience = new AudienceController();
		$audience->register_routes();
	}

	/**
	 * Admin initialization.
	 *
	 * Wires Settings API registration.
	 *
	 * @return void
	 */
	public function admin_init(): void {
		SettingsPage::register_settings();
	}

	/**
	 * Get plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return self::VERSION;
	}
}
