<?php
/**
 * Shared helpers for the revision abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Revisions;

use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - RevisionSupport
 *
 * Loads a revision only for a user who can edit its parent post (WordPress
 * shows revisions on the edit screen, so the same rule applies here), and
 * formats revisions and field-level comparisons for a client.
 */
final class RevisionSupport {

	/**
	 * Fields a revision stores and a restore brings back.
	 */
	public const FIELDS = array( 'post_title', 'post_content', 'post_excerpt' );

	/**
	 * Loads a revision and its parent, checking the user may edit the parent.
	 *
	 * @param mixed $revision_id Revision ID.
	 *
	 * @return array{0: \WP_Post, 1: \WP_Post}|\WP_Error The revision and its parent.
	 */
	public static function require_revision( $revision_id ) {
		$revision_id = (int) $revision_id;
		$revision    = $revision_id > 0 ? wp_get_post_revision( $revision_id ) : null;

		if ( ! $revision instanceof WP_Post ) {
			return new WP_Error( 'revision_not_found', sprintf( 'Revision %d does not exist. list-revisions shows the revisions of a post.', $revision_id ) );
		}

		$parent = ContentSupport::require_can_edit( (int) $revision->post_parent );

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		return array( $revision, $parent );
	}

	/**
	 * Summarises a revision.
	 *
	 * @param \WP_Post $revision The revision.
	 *
	 * @return array<string, mixed> The summary.
	 */
	public static function format_revision( WP_Post $revision, ?bool $has_elementor = null ): array {
		$author = get_userdata( (int) $revision->post_author );

		return array(
			'id'             => (int) $revision->ID,
			'post_id'        => (int) $revision->post_parent,
			'date'           => $revision->post_date,
			'date_gmt'       => $revision->post_date_gmt,
			'author'         => (int) $revision->post_author,
			'author_name'    => $author ? $author->display_name : '',
			'title'          => $revision->post_title,
			'is_autosave'    => (bool) wp_is_post_autosave( $revision ),
			'content_length' => strlen( $revision->post_content ),
			'has_elementor'  => null !== $has_elementor ? $has_elementor : self::ids_with_elementor( array( (int) $revision->ID ) ) !== array(),
		);
	}

	/**
	 * Which of these revisions carry an Elementor layout, without loading it.
	 *
	 * @param int[] $ids Revision IDs.
	 *
	 * @return int[] The IDs that have non-empty _elementor_data.
	 */
	public static function ids_with_elementor( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND LENGTH(meta_value) > 2 AND post_id IN ($placeholders)", $ids ) );

		return array_map( 'intval', (array) $found );
	}

	/**
	 * Compares a revision with the current post, field by field.
	 *
	 * @param \WP_Post $revision The revision.
	 * @param \WP_Post $post     The current post.
	 *
	 * @return array<string, mixed> Per-field comparison.
	 */
	public static function compare( WP_Post $revision, WP_Post $post ): array {
		$fields = array();

		foreach ( self::FIELDS as $field ) {
			$old = (string) $revision->{$field};
			$new = (string) $post->{$field};

			$entry = array(
				'differs'         => $old !== $new,
				'revision_length' => strlen( $old ),
				'current_length'  => strlen( $new ),
			);

			if ( $old !== $new ) {
				if ( 'post_content' === $field ) {
					$entry['revision_preview'] = self::preview( $old );
					$entry['current_preview']  = self::preview( $new );
					$entry['first_difference'] = self::first_difference( $old, $new );
				} else {
					$entry['revision'] = $old;
					$entry['current']  = $new;
				}
			}

			$fields[ str_replace( 'post_', '', $field ) ] = $entry;
		}

		$old_elementor = (string) get_post_meta( $revision->ID, '_elementor_data', true );
		$new_elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );

		$fields['elementor'] = array(
			'revision_has_data' => '' !== $old_elementor,
			'current_has_data'  => '' !== $new_elementor,
			'differs'           => '' !== $old_elementor && $old_elementor !== $new_elementor,
			'revision_length'   => strlen( $old_elementor ),
			'current_length'    => strlen( $new_elementor ),
		);

		return $fields;
	}

	/**
	 * Plain-text preview of HTML content.
	 *
	 * @param string $html The content.
	 *
	 * @return string Up to 300 characters.
	 */
	private static function preview( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}

	/**
	 * Context around the first position at which two strings differ.
	 *
	 * @param string $old Revision text.
	 * @param string $new Current text.
	 *
	 * @return array{offset: int, revision: string, current: string} The context.
	 */
	private static function first_difference( string $old, string $new ): array {
		$length = min( strlen( $old ), strlen( $new ) );
		$offset = 0;

		while ( $offset < $length && $old[ $offset ] === $new[ $offset ] ) {
			++$offset;
		}

		$start = max( 0, $offset - 60 );

		return array(
			'offset'   => $offset,
			'revision' => wp_check_invalid_utf8( substr( $old, $start, 200 ), true ),
			'current'  => wp_check_invalid_utf8( substr( $new, $start, 200 ), true ),
		);
	}
}
