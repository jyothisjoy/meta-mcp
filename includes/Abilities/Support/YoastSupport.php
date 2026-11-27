<?php
/**
 * Yoast SEO integration helpers.
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
 * Class - YoastSupport
 *
 * Yoast keeps a page's SEO title, description, canonical and robots in
 * `_yoast_wpseo_*` post meta (protected, so the generic meta ability cannot
 * touch them) and its site-wide settings in the `wpseo`, `wpseo_titles` and
 * `wpseo_social` options.
 *
 * Yoast's front end does not read that meta directly: since Yoast 14 it prints
 * what its indexables table holds, a per-object cache that Yoast rebuilds when
 * a post is saved. A meta write on its own would therefore change what the
 * editor shows but not what the page prints. Every write here is followed by an
 * explicit indexable rebuild, and read_post reports the indexable and the
 * resolved front-end output next to the stored meta, so a caller can see the
 * change has really landed.
 *
 * Same static interface as {@see RankMathSupport}, selected by {@see SeoBackend}.
 */
final class YoastSupport {

	/**
	 * Option name for each Yoast settings area.
	 */
	public const OPTION_GROUPS = array(
		'general' => 'wpseo',
		'titles'  => 'wpseo_titles',
		'social'  => 'wpseo_social',
	);

	/**
	 * Prefix Yoast puts in front of every per-post meta key.
	 */
	private const META_PREFIX = '_yoast_wpseo_';

	/**
	 * Robots directives accepted on a post. "index"/"follow" are accepted so a
	 * page can be forced indexable even when its post type defaults to noindex.
	 */
	public const ROBOTS = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Advanced robots directives, stored together in `meta-robots-adv`.
	 */
	private const ADVANCED_ROBOTS = array( 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Per-post fields, keyed by input name, with their Yoast meta key (without
	 * the prefix) and value kind. "robots" spans three keys and is handled
	 * separately.
	 */
	public const POST_FIELDS = array(
		'title'                => array( 'title', 'text' ),
		'description'          => array( 'metadesc', 'text' ),
		'focus_keyword'        => array( 'focuskw', 'text' ),
		'canonical_url'        => array( 'canonical', 'url' ),
		'robots'               => array( 'meta-robots-noindex', 'robots' ),
		'breadcrumb_title'     => array( 'bctitle', 'text' ),
		'facebook_title'       => array( 'opengraph-title', 'text' ),
		'facebook_description' => array( 'opengraph-description', 'text' ),
		'facebook_image'       => array( 'opengraph-image', 'image' ),
		'twitter_title'        => array( 'twitter-title', 'text' ),
		'twitter_description'  => array( 'twitter-description', 'text' ),
		'twitter_image'        => array( 'twitter-image', 'image' ),
		'pillar_content'       => array( 'is_cornerstone', 'toggle' ),
		'schema_page_type'     => array( 'schema_page_type', 'text' ),
		'schema_article_type'  => array( 'schema_article_type', 'text' ),
	);

	/**
	 * Whether Yoast SEO is active on this site.
	 *
	 * @return bool True when Yoast is loaded.
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Options' );
	}

	/**
	 * Returns the Yoast version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'WPSEO_VERSION' ) ? (string) constant( 'WPSEO_VERSION' ) : '';
	}

	/**
	 * Guard used by every Yoast code path.
	 *
	 * @return true|\WP_Error True when Yoast is usable, WP_Error otherwise.
	 */
	public static function require_active() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'yoast_not_active',
				'Yoast SEO is not active on this site. Install and activate Yoast SEO to use this provider.'
			);
		}

		return true;
	}

	/**
	 * Checks that the current user may change Yoast's settings.
	 *
	 * Yoast gates its settings screens on `wpseo_manage_options`, which its SEO
	 * Manager role carries; administrators have `manage_options`.
	 *
	 * @param string $group Settings group key (unused: Yoast has one capability for all of them).
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_can_manage_settings( string $group ) {
		unset( $group );

		if ( current_user_can( 'manage_options' ) || current_user_can( 'wpseo_manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'insufficient_capability',
			'User lacks the "manage_options" or "wpseo_manage_options" capability needed to change Yoast SEO settings.'
		);
	}

	/**
	 * The settings group holding the sitemap switches, for permission checks.
	 *
	 * @return string Group key.
	 */
	public static function sitemap_group(): string {
		return 'general';
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
				sprintf( 'For Yoast SEO, group must be one of: %s.', implode( ', ', array_keys( self::OPTION_GROUPS ) ) )
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
	 * Merges new values into a settings group.
	 *
	 * Behaves like {@see RankMathSupport::update_settings()}: unknown keys are
	 * refused unless `$allow_new` is set, and one refused key means nothing is
	 * saved. Yoast's own option validation (WPSEO_Option, hooked on
	 * `sanitize_option_*`) still runs on save and may correct or refuse a value;
	 * any key whose stored value differs from what was asked is reported in
	 * `adjusted` with what Yoast actually kept.
	 *
	 * @param string               $group     Settings group key.
	 * @param array<string, mixed> $values    New values keyed by setting key.
	 * @param bool                 $allow_new Whether keys not yet stored may be added.
	 * @param bool                 $dry_run   Report the change without saving it.
	 *
	 * @return array{changed: array<string, array{before: mixed, after: mixed}>, unchanged: string[], errors: array<string, string>, saved: bool, adjusted: array<string, mixed>} Result summary.
	 */
	public static function update_settings( string $group, array $values, bool $allow_new, bool $dry_run = false ): array {
		$settings = self::get_settings( $group );
		$result   = array(
			'changed'   => array(),
			'unchanged' => array(),
			'errors'    => array(),
			'adjusted'  => array(),
		);

		foreach ( $values as $key => $value ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			$exists = array_key_exists( $key, $settings );

			if ( ! $exists && ! $allow_new ) {
				$result['errors'][ $key ] = sprintf(
					'"%s" is not an existing Yoast SEO %s setting. Check the key with seo-get-settings, or pass allow_new_keys true to add it.',
					$key,
					$group
				);
				continue;
			}

			if ( null === $value ) {
				$result['errors'][ $key ] = 'A null value is not accepted. Pass the value the setting should hold, e.g. false or an empty string.';
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

		$result['saved'] = false;

		if ( ! $dry_run && empty( $result['errors'] ) && ! empty( $result['changed'] ) ) {
			$result['saved'] = true;

			update_option( self::OPTION_GROUPS[ $group ], $settings );

			// Yoast keeps a static copy of its options for the request.
			if ( is_callable( array( '\WPSEO_Options', 'clear_cache' ) ) ) {
				\WPSEO_Options::clear_cache();
			}

			$stored = self::get_settings( $group );

			foreach ( $result['changed'] as $key => $change ) {
				$kept = $stored[ $key ] ?? null;

				if ( $kept !== $change['after'] ) {
					$result['adjusted'][ $key ] = $kept;
				}
			}

			// Sitemap and permalink switches live in "wpseo" and change rewrites.
			if ( 'general' === $group ) {
				self::flush_sitemap_cache();
			}
		}

		return $result;
	}

	/**
	 * Converts an incoming setting value to the shape Yoast stores.
	 *
	 * Yoast stores its switches as real booleans, so "on"/"off" and 1/0 are
	 * converted where the existing value is a boolean.
	 *
	 * @param mixed $value  Incoming value.
	 * @param mixed $before Currently stored value, or null.
	 *
	 * @return mixed The value to store.
	 */
	private static function normalise_setting_value( $value, $before ) {
		if ( is_bool( $before ) && ! is_bool( $value ) && ( is_scalar( $value ) ) ) {
			$string = strtolower( trim( (string) $value ) );

			if ( in_array( $string, array( 'on', 'true', '1', 'yes' ), true ) ) {
				return true;
			}

			if ( in_array( $string, array( 'off', 'false', '0', 'no', '' ), true ) ) {
				return false;
			}
		}

		if ( is_array( $value ) ) {
			return self::clean_setting_array( $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return is_string( $before ) ? (string) $value : $value;
		}

		return current_user_can( 'unfiltered_html' ) ? (string) $value : wp_kses_post( (string) $value );
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
			} elseif ( is_string( $item ) && ! current_user_can( 'unfiltered_html' ) ) {
				$value[ $key ] = wp_kses_post( $item );
			}
		}

		return $value;
	}

	/**
	 * Reads one Yoast meta value of a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key without the prefix.
	 *
	 * @return string The stored value, or an empty string.
	 */
	private static function meta( int $post_id, string $key ): string {
		$raw = get_post_meta( $post_id, self::META_PREFIX . $key, true );

		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Reads the Yoast SEO fields of a post.
	 *
	 * Reports the stored meta in `seo`, what Yoast will print in `effective`
	 * (the resolved front-end values when Yoast can build them, otherwise
	 * worked out from the meta and the Search Appearance defaults), and what
	 * the indexables table holds in `indexable`.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array<string, mixed> The SEO data.
	 */
	public static function read_post( WP_Post $post ): array {
		$fields = array();

		foreach ( self::POST_FIELDS as $name => $field ) {
			if ( 'robots' === $field[1] ) {
				$fields[ $name ] = self::stored_robots( $post->ID );
				continue;
			}

			$raw = self::meta( $post->ID, $field[0] );

			$fields[ $name ] = 'toggle' === $field[1] ? '1' === $raw : $raw;
		}

		$titles    = self::get_settings( 'titles' );
		$general   = self::get_settings( 'general' );
		$post_type = $post->post_type;
		$robots    = self::effective_robots( $post, $fields['robots'], $titles );
		$frontend  = self::frontend( $post->ID );
		$type_noindex = ! empty( $titles[ "noindex-{$post_type}" ] );

		$effective = array(
			'robots'               => $robots['robots'],
			'robots_source'        => $robots['source'],
			'canonical_url'        => '' !== $fields['canonical_url'] ? $fields['canonical_url'] : (string) get_permalink( $post ),
			'title_template'       => '' !== $fields['title'] ? $fields['title'] : (string) ( $titles[ "title-{$post_type}" ] ?? '' ),
			'description_template' => '' !== $fields['description'] ? $fields['description'] : (string) ( $titles[ "metadesc-{$post_type}" ] ?? '' ),
		);

		if ( ! empty( $frontend ) ) {
			$effective = array_merge( $effective, $frontend );
		}

		$result = array(
			'post_id'            => $post->ID,
			'title'              => $post->post_title,
			'post_type'          => $post_type,
			'status'             => $post->post_status,
			'link'               => (string) get_permalink( $post ),
			'seo'                => $fields,
			'effective'          => $effective,
			'post_type_defaults' => array(
				'custom_robots' => $type_noindex,
				'robots'        => $type_noindex ? array( 'noindex' ) : array(),
				'in_sitemap'    => ! $type_noindex && ( ! array_key_exists( 'enable_xml_sitemap', $general ) || ! empty( $general['enable_xml_sitemap'] ) ),
			),
		);

		$indexable = self::indexable( $post->ID );

		if ( null !== $indexable ) {
			$result['indexable'] = $indexable;
		}

		return $result;
	}

	/**
	 * Rebuilds the robots list stored on a post from Yoast's three robots keys.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string[] Directives stored on the post (empty when it follows the defaults).
	 */
	private static function stored_robots( int $post_id ): array {
		$robots  = array();
		$noindex = self::meta( $post_id, 'meta-robots-noindex' );

		if ( '1' === $noindex ) {
			$robots[] = 'noindex';
		} elseif ( '2' === $noindex ) {
			$robots[] = 'index';
		}

		if ( '1' === self::meta( $post_id, 'meta-robots-nofollow' ) ) {
			$robots[] = 'nofollow';
		}

		foreach ( explode( ',', self::meta( $post_id, 'meta-robots-adv' ) ) as $item ) {
			$item = strtolower( trim( $item ) );

			if ( in_array( $item, self::ADVANCED_ROBOTS, true ) ) {
				$robots[] = $item;
			}
		}

		return array_values( array_unique( $robots ) );
	}

	/**
	 * Works out the robots directives Yoast will print for a post.
	 *
	 * The post's own noindex choice, else the post type default from Search
	 * Appearance; "Discourage search engines" (blog_public 0) and private posts
	 * are always noindex.
	 *
	 * @param \WP_Post             $post   The post.
	 * @param string[]             $stored The robots stored on the post.
	 * @param array<string, mixed> $titles The wpseo_titles settings.
	 *
	 * @return array{robots: string[], source: string} The directives and where they came from.
	 */
	private static function effective_robots( WP_Post $post, array $stored, array $titles ): array {
		$robots = $stored;
		$source = 'post';

		if ( ! in_array( 'index', $robots, true ) && ! in_array( 'noindex', $robots, true ) ) {
			// No index choice on the post: the post type default decides it.
			$source = empty( $stored ) ? 'post_type' : 'post+post_type';
			array_unshift( $robots, ! empty( $titles[ "noindex-{$post->post_type}" ] ) ? 'noindex' : 'index' );
		}

		if ( '0' === (string) get_option( 'blog_public' ) && ! in_array( 'noindex', $robots, true ) ) {
			$robots   = array_values( array_diff( $robots, array( 'index' ) ) );
			$robots[] = 'noindex';
			$source  .= '+blog_public';
		}

		if ( 'private' === $post->post_status && ! in_array( 'noindex', $robots, true ) ) {
			$robots   = array_values( array_diff( $robots, array( 'index' ) ) );
			$robots[] = 'noindex';
			$source  .= '+private';
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
	 * The values Yoast's front end resolves for a post, via its meta surface.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, mixed> Resolved title, description, canonical and robots; empty when unavailable.
	 */
	private static function frontend( int $post_id ): array {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return array();
		}

		try {
			$surface = YoastSEO()->meta;

			if ( ! is_object( $surface ) || ! is_callable( array( $surface, 'for_post' ) ) ) {
				return array();
			}

			$meta = $surface->for_post( $post_id );

			if ( ! is_object( $meta ) ) {
				return array();
			}

			$robots = $meta->robots;

			return array(
				'frontend_title'       => is_scalar( $meta->title ) ? (string) $meta->title : '',
				'frontend_description' => is_scalar( $meta->description ) ? (string) $meta->description : '',
				'frontend_canonical'   => is_scalar( $meta->canonical ) ? (string) $meta->canonical : '',
				'frontend_robots'      => is_array( $robots ) ? array_values( array_map( 'strval', array_filter( $robots ) ) ) : array(),
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}
	}

	/**
	 * Fetches Yoast's indexable for a post, if its indexables are available.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $raw     Return the indexable object rather than a summary.
	 *
	 * @return mixed Summary array, the object, or null.
	 */
	private static function indexable( int $post_id, bool $raw = false ) {
		$repository = self::service( '\Yoast\WP\SEO\Repositories\Indexable_Repository' );

		if ( null === $repository || ! is_callable( array( $repository, 'find_by_id_and_type' ) ) ) {
			return null;
		}

		try {
			$indexable = $repository->find_by_id_and_type( $post_id, 'post', false );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}

		if ( ! is_object( $indexable ) ) {
			return null;
		}

		if ( $raw ) {
			return $indexable;
		}

		$summary = array();

		foreach ( array( 'title', 'description', 'canonical', 'breadcrumb_title', 'open_graph_title', 'open_graph_description', 'open_graph_image', 'twitter_title', 'twitter_description', 'twitter_image', 'primary_focus_keyword', 'is_robots_noindex', 'is_robots_nofollow', 'is_cornerstone', 'updated_at' ) as $column ) {
			$value = $indexable->{$column} ?? null;

			$summary[ $column ] = is_scalar( $value ) || null === $value ? $value : null;
		}

		return $summary;
	}

	/**
	 * Gets a service from Yoast's container.
	 *
	 * @param string $class Class name.
	 *
	 * @return object|null The service.
	 */
	private static function service( string $class ) {
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( $class ) ) {
			return null;
		}

		try {
			$classes = YoastSEO()->classes;

			if ( ! is_object( $classes ) || ! is_callable( array( $classes, 'get' ) ) ) {
				return null;
			}

			$service = $classes->get( ltrim( $class, '\\' ) );

			return is_object( $service ) ? $service : null;
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Rebuilds a post's indexable so Yoast's front end prints the new values.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when Yoast rebuilt it.
	 */
	private static function rebuild_indexable( int $post_id ): bool {
		$builder = self::service( '\Yoast\WP\SEO\Builders\Indexable_Builder' );

		if ( null === $builder || ! is_callable( array( $builder, 'build_for_id_and_type' ) ) ) {
			return false;
		}

		try {
			$existing = self::indexable( $post_id, true );
			$built    = $builder->build_for_id_and_type( $post_id, 'post', is_object( $existing ) ? $existing : false );

			return false !== $built && null !== $built;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Stores or deletes one Yoast meta value.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $key     Meta key without the prefix.
	 * @param string|null $value   Value, or null to delete.
	 *
	 * @return void
	 */
	private static function set_meta( int $post_id, string $key, ?string $value ): void {
		if ( null === $value ) {
			delete_post_meta( $post_id, self::META_PREFIX . $key );
			return;
		}

		update_post_meta( $post_id, self::META_PREFIX . $key, wp_slash( $value ) );
	}

	/**
	 * Writes Yoast SEO fields onto a post.
	 *
	 * Only fields present in `$changes` are touched. An empty string, empty
	 * array or null deletes the key, which hands the field back to Yoast's
	 * default: the page's own URL for canonical, the post type robots, and the
	 * Search Appearance title and description templates.
	 *
	 * @param \WP_Post             $post    The post.
	 * @param array<string, mixed> $changes Field values keyed by input name.
	 * @param bool                 $dry_run Validate and report without saving.
	 *
	 * @return array{updated: string[], deleted: string[], errors: array<string, string>, indexable_rebuilt?: bool} Result summary.
	 */
	public static function write_post( WP_Post $post, array $changes, bool $dry_run = false ): array {
		$result = array(
			'updated' => array(),
			'deleted' => array(),
			'errors'  => array(),
		);

		$writes = array();

		foreach ( $changes as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( self::POST_FIELDS[ $name ] ) ) {
				continue;
			}

			list( $key, $kind ) = self::POST_FIELDS[ $name ];

			$clean = self::clean_post_value( $kind, $value );

			if ( is_wp_error( $clean ) ) {
				$result['errors'][ $name ] = $clean->get_error_message();
				continue;
			}

			if ( 'robots' === $kind ) {
				$writes = array_merge( $writes, self::robots_writes( $clean ) );
			} elseif ( 'image' === $kind ) {
				$writes[ $key ] = $clean;

				// Yoast keeps the attachment ID beside the URL; a stale ID would win.
				$attachment         = null === $clean ? 0 : (int) attachment_url_to_postid( (string) $clean );
				$writes[ $key . '-id' ] = $attachment > 0 ? (string) $attachment : null;
			} else {
				$writes[ $key ] = $clean;
			}

			if ( null === $clean ) {
				$result['deleted'][] = $name;
			} else {
				$result['updated'][] = $name;
			}
		}

		if ( $dry_run || ! empty( $result['errors'] ) || empty( $writes ) ) {
			return $result;
		}

		foreach ( $writes as $key => $value ) {
			self::set_meta( $post->ID, $key, $value );
		}

		clean_post_cache( $post->ID );

		$result['indexable_rebuilt'] = self::rebuild_indexable( $post->ID );

		/** This action is documented in includes/Abilities/Support/RankMathSupport.php */
		do_action( 'mcp_adapter_seo_post_updated', $post->ID, $result );

		return $result;
	}

	/**
	 * Translates a robots list into Yoast's three robots meta keys.
	 *
	 * @param string[]|null $robots Directives, or null to clear them all.
	 *
	 * @return array<string, string|null> Meta values keyed by key without prefix; null deletes.
	 */
	private static function robots_writes( ?array $robots ): array {
		if ( null === $robots ) {
			return array(
				'meta-robots-noindex'  => null,
				'meta-robots-nofollow' => null,
				'meta-robots-adv'      => null,
			);
		}

		$noindex = null;

		if ( in_array( 'noindex', $robots, true ) ) {
			$noindex = '1';
		} elseif ( in_array( 'index', $robots, true ) ) {
			$noindex = '2';
		}

		$advanced = array_values( array_intersect( self::ADVANCED_ROBOTS, $robots ) );

		return array(
			'meta-robots-noindex'  => $noindex,
			'meta-robots-nofollow' => in_array( 'nofollow', $robots, true ) ? '1' : null,
			'meta-robots-adv'      => empty( $advanced ) ? null : implode( ',', $advanced ),
		);
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

				// Not sanitize_text_field(): it would mangle Yoast variables such as %%title%%.
				$clean = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $value ) ) );

				return '' === $clean ? null : $clean;

			case 'url':
			case 'image':
				if ( ! is_string( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a URL string.' );
				}

				return ContentSupport::clean_absolute_url( $value );

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

			case 'toggle':
				return ContentSupport::to_bool( $value, false ) ? '1' : null;
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
				'description' => 'SEO title. Variables such as %title% / %%title%%, %sep% and %sitename% are kept. Empty or null clears it, so the post type title template applies.',
			),
			'description'          => array(
				'type'        => $nullable_string,
				'description' => 'Meta description. Empty or null clears it, so the post type description template applies.',
			),
			'focus_keyword'        => array(
				'type'        => $nullable_string,
				'description' => 'Focus keyword(s).',
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
				'description' => 'Robots directives stored on this post, e.g. ["noindex","follow"]. A value here overrides the post type default. An empty array or null clears it so the post type default applies.',
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
			'twitter_image'        => array(
				'type'        => $nullable_string,
				'description' => 'X (Twitter) card image URL.',
			),
			'pillar_content'       => array(
				'type'        => array( 'boolean', 'null' ),
				'description' => 'Mark as pillar (Rank Math) / cornerstone (Yoast) content.',
			),
			'schema_page_type'     => array(
				'type'        => $nullable_string,
				'description' => 'Schema page type, e.g. "WebPage", "AboutPage", "ContactPage", "FAQPage". Null clears it so the post type default applies.',
			),
			'schema_article_type'  => array(
				'type'        => $nullable_string,
				'description' => 'Schema article type, e.g. "Article", "BlogPosting", "NewsArticle", or "None". Null clears it.',
			),
		);
	}

	/**
	 * Builds the meta query for a seo-get-posts filter.
	 *
	 * @param string $filter Filter name.
	 *
	 * @return array<mixed> The meta query, or an empty array.
	 */
	public static function filter_meta_query( string $filter ): array {
		$prefix = self::META_PREFIX;

		switch ( $filter ) {
			case 'robots_override':
				return array(
					'relation' => 'OR',
					array(
						'key'     => $prefix . 'meta-robots-noindex',
						'value'   => array( '1', '2' ),
						'compare' => 'IN',
					),
					array(
						'key'   => $prefix . 'meta-robots-nofollow',
						'value' => '1',
					),
				);

			case 'noindex':
				return array(
					array(
						'key'   => $prefix . 'meta-robots-noindex',
						'value' => '1',
					),
				);

			case 'canonical_override':
				return array(
					array(
						'key'     => $prefix . 'canonical',
						'value'   => '',
						'compare' => '!=',
					),
				);

			case 'missing_title':
			case 'missing_description':
				$key = $prefix . ( 'missing_title' === $filter ? 'title' : 'metadesc' );

				return array(
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => $key,
						'value' => '',
					),
				);
		}

		return array();
	}

	/**
	 * Clears Yoast's sitemap cache so every sitemap is rebuilt on its next request.
	 *
	 * @param string[] $types Sitemap types to clear, e.g. "page", "offer"; empty for all.
	 *
	 * @return string[] What was cleared, for the response.
	 */
	public static function flush_sitemap_cache( array $types = array() ): array {
		$done = array();

		if ( is_callable( array( '\WPSEO_Sitemaps_Cache', 'clear' ) ) ) {
			try {
				\WPSEO_Sitemaps_Cache::clear( $types );
				$done[] = 'WPSEO_Sitemaps_Cache::clear';
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( empty( $done ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fallback when Yoast exposes no cache API.
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_yst_sm' ) . '%',
					$wpdb->esc_like( '_transient_timeout_yst_sm' ) . '%'
				)
			);

			$done[] = sprintf( 'deleted %d sitemap transients', $deleted );
		}

		$general = self::get_settings( 'general' );

		if ( array_key_exists( 'enable_xml_sitemap', $general ) && empty( $general['enable_xml_sitemap'] ) ) {
			$done[] = 'note: Yoast XML sitemaps are switched off (wpseo enable_xml_sitemap is false)';
		}

		/** This action is documented in includes/Abilities/Support/RankMathSupport.php */
		do_action( 'mcp_adapter_sitemap_flushed', $types );

		return $done;
	}

	/**
	 * Returns the URL of Yoast's sitemap index.
	 *
	 * @return string The index URL.
	 */
	public static function sitemap_index_url(): string {
		if ( is_callable( array( '\WPSEO_Sitemaps_Router', 'get_base_url' ) ) ) {
			try {
				return (string) \WPSEO_Sitemaps_Router::get_base_url( 'sitemap_index.xml' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return (string) home_url( '/sitemap_index.xml' );
	}
}
