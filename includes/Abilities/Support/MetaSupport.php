<?php
/**
 * Guarded post meta reads and writes for the content abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - MetaSupport
 *
 * Protected meta (keys starting with an underscore) is not writable through the
 * generic meta ability, because those keys drive builders, page templates and
 * plugin internals that expect their own validation. Elementor's keys are
 * handled by the dedicated Elementor abilities instead, which know how to keep
 * the document consistent. Sites that need a specific protected key can add it
 * to the allowlist filter.
 */
final class MetaSupport {

	/**
	 * Meta keys that are never readable or writable through MCP.
	 */
	private const DENIED_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_old_slug',
		'_wp_old_date',
	);

	/**
	 * Reads the meta of a post, excluding protected and denied keys.
	 *
	 * @param int  $post_id            Post ID.
	 * @param bool $include_protected  Whether to include underscore-prefixed keys. Default false.
	 *
	 * @return array<string, mixed> Meta values keyed by meta key.
	 */
	public static function read_meta( int $post_id, bool $include_protected = false ): array {
		$raw    = get_post_meta( $post_id );
		$result = array();

		if ( ! is_array( $raw ) ) {
			return $result;
		}

		foreach ( $raw as $key => $values ) {
			if ( in_array( $key, self::DENIED_KEYS, true ) ) {
				continue;
			}

			if ( ! $include_protected && is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			// Elementor data is large and has its own abilities; never inline it.
			if ( '_elementor_data' === $key ) {
				continue;
			}

			$decoded = array_map( array( self::class, 'maybe_unserialize_value' ), (array) $values );

			$result[ $key ] = 1 === count( $decoded ) ? reset( $decoded ) : $decoded;
		}

		return $result;
	}

	/**
	 * Determines whether a meta key may be written through the generic ability.
	 *
	 * @param string $key       Meta key.
	 * @param string $post_type Post type slug.
	 *
	 * @return true|\WP_Error True when writable, WP_Error otherwise.
	 */
	public static function validate_writable_key( string $key, string $post_type ) {
		if ( '' === $key ) {
			return new WP_Error( 'invalid_meta_key', 'Meta key cannot be empty.' );
		}

		if ( in_array( $key, self::DENIED_KEYS, true ) ) {
			return new WP_Error( 'meta_key_denied', sprintf( 'Meta key "%s" cannot be modified through MCP.', $key ) );
		}

		if ( 0 === strpos( $key, '_elementor' ) ) {
			return new WP_Error(
				'meta_key_denied',
				sprintf( 'Meta key "%s" is managed by the Elementor abilities. Use elementor-set-data or elementor-update-element instead.', $key )
			);
		}

		if ( ! is_protected_meta( $key, 'post' ) ) {
			return true;
		}

		/**
		 * Filters the protected post meta keys writable through MCP.
		 *
		 * Underscore-prefixed keys are blocked by default. Add the specific keys
		 * a site needs, e.g. `_yoast_wpseo_title`.
		 *
		 * @since 0.6.1
		 *
		 * @param string[] $keys      Allowlisted protected meta keys.
		 * @param string   $post_type Post type being written.
		 */
		$allowed = (array) apply_filters( 'mcp_adapter_content_writable_protected_meta', array(), $post_type );

		if ( in_array( $key, $allowed, true ) ) {
			return true;
		}

		return new WP_Error(
			'meta_key_protected',
			sprintf(
				'Meta key "%s" is protected. Add it to the "mcp_adapter_content_writable_protected_meta" filter to allow writing it.',
				$key
			)
		);
	}

	/**
	 * Writes a set of meta values onto a post.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $meta      Meta values keyed by meta key. A null value deletes the key.
	 * @param string               $post_type Post type slug.
	 *
	 * @return array{updated: string[], deleted: string[], errors: array<string, string>} Result summary.
	 */
	public static function write_meta( int $post_id, array $meta, string $post_type ): array {
		$result = array(
			'updated' => array(),
			'deleted' => array(),
			'errors'  => array(),
		);

		foreach ( $meta as $key => $value ) {
			$key      = (string) $key;
			$writable = self::validate_writable_key( $key, $post_type );

			if ( is_wp_error( $writable ) ) {
				$result['errors'][ $key ] = $writable->get_error_message();
				continue;
			}

			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
				$result['deleted'][] = $key;
				continue;
			}

			// update_post_meta expects slashed data.
			update_post_meta( $post_id, $key, wp_slash( $value ) );
			$result['updated'][] = $key;
		}

		return $result;
	}

	/**
	 * Unserializes a raw meta value when needed.
	 *
	 * @param mixed $value Raw meta value.
	 *
	 * @return mixed The unserialized value.
	 */
	private static function maybe_unserialize_value( $value ) {
		return is_string( $value ) ? maybe_unserialize( $value ) : $value;
	}
}
