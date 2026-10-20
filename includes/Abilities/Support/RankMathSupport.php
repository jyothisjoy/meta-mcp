<?php
/**
 * Rank Math SEO integration helpers.
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
 * Class - RankMathSupport
 *
 * Rank Math keeps a page's SEO title, description, canonical and robots in
 * `rank_math_*` post meta, and marks every one of those keys protected through
 * the `is_protected_meta` filter, so the generic meta ability cannot touch them.
 * Its site-wide settings live in three options, one per settings screen.
 *
 * Fields are written the way Rank Math's own editor writes them: an empty value
 * deletes the key rather than storing an empty string, because an empty
 * `rank_math_robots` or `rank_math_canonical_url` is what makes Rank Math fall
 * back to the post type default and the page's own URL.
 */
final class RankMathSupport {

	/**
	 * Option name for each Rank Math settings screen.
	 */
	public const OPTION_GROUPS = array(
		'general' => 'rank-math-options-general',
		'titles'  => 'rank-math-options-titles',
		'sitemap' => 'rank-math-options-sitemap',
	);

	/**
	 * Robots directives Rank Math accepts on a post.
	 */
	public const ROBOTS = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Per-post fields, keyed by input name, with their meta key and value kind.
	 */
	public const POST_FIELDS = array(
		'title'                => array( 'rank_math_title', 'text' ),
		'description'          => array( 'rank_math_description', 'text' ),
		'focus_keyword'        => array( 'rank_math_focus_keyword', 'text' ),
		'canonical_url'        => array( 'rank_math_canonical_url', 'url' ),
		'robots'               => array( 'rank_math_robots', 'robots' ),
		'advanced_robots'      => array( 'rank_math_advanced_robots', 'map' ),
		'breadcrumb_title'     => array( 'rank_math_breadcrumb_title', 'text' ),
		'facebook_title'       => array( 'rank_math_facebook_title', 'text' ),
		'facebook_description' => array( 'rank_math_facebook_description', 'text' ),
		'facebook_image'       => array( 'rank_math_facebook_image', 'url' ),
		'twitter_title'        => array( 'rank_math_twitter_title', 'text' ),
		'twitter_description'  => array( 'rank_math_twitter_description', 'text' ),
		'pillar_content'       => array( 'rank_math_pillar_content', 'toggle' ),
	);

	/**
	 * Whether Rank Math is active on this site.
	 *
	 * @return bool True when Rank Math is loaded.
	 */
	public static function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Helper' );
	}

	/**
	 * Returns the Rank Math version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) constant( 'RANK_MATH_VERSION' ) : '';
	}

	/**
	 * Guard used by every Rank Math ability.
	 *
	 * @return true|\WP_Error True when Rank Math is usable, WP_Error otherwise.
	 */
	public static function require_active() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'rank_math_not_active',
				'Rank Math SEO is not active on this site. Install and activate Rank Math to use this ability.'
			);
		}

		return true;
	}

	/**
	 * Checks that the current user may change a Rank Math settings screen.
	 *
	 * Rank Math gates each screen on its own capability (`rank_math_titles` and
	 * so on), which its Role Manager can grant to non-administrators.
	 *
	 * @param string $group Settings group key.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_can_manage_settings( string $group ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'rank_math_' . $group ) ) {
			return true;
		}

		return new WP_Error(
			'insufficient_capability',
			sprintf( 'User lacks the "manage_options" or "rank_math_%s" capability needed to change Rank Math %s settings.', $group, $group )
		);
	}

	/**
	 * Validates a settings group key.
	 *
	 * @param mixed $group Raw group.
	 *
	 * @return string|\WP_Error The group key.
	 */
	public static function validate_group( $group ) {
		$group = is_string( $group ) ? $group : '';

		if ( ! isset( self::OPTION_GROUPS[ $group ] ) ) {
			return new WP_Error(
				'invalid_group',
				sprintf( 'group must be one of: %s.', implode( ', ', array_keys( self::OPTION_GROUPS ) ) )
			);
		}

		return $group;
	}

	/**
	 * Reads a settings group.
	 *
	 * @param string $group Settings group key.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	public static function get_settings( string $group ): array {
		$stored = get_option( self::OPTION_GROUPS[ $group ], array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a Rank Math setting value means "on".
	 *
	 * Rank Math stores its switches as "on"/"off" strings, and newer screens
	 * occasionally as real booleans.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return bool True when switched on.
	 */
	public static function is_on( $value ): bool {
		return true === $value || 'on' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Merges new values into a settings group.
	 *
	 * Unknown keys are refused unless `$allow_new` is set, so a typo such as
	 * `pt_facilty_robots` is reported instead of being stored where nothing
	 * reads it. Booleans are converted to the "on"/"off" strings Rank Math uses
	 * when the existing value is stored that way.
	 *
	 * @param string               $group     Settings group key.
	 * @param array<string, mixed> $values    New values keyed by setting key.
	 * @param bool                 $allow_new Whether keys not yet stored may be added.
	 * @param bool                 $dry_run   Report the change without saving it.
	 *
	 * @return array{changed: array<string, array{before: mixed, after: mixed}>, unchanged: string[], errors: array<string, string>, saved: bool} Result summary.
	 */
	public static function update_settings( string $group, array $values, bool $allow_new, bool $dry_run = false ): array {
		$settings = self::get_settings( $group );
		$result   = array(
			'changed'   => array(),
			'unchanged' => array(),
			'errors'    => array(),
		);

		foreach ( $values as $key => $value ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			$exists = array_key_exists( $key, $settings );

			if ( ! $exists && ! $allow_new ) {
				$result['errors'][ $key ] = sprintf(
					'"%s" is not an existing Rank Math %s setting. Check the key with seo-get-settings, or pass allow_new_keys true to add it.',
					$key,
					$group
				);
				continue;
			}

			if ( null === $value ) {
				$result['errors'][ $key ] = 'A null value is not accepted. Pass the value the setting should hold, e.g. "off" or an empty array.';
				continue;
			}

			$before = $exists ? $settings[ $key ] : null;
			$value  = self::normalise_setting_value( $value, $before );

			if ( $exists && $before === $value ) {
				$result['unchanged'][] = $key;
				continue;
			}

			$settings[ $key ]          = $value;
			$result['changed'][ $key ] = array(
				'before' => $before,
				'after'  => $value,
			);
		}

		// All or nothing: one rejected key means nothing is saved, so a batch
		// with a typo in it never half-applies.
		$result['saved'] = false;

		if ( ! $dry_run && empty( $result['errors'] ) && ! empty( $result['changed'] ) ) {
			$result['saved'] = true;

			// Rank Math's sitemap cache watcher clears the sitemap on this update.
			update_option( self::OPTION_GROUPS[ $group ], $settings );

			// Sitemap and permalink-base settings change rewrite rules; Rank Math
			// flushes them on its next admin load when this flag is set.
			if ( in_array( $group, array( 'general', 'sitemap' ), true ) && is_callable( array( '\RankMath\Helper', 'schedule_flush_rewrite' ) ) ) {
				\RankMath\Helper::schedule_flush_rewrite();
			}
		}

		return $result;
	}

	/**
	 * Converts an incoming setting value to the shape Rank Math stores.
	 *
	 * @param mixed $value  Incoming value.
	 * @param mixed $before Currently stored value, or null.
	 *
	 * @return mixed The value to store.
	 */
	private static function normalise_setting_value( $value, $before ) {
		if ( is_bool( $value ) ) {
			// Keep real booleans where Rank Math already stores one.
			if ( is_bool( $before ) ) {
				return $value;
			}

			return $value ? 'on' : 'off';
		}

		if ( is_array( $value ) ) {
			return self::clean_setting_array( $value );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return is_string( $before ) ? (string) $value : $value;
		}

		return self::clean_setting_string( (string) $value );
	}

	/**
	 * Cleans every string inside a settings array.
	 *
	 * @param array<mixed> $value Incoming array.
	 *
	 * @return array<mixed> The cleaned array.
	 */
	private static function clean_setting_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::clean_setting_array( $item );
			} elseif ( is_string( $item ) ) {
				$value[ $key ] = self::clean_setting_string( $item );
			} elseif ( is_bool( $item ) ) {
				$value[ $key ] = $item ? 'on' : 'off';
			}
		}

		return $value;
	}

	/**
	 * Cleans a settings string.
	 *
	 * Some Rank Math settings legitimately hold markup (RSS before/after
	 * content, the robots.txt body), so an administrator's value is kept as it
	 * is, the same as on Rank Math's own screens. Anyone without
	 * `unfiltered_html` has it run through the post kses rules.
	 *
	 * @param string $value Incoming string.
	 *
	 * @return string The cleaned string.
	 */
	private static function clean_setting_string( string $value ): string {
		return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
	}

	/**
	 * Reads the Rank Math SEO fields of a post.
	 *
	 * Besides what is stored on the post, this reports what Rank Math will
	 * actually print for robots, and where that comes from: a value stored on
	 * the post always wins over the post type default, which is why a sitewide
	 * noindex can appear not to work.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array<string, mixed> The SEO data.
	 */
	public static function read_post( WP_Post $post ): array {
		$fields = array();

		foreach ( self::POST_FIELDS as $name => $field ) {
			$raw = get_post_meta( $post->ID, $field[0], true );

			if ( 'robots' === $field[1] || 'map' === $field[1] ) {
				$fields[ $name ] = is_array( $raw ) ? $raw : array();
				continue;
			}

			if ( 'toggle' === $field[1] ) {
				$fields[ $name ] = self::is_on( $raw );
				continue;
			}

			$fields[ $name ] = is_scalar( $raw ) ? (string) $raw : '';
		}

		$titles    = self::get_settings( 'titles' );
		$post_type = $post->post_type;
		$robots    = self::effective_robots( $post, $fields['robots'], $titles );

		return array(
			'post_id'           => $post->ID,
			'title'             => $post->post_title,
			'post_type'         => $post_type,
			'status'            => $post->post_status,
			'link'              => (string) get_permalink( $post ),
			'seo'               => $fields,
			'effective'         => array(
				'robots'         => $robots['robots'],
				'robots_source'  => $robots['source'],
				'canonical_url'  => '' !== $fields['canonical_url'] ? $fields['canonical_url'] : (string) get_permalink( $post ),
				'title_template' => '' !== $fields['title'] ? $fields['title'] : (string) ( $titles[ "pt_{$post_type}_title" ] ?? '' ),
				'description_template' => '' !== $fields['description'] ? $fields['description'] : (string) ( $titles[ "pt_{$post_type}_description" ] ?? '' ),
			),
			'post_type_defaults' => array(
				'custom_robots' => self::is_on( $titles[ "pt_{$post_type}_custom_robots" ] ?? '' ),
				'robots'        => isset( $titles[ "pt_{$post_type}_robots" ] ) && is_array( $titles[ "pt_{$post_type}_robots" ] ) ? array_values( $titles[ "pt_{$post_type}_robots" ] ) : array(),
				'in_sitemap'    => self::is_on( self::get_settings( 'sitemap' )[ "pt_{$post_type}_sitemap" ] ?? '' ),
			),
		);
	}

	/**
	 * Works out the robots directives Rank Math will print for a post.
	 *
	 * Mirrors Rank Math's `Singular::get_post_robots()` and `Paper::get_robots()`:
	 * the post's own value, else the post type default when "custom robots" is
	 * on for that type, else the global default; a private post is always noindex.
	 *
	 * @param \WP_Post             $post   The post.
	 * @param array<mixed>         $stored The robots stored on the post.
	 * @param array<string, mixed> $titles The Titles & Meta settings.
	 *
	 * @return array{robots: string[], source: string} The directives and where they came from.
	 */
	private static function effective_robots( WP_Post $post, array $stored, array $titles ): array {
		$post_type = $post->post_type;
		$source    = 'post';
		$robots    = array_values( array_map( 'strval', $stored ) );

		if ( empty( $robots ) ) {
			if ( self::is_on( $titles[ "pt_{$post_type}_custom_robots" ] ?? '' ) ) {
				$source = 'post_type';
				$robots = isset( $titles[ "pt_{$post_type}_robots" ] ) && is_array( $titles[ "pt_{$post_type}_robots" ] ) ? array_values( $titles[ "pt_{$post_type}_robots" ] ) : array();
			} else {
				$source = 'global';
				$robots = isset( $titles['robots_global'] ) && is_array( $titles['robots_global'] ) ? array_values( $titles['robots_global'] ) : array();
			}
		}

		if ( 'private' === $post->post_status && ! in_array( 'noindex', $robots, true ) ) {
			$robots   = array_values( array_diff( $robots, array( 'index' ) ) );
			$robots[] = 'noindex';
			$source  .= '+private';
		}

		if ( ! in_array( 'noindex', $robots, true ) && ! in_array( 'index', $robots, true ) ) {
			array_unshift( $robots, 'index' );
		}

		if ( ! in_array( 'nofollow', $robots, true ) && ! in_array( 'follow', $robots, true ) ) {
			$robots[] = 'follow';
		}

		return array(
			'robots' => array_values( array_unique( $robots ) ),
			'source' => $source,
		);
	}

	/**
	 * Writes Rank Math SEO fields onto a post.
	 *
	 * Only fields present in `$changes` are touched. An empty string, empty
	 * array or null deletes the key, which hands the field back to Rank Math's
	 * default: the page's own URL for canonical, the post type robots, and the
	 * title and description templates.
	 *
	 * @param \WP_Post             $post    The post.
	 * @param array<string, mixed> $changes Field values keyed by input name.
	 * @param bool                 $dry_run Validate and report without saving.
	 *
	 * @return array{updated: string[], deleted: string[], errors: array<string, string>} Result summary.
	 */
	public static function write_post( WP_Post $post, array $changes, bool $dry_run = false ): array {
		$result = array(
			'updated' => array(),
			'deleted' => array(),
			'errors'  => array(),
		);

		foreach ( $changes as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( self::POST_FIELDS[ $name ] ) ) {
				continue;
			}

			list( $meta_key, $kind ) = self::POST_FIELDS[ $name ];

			$clean = self::clean_post_value( $kind, $value );

			if ( is_wp_error( $clean ) ) {
				$result['errors'][ $name ] = $clean->get_error_message();
				continue;
			}

			if ( null === $clean ) {
				if ( ! $dry_run ) {
					delete_post_meta( $post->ID, $meta_key );
				}

				$result['deleted'][] = $name;
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $post->ID, $meta_key, wp_slash( $clean ) );
			}

			$result['updated'][] = $name;
		}

		if ( ! $dry_run && ( ! empty( $result['updated'] ) || ! empty( $result['deleted'] ) ) ) {
			clean_post_cache( $post->ID );

			/**
			 * Fires after the MCP SEO ability changes a post's Rank Math fields.
			 *
			 * Cache plugins can hook this to purge the page.
			 *
			 * @since 1.5.0
			 *
			 * @param int   $post_id The post.
			 * @param array $result  Which fields were updated and deleted.
			 */
			do_action( 'mcp_adapter_seo_post_updated', $post->ID, $result );
		}

		return $result;
	}

	/**
	 * Validates and cleans one per-post field value.
	 *
	 * @param string $kind  Value kind from {@see self::POST_FIELDS}.
	 * @param mixed  $value Incoming value.
	 *
	 * @return mixed|null|\WP_Error The value to store, null to delete, or WP_Error.
	 */
	private static function clean_post_value( string $kind, $value ) {
		if ( null === $value || '' === $value || ( is_array( $value ) && empty( $value ) ) ) {
			return null;
		}

		switch ( $kind ) {
			case 'text':
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a string.' );
				}

				// Not sanitize_text_field(): it strips "%da"-like sequences and
				// so would mangle Rank Math variables such as %date%.
				$clean = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $value ) ) );

				return '' === $clean ? null : $clean;

			case 'url':
				if ( ! is_string( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a URL string.' );
				}

				$clean = esc_url_raw( trim( $value ) );

				if ( '' === $clean ) {
					return new WP_Error( 'invalid_url', sprintf( '"%s" is not a valid URL.', $value ) );
				}

				return $clean;

			case 'robots':
				$list = is_array( $value ) ? $value : explode( ',', (string) $value );
				$list = array_values( array_unique( array_filter( array_map( static fn( $item ): string => strtolower( trim( (string) $item ) ), $list ) ) ) );

				$unknown = array_diff( $list, self::ROBOTS );

				if ( ! empty( $unknown ) ) {
					return new WP_Error(
						'invalid_robots',
						sprintf( 'Unknown robots directive(s): %s. Allowed: %s.', implode( ', ', $unknown ), implode( ', ', self::ROBOTS ) )
					);
				}

				if ( in_array( 'index', $list, true ) && in_array( 'noindex', $list, true ) ) {
					return new WP_Error( 'invalid_robots', 'robots cannot contain both "index" and "noindex".' );
				}

				if ( in_array( 'follow', $list, true ) && in_array( 'nofollow', $list, true ) ) {
					return new WP_Error( 'invalid_robots', 'robots cannot contain both "follow" and "nofollow".' );
				}

				return empty( $list ) ? null : $list;

			case 'map':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected an object, e.g. {"max-snippet": "-1", "max-image-preview": "large"}.' );
				}

				$clean = array();

				foreach ( $value as $key => $item ) {
					if ( is_scalar( $item ) ) {
						$clean[ sanitize_key( (string) $key ) ] = (string) $item;
					}
				}

				return empty( $clean ) ? null : $clean;

			case 'toggle':
				return ContentSupport::to_bool( $value, false ) ? 'on' : null;
		}

		return new WP_Error( 'invalid_value', 'Unsupported field.' );
	}

	/**
	 * Input schema properties for the per-post fields.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	public static function post_field_schema(): array {
		$nullable_string = array( 'string', 'null' );

		return array(
			'title'                => array(
				'type'        => $nullable_string,
				'description' => 'SEO title. Rank Math variables such as %title%, %sep% and %sitename% are kept. Empty or null clears it, so the post type title template applies.',
			),
			'description'          => array(
				'type'        => $nullable_string,
				'description' => 'Meta description. Empty or null clears it, so the post type description template applies.',
			),
			'focus_keyword'        => array(
				'type'        => $nullable_string,
				'description' => 'Focus keyword(s), comma separated.',
			),
			'canonical_url'        => array(
				'type'        => $nullable_string,
				'description' => 'Canonical URL override. Empty or null clears it, which makes the page canonical to itself.',
			),
			'robots'               => array(
				'type'        => array( 'array', 'null' ),
				'items'       => array(
					'type' => 'string',
					'enum' => self::ROBOTS,
				),
				'description' => 'Robots directives stored on this post, e.g. ["noindex","follow"]. A value here overrides the post type default. An empty array or null clears it so the post type default from Titles & Meta applies.',
			),
			'advanced_robots'      => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Advanced robots, e.g. {"max-snippet":"-1","max-video-preview":"-1","max-image-preview":"large"}. Null clears it.',
			),
			'breadcrumb_title'     => array(
				'type'        => $nullable_string,
				'description' => 'Title used in breadcrumbs.',
			),
			'facebook_title'       => array( 'type' => $nullable_string ),
			'facebook_description' => array( 'type' => $nullable_string ),
			'facebook_image'       => array(
				'type'        => $nullable_string,
				'description' => 'Open Graph image URL.',
			),
			'twitter_title'        => array( 'type' => $nullable_string ),
			'twitter_description'  => array( 'type' => $nullable_string ),
			'pillar_content'       => array(
				'type'        => array( 'boolean', 'null' ),
				'description' => 'Mark as pillar content.',
			),
		);
	}
}
