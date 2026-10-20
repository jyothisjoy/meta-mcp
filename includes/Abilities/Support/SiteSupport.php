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
	);

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
}
