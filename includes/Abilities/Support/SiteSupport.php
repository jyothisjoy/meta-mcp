<?php
/**
 * Site-wide settings and cache helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - SiteSupport
 *
 * Exposes a short, explicit list of WordPress options rather than
 * `update_option()` on anything. Options such as `siteurl`, `home`,
 * `admin_email`, `users_can_register` and `default_role` can take a site
 * offline or open it up to strangers, and are never writable here. Sites that
 * need more can add keys through the `mcp_adapter_site_settings` filter.
 */
final class SiteSupport {

	/**
	 * Options readable and writable by default, with their value kind.
	 *
	 * Kinds: text, int, page (a published page ID or 0), enum:a|b, bool.
	 */
	private const DEFAULT_SETTINGS = array(
		'blogname'               => 'text',
		'blogdescription'        => 'text',
		'timezone_string'        => 'timezone',
		'date_format'            => 'text',
		'time_format'            => 'text',
		'start_of_week'          => 'int',
		'posts_per_page'         => 'int',
		'show_on_front'          => 'enum:posts|page',
		'page_on_front'          => 'page',
		'page_for_posts'         => 'page',
		'default_comment_status' => 'enum:open|closed',
		'default_ping_status'    => 'enum:open|closed',
		'site_icon'              => 'attachment',
		// General.
		'WPLANG'                 => 'locale',
		// Reading.
		'blog_public'            => 'bool',
		// Permalinks.
		'permalink_structure'    => 'permalink',
		'category_base'          => 'base',
		'tag_base'               => 'base',
		// Discussion.
		'default_pingback_flag'          => 'bool',
		'require_name_email'             => 'bool',
		'comment_registration'           => 'bool',
		'close_comments_for_old_posts'   => 'bool',
		'close_comments_days_old'        => 'int',
		'show_comments_cookies_opt_in'   => 'bool',
		'thread_comments'                => 'bool',
		'thread_comments_depth'          => 'int',
		'page_comments'                  => 'bool',
		'comments_per_page'              => 'int',
		'default_comments_page'          => 'enum:newest|oldest',
		'comment_order'                  => 'enum:asc|desc',
		'comments_notify'                => 'bool',
		'moderation_notify'              => 'bool',
		'comment_moderation'             => 'bool',
		'comment_previously_approved'    => 'bool',
		'comment_max_links'              => 'int',
		'moderation_keys'                => 'textarea',
		'disallowed_keys'                => 'textarea',
		'show_avatars'                   => 'bool',
		'avatar_rating'                  => 'enum:G|PG|R|X',
		'avatar_default'                 => 'enum:mystery|blank|gravatar_default|identicon|wavatar|monsterid|retro|robohash',
		// Media.
		'thumbnail_size_w'               => 'int',
		'thumbnail_size_h'               => 'int',
		'thumbnail_crop'                 => 'bool',
		'medium_size_w'                  => 'int',
		'medium_size_h'                  => 'int',
		'large_size_w'                   => 'int',
		'large_size_h'                   => 'int',
		'uploads_use_yearmonth_folders'  => 'bool',
		// Privacy.
		'wp_page_for_privacy_policy'     => 'page',
	);

	/**
	 * Settings whose change needs the rewrite rules rebuilt.
	 */
	public const REWRITE_SETTINGS = array( 'permalink_structure', 'category_base', 'tag_base' );

	/**
	 * Warnings shown when a setting changes, so the client can relay the consequence.
	 *
	 * @param string $option The option.
	 * @param mixed  $value  The new value.
	 *
	 * @return string The warning, or an empty string.
	 */
	public static function change_warning( string $option, $value ): string {
		switch ( $option ) {
			case 'blog_public':
				return (int) $value
					? 'Search engines are allowed to index the site again.'
					: 'Search engines are now asked NOT to index this site ("Discourage search engines"). Use only on staging sites; on a live site this removes it from search results over time.';
			case 'permalink_structure':
			case 'category_base':
			case 'tag_base':
				return 'Every URL of this kind changes. WordPress redirects old post URLs only in some cases; add redirects (redirects-create) for any changed URL that has links or rankings, and purge the page cache.';
			case 'comment_registration':
				return (int) $value ? 'Only logged-in users can comment now.' : '';
			case 'WPLANG':
				return 'The admin and front-end language changes for everyone who has not chosen their own.';
		}

		if ( false !== strpos( $option, '_size_' ) || 'thumbnail_crop' === $option ) {
			return 'Only images uploaded from now on use the new size; existing images keep theirs until thumbnails are regenerated.';
		}

		return '';
	}

	/**
	 * Option keys that are never exposed, even through the filter.
	 */
	private const NEVER = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'template',
		'stylesheet',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secret_key',
		'secure_auth_key',
		'secure_auth_salt',
		'wp_user_roles',
		'cron',
		'recently_activated',
		'upload_path',
		'upload_url_path',
	);

	/**
	 * Returns the exposed settings and their value kinds.
	 *
	 * @return array<string, string> Kinds keyed by option name.
	 */
	public static function settings(): array {
		/**
		 * Filters the WordPress options readable and writable through MCP.
		 *
		 * Keys are option names; values are the kind of value they hold: "text",
		 * "int", "bool", "page", "attachment", "timezone" or "enum:a|b|c". Keys
		 * that would let a caller take over the site (siteurl, home, admin_email,
		 * default_role, users_can_register and similar) are always removed.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $settings Kinds keyed by option name.
		 */
		$settings = (array) apply_filters( 'mcp_adapter_site_settings', self::DEFAULT_SETTINGS );

		return array_diff_key( $settings, array_flip( self::NEVER ) );
	}

	/**
	 * Validates one option value.
	 *
	 * @param string $kind  Value kind.
	 * @param mixed  $value Incoming value.
	 *
	 * @return mixed|\WP_Error The value to store.
	 */
	public static function clean_value( string $kind, $value ) {
		if ( 0 === strpos( $kind, 'enum:' ) ) {
			$allowed = explode( '|', substr( $kind, 5 ) );

			if ( ! in_array( (string) $value, $allowed, true ) ) {
				return new WP_Error( 'invalid_value', sprintf( 'Must be one of: %s.', implode( ', ', $allowed ) ) );
			}

			return (string) $value;
		}

		switch ( $kind ) {
			case 'text':
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a string.' );
				}

				return sanitize_text_field( (string) $value );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a number.' );
				}

				return (int) $value;

			case 'bool':
				return ContentSupport::to_bool( $value, false ) ? 1 : 0;

			case 'textarea':
				if ( ! is_scalar( $value ) && ! is_array( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a string, one entry per line, or a list.' );
				}

				$lines = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : preg_split( '/\r\n|\r|\n/', (string) $value );

				return implode( "\n", array_filter( array_map( static fn( $line ): string => sanitize_text_field( (string) $line ), $lines ), 'strlen' ) );

			case 'locale':
				$value = (string) $value;

				// "" and "en_US" both mean English (United States), which is always available.
				if ( '' === $value || 'en_US' === $value ) {
					return '';
				}

				if ( ! function_exists( 'get_available_languages' ) || ! in_array( $value, get_available_languages(), true ) ) {
					return new WP_Error(
						'invalid_value',
						sprintf(
							'"%s" is not installed on this site. Installed: en_US, %s. Install a language under Settings → General first.',
							$value,
							implode( ', ', function_exists( 'get_available_languages' ) ? get_available_languages() : array() )
						)
					);
				}

				return $value;

			case 'permalink':
				$value = trim( (string) $value );

				// Empty means plain permalinks (?p=123).
				if ( '' === $value ) {
					return '';
				}

				global $wp_rewrite;

				$value = '/' . ltrim( preg_replace( '#/+#', '/', $value ), '/' );
				preg_match_all( '/%[a-z_]+%/', $value, $tags );
				$known = isset( $wp_rewrite->rewritecode ) ? (array) $wp_rewrite->rewritecode : array();

				if ( empty( $tags[0] ) ) {
					return new WP_Error( 'invalid_value', 'A permalink structure needs at least one tag, e.g. "/%postname%/" or "/%year%/%monthnum%/%postname%/". Use "" for plain permalinks.' );
				}

				$unknown = empty( $known ) ? array() : array_diff( $tags[0], $known );

				if ( ! empty( $unknown ) ) {
					return new WP_Error( 'invalid_value', sprintf( 'Unknown permalink tag(s): %s.', implode( ', ', $unknown ) ) );
				}

				return (string) sanitize_option( 'permalink_structure', $value );

			case 'base':
				$value = trim( (string) $value, " /\t\n" );

				return '' === $value ? '' : (string) sanitize_option( 'category_base', '/' . $value );

			case 'timezone':
				$value = (string) $value;

				if ( ! in_array( $value, timezone_identifiers_list(), true ) ) {
					return new WP_Error( 'invalid_value', sprintf( '"%s" is not a valid timezone, e.g. "Asia/Dubai".', $value ) );
				}

				return $value;

			case 'page':
				$id = (int) $value;

				if ( 0 === $id ) {
					return 0;
				}

				if ( 'page' !== get_post_type( $id ) ) {
					return new WP_Error( 'invalid_value', sprintf( 'Post %d is not a page.', $id ) );
				}

				return $id;

			case 'attachment':
				$id = (int) $value;

				if ( 0 === $id ) {
					return 0;
				}

				if ( 'attachment' !== get_post_type( $id ) ) {
					return new WP_Error( 'invalid_value', sprintf( 'Post %d is not a media attachment.', $id ) );
				}

				return $id;
		}

		return new WP_Error( 'invalid_value', sprintf( 'Unknown value kind "%s".', $kind ) );
	}

	/**
	 * Purges whichever page caches are installed.
	 *
	 * Each cache plugin has its own public purge hook or function; every one
	 * found is called and reported. Nothing here throws if a plugin is absent.
	 *
	 * @param int[] $post_ids Purge only these posts' pages. Empty purges everything.
	 *
	 * @return array{purged: string[], skipped: string[]} Which caches were purged.
	 */
	public static function purge_caches( array $post_ids ): array {
		$purged  = array();
		$skipped = array();
		$all     = empty( $post_ids );

		// LiteSpeed Cache. Its purge goes out as a response header on this request.
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			if ( $all ) {
				do_action( 'litespeed_purge_all' );
			} else {
				foreach ( $post_ids as $id ) {
					do_action( 'litespeed_purge_post', $id );
				}
			}

			$purged[] = 'litespeed';
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			if ( $all ) {
				rocket_clean_domain();
			} elseif ( function_exists( 'rocket_clean_post' ) ) {
				foreach ( $post_ids as $id ) {
					rocket_clean_post( $id );
				}
			}

			$purged[] = 'wp-rocket';
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			if ( $all ) {
				w3tc_flush_all();
			} elseif ( function_exists( 'w3tc_flush_post' ) ) {
				foreach ( $post_ids as $id ) {
					w3tc_flush_post( $id );
				}
			}

			$purged[] = 'w3-total-cache';
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			if ( $all ) {
				wp_cache_clear_cache();
			} elseif ( function_exists( 'wpsc_delete_post_cache' ) ) {
				foreach ( $post_ids as $id ) {
					wpsc_delete_post_cache( $id );
				}
			}

			$purged[] = 'wp-super-cache';
		}

		// SiteGround Speed Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			if ( $all ) {
				sg_cachepress_purge_cache();
			} else {
				foreach ( $post_ids as $id ) {
					sg_cachepress_purge_cache( (string) get_permalink( $id ) );
				}
			}

			$purged[] = 'siteground';
		}

		// Elementor's generated CSS. Only on a full purge: regenerating every
		// post's stylesheet is site-wide by nature.
		if ( $all && class_exists( '\Elementor\Plugin' ) ) {
			try {
				$elementor = \Elementor\Plugin::instance();

				if ( isset( $elementor->files_manager ) && is_object( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
					$elementor->files_manager->clear_cache();
					$purged[] = 'elementor-css';
				}
			} catch ( \Throwable $e ) {
				$skipped[] = 'elementor-css';
			}
		}

		foreach ( $post_ids as $id ) {
			clean_post_cache( $id );
		}

		/**
		 * Fires after the MCP purge-cache ability has run.
		 *
		 * Hook this to purge a cache layer the ability does not know about.
		 *
		 * @since 1.5.0
		 *
		 * @param int[] $post_ids The posts purged, or empty for everything.
		 */
		do_action( 'mcp_adapter_purge_cache', $post_ids );

		return array(
			'purged'  => $purged,
			'skipped' => $skipped,
		);
	}

	/**
	 * Rebuilds the rewrite rules in a normal front-end request.
	 *
	 * flush_rewrite_rules() inside this REST request rebuilt the rules without the
	 * ones some plugins add only on a front-end load (Polylang's language rules
	 * among them), and tag and term archives started returning 404 until someone
	 * opened Settings → Permalinks. Clearing the stored rules makes WordPress
	 * rebuild them on the next request, and a loopback request to the homepage
	 * makes that next request an ordinary front-end one, straight away.
	 *
	 * @return array{loopback_status: int, ok: bool, note: string} What happened.
	 */
	public static function rebuild_rewrite_rules(): array {
		delete_option( 'rewrite_rules' );

		$response = wp_remote_get(
			add_query_arg( 'mcp-rewrite-rebuild', (string) time(), home_url( '/' ) ),
			array(
				'timeout'   => 30,
				'blocking'  => true,
				// Same filter WordPress core uses for its own loopback requests.
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// The loopback wrote the rules in another process; drop this request's cached copy before checking.
		wp_cache_delete( 'rewrite_rules', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$ok     = $status > 0 && $status < 500 && false !== get_option( 'rewrite_rules' ) && ! empty( get_option( 'rewrite_rules' ) );

		return array(
			'loopback_status' => $status,
			'ok'              => $ok,
			'note'            => $ok
				? 'Rewrite rules rebuilt by a front-end request.'
				: 'The rewrite rules are rebuilt on the next page visit. If archives return 404, open Settings → Permalinks once in wp-admin.',
		);
	}
}
