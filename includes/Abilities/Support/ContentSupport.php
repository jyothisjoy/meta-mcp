<?php
/**
 * Shared helpers for the content management abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - ContentSupport
 *
 * Central place for post-type gating, capability checks and the shape of a post
 * as returned to an MCP client. Every content, Elementor and Polylang ability
 * goes through here so authorization is enforced in exactly one place.
 */
final class ContentSupport {

	/**
	 * Post types that are never exposed, regardless of filters.
	 *
	 * Deliberately short. These are not content: they are WordPress's own
	 * bookkeeping rows that happen to be stored in the posts table, and editing
	 * them through generic post tools corrupts the feature that owns them —
	 * a revision is managed by the revision system, a nav_menu_item carries its
	 * meaning in meta that a title/content edit would desynchronise, and
	 * wp_global_styles holds theme JSON that is not post content at all.
	 * Everything else a site registers is real content and is exposed.
	 */
	private const ALWAYS_DENIED = array(
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_global_styles',
	);

	/**
	 * Post statuses an ability may set.
	 */
	public const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

	/**
	 * Returns every post type that may be offered for MCP exposure.
	 *
	 * This is the full set a site could choose from: everything registered,
	 * minus the internal types in {@see self::ALWAYS_DENIED}. Filtering on
	 * `show_in_rest` or `public` was the wrong gate, because plenty of theme and
	 * plugin custom post types hold real content while being registered with
	 * neither flag. What is actually exposed is the subset selected on the
	 * settings screen; this method is what populates that screen.
	 *
	 * @return string[] List of post type slugs.
	 */
	public static function registered_post_types(): array {
		return array_values( array_diff( get_post_types( array(), 'names' ), self::ALWAYS_DENIED ) );
	}

	/**
	 * Returns the post types the content abilities may operate on.
	 *
	 * Resolved from the settings screen, then passed through the filter so code
	 * can still override a site's stored choice. Capability checks apply per post
	 * type and per post regardless, so this governs what is *visible*, never what
	 * a user is *permitted* to do.
	 *
	 * @return string[] List of post type slugs.
	 */
	public static function allowed_post_types(): array {
		$post_types = Settings::active_post_types();

		/**
		 * Filters the post types exposed through the MCP content abilities.
		 *
		 * @since 0.6.1
		 *
		 * @param string[] $post_types List of post type slugs.
		 */
		$post_types = (array) apply_filters( 'mcp_adapter_content_post_types', $post_types );

		// Re-apply the hard denylist so a filter cannot open up internal types.
		return array_values(
			array_filter(
				array_map( 'strval', $post_types ),
				static fn( string $type ): bool => post_type_exists( $type ) && ! in_array( $type, self::ALWAYS_DENIED, true )
			)
		);
	}

	/**
	 * Validates a post type against the allowlist.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return true|\WP_Error True when usable, WP_Error otherwise.
	 */
	public static function validate_post_type( string $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf( 'Post type "%s" is not registered on this site.', $post_type )
			);
		}

		if ( ! in_array( $post_type, self::allowed_post_types(), true ) ) {
			return new WP_Error(
				'post_type_not_allowed',
				sprintf( 'Post type "%s" is not exposed through MCP.', $post_type )
			);
		}

		return true;
	}

	/**
	 * Resolves a post ID into a post object that passes the allowlist.
	 *
	 * @param mixed $post_id Post ID.
	 *
	 * @return \WP_Post|\WP_Error The post, or WP_Error when missing or not allowed.
	 */
	public static function get_post( $post_id ) {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post_id', 'A positive integer post_id is required.' );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'post_not_found', sprintf( 'No post found with ID %d.', $post_id ) );
		}

		$valid = self::validate_post_type( $post->post_type );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $post;
	}

	/**
	 * Returns the capability object for a post type.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return object|null Capability object, or null when the type is unknown.
	 */
	public static function capabilities( string $post_type ): ?object {
		$object = get_post_type_object( $post_type );

		return $object->cap ?? null;
	}

	/**
	 * Checks that the current user is authenticated.
	 *
	 * @return true|\WP_Error True when logged in, WP_Error otherwise.
	 */
	public static function require_authentication() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', 'User must be authenticated to use this ability.' );
		}

		return true;
	}

	/**
	 * Checks whether write abilities are enabled at all.
	 *
	 * Lets a site run the content server in read-only mode without having to
	 * unregister individual abilities.
	 *
	 * @return true|\WP_Error True when writes are allowed, WP_Error otherwise.
	 */
	public static function require_writes_enabled() {
		/**
		 * Filters whether MCP content abilities may modify the site.
		 *
		 * Defaults to the value set on the Meta MCP settings screen. Return false
		 * to expose the read abilities only.
		 *
		 * @since 0.6.1
		 *
		 * @param bool $enabled Whether writes are enabled.
		 */
		if ( ! apply_filters( 'mcp_adapter_content_writes_enabled', Settings::writes_enabled() ) ) {
			return new WP_Error( 'writes_disabled', 'MCP content write abilities are disabled on this site.' );
		}

		return true;
	}

	/**
	 * Checks a post type level capability, e.g. `edit_posts` or `publish_posts`.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $cap_key   Key on the post type capability object.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_post_type_cap( string $post_type, string $cap_key ) {
		$caps = self::capabilities( $post_type );

		if ( ! $caps || ! isset( $caps->{$cap_key} ) ) {
			return new WP_Error(
				'unknown_capability',
				sprintf( 'Post type "%s" does not define the "%s" capability.', $post_type, $cap_key )
			);
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability comes from the post type object.
		if ( ! current_user_can( $caps->{$cap_key} ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf( 'User lacks the "%s" capability for post type "%s".', $caps->{$cap_key}, $post_type )
			);
		}

		return true;
	}

	/**
	 * Checks that the current user may edit a specific post.
	 *
	 * @param mixed $post_id Post ID.
	 *
	 * @return \WP_Post|\WP_Error The post when permitted, WP_Error otherwise.
	 */
	public static function require_can_edit( $post_id ) {
		$post = self::get_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'cannot_edit_post',
				sprintf( 'User cannot edit post %d.', $post->ID )
			);
		}

		return $post;
	}

	/**
	 * Whether visitors can see posts of this type: public, or publicly queryable.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool True for a public type.
	 */
	public static function is_public_type( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		return $object instanceof \WP_Post_Type && ( ! empty( $object->public ) || ! empty( $object->publicly_queryable ) );
	}

	/**
	 * Whether the current user may see a post's password-protected content.
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return bool True when there is no password or the user can edit the post.
	 */
	public static function can_see_protected_content( WP_Post $post ): bool {
		return '' === (string) $post->post_password || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Checks that the current user may read a specific post.
	 *
	 * @param mixed $post_id Post ID.
	 *
	 * @return \WP_Post|\WP_Error The post when permitted, WP_Error otherwise.
	 */
	public static function require_can_read( $post_id ) {
		$post = self::get_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// "Published" only means "anyone may read it" for a post type the public can
		// see. A plugin's internal type (form entries, bookings, logs) publishes rows
		// that were never meant for subscribers.
		if ( 'publish' === $post->post_status && self::is_public_type( $post->post_type ) ) {
			return $post;
		}

		if ( 'publish' === $post->post_status ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				return $post;
			}

			return new WP_Error(
				'cannot_read_post',
				sprintf( 'User cannot read post %d: its post type is not public, so it needs edit permission.', $post->ID )
			);
		}

		if ( ! current_user_can( 'read_post', $post->ID ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'cannot_read_post',
				sprintf( 'User cannot read post %d.', $post->ID )
			);
		}

		return $post;
	}

	/**
	 * Validates that the user may move a post into the requested status.
	 *
	 * @param string $status    Target status.
	 * @param string $post_type Post type slug.
	 * @param int    $post_id   Post ID, or 0 when creating.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function require_can_set_status( string $status, string $post_type, int $post_id = 0 ) {
		if ( ! in_array( $status, self::WRITABLE_STATUSES, true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					'Status "%s" is not writable. Allowed: %s.',
					$status,
					implode( ', ', self::WRITABLE_STATUSES )
				)
			);
		}

		// Only publish-like statuses need the elevated capability.
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return true;
		}

		$caps = self::capabilities( $post_type );

		if ( ! $caps ) {
			return new WP_Error( 'invalid_post_type', sprintf( 'Post type "%s" is not registered.', $post_type ) );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability comes from the post type object.
		if ( ! current_user_can( $caps->publish_posts ) ) {
			return new WP_Error(
				'cannot_publish',
				sprintf( 'User lacks the "%s" capability required to set status "%s".', $caps->publish_posts, $status )
			);
		}

		if ( $post_id > 0 && 'private' === $status && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'cannot_edit_post', sprintf( 'User cannot edit post %d.', $post_id ) );
		}

		return true;
	}

	/**
	 * Re-reads a post after a write so the response reflects it.
	 *
	 * Falls back to the object already in hand if the re-read comes back empty,
	 * which keeps a stale summary from turning into a fatal error.
	 *
	 * @param \WP_Post $post The post as it was before the write.
	 *
	 * @return \WP_Post The refreshed post, or the original.
	 */
	public static function refresh( WP_Post $post ): WP_Post {
		clean_post_cache( $post->ID );

		$fresh = get_post( $post->ID );

		return $fresh instanceof WP_Post ? $fresh : $post;
	}

	/**
	 * Converts a post into the array shape returned by the abilities.
	 *
	 * @param \WP_Post $post Post object.
	 * @param array    $args {
	 *     Optional. Shaping options.
	 *
	 *     @type bool $include_content      Include post_content. Default false.
	 *     @type bool $include_terms        Include taxonomy terms. Default false.
	 *     @type bool $include_meta         Include public meta. Default false.
	 *     @type bool $include_language     Include Polylang language data. Default true.
	 *     @type bool $include_elementor    Include Elementor status. Default true.
	 * }
	 *
	 * @return array<string, mixed> The formatted post.
	 */
	public static function format_post( WP_Post $post, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'include_content'   => false,
				'include_terms'     => false,
				'include_meta'      => false,
				'include_language'  => true,
				'include_elementor' => true,
				// Lists: leave out what is derivable (edit_link, date_gmt) and give
				// translations as {lang: id}, which is most of a row's size on a
				// multilingual site.
				'compact'           => false,
			)
		);

		$data = array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'post_type'      => $post->post_type,
			'status'         => $post->post_status,
			'link'           => (string) get_permalink( $post ),
			'edit_link'      => (string) get_edit_post_link( $post->ID, 'raw' ),
			'author'         => (int) $post->post_author,
			'author_name'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'excerpt'        => $post->post_excerpt,
			'date'           => $post->post_date,
			'date_gmt'       => $post->post_date_gmt,
			'modified'       => $post->post_modified,
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'template'       => (string) get_page_template_slug( $post ),
			'comment_status' => $post->comment_status,
		);

		if ( $args['compact'] ) {
			unset( $data['edit_link'], $data['date_gmt'] );
		}

		if ( $args['include_content'] ) {
			if ( self::can_see_protected_content( $post ) ) {
				$data['content'] = $post->post_content;
			} else {
				$data['content_protected'] = true;
			}
		}

		if ( $args['include_terms'] ) {
			$data['terms'] = self::get_post_terms( $post );
		}

		if ( $args['include_meta'] ) {
			$data['meta'] = MetaSupport::read_meta( $post->ID );
		}

		if ( $args['include_elementor'] ) {
			$data['elementor'] = array(
				'built_with_elementor' => ElementorSupport::is_built_with_elementor( $post->ID ),
				'template_type'        => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			);
		}

		if ( $args['include_language'] && PolylangSupport::is_active() ) {
			$data['language']     = PolylangSupport::get_post_language( $post->ID );
			$data['translations'] = PolylangSupport::get_post_translations( $post->ID );

			if ( $args['compact'] ) {
				$data['language']     = $data['language']['slug'] ?? null;
				$data['translations'] = array_map( static fn( $t ) => is_array( $t ) ? (int) ( $t['id'] ?? 0 ) : $t, (array) $data['translations'] );
			}
		}

		return $data;
	}

	/**
	 * Collects the terms attached to a post, grouped by taxonomy.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Terms keyed by taxonomy.
	 */
	public static function get_post_terms( WP_Post $post ): array {
		$result     = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public && ! $taxonomy->show_in_rest ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			$result[ $taxonomy->name ] = array_map(
				static fn( $term ): array => array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'parent' => (int) $term->parent,
				),
				$terms
			);
		}

		return $result;
	}

	/**
	 * Validates a URL given for an SEO field (canonical, social image).
	 *
	 * esc_url_raw() alone turns "not a url" into "http://not%20a%20url", which
	 * would be stored and printed as the canonical. A path starting with "/" is
	 * taken as a URL on this site.
	 *
	 * @param string $value Incoming URL or site path.
	 *
	 * @return string|\WP_Error The absolute URL.
	 */
	public static function clean_absolute_url( string $value ) {
		$value = trim( $value );

		if ( '' !== $value && '/' === $value[0] && ( ! isset( $value[1] ) || '/' !== $value[1] ) ) {
			$value = home_url( $value );
		}

		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		$host  = '' === $clean ? '' : (string) wp_parse_url( $clean, PHP_URL_HOST );

		if ( '' === $clean || '' === $host || ! filter_var( $clean, FILTER_VALIDATE_URL ) || ! preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
			return new \WP_Error( 'invalid_url', sprintf( '"%s" is not a valid http(s) URL. Pass a full URL such as "https://example.com/page/", or a path on this site such as "/page/".', $value ) );
		}

		return $clean;
	}

	/**
	 * Normalizes a boolean-ish input value.
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $default Fallback when the value is absent.
	 *
	 * @return bool The normalized boolean.
	 */
	public static function to_bool( $value, bool $default = false ): bool {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		return (bool) rest_sanitize_boolean( $value );
	}
}
