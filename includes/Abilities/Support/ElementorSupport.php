<?php
/**
 * Elementor integration helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - ElementorSupport
 *
 * Elementor stores a page as a JSON tree in the `_elementor_data` post meta.
 * Writing that meta by hand is only half the job: the edit mode, version and
 * template type metas have to agree with it, and the generated CSS file has to
 * be rebuilt or the front end keeps rendering the previous layout. This class
 * routes through Elementor's own document API when it is available, so those
 * side effects are handled by Elementor itself, and falls back to a careful
 * manual write when it is not.
 */
final class ElementorSupport {

	/**
	 * Element types Elementor understands as containers of other elements.
	 */
	private const CONTAINER_TYPES = array( 'section', 'column', 'container' );

	/**
	 * Upper bound on the number of nodes accepted in a single write.
	 */
	private const MAX_NODES = 5000;

	/**
	 * Settings keys commonly holding the visible text of a widget.
	 */
	private const TEXT_KEYS = array( 'title', 'editor', 'text', 'heading', 'description', 'caption', 'html', 'button_text', 'tab_title', 'testimonial_content' );

	/**
	 * Whether Elementor is active on this site.
	 *
	 * @return bool True when Elementor is loaded.
	 */
	public static function is_active(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) > 0 || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Whether Elementor Pro is active on this site.
	 *
	 * @return bool True when Elementor Pro is loaded.
	 */
	public static function is_pro_active(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Plugin' );
	}

	/**
	 * Returns the Elementor version string, if known.
	 *
	 * @return string Version, or an empty string.
	 */
	public static function version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';
	}

	/**
	 * Returns the post types Elementor is enabled for.
	 *
	 * @return string[] Post type slugs.
	 */
	public static function supported_post_types(): array {
		$types = get_option( 'elementor_cpt_support', array( 'page', 'post' ) );

		return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array( 'page', 'post' );
	}

	/**
	 * Guard used by every Elementor ability.
	 *
	 * @return true|\WP_Error True when Elementor is usable, WP_Error otherwise.
	 */
	public static function require_active() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				'Elementor is not active on this site. Install and activate Elementor to use this ability.'
			);
		}

		return true;
	}

	/**
	 * Whether a post is built with the Elementor editor.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True when the post uses the Elementor builder.
	 */
	public static function is_built_with_elementor( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Reads the Elementor element tree for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<int, mixed>|\WP_Error The element tree, or WP_Error when the stored JSON is unreadable.
	 */
	public static function get_elements( int $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( '' === $raw || null === $raw ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'elementor_data_corrupt',
				sprintf( 'The _elementor_data meta on post %d is not valid JSON: %s', $post_id, json_last_error_msg() )
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persists an element tree onto a post.
	 *
	 * Prefers Elementor's document API, which regenerates CSS, stores a revision
	 * and keeps the companion metas in sync. Falls back to a manual write that
	 * reproduces those side effects when the document API is unavailable.
	 *
	 * @param int               $post_id  Post ID.
	 * @param array<int, mixed> $elements Element tree.
	 *
	 * @return true|\WP_Error True on success, WP_Error otherwise.
	 */
	public static function save_elements( int $post_id, array $elements ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'post_not_found', sprintf( 'No post found with ID %d.', $post_id ) );
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( $document ) {
				try {
					$document->save( array( 'elements' => $elements ) );

					// Elementor's own save does not reliably set the edit mode on a
					// post that was not previously built with Elementor — a page
					// created empty by a translation plugin, for instance. Without
					// `_elementor_edit_mode`, the data is stored but the front end
					// silently renders nothing, so assert the companion metas here
					// rather than trusting the document to have done it.
					self::ensure_builder_meta( $post_id, $post->post_type );

					return true;
				} catch ( \Throwable $e ) {
					// Fall through to the manual write below.
					return self::save_elements_manually( $post_id, $elements, $post->post_type, $e->getMessage() );
				}
			}
		}

		return self::save_elements_manually( $post_id, $elements, $post->post_type );
	}

	/**
	 * Ensures the metas Elementor needs to treat a post as one of its documents.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 *
	 * @return void
	 */
	private static function ensure_builder_meta( int $post_id, string $post_type ): void {
		if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		}

		if ( '' !== self::version() && '' === (string) get_post_meta( $post_id, '_elementor_version', true ) ) {
			update_post_meta( $post_id, '_elementor_version', self::version() );
		}

		if ( '' === (string) get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			update_post_meta( $post_id, '_elementor_template_type', 'wp-' . $post_type );
		}
	}

	/**
	 * Writes the Elementor metas directly and rebuilds the CSS.
	 *
	 * @param int               $post_id     Post ID.
	 * @param array<int, mixed> $elements    Element tree.
	 * @param string            $post_type   Post type slug.
	 * @param string            $fallback_of Optional message describing why the document API was skipped.
	 *
	 * @return true|\WP_Error True on success, WP_Error when encoding fails.
	 */
	private static function save_elements_manually( int $post_id, array $elements, string $post_type, string $fallback_of = '' ) {
		$json = wp_json_encode( $elements );

		if ( false === $json ) {
			return new WP_Error(
				'elementor_encode_failed',
				'Could not encode the element tree as JSON.' . ( '' !== $fallback_of ? ' Document API error: ' . $fallback_of : '' )
			);
		}

		// Elementor stores the JSON slashed; update_post_meta expects slashed data.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( '' !== self::version() ) {
			update_post_meta( $post_id, '_elementor_version', self::version() );
		}

		if ( '' === (string) get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			update_post_meta( $post_id, '_elementor_template_type', 'wp-' . $post_type );
		}

		self::regenerate_css( $post_id );

		return true;
	}

	/**
	 * Rebuilds the generated CSS file for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function regenerate_css( int $post_id ): void {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}

		try {
			$css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
			$css_file->delete();
			$css_file->update();
		} catch ( \Throwable $e ) {
			// A stale CSS file is recoverable; a fatal here is not worth it.
			unset( $e );
		}
	}

	/**
	 * Flattens the element tree into an addressable list.
	 *
	 * Nested JSON is hard for a client to reason about; a flat list keyed by the
	 * element id is what makes targeted edits practical.
	 *
	 * @param array<int, mixed> $elements Element tree.
	 * @param array             $args {
	 *     Optional. Shaping options.
	 *
	 *     @type bool   $include_settings Include the full settings array per node. Default false.
	 *     @type int    $max_depth        Maximum depth to descend, 0 for unlimited. Default 0.
	 *     @type string $search           Case-insensitive text filter applied to the preview. Default ''.
	 * }
	 *
	 * @return array<int, array<string, mixed>> Flat list of nodes.
	 */
	public static function flatten( array $elements, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'include_settings' => false,
				'max_depth'        => 0,
				'search'           => '',
			)
		);

		$flat = array();
		self::walk( $elements, $flat, $args, 0, '', array() );

		if ( '' === (string) $args['search'] ) {
			return $flat;
		}

		$needle = strtolower( (string) $args['search'] );

		return array_values(
			array_filter(
				$flat,
				static fn( array $node ): bool => false !== strpos( strtolower( (string) $node['preview'] ), $needle )
					|| false !== strpos( strtolower( (string) $node['type'] ), $needle )
			)
		);
	}

	/**
	 * Recursive worker for {@see self::flatten()}.
	 *
	 * @param array<int, mixed>                 $elements  Elements at the current level.
	 * @param array<int, array<string, mixed>>  $flat      Accumulator, passed by reference.
	 * @param array<string, mixed>              $args      Shaping options.
	 * @param int                               $depth     Current depth.
	 * @param string                            $parent_id Parent element id.
	 * @param array<int, int>                   $path      Index path from the root.
	 *
	 * @return void
	 */
	private static function walk( array $elements, array &$flat, array $args, int $depth, string $parent_id, array $path ): void {
		$max_depth = (int) $args['max_depth'];

		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$current_path = array_merge( $path, array( $index ) );
			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$el_type      = (string) ( $element['elType'] ?? 'unknown' );
			$widget_type  = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

			$flat[] = array(
				'id'          => (string) ( $element['id'] ?? '' ),
				'el_type'     => $el_type,
				'widget_type' => $widget_type,
				'type'        => '' !== $widget_type ? $widget_type : $el_type,
				'depth'       => $depth,
				'parent_id'   => $parent_id,
				'path'        => implode( '.', $current_path ),
				'child_count' => isset( $element['elements'] ) && is_array( $element['elements'] ) ? count( $element['elements'] ) : 0,
				'preview'     => self::preview_text( $settings ),
				'setting_keys' => array_keys( $settings ),
				'settings'    => $args['include_settings'] ? $settings : null,
			);

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

			if ( empty( $children ) ) {
				continue;
			}

			if ( $max_depth > 0 && $depth + 1 >= $max_depth ) {
				continue;
			}

			self::walk( $children, $flat, $args, $depth + 1, (string) ( $element['id'] ?? '' ), $current_path );
		}
	}

	/**
	 * Builds a short human-readable preview of a widget's content.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 *
	 * @return string Trimmed preview text.
	 */
	private static function preview_text( array $settings ): string {
		foreach ( self::TEXT_KEYS as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
				continue;
			}

			$text = trim( wp_strip_all_tags( $settings[ $key ] ) );

			if ( '' !== $text ) {
				return mb_substr( $text, 0, 160 );
			}
		}

		return '';
	}

	/**
	 * Applies an operation to a single element, located by id.
	 *
	 * @param array<int, mixed>    $elements   Element tree, modified in place by the recursion.
	 * @param string               $element_id Target element id.
	 * @param array<string, mixed> $settings   Settings to apply. Ignored for the delete operation.
	 * @param string               $operation  One of `merge`, `replace` or `delete`.
	 *
	 * @return array{tree: array<int, mixed>, found: bool} The updated tree and whether the target was located.
	 */
	public static function apply_to_element( array $elements, string $element_id, array $settings, string $operation ): array {
		$found = false;
		$tree  = self::apply_recursive( $elements, $element_id, $settings, $operation, $found );

		return array(
			'tree'  => $tree,
			'found' => $found,
		);
	}

	/**
	 * Recursive worker for {@see self::apply_to_element()}.
	 *
	 * @param array<int, mixed>    $elements   Elements at the current level.
	 * @param string               $element_id Target element id.
	 * @param array<string, mixed> $settings   Settings to apply.
	 * @param string               $operation  Operation name.
	 * @param bool                 $found      Whether the target has been located, passed by reference.
	 *
	 * @return array<int, mixed> The rebuilt level.
	 */
	private static function apply_recursive( array $elements, string $element_id, array $settings, string $operation, bool &$found ): array {
		$result = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				$result[] = $element;
				continue;
			}

			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				$found = true;

				if ( 'delete' === $operation ) {
					continue;
				}

				$current = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				$element['settings'] = 'replace' === $operation ? $settings : array_merge( $current, $settings );

				$result[] = $element;
				continue;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::apply_recursive( $element['elements'], $element_id, $settings, $operation, $found );
			}

			$result[] = $element;
		}

		return array_values( $result );
	}

	/**
	 * Validates and normalizes an element tree supplied by a client.
	 *
	 * Missing ids are generated, `settings` and `elements` are coerced to arrays,
	 * and the tree is rejected when it is not shaped like Elementor data at all.
	 *
	 * @param mixed $elements Raw element tree, decoded from JSON.
	 *
	 * @return array<int, mixed>|\WP_Error The normalized tree, or WP_Error when invalid.
	 */
	public static function normalize_tree( $elements ) {
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'invalid_elementor_data', 'Elementor data must be an array of top-level elements.' );
		}

		$count  = 0;
		$result = self::normalize_level( $elements, $count, 0 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Recursive worker for {@see self::normalize_tree()}.
	 *
	 * @param array<int, mixed> $elements Elements at the current level.
	 * @param int               $count    Running node count, passed by reference.
	 * @param int               $depth    Current depth.
	 *
	 * @return array<int, mixed>|\WP_Error The normalized level, or WP_Error when invalid.
	 */
	private static function normalize_level( array $elements, int &$count, int $depth ) {
		if ( $depth > 20 ) {
			return new WP_Error( 'elementor_data_too_deep', 'Elementor data is nested more than 20 levels deep.' );
		}

		$result = array();

		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				return new WP_Error(
					'invalid_elementor_element',
					sprintf( 'Element at depth %d, index %d is not an object.', $depth, $index )
				);
			}

			++$count;

			if ( $count > self::MAX_NODES ) {
				return new WP_Error(
					'elementor_data_too_large',
					sprintf( 'Elementor data exceeds the %d element limit.', self::MAX_NODES )
				);
			}

			$el_type = isset( $element['elType'] ) ? (string) $element['elType'] : '';

			if ( '' === $el_type ) {
				return new WP_Error(
					'invalid_elementor_element',
					sprintf( 'Element at depth %d, index %d is missing the required "elType" property.', $depth, $index )
				);
			}

			if ( 'widget' === $el_type && empty( $element['widgetType'] ) ) {
				return new WP_Error(
					'invalid_elementor_element',
					sprintf( 'Widget at depth %d, index %d is missing the required "widgetType" property.', $depth, $index )
				);
			}

			$normalized = array(
				'id'       => ! empty( $element['id'] ) ? (string) $element['id'] : self::generate_id(),
				'elType'   => $el_type,
				'settings' => isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(),
				'elements' => array(),
			);

			if ( isset( $element['widgetType'] ) ) {
				$normalized['widgetType'] = (string) $element['widgetType'];
			}

			if ( isset( $element['isInner'] ) ) {
				$normalized['isInner'] = (bool) $element['isInner'];
			}

			// Preserve any other keys Elementor or a third-party addon may rely on.
			foreach ( $element as $key => $value ) {
				if ( in_array( $key, array( 'id', 'elType', 'settings', 'elements', 'widgetType', 'isInner' ), true ) ) {
					continue;
				}

				$normalized[ $key ] = $value;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$children = self::normalize_level( $element['elements'], $count, $depth + 1 );

				if ( is_wp_error( $children ) ) {
					return $children;
				}

				$normalized['elements'] = $children;
			}

			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * Generates an element id in Elementor's format.
	 *
	 * @return string A seven character hexadecimal id.
	 */
	public static function generate_id(): string {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	}

	/**
	 * Whether an element type can contain children.
	 *
	 * @param string $el_type Element type.
	 *
	 * @return bool True when the type is a container.
	 */
	public static function is_container( string $el_type ): bool {
		return in_array( $el_type, self::CONTAINER_TYPES, true );
	}

	/**
	 * Copies the Elementor state of one post onto another.
	 *
	 * Used when a translation or duplicate needs to start from the same layout.
	 * Element ids are regenerated so the two documents do not collide in
	 * Elementor's own caches.
	 *
	 * @param int  $source_id      Source post ID.
	 * @param int  $target_id      Target post ID.
	 * @param bool $regenerate_ids Whether to assign fresh element ids. Default true.
	 *
	 * @return bool|\WP_Error True when data was copied, false when the source has none, WP_Error on failure.
	 */
	public static function copy_document( int $source_id, int $target_id, bool $regenerate_ids = true ) {
		$elements = self::get_elements( $source_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		if ( empty( $elements ) ) {
			return false;
		}

		if ( $regenerate_ids ) {
			$elements = self::regenerate_ids( $elements );
		}

		$target_post = get_post( $target_id );

		if ( ! $target_post ) {
			return new WP_Error( 'post_not_found', sprintf( 'No post found with ID %d.', $target_id ) );
		}

		$template_type = (string) get_post_meta( $source_id, '_elementor_template_type', true );
		$page_settings = get_post_meta( $source_id, '_elementor_page_settings', true );

		$saved = self::save_elements( $target_id, $elements );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// These have to be written after the elements, not before. Elementor's
		// document API rewrites `_elementor_template_type` from the document
		// instance it just saved, and Elementor Pro's theme documents drop any
		// conditions that were not part of the save payload. Setting them first
		// means a copied header or footer is stored as a plain document with no
		// conditions, which Elementor then never renders — while the language
		// it belongs to stops falling back to the source template, so the front
		// end loses its header entirely.
		if ( '' !== $template_type ) {
			update_post_meta( $target_id, '_elementor_template_type', $template_type );
		}

		if ( ! empty( $page_settings ) ) {
			update_post_meta( $target_id, '_elementor_page_settings', wp_slash( $page_settings ) );
		}

		self::copy_display_conditions( $source_id, $target_id );

		return $saved;
	}

	/**
	 * Copies Elementor Pro's Theme Builder display conditions to a copy.
	 *
	 * A translated header, footer or loop item is inert without these: Elementor
	 * decides which template to render for a request purely from the conditions,
	 * so a translation that has none is never served and the source template
	 * keeps rendering in its place, in the wrong language.
	 *
	 * Only copied when the target has none of its own, so a deliberately narrowed
	 * condition on a translation is never overwritten.
	 *
	 * @param int $source_id Source template ID.
	 * @param int $target_id Target template ID.
	 *
	 * @return bool True when conditions were copied.
	 */
	public static function copy_display_conditions( int $source_id, int $target_id ): bool {
		$conditions = get_post_meta( $source_id, '_elementor_conditions', true );

		if ( empty( $conditions ) ) {
			return false;
		}

		if ( ! empty( get_post_meta( $target_id, '_elementor_conditions', true ) ) ) {
			return false;
		}

		update_post_meta( $target_id, '_elementor_conditions', wp_slash( $conditions ) );

		// Elementor caches the condition-to-template map in an option.
		if ( class_exists( '\Elementor\Core\Base\Document' ) ) {
			delete_option( 'elementor_pro_theme_builder_conditions' );
		}

		return true;
	}

	/**
	 * Assigns a fresh id to every element in a tree.
	 *
	 * @param array<int, mixed> $elements Element tree.
	 *
	 * @return array<int, mixed> The tree with regenerated ids.
	 */
	public static function regenerate_ids( array $elements ): array {
		$result = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				$result[] = $element;
				continue;
			}

			$element['id'] = self::generate_id();

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::regenerate_ids( $element['elements'] );
			}

			$result[] = $element;
		}

		return $result;
	}
}
