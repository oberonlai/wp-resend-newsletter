<?php
/**
 * Tag application service (CRUD + assign/remove).
 *
 * @package WpResendNewsletter
 */

declare(strict_types=1);

namespace WpResendNewsletter\Application;

use WpResendNewsletter\Persistence\SubscriberRepository;
use WpResendNewsletter\Persistence\SubscriberTagRepository;
use WpResendNewsletter\Persistence\TagRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create / rename / delete tags; assign / remove on subscribers.
 *
 * Tags are local only — never synced to Resend Topics.
 */
class TagService {

	/**
	 * Capability required to mutate tags.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Max tag name length (matches varchar(191)).
	 */
	public const MAX_NAME_LENGTH = 191;

	/**
	 * Tag repository.
	 *
	 * @var TagRepository
	 */
	private TagRepository $tags;

	/**
	 * Join repository.
	 *
	 * @var SubscriberTagRepository
	 */
	private SubscriberTagRepository $subscriber_tags;

	/**
	 * Subscribers (existence checks).
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Constructor.
	 *
	 * @param TagRepository           $tags            Tags.
	 * @param SubscriberTagRepository $subscriber_tags Joins.
	 * @param SubscriberRepository    $subscribers     Subscribers.
	 */
	public function __construct(
		TagRepository $tags,
		SubscriberTagRepository $subscriber_tags,
		SubscriberRepository $subscribers
	) {
		$this->tags            = $tags;
		$this->subscriber_tags = $subscriber_tags;
		$this->subscribers     = $subscribers;
	}

	/**
	 * Create a tag.
	 *
	 * @param string $name Display name.
	 * @return array{ok: bool, id?: int, code?: string, message: string}
	 */
	public function create( string $name ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$name  = $this->sanitize_name( $name );
		$error = $this->validate_name( $name );
		if ( null !== $error ) {
			return $error;
		}

		if ( null !== $this->tags->find_by_name( $name ) ) {
			return array(
				'ok'      => false,
				'code'    => 'duplicate_name',
				'message' => __( 'A tag with this name already exists.', 'wp-resend-newsletter' ),
			);
		}

		$slug = $this->unique_slug( $name );
		$id   = $this->tags->insert(
			array(
				'name' => $name,
				'slug' => $slug,
			)
		);

		if ( false === $id ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not create tag.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'      => true,
			'id'      => $id,
			'message' => __( 'Tag created.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Rename a tag.
	 *
	 * @param int    $id   Tag ID.
	 * @param string $name New name.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function rename( int $id, string $name ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$tag = $this->tags->find_by_id( $id );
		if ( null === $tag ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Tag not found.', 'wp-resend-newsletter' ),
			);
		}

		$name  = $this->sanitize_name( $name );
		$error = $this->validate_name( $name );
		if ( null !== $error ) {
			return $error;
		}

		$existing = $this->tags->find_by_name( $name );
		if ( null !== $existing && (int) $existing->id !== $id ) {
			return array(
				'ok'      => false,
				'code'    => 'duplicate_name',
				'message' => __( 'A tag with this name already exists.', 'wp-resend-newsletter' ),
			);
		}

		$slug = $this->unique_slug( $name, $id );
		$ok   = $this->tags->update(
			$id,
			array(
				'name' => $name,
				'slug' => $slug,
			)
		);

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not rename tag.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Tag renamed.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Delete a tag and its join rows (subscribers untouched).
	 *
	 * @param int $id Tag ID.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function delete( int $id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$tag = $this->tags->find_by_id( $id );
		if ( null === $tag ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Tag not found.', 'wp-resend-newsletter' ),
			);
		}

		$this->subscriber_tags->delete_by_tag( $id );
		$ok = $this->tags->delete( $id );

		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not delete tag.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Tag deleted.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Assign a tag to a subscriber (idempotent).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $tag_id        Tag ID.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function assign( int $subscriber_id, int $tag_id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		if ( null === $this->subscribers->find_by_id( $subscriber_id ) ) {
			return array(
				'ok'      => false,
				'code'    => 'subscriber_not_found',
				'message' => __( 'Subscriber not found.', 'wp-resend-newsletter' ),
			);
		}

		if ( null === $this->tags->find_by_id( $tag_id ) ) {
			return array(
				'ok'      => false,
				'code'    => 'tag_not_found',
				'message' => __( 'Tag not found.', 'wp-resend-newsletter' ),
			);
		}

		if ( ! $this->subscriber_tags->attach( $subscriber_id, $tag_id ) ) {
			return array(
				'ok'      => false,
				'code'    => 'db_error',
				'message' => __( 'Could not assign tag.', 'wp-resend-newsletter' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Tag assigned.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Remove a tag from a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $tag_id        Tag ID.
	 * @return array{ok: bool, code?: string, message: string}
	 */
	public function remove( int $subscriber_id, int $tag_id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$this->subscriber_tags->detach( $subscriber_id, $tag_id );

		return array(
			'ok'      => true,
			'message' => __( 'Tag removed.', 'wp-resend-newsletter' ),
		);
	}

	/**
	 * Bulk assign one tag to many subscribers (idempotent per pair).
	 *
	 * @param array $subscriber_ids Subscriber IDs (list of ints).
	 * @param int   $tag_id         Tag ID.
	 * @return array{ok: bool, assigned?: int, code?: string, message: string}
	 */
	public function bulk_assign( array $subscriber_ids, int $tag_id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		if ( null === $this->tags->find_by_id( $tag_id ) ) {
			return array(
				'ok'      => false,
				'code'    => 'tag_not_found',
				'message' => __( 'Tag not found.', 'wp-resend-newsletter' ),
			);
		}

		$assigned = 0;
		foreach ( $subscriber_ids as $subscriber_id ) {
			$subscriber_id = (int) $subscriber_id;
			if ( $subscriber_id < 1 ) {
				continue;
			}
			if ( null === $this->subscribers->find_by_id( $subscriber_id ) ) {
				continue;
			}
			if ( $this->subscriber_tags->attach( $subscriber_id, $tag_id ) ) {
				++$assigned;
			}
		}

		return array(
			'ok'       => true,
			'assigned' => $assigned,
			'message'  => sprintf(
				/* translators: %d: number of subscribers */
				_n( 'Tag added to %d subscriber.', 'Tag added to %d subscribers.', $assigned, 'wp-resend-newsletter' ),
				$assigned
			),
		);
	}

	/**
	 * Bulk remove one tag from many subscribers.
	 *
	 * @param array $subscriber_ids Subscriber IDs (list of ints).
	 * @param int   $tag_id         Tag ID.
	 * @return array{ok: bool, removed?: int, code?: string, message: string}
	 */
	public function bulk_remove( array $subscriber_ids, int $tag_id ): array {
		$denied = $this->deny_if_unauthorized();
		if ( null !== $denied ) {
			return $denied;
		}

		$removed = 0;
		foreach ( $subscriber_ids as $subscriber_id ) {
			$subscriber_id = (int) $subscriber_id;
			if ( $subscriber_id < 1 ) {
				continue;
			}
			if ( $this->subscriber_tags->detach( $subscriber_id, $tag_id ) ) {
				++$removed;
			}
		}

		return array(
			'ok'      => true,
			'removed' => $removed,
			'message' => sprintf(
				/* translators: %d: number of subscribers */
				_n( 'Tag removed from %d subscriber.', 'Tag removed from %d subscribers.', $removed, 'wp-resend-newsletter' ),
				$removed
			),
		);
	}

	/**
	 * Deny when current user lacks manage_options.
	 *
	 * @return array{ok: bool, code: string, message: string}|null
	 */
	private function deny_if_unauthorized(): ?array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array(
				'ok'      => false,
				'code'    => 'forbidden',
				'message' => __( 'You do not have permission to manage tags.', 'wp-resend-newsletter' ),
			);
		}
		return null;
	}

	/**
	 * Sanitize display name.
	 *
	 * @param string $name Raw.
	 * @return string
	 */
	private function sanitize_name( string $name ): string {
		$name = sanitize_text_field( $name );
		return trim( $name );
	}

	/**
	 * Validate name.
	 *
	 * @param string $name Name.
	 * @return array{ok: bool, code: string, message: string}|null
	 */
	private function validate_name( string $name ): ?array {
		if ( '' === $name ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_name',
				'message' => __( 'Tag name is required.', 'wp-resend-newsletter' ),
			);
		}
		if ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_name',
				'message' => __( 'Tag name is too long.', 'wp-resend-newsletter' ),
			);
		}
		return null;
	}

	/**
	 * Build a unique slug from name.
	 *
	 * @param string   $name       Name.
	 * @param int|null $exclude_id Tag ID to exclude from uniqueness check.
	 * @return string
	 */
	private function unique_slug( string $name, ?int $exclude_id = null ): string {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'tag';
		}

		$slug     = $base;
		$suffix   = 2;
		$existing = $this->tags->find_by_slug( $slug );
		while ( null !== $existing && ( null === $exclude_id || (int) $existing->id !== $exclude_id ) ) {
			$slug = $base . '-' . $suffix;
			++$suffix;
			$existing = $this->tags->find_by_slug( $slug );
		}

		return $slug;
	}
}
