<?php
/**
 * Polylang and Polylang Pro integration helpers.
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
 * Class - PolylangSupport
 *
 * Everything here goes through Polylang's documented `pll_*` API rather than its
 * internal classes, so the same code works on Polylang, Polylang Pro and
 * Polylang for WooCommerce. Pro-only behaviour is detected at runtime and the
 * abilities degrade to the free equivalent instead of failing.
 */
final class PolylangSupport {

	/**
	 * Meta keys never copied between translations.
	 */
	private const NEVER_COPIED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
	);

	/**
	 * Whether Polylang is active on this site.
	 *
	 * @return bool True when Polylang is loaded.
	 */
	public static function is_active(): bool {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_language' );
	}

	/**
	 * Whether Polylang Pro is active on this site.
	 *
	 * @return bool True when the Pro edition is loaded.
	 */
	public static function is_pro(): bool {
		if ( defined( 'POLYLANG_PRO' ) && constant( 'POLYLANG_PRO' ) ) {
			return true;
		}

		return class_exists( '\PLL_Pro' ) || class_exists( '\PLL_Admin_Pro' );
	}

	/**
	 * Returns the Polylang version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'POLYLANG_VERSION' ) ? (string) constant( 'POLYLANG_VERSION' ) : '';
	}

	/**
	 * Guard used by every Polylang ability.
	 *
	 * @return true|\WP_Error True when Polylang is usable, WP_Error otherwise.
	 */
	public static function require_active() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'polylang_not_active',
				'Polylang is not active on this site. Install and activate Polylang or Polylang Pro to use this ability.'
			);
		}

		return true;
	}

	/**
	 * Returns the configured languages.
	 *
	 * @return array<int, array<string, mixed>> Language descriptors.
	 */
	public static function languages(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$default   = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$languages = pll_languages_list(
			array(
				'hide_empty' => false,
				'fields'     => '',
			)
		);

		if ( ! is_array( $languages ) ) {
			return array();
		}

		$result = array();

		foreach ( $languages as $language ) {
			// `fields => ''` yields PLL_Language objects, but older versions and
			// some configurations return plain slugs.
			if ( is_string( $language ) ) {
				$result[] = array(
					'slug'       => $language,
					'name'       => $language,
					'locale'     => '',
					'is_default' => $language === $default,
					'is_rtl'     => false,
					'flag_url'   => '',
					'post_count' => null,
				);

				continue;
			}

			if ( ! is_object( $language ) ) {
				continue;
			}

			$slug = (string) ( $language->slug ?? '' );

			$result[] = array(
				'slug'       => $slug,
				'name'       => (string) ( $language->name ?? $slug ),
				'locale'     => (string) ( $language->locale ?? '' ),
				'is_default' => $slug === $default,
				'is_rtl'     => (bool) ( $language->is_rtl ?? false ),
				'flag_url'   => (string) ( $language->flag_url ?? '' ),
				'post_count' => isset( $language->count ) ? (int) $language->count : null,
			);
		}

		return $result;
	}

	/**
	 * Returns the list of configured language slugs.
	 *
	 * @return string[] Language slugs.
	 */
	public static function language_slugs(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$slugs = pll_languages_list( array( 'hide_empty' => false ) );

		return is_array( $slugs ) ? array_values( array_map( 'strval', $slugs ) ) : array();
	}

	/**
	 * Validates a language slug against the configured languages.
	 *
	 * @param string $language Language slug.
	 *
	 * @return true|\WP_Error True when the language exists, WP_Error otherwise.
	 */
	public static function validate_language( string $language ) {
		$slugs = self::language_slugs();

		if ( in_array( $language, $slugs, true ) ) {
			return true;
		}

		return new WP_Error(
			'invalid_language',
			sprintf(
				'Language "%s" is not configured. Available languages: %s.',
				$language,
				$slugs ? implode( ', ', $slugs ) : 'none'
			)
		);
	}

	/**
	 * Returns the language assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, string>|null Language descriptor, or null when unassigned.
	 */
	public static function get_post_language( int $post_id ): ?array {
		if ( ! self::is_active() ) {
			return null;
		}

		$slug = pll_get_post_language( $post_id, 'slug' );

		if ( ! $slug ) {
			return null;
		}

		return array(
			'slug'   => (string) $slug,
			'name'   => (string) pll_get_post_language( $post_id, 'name' ),
			'locale' => (string) pll_get_post_language( $post_id, 'locale' ),
		);
	}

	/**
	 * Returns every translation of a post, including the post itself.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, array<string, mixed>> Translations keyed by language slug.
	 */
	public static function get_post_translations( int $post_id ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$translations = pll_get_post_translations( $post_id );

		if ( ! is_array( $translations ) ) {
			return array();
		}

		$result = array();

		foreach ( $translations as $language => $translated_id ) {
			$translated_id = (int) $translated_id;
			$translated    = get_post( $translated_id );

			if ( ! $translated instanceof WP_Post ) {
				continue;
			}

			$result[ (string) $language ] = array(
				'id'        => $translated_id,
				'title'     => $translated->post_title,
				'slug'      => $translated->post_name,
				'status'    => $translated->post_status,
				'link'      => (string) get_permalink( $translated ),
				'is_source' => $translated_id === $post_id,
			);
		}

		return $result;
	}

	/**
	 * Assigns a language to a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language slug.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function set_post_language( int $post_id, string $language ) {
		$active = self::require_active();

		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$valid = self::validate_language( $language );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		pll_set_post_language( $post_id, $language );

		return true;
	}

	/**
	 * Links a set of posts together as translations of each other.
	 *
	 * @param array<string, int> $translations Post IDs keyed by language slug.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function save_post_translations( array $translations ) {
		$active = self::require_active();

		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$clean = array();

		foreach ( $translations as $language => $post_id ) {
			$language = (string) $language;
			$valid    = self::validate_language( $language );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$post_id = (int) $post_id;
			$post    = ContentSupport::require_can_edit( $post_id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			// Polylang requires each post to already carry its language.
			$current = pll_get_post_language( $post_id, 'slug' );

			if ( $current !== $language ) {
				pll_set_post_language( $post_id, $language );
			}

			$clean[ $language ] = $post_id;
		}

		if ( count( $clean ) < 2 ) {
			return new WP_Error(
				'insufficient_translations',
				'At least two posts in different languages are required to link translations.'
			);
		}

		pll_save_post_translations( $clean );

		return true;
	}

	/**
	 * Whether a post type is translatable in the current Polylang configuration.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool True when translatable.
	 */
	public static function is_translated_post_type( string $post_type ): bool {
		if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
			return false;
		}

		return (bool) pll_is_translated_post_type( $post_type );
	}

	/**
	 * Whether a taxonomy is translatable in the current Polylang configuration.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return bool True when translatable.
	 */
	public static function is_translated_taxonomy( string $taxonomy ): bool {
		if ( ! function_exists( 'pll_is_translated_taxonomy' ) ) {
			return false;
		}

		return (bool) pll_is_translated_taxonomy( $taxonomy );
	}

	/**
	 * Returns the translation of a post in a given language.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language slug.
	 *
	 * @return int The translated post ID, or 0 when there is none.
	 */
	public static function get_translated_post( int $post_id, string $language ): int {
		if ( ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}

		$translated = pll_get_post( $post_id, $language );

		return $translated ? (int) $translated : 0;
	}

	/**
	 * Returns the translation of a term in a given language.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $language Language slug.
	 *
	 * @return int The translated term ID, or 0 when there is none.
	 */
	public static function get_translated_term( int $term_id, string $language ): int {
		if ( ! function_exists( 'pll_get_term' ) ) {
			return 0;
		}

		$translated = pll_get_term( $term_id, $language );

		return $translated ? (int) $translated : 0;
	}

	/**
	 * Copies the taxonomy assignments of a post onto a translation.
	 *
	 * Terms in translatable taxonomies are mapped to their counterpart in the
	 * target language; terms that have no translation are skipped rather than
	 * assigned in the wrong language.
	 *
	 * @param \WP_Post $source   Source post.
	 * @param int      $target_id Target post ID.
	 * @param string   $language  Target language slug.
	 *
	 * @return array{assigned: array<string, int>, skipped: array<string, int>} Counts per taxonomy.
	 */
	public static function copy_taxonomies( WP_Post $source, int $target_id, string $language ): array {
		$assigned = array();
		$skipped  = array();

		foreach ( get_object_taxonomies( $source->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public && ! $taxonomy->show_in_rest ) {
				continue;
			}

			$terms = get_the_terms( $source, $taxonomy->name );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$translatable = self::is_translated_taxonomy( $taxonomy->name );
			$target_terms = array();

			foreach ( $terms as $term ) {
				if ( ! $translatable ) {
					$target_terms[] = (int) $term->term_id;
					continue;
				}

				$translated = self::get_translated_term( (int) $term->term_id, $language );

				if ( $translated > 0 ) {
					$target_terms[] = $translated;
					continue;
				}

				$skipped[ $taxonomy->name ] = ( $skipped[ $taxonomy->name ] ?? 0 ) + 1;
			}

			if ( empty( $target_terms ) ) {
				continue;
			}

			wp_set_object_terms( $target_id, $target_terms, $taxonomy->name, false );
			$assigned[ $taxonomy->name ] = count( $target_terms );
		}

		return array(
			'assigned' => $assigned,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Copies post meta from a source post onto a translation.
	 *
	 * The key list is passed through Polylang's `pll_copy_post_metas` filter, so
	 * Pro's synchronisation settings and third-party integrations get the same
	 * say they have when Polylang copies metas itself.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $language  Target language slug.
	 * @param bool   $sync      Whether this is an ongoing sync rather than a first copy.
	 *
	 * @return string[] The meta keys that were copied.
	 */
	public static function copy_metas( int $source_id, int $target_id, string $language, bool $sync = false ): array {
		$all_meta = get_post_meta( $source_id );

		if ( ! is_array( $all_meta ) ) {
			return array();
		}

		$keys = array();

		foreach ( array_keys( $all_meta ) as $key ) {
			$key = (string) $key;

			if ( in_array( $key, self::NEVER_COPIED_META, true ) ) {
				continue;
			}

			// Elementor documents are copied by ElementorSupport, which also
			// rebuilds the CSS and regenerates element ids.
			if ( 0 === strpos( $key, '_elementor' ) ) {
				continue;
			}

			$keys[] = $key;
		}

		/**
		 * Filters the post meta keys copied to a translation.
		 *
		 * This is Polylang's own filter, reused here so a site's existing
		 * synchronisation rules apply to MCP-driven translations too.
		 *
		 * @param string[] $keys      Meta keys to copy.
		 * @param bool     $sync      Whether this is a sync rather than a first copy.
		 * @param int      $source_id Source post ID.
		 * @param int      $target_id Target post ID.
		 * @param string   $language  Target language slug.
		 */
		$keys = (array) apply_filters( 'pll_copy_post_metas', $keys, $sync, $source_id, $target_id, $language );

		$copied = array();

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( ! isset( $all_meta[ $key ] ) ) {
				continue;
			}

			delete_post_meta( $target_id, $key );

			foreach ( (array) $all_meta[ $key ] as $value ) {
				$value = is_string( $value ) ? maybe_unserialize( $value ) : $value;

				// The featured image and other attachment references follow the
				// media translation when Polylang has one.
				if ( '_thumbnail_id' === $key ) {
					$translated_media = self::get_translated_post( (int) $value, $language );
					$value            = $translated_media > 0 ? $translated_media : $value;
				}

				add_post_meta( $target_id, $key, wp_slash( $value ) );
			}

			$copied[] = $key;
		}

		return $copied;
	}

	/**
	 * Reads Polylang's synchronisation settings.
	 *
	 * @return string[] The enabled sync options, e.g. `post_meta`, `taxonomies`.
	 */
	public static function sync_settings(): array {
		if ( ! function_exists( 'PLL' ) ) {
			return array();
		}

		$polylang = PLL();

		if ( ! is_object( $polylang ) || ! isset( $polylang->options['sync'] ) ) {
			return array();
		}

		$sync = $polylang->options['sync'];

		return is_array( $sync ) ? array_values( array_map( 'strval', $sync ) ) : array();
	}
}
