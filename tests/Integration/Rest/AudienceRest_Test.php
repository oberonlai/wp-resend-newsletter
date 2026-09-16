<?php
/**
 * Integration tests for GET /wprn/v1/audience (admin audience estimate).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;
use WpResendNewsletter\Database\SubscriberTagTable;
use WpResendNewsletter\Database\SubscribersTable;
use WpResendNewsletter\Database\TagsTable;
use WpResendNewsletter\Domain\SubscriberStatus;
use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

/**
 * @covers \WpResendNewsletter\Rest\AudienceController
 */
class AudienceRest_Test extends WP_UnitTestCase {

	/**
	 * Create tables.
	 */
	public function set_up(): void {
		parent::set_up();
		SubscribersTable::create_table();
		TagsTable::create_table();
		SubscriberTagTable::create_table();
	}

	/**
	 * Truncate tables.
	 */
	public function tear_down(): void {
		global $wpdb;
		foreach (
			array(
				SubscriberTagTable::get_table_name(),
				TagsTable::get_table_name(),
				SubscribersTable::get_table_name(),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		parent::tear_down();
	}

	/**
	 * @return int Admin user ID.
	 */
	private function as_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * @return int Subscriber user with no manage_options.
	 */
	private function as_author(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Seed confirmed/pending subscribers with VIP / Product tags.
	 *
	 * Confirmed: A(VIP), B(VIP+Product), C(none), E(Product). Pending: D(VIP).
	 * Uses repositories directly so auth tests can seed without manage_options.
	 *
	 * @return array{vip: int, product: int}
	 */
	private function seed_audience(): array {
		$subs = new SubscriberRepository();
		$tags = new TagRepository();
		$join = new SubscriberTagRepository();

		$vip_id = $tags->insert(
			array(
				'name' => 'VIP',
				'slug' => 'vip',
			)
		);
		$product_id = $tags->insert(
			array(
				'name' => 'Product',
				'slug' => 'product',
			)
		);
		$this->assertNotFalse( $vip_id );
		$this->assertNotFalse( $product_id );
		$vip_id     = (int) $vip_id;
		$product_id = (int) $product_id;

		$a = (int) $subs->insert( array( 'email' => 'a@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$b = (int) $subs->insert( array( 'email' => 'b@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$c = (int) $subs->insert( array( 'email' => 'c@example.com', 'status' => SubscriberStatus::CONFIRMED ) );
		$d = (int) $subs->insert( array( 'email' => 'd@example.com', 'status' => SubscriberStatus::PENDING ) );
		$e = (int) $subs->insert( array( 'email' => 'e@example.com', 'status' => SubscriberStatus::CONFIRMED ) );

		$join->attach( $a, $vip_id );
		$join->attach( $b, $vip_id );
		$join->attach( $b, $product_id );
		$join->attach( $d, $vip_id );
		$join->attach( $e, $product_id );

		unset( $c );

		return array(
			'vip'     => $vip_id,
			'product' => $product_id,
		);
	}

	/**
	 * Anonymous / insufficient capability is rejected.
	 */
	public function test_audience_requires_manage_options(): void {
		$this->seed_audience();

		wp_set_current_user( 0 );
		$anon = new WP_REST_Request( 'GET', '/wprn/v1/audience' );
		$anon_res = rest_get_server()->dispatch( $anon );
		$this->assertSame( 401, $anon_res->get_status() );

		$this->as_author();
		$author = new WP_REST_Request( 'GET', '/wprn/v1/audience' );
		$author_res = rest_get_server()->dispatch( $author );
		$this->assertSame( 403, $author_res->get_status() );
	}

	/**
	 * Empty tag filter returns all confirmed as matching.
	 */
	public function test_audience_empty_tags_returns_all_confirmed(): void {
		$this->as_admin();
		$this->seed_audience();

		$req = new WP_REST_Request( 'GET', '/wprn/v1/audience' );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 4, $data['confirmed'] );
		$this->assertSame( 4, $data['matching'] );
	}

	/**
	 * AND tag filter matches repository count_confirmed_with_all_tags.
	 */
	public function test_audience_and_tag_filter_count(): void {
		$this->as_admin();
		$tags = $this->seed_audience();

		$req = new WP_REST_Request( 'GET', '/wprn/v1/audience' );
		$req->set_param( 'tag_ids', array( $tags['vip'] ) );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 4, $data['confirmed'] );
		$this->assertSame( 2, $data['matching'] ); // A + B (D pending excluded).

		$req2 = new WP_REST_Request( 'GET', '/wprn/v1/audience' );
		$req2->set_param( 'tag_ids', array( $tags['vip'], $tags['product'] ) );
		$res2 = rest_get_server()->dispatch( $req2 );

		$this->assertSame( 200, $res2->get_status() );
		$data2 = $res2->get_data();
		$this->assertSame( 4, $data2['confirmed'] );
		$this->assertSame( 1, $data2['matching'] ); // B only.
	}

	/**
	 * Controller route is registered under wprn/v1.
	 */
	public function test_audience_route_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wprn/v1/audience', $routes );
	}
}
