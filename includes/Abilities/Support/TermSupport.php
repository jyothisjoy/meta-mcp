<?php
/**
 * Shared helpers for the taxonomy term abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - TermSupport
 *
 * Resolves taxonomies and terms, checks the taxonomy's own capabilities, and
 * formats a term for a client, with its Polylang language and translations
 * when the taxonomy is translatable.
 *
 * Internal taxonomies are refused outright: navigation menus have their own
 * tools, post formats are fixed by core, and Polylang keeps its languages and
 * translation groups in taxonomies that must never be edited as ordinary terms.
 */
final class TermSupport {

	/**
	 * Taxonomies that are never reachable through the term abilities.
	 */
	public const INTERNAL_TAXONOMIES = array(
		'nav_menu',
		'post_format',
		'link_category',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
		'language',
		'term_language',
		'post_translations',
		'term_translations',
		'elementor_library_type',
		'elementor_library_category',
		'elementor_font_type',
	);

	/**
	 * Validates a taxonomy slug.
	 *
	 * @param mixed $taxonomy Raw taxonomy slug.
	 *
	 * @return \WP_Taxonomy|\WP_Error The taxonomy object.
	 */
	public static function require_taxonomy( $taxonomy ) {
		$taxonomy = is_string( $taxonomy ) ? sanitize_key( $taxonomy ) : '';

		if ( '' === $taxonomy ) {
			return new WP_Error( 'missing_taxonomy', 'taxonomy is required, e.g. "category", "post_tag" or a custom taxonomy slug. list-post-types shows the taxonomies of each post type.' );
		}

		if ( in_array( $taxonomy, self::INTERNAL_TAXONOMIES, true ) ) {
			return new WP_Error(
				'taxonomy_not_allowed',
				sprintf( 'The "%s" taxonomy is internal and cannot be edited as ordinary terms. Use the menu or Polylang tools instead.', $taxonomy )
			);
		}

		$object = get_taxonomy( $taxonomy );

		if ( ! $object ) {
			return new WP_Error( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" is not registered on this site.', $taxonomy ) );
		}

		return $object;
	}

	/**
	 * Checks one of the taxonomy's own capabilities.
	 *
	 * @param \WP_Taxonomy $taxonomy The taxonomy.
	 * @param string       $cap_key  "manage_terms", "edit_terms", "delete_terms" or "assign_terms".
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_cap( \WP_Taxonomy $taxonomy, string $cap_key ) {
		$cap = $taxonomy->cap->{$cap_key} ?? 'manage_categories';

		if ( current_user_can( $cap ) ) {
			return true;
		}

		return new WP_Error(
			'insufficient_capability',
			sprintf( 'User lacks the "%s" capability needed to %s %s terms.', $cap, str_replace( '_terms', '', $cap_key ), $taxonomy->name )
		);
	}

	/**
	 * Checks that the current user may read terms of a taxonomy.
	 *
	 * Terms of a public taxonomy, or one with an admin screen, are readable by
	 * any user who may read; a hidden taxonomy's terms need manage_terms. Used by
	 * both list-terms and get-term so the two can never disagree.
	 *
	 * @param \WP_Taxonomy $taxonomy The taxonomy.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_can_view( \WP_Taxonomy $taxonomy ) {
		if ( $taxonomy->public || $taxonomy->show_ui ) {
			return true;
		}

		return self::require_cap( $taxonomy, 'manage_terms' );
	}

	/**
	 * Loads a term by ID, optionally checking it belongs to a taxonomy.
	 *
	 * @param mixed  $term_id  Term ID.
	 * @param string $taxonomy Expected taxonomy, or an empty string for any.
	 *
	 * @return \WP_Term|\WP_Error The term.
	 */
	public static function get_term( $term_id, string $taxonomy = '' ) {
		$term_id = (int) $term_id;

		if ( $term_id <= 0 ) {
			return new WP_Error( 'missing_term_id', 'term_id must be a positive integer.' );
		}

		$term = get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'term_not_found', sprintf( 'Term %d does not exist.', $term_id ) );
		}

		if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
			return new WP_Error( 'term_taxonomy_mismatch', sprintf( 'Term %d belongs to "%s", not "%s".', $term_id, $term->taxonomy, $taxonomy ) );
		}

		$valid = self::require_taxonomy( $term->taxonomy );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $term;
	}

	/**
	 * Returns the default term ID of a taxonomy, if it has one.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return int The default term ID, or 0.
	 */
	public static function default_term_id( string $taxonomy ): int {
		if ( 'category' === $taxonomy ) {
			return (int) get_option( 'default_category' );
		}

		$default = (int) get_option( 'default_term_' . $taxonomy );

		return $default > 0 ? $default : 0;
	}

	/**
	 * Formats a term for a client.
	 *
	 * @param \WP_Term $term The term.
	 *
	 * @return array<string, mixed> The term.
	 */
	public static function format_term( WP_Term $term, bool $compact = false ): array {
		$link = get_term_link( $term );

		$data = array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'link'        => is_wp_error( $link ) ? '' : (string) $link,
			'edit_link'   => (string) get_edit_term_link( $term->term_id, $term->taxonomy ),
		);

		if ( PolylangSupport::is_active() && PolylangSupport::is_translated_taxonomy( $term->taxonomy ) ) {
			$data['language']     = self::term_language( (int) $term->term_id );
			$data['translations'] = self::term_translations( (int) $term->term_id );

			// Lists give translations as {lang: term id}; get-term has the full entries.
			if ( $compact ) {
				$data['translations'] = array_map( static fn( array $t ): int => (int) $t['id'], $data['translations'] );
			}
		}

		if ( $compact ) {
			unset( $data['edit_link'] );
		}

		$default = self::default_term_id( $term->taxonomy );

		if ( $default > 0 ) {
			$data['is_default'] = $default === (int) $term->term_id;
		}

		return $data;
	}

	/**
	 * Returns the Polylang language of a term.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array{slug: string, name: string, locale: string}|null The language, or null.
	 */
	public static function term_language( int $term_id ): ?array {
		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return null;
		}

		$slug = pll_get_term_language( $term_id, 'slug' );

		if ( ! $slug ) {
			return null;
		}

		return array(
			'slug'   => (string) $slug,
			'name'   => (string) pll_get_term_language( $term_id, 'name' ),
			'locale' => (string) pll_get_term_language( $term_id, 'locale' ),
		);
	}

	/**
	 * Returns the translations of a term, keyed by language slug.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array<string, array{id: int, name: string, slug: string}> The translations.
	 */
	public static function term_translations( int $term_id ): array {
		if ( ! function_exists( 'pll_get_term_translations' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) pll_get_term_translations( $term_id ) as $language => $translated_id ) {
			$translated = get_term( (int) $translated_id );

			if ( $translated instanceof WP_Term ) {
				$out[ (string) $language ] = array(
					'id'   => (int) $translated->term_id,
					'name' => $translated->name,
					'slug' => $translated->slug,
				);
			}
		}

		return $out;
	}

	/**
	 * Output schema for one formatted term.
	 *
	 * @return array<string, mixed> Schema.
	 */
	public static function term_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'language'     => array( 'type' => array( 'object', 'null' ) ),
				'translations' => array( 'type' => 'object' ),
			),
		);
	}
}
