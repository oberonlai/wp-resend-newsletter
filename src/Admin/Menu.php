<?php
/**
 * Admin menu registration (IA).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level WP Resend Newsletter menu + submenus.
 */
class Menu {

	/**
	 * Parent / settings menu slug (existing Settings page).
	 */
	public const PARENT_SLUG = SettingsPage::MENU_SLUG;

	/**
	 * Subscribers submenu slug.
	 */
	public const SUBSCRIBERS_SLUG = 'wprn-subscribers';

	/**
	 * Campaigns submenu slug.
	 */
	public const CAMPAIGNS_SLUG = 'wprn-campaigns';

	/**
	 * Campaign edit (hidden) slug.
	 */
	public const CAMPAIGN_EDIT_SLUG = 'wprn-campaign-edit';

	/**
	 * Campaign analytics (hidden) slug.
	 */
	public const CAMPAIGN_ANALYTICS_SLUG = 'wprn-campaign-analytics';

	/**
	 * Subscriber detail (hidden) slug.
	 */
	public const SUBSCRIBER_DETAIL_SLUG = 'wprn-subscriber-detail';

	/**
	 * Tags submenu slug.
	 */
	public const TAGS_SLUG = 'wprn-tags';

	/**
	 * Queue submenu slug.
	 */
	public const QUEUE_SLUG = 'wprn-queue';

	/**
	 * Required capability.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Register admin menus.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_pages' ) );
	}

	/**
	 * Add top-level menu and submenus.
	 *
	 * @return void
	 */
	public static function add_menu_pages(): void {
		add_menu_page(
			__( 'WP Resend Newsletter', 'wp-resend-newsletter' ),
			__( 'Resend Newsletter', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( SettingsPage::class, 'render_page' ),
			'dashicons-email-alt',
			58
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'wp-resend-newsletter' ),
			__( 'Settings', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( SettingsPage::class, 'render_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Subscribers', 'wp-resend-newsletter' ),
			__( 'Subscribers', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::SUBSCRIBERS_SLUG,
			array( SubscribersPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Tags', 'wp-resend-newsletter' ),
			__( 'Tags', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::TAGS_SLUG,
			array( TagsPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Campaigns', 'wp-resend-newsletter' ),
			__( 'Campaigns', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::CAMPAIGNS_SLUG,
			array( CampaignsPage::class, 'render_list' )
		);

		// Register under parent then remove from visible submenu (linked from list / Add New).
		$edit_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Edit Campaign', 'wp-resend-newsletter' ),
			__( 'Edit Campaign', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::CAMPAIGN_EDIT_SLUG,
			array( CampaignsPage::class, 'render_edit' )
		);
		remove_submenu_page( self::PARENT_SLUG, self::CAMPAIGN_EDIT_SLUG );

		/*
		 * remove_submenu_page() only hides the item from $submenu. After that,
		 * get_admin_page_parent() returns empty for this slug, so capability
		 * checks look up the empty-parent hook (admin_page_{slug}) which was
		 * never registered — causing HTTP 403. Mirror core's tools.php pattern:
		 * keep the page registered under the orphan hook and preserve parent
		 * for menu_page_url() / Add New links.
		 */
		global $_registered_pages, $_parent_pages;
		$orphan_hook = get_plugin_page_hookname( self::CAMPAIGN_EDIT_SLUG, '' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core orphan-hook pattern for hidden submenu pages.
		$_registered_pages[ $orphan_hook ] = true;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Preserve parent for menu_page_url() after hide.
		$_parent_pages[ self::CAMPAIGN_EDIT_SLUG ] = self::PARENT_SLUG;

		// Callback was attached to the parent-scoped hook; admin.php resolves
		// the empty-parent hook when loading admin.php?page={slug}.
		if ( is_string( $edit_hook ) && $edit_hook !== $orphan_hook ) {
			add_action( $orphan_hook, array( CampaignsPage::class, 'render_edit' ) );
		}

		self::register_hidden_page(
			self::CAMPAIGN_ANALYTICS_SLUG,
			__( 'Campaign Analytics', 'wp-resend-newsletter' ),
			array( CampaignAnalyticsPage::class, 'render' )
		);

		self::register_hidden_page(
			self::SUBSCRIBER_DETAIL_SLUG,
			__( 'Subscriber', 'wp-resend-newsletter' ),
			array( SubscriberDetailPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Queue', 'wp-resend-newsletter' ),
			__( 'Queue', 'wp-resend-newsletter' ),
			self::CAPABILITY,
			self::QUEUE_SLUG,
			array( QueuePage::class, 'render' )
		);
	}

	/**
	 * Register a submenu page then hide it (orphan-hook pattern).
	 *
	 * @param string   $slug     Page slug.
	 * @param string   $title    Page title.
	 * @param callable $callback Render callback.
	 * @return void
	 */
	private static function register_hidden_page( string $slug, string $title, $callback ): void {
		$hook = add_submenu_page(
			self::PARENT_SLUG,
			$title,
			$title,
			self::CAPABILITY,
			$slug,
			$callback
		);
		remove_submenu_page( self::PARENT_SLUG, $slug );

		global $_registered_pages, $_parent_pages;
		$orphan_hook = get_plugin_page_hookname( $slug, '' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core orphan-hook pattern for hidden submenu pages.
		$_registered_pages[ $orphan_hook ] = true;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Preserve parent for menu_page_url() after hide.
		$_parent_pages[ $slug ] = self::PARENT_SLUG;

		if ( is_string( $hook ) && $hook !== $orphan_hook ) {
			add_action( $orphan_hook, $callback );
		}
	}
}
