<?php
/**
 * Integration tests: schema maybe_upgrade + Bootstrap boot path.
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Database;

use ReflectionClass;
use WP_UnitTestCase;
use WpResendNewsletter\Bootstrap;
use WpResendNewsletter\Database\CampaignsTable;
use WpResendNewsletter\Database\DeliveryEventsTable;
use WpResendNewsletter\Database\SendJobsTable;
use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;

/**
 * @covers \WpResendNewsletter\Database\CampaignsTable
 * @covers \WpResendNewsletter\Database\SubscribersTable
 * @covers \WpResendNewsletter\Database\SendJobsTable
 * @covers \WpResendNewsletter\Database\DeliveryEventsTable
 * @covers \WpResendNewsletter\Database\TagsTable
 * @covers \WpResendNewsletter\Database\SubscriberTagTable
 * @covers \WpResendNewsletter\Bootstrap
 */
class SchemaUpgrade_Test extends WP_UnitTestCase {

	/**
	 * Table classes under test.
	 *
	 * @return array<int, class-string>
	 */
	private function table_classes(): array {
		return array(
			SubscribersTable::class,
			CampaignsTable::class,
			SendJobsTable::class,
			DeliveryEventsTable::class,
			TagsTable::class,
			SubscriberTagTable::class,
		);
	}

	/**
	 * Run $callback without WP test suite rewriting CREATE/DROP to TEMPORARY.
	 *
	 * Bootstrap creates real tables on plugins_loaded (before per-test filters),
	 * so DROP TEMPORARY would be a no-op against those base tables.
	 *
	 * @param callable $callback Callback.
	 */
	private function without_temporary_table_filters( callable $callback ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$callback();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * maybe_upgrade creates/updates when version option is missing / behind.
	 */
	public function test_maybe_upgrade_creates_when_version_behind(): void {
		foreach ( $this->table_classes() as $class ) {
			delete_option( $class::VERSION_OPTION );

			$class::maybe_upgrade();

			$this->assertSame( $class::VERSION, get_option( $class::VERSION_OPTION ), $class );
			$this->assertTrue( $class::table_exists(), $class );
		}
	}

	/**
	 * maybe_upgrade recreates when version option is set but table is gone.
	 */
	public function test_maybe_upgrade_recreates_when_version_set_but_table_missing(): void {
		foreach ( $this->table_classes() as $class ) {
			$this->without_temporary_table_filters(
				function () use ( $class ): void {
					global $wpdb;
					$table = $class::get_table_name();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
					update_option( $class::VERSION_OPTION, $class::VERSION );

					$this->assertFalse( $class::table_exists(), $class . ' should be missing after real DROP' );

					$class::maybe_upgrade();

					$this->assertTrue( $class::table_exists(), $class . ' recreated' );
					$this->assertSame( $class::VERSION, get_option( $class::VERSION_OPTION ), $class );
				}
			);
		}
	}

	/**
	 * Bootstrap::init_hooks() calls maybe_upgrade directly (not plugins_loaded@5).
	 */
	public function test_bootstrap_init_hooks_runs_maybe_upgrade_directly(): void {
		foreach ( $this->table_classes() as $class ) {
			delete_option( $class::VERSION_OPTION );
		}

		$bootstrap = Bootstrap::get_instance();
		$ref       = new ReflectionClass( $bootstrap );
		$method    = $ref->getMethod( 'init_hooks' );
		$method->setAccessible( true );
		$method->invoke( $bootstrap );

		foreach ( $this->table_classes() as $class ) {
			$this->assertSame(
				$class::VERSION,
				get_option( $class::VERSION_OPTION ),
				$class . ' version set via Bootstrap init_hooks direct maybe_upgrade'
			);
			$this->assertTrue( $class::table_exists(), $class . ' after Bootstrap init_hooks' );
		}
	}
}
