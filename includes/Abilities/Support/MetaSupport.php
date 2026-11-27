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
	 * Protected keys that stay unwritable even when a site allowlists them,
	 * because WordPress or another ability owns them and a raw write would leave
	 * things inconsistent. Each maps to the message explaining what to use.
	 */
	private const OWNED_KEYS = array(
		'_wp_attached_file'        => 'It is the attachment\'s file path. Use replace-media to change the file.',
		'_wp_attachment_metadata'  => 'It is generated from the file. Use replace-media to change the file.',
		'_wp_attachment_image_alt' => 'Image alt text is set with update-media.',
		'_wp_page_template'        => 'Use the template input of update-post.',
		'_thumbnail_id'            => 'Use the featured_media input of update-post.',
	);

	/**
	 * Key prefixes that stay unwritable even when allowlisted.
	 */
	private const OWNED_PREFIXES = array(
		'_wp_trash_' => 'WordPress manages it when a post is trashed or restored.',
		'_menu_item_' => 'Menu items are edited with the menu abilities.',
		'_pll_'      => 'Polylang manages it. Use the polylang-* abilities.',
	);

	/**
	 * Fragments that mark a key as holding a secret; its value is never returned.
	 */
	private const SENSITIVE_FRAGMENTS = array( 'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'license_key', 'auth_key' );

	/**
	 * Longest string value returned for a protected key before it is truncated.
	 */
	private const MAX_PROTECTED_VALUE_LENGTH = 2000;

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

		if ( in_array( $key, array( '_elementor_code', '_elementor_location', '_elementor_priority', '_elementor_extra_options' ), true ) && CustomCodeSupport::CPT === $post_type ) {
			return new WP_Error(
				'meta_key_denied',
				sprintf( 'Meta key "%s" belongs to an Elementor Custom Code snippet. Use elementor-get-custom-code and elementor-set-custom-code instead.', $key )
			);
		}

		if ( 0 === strpos( $key, '_elementor_conditions' ) ) {
			return new WP_Error(
				'meta_key_denied',
				'Display conditions are managed by elementor-set-conditions (templates) and elementor-set-custom-code (snippets), which also rebuild Elementor Pro\'s conditions cache.'
			);
		}

		if ( 0 === strpos( $key, '_elementor' ) ) {
			return new WP_Error(
				'meta_key_denied',
				sprintf( 'Meta key "%s" is managed by the Elementor abilities. Use elementor-set-data or elementor-update-element instead.', $key )
			);
		}

		$owned = self::owned_key_message( $key );

		if ( null !== $owned ) {
			return new WP_Error( 'meta_key_denied', sprintf( 'Meta key "%s" cannot be written through set-post-meta. %s', $key, $owned ) );
		}

		// Rank Math marks these protected on some sites and not on others, and
		// several of them (robots, advanced robots) must be stored as arrays.
		// Route them all to the SEO ability so they behave the same everywhere.
		if ( 0 === strpos( $key, 'rank_math_' ) && RankMathSupport::is_active() ) {
			return new WP_Error(
				'meta_key_protected',
				sprintf( 'Meta key "%s" is a Rank Math SEO field. Use seo-update-posts, which validates it and can change many posts in one call.', $key )
			);
		}

		// Yoast reads its fields from its indexables table, which a raw meta write
		// does not refresh; the SEO ability writes them and rebuilds the indexable.
		if ( 0 === strpos( $key, '_yoast_wpseo_' ) && defined( 'WPSEO_VERSION' ) ) {
			return new WP_Error(
				'meta_key_protected',
				sprintf( 'Meta key "%s" is a Yoast SEO field. Use seo-update-posts, which validates it and refreshes what Yoast prints.', $key )
			);
		}

		if ( self::is_allowlisted( $key, $post_type ) ) {
			return true;
		}

		if ( ! is_protected_meta( $key, 'post' ) ) {
			return true;
		}

		return new WP_Error(
			'meta_key_protected',
			sprintf(
				'Meta key "%s" is protected. An administrator can allow it under Settings → Meta MCP → "Writable protected meta keys" (exact keys, or prefixes ending in *), or through the "mcp_adapter_content_writable_protected_meta" filter.',
				$key
			)
		);
	}

	/**
	 * Explains why a key belongs to WordPress or another ability, if it does.
	 *
	 * @param string $key Meta key.
	 *
	 * @return string|null The explanation, or null when the key is not owned.
	 */
	private static function owned_key_message( string $key ): ?string {
		if ( isset( self::OWNED_KEYS[ $key ] ) ) {
			return self::OWNED_KEYS[ $key ];
		}

		foreach ( self::OWNED_PREFIXES as $prefix => $message ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return $message;
			}
		}

		return null;
	}

	/**
	 * Whether a protected key is on the site's allowlist.
	 *
	 * Entries are exact keys, or prefixes when they end in "*" ("_mh_*" allows
	 * every key starting with "_mh_").
	 *
	 * @param string $key       Meta key.
	 * @param string $post_type Post type being written.
	 *
	 * @return bool True when allowlisted.
	 */
	private static function is_allowlisted( string $key, string $post_type ): bool {
		foreach ( self::writable_protected_keys( $post_type ) as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry || '*' === $entry ) {
				continue;
			}

			if ( '*' === substr( $entry, -1 ) ) {
				if ( 0 === strpos( $key, substr( $entry, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $key === $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the protected meta keys and prefixes a site has allowlisted for writing.
	 *
	 * Combines the "Writable protected meta keys" setting with the filter.
	 *
	 * @param string $post_type Post type being written.
	 *
	 * @return string[] Allowlisted keys, and prefixes ending in "*".
	 */
	private static function writable_protected_keys( string $post_type ): array {
		$settings = Settings::get();
		$stored   = isset( $settings['writable_protected_meta'] ) && is_array( $settings['writable_protected_meta'] )
			? $settings['writable_protected_meta']
			: array();

		/**
		 * Filters the protected post meta keys writable through MCP.
		 *
		 * Underscore-prefixed keys are blocked by default. Add the specific keys
		 * a site needs, e.g. `_room_price`, or a prefix ending in "*" such as
		 * `_mh_*`. Keys owned by WordPress or by another ability (Elementor,
		 * Polylang, SEO plugins, attachment files) stay blocked regardless.
		 *
		 * @since 0.6.1
		 *
		 * @param string[] $keys      Allowlisted protected meta keys and prefixes, starting with those from the settings screen.
		 * @param string   $post_type Post type being written.
		 */
		return array_map( 'strval', (array) apply_filters( 'mcp_adapter_content_writable_protected_meta', array_map( 'strval', $stored ), $post_type ) );
	}

	/**
	 * Reads the protected (underscore) meta of a post, flagging which keys are writable.
	 *
	 * Elementor's layout and keys holding secrets are listed without their
	 * value; long strings are cut short and flagged.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 *
	 * @return array<string, array<string, mixed>> Entries keyed by meta key.
	 */
	public static function read_protected_meta( int $post_id, string $post_type ): array {
		$raw    = get_post_meta( $post_id );
		$result = array();

		if ( ! is_array( $raw ) ) {
			return $result;
		}

		ksort( $raw );

		foreach ( $raw as $key => $values ) {
			$key = (string) $key;

			if ( in_array( $key, self::DENIED_KEYS, true ) || ! is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			$writable = self::validate_writable_key( $key, $post_type );
			$entry    = array( 'writable' => ! is_wp_error( $writable ) );

			if ( is_wp_error( $writable ) ) {
				$entry['why_not'] = $writable->get_error_message();
			}

			if ( self::is_sensitive( $key ) ) {
				$entry['value_omitted'] = 'sensitive';
				$result[ $key ]         = $entry;
				continue;
			}

			if ( '_elementor_data' === $key || '_elementor_css' === $key || '_elementor_element_cache' === $key ) {
				$entry['value_omitted'] = 'large; use the elementor-* abilities';
				$result[ $key ]         = $entry;
				continue;
			}

			$decoded = array_map( array( self::class, 'maybe_unserialize_value' ), (array) $values );
			$value   = 1 === count( $decoded ) ? reset( $decoded ) : $decoded;

			$encoded = is_string( $value ) ? $value : wp_json_encode( $value );

			if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_PROTECTED_VALUE_LENGTH ) {
				$entry['value']     = substr( $encoded, 0, self::MAX_PROTECTED_VALUE_LENGTH );
				$entry['truncated'] = true;
				$entry['length']    = strlen( $encoded );
			} else {
				$entry['value'] = $value;
			}

			$result[ $key ] = $entry;
		}

		return $result;
	}

	/**
	 * Whether a key looks like it holds a secret.
	 *
	 * @param string $key Meta key.
	 *
	 * @return bool True when its value must not be returned.
	 */
	private static function is_sensitive( string $key ): bool {
		$lower = strtolower( $key );

		foreach ( self::SENSITIVE_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $lower, $fragment ) ) {
				return true;
			}
		}

		return false;
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

			// Meta registered with an auth_callback limits who may write it; honour that.
			// Not for protected keys: core denies edit_post_meta on those outright, and
			// they have already passed the allowlist above.
			if ( ! is_protected_meta( $key, 'post' ) && ! current_user_can( null === $value ? 'delete_post_meta' : 'edit_post_meta', $post_id, $key ) ) {
				$result['errors'][ $key ] = sprintf( 'User cannot change custom field "%s" on this post.', $key );
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
