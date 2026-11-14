<?php
/**
 * Ability for adding one element to an Elementor page.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Insert Elementor Element - Adds a widget (or a container with widgets) next to an existing element, or at the start
 * or end of the page, without rewriting the rest of the layout.
 *
 * Text that is missing from a page (an intro under the H1, a lead paragraph above a room's amenity list, a new
 * section on the home page) cannot be added with a find / replace: there is nothing to find. This adds it where it
 * belongs. A widget inserted at the top level of the page is wrapped the way the page is built: in a container on
 * flexbox-container pages, in a section and column on section pages. Undo with elementor-update-element, operation
 * "delete", on the returned element_id.
 */
final class InsertElementorElementAbility {

	/**
	 * Where the element goes relative to the anchor.
	 */
	private const POSITIONS = array( 'before', 'after', 'first_child', 'last_child', 'page_start', 'page_end' );

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-insert-element',
			array(
				'label'               => 'Insert Elementor Element',
				'description'         => 'Add one widget to an Elementor page, e.g. a text editor with an intro paragraph directly under the H1: {"post_id": 12, "anchor_id": "<id of the H1 heading>", "position": "after", "element": {"widgetType": "text-editor", "settings": {"editor": "<p>New intro.</p>"}}}. position: "before" / "after" the anchor element, "first_child" / "last_child" inside an anchor container, or "page_start" / "page_end" (no anchor; the widget is wrapped in a container or a section the way the page is built). Find anchor ids with elementor-get-structure or find-text. The rest of the layout is left exactly as it is. Returns the new element_id; undo with elementor-update-element, operation "delete".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => 'The Elementor page or post.',
						),
						'anchor_id' => array(
							'type'        => 'string',
							'description' => 'The element to insert next to (or into, for first_child / last_child). Not used with page_start / page_end.',
						),
						'position'  => array(
							'type'        => 'string',
							'enum'        => self::POSITIONS,
							'description' => 'Where the new element goes. Default "after".',
						),
						'element'   => array(
							'type'        => 'object',
							'description' => 'The new element: {"widgetType": "text-editor", "settings": {"editor": "<p>...</p>"}} for a widget, or a full Elementor element ({"elType": "container", "settings": {}, "elements": [...]}). Ids are generated.',
						),
					),
					'required'   => array( 'post_id', 'element' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'post_id'    => array( 'type' => 'integer' ),
						'element_id' => array( 'type' => 'string' ),
						'wrapper_id' => array( 'type' => 'string' ),
						'position'   => array( 'type' => 'string' ),
						'anchor_id'  => array( 'type' => 'string' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$input  = is_array( $input ) ? $input : array();
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return AbilityRegistrar::failure( $active );
		}

		$post = ContentSupport::require_can_edit( $input['post_id'] ?? 0 );

		if ( is_wp_error( $post ) ) {
			return AbilityRegistrar::failure( $post );
		}

		$position = isset( $input['position'] ) ? (string) $input['position'] : 'after';

		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'invalid_position', sprintf( 'position must be one of: %s.', implode( ', ', self::POSITIONS ) ) ) );
		}

		$anchor = isset( $input['anchor_id'] ) ? trim( (string) $input['anchor_id'] ) : '';
		$at_end = in_array( $position, array( 'page_start', 'page_end' ), true );

		if ( ! $at_end && '' === $anchor ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_anchor', 'anchor_id is required unless position is page_start or page_end.' ) );
		}

		$element = self::build_element( $input['element'] ?? null );

		if ( is_wp_error( $element ) ) {
			return AbilityRegistrar::failure( $element );
		}

		$elements = ElementorSupport::get_elements( $post->ID );

		if ( is_wp_error( $elements ) ) {
			return AbilityRegistrar::failure( $elements );
		}

		$new_id     = (string) $element['id'];
		$wrapper_id = '';

		if ( $at_end ) {
			// The page's top level holds sections or containers only: wrap a lone widget the way the page is built.
			if ( 'widget' === $element['elType'] ) {
				$element    = self::wrap( $element, self::uses_sections( (array) $elements ) );
				$wrapper_id = (string) $element['id'];
			}

			$tree = (array) $elements;
			'page_start' === $position ? array_unshift( $tree, $element ) : $tree[] = $element;
		} else {
			$found = false;
			$tree  = self::insert( (array) $elements, $anchor, $position, $element, $found );

			if ( ! $found ) {
				return AbilityRegistrar::failure(
					new WP_Error( 'anchor_not_found', sprintf( 'No element with id "%s" on post %d (or it is not a container, for first_child / last_child). Call elementor-get-structure to list the ids.', $anchor, $post->ID ) )
				);
			}
		}

		$saved = ElementorSupport::save_elements( $post->ID, $tree );

		if ( is_wp_error( $saved ) ) {
			return AbilityRegistrar::failure( $saved );
		}

		$out = array(
			'success'    => true,
			'post_id'    => $post->ID,
			'element_id' => '' !== $wrapper_id ? $wrapper_id : $new_id,
			'position'   => $position,
		);

		if ( '' !== $wrapper_id ) {
			// Undo deletes the wrapper, which takes the widget with it.
			$out['wrapper_id'] = $wrapper_id;
		}

		if ( '' !== $anchor ) {
			$out['anchor_id'] = $anchor;
		}

		return $out;
	}

	/**
	 * A complete element with fresh ids.
	 *
	 * @param mixed $given The element as given.
	 *
	 * @return array<string, mixed>|\WP_Error The element.
	 */
	private static function build_element( $given ) {
		if ( ! is_array( $given ) || empty( $given ) ) {
			return new WP_Error( 'missing_element', 'element is required, e.g. {"widgetType": "text-editor", "settings": {"editor": "<p>Text</p>"}}.' );
		}

		if ( ! empty( $given['widgetType'] ) && empty( $given['elType'] ) ) {
			$given['elType'] = 'widget';
		}

		$el_type = (string) ( $given['elType'] ?? '' );

		if ( ! in_array( $el_type, array( 'widget', 'container', 'section', 'column' ), true ) ) {
			return new WP_Error( 'invalid_element', 'element needs a widgetType (for a widget) or elType "container" / "section" / "column".' );
		}

		if ( 'widget' === $el_type && empty( $given['widgetType'] ) ) {
			return new WP_Error( 'invalid_element', 'A widget element needs its widgetType, e.g. "text-editor" or "heading".' );
		}

		$element = array(
			'id'       => '',
			'elType'   => $el_type,
			'settings' => isset( $given['settings'] ) && is_array( $given['settings'] ) ? $given['settings'] : array(),
			'elements' => isset( $given['elements'] ) && is_array( $given['elements'] ) ? array_values( $given['elements'] ) : array(),
		);

		if ( 'widget' === $el_type ) {
			$element['widgetType'] = (string) $given['widgetType'];
			$element['elements']   = array();
		}

		$with_ids = ElementorSupport::regenerate_ids( array( $element ) );

		return $with_ids[0];
	}

	/**
	 * A widget wrapped for the top level of the page.
	 *
	 * @param array<string, mixed> $widget   The widget.
	 * @param bool                 $sections Whether the page is built from sections and columns.
	 *
	 * @return array<string, mixed> The wrapper.
	 */
	private static function wrap( array $widget, bool $sections ): array {
		if ( $sections ) {
			return array(
				'id'       => ElementorSupport::generate_id(),
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => ElementorSupport::generate_id(),
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array( $widget ),
					),
				),
			);
		}

		return array(
			'id'       => ElementorSupport::generate_id(),
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array( $widget ),
		);
	}

	/**
	 * Whether the page's top level is built from sections (old layout) rather than containers.
	 *
	 * @param array<int, array<string, mixed>> $elements Top-level elements.
	 *
	 * @return bool True for sections.
	 */
	private static function uses_sections( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['elType'] ) ) {
				return 'section' === $element['elType'];
			}
		}

		return ! ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments )
			&& is_callable( array( \Elementor\Plugin::$instance->experiments, 'is_feature_active' ) )
			&& \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' ) );
	}

	/**
	 * Inserts the element relative to the anchor, anywhere in the tree.
	 *
	 * @param array<int, array<string, mixed>> $elements The elements at this level.
	 * @param string                           $anchor   Anchor element id.
	 * @param string                           $position before, after, first_child or last_child.
	 * @param array<string, mixed>             $element  The new element.
	 * @param bool                             $found    Set to true when the anchor was found.
	 *
	 * @return array<int, array<string, mixed>> The elements at this level, changed.
	 */
	private static function insert( array $elements, string $anchor, string $position, array $element, bool &$found ): array {
		$out = array();

		foreach ( array_values( $elements ) as $node ) {
			if ( $found || ! is_array( $node ) ) {
				$out[] = $node;
				continue;
			}

			if ( (string) ( $node['id'] ?? '' ) === $anchor ) {
				if ( 'before' === $position ) {
					$out[] = $element;
					$out[] = $node;
					$found = true;
					continue;
				}

				if ( 'after' === $position ) {
					$out[] = $node;
					$out[] = $element;
					$found = true;
					continue;
				}

				if ( 'widget' !== ( $node['elType'] ?? '' ) ) {
					$children         = isset( $node['elements'] ) && is_array( $node['elements'] ) ? array_values( $node['elements'] ) : array();
					$node['elements'] = 'first_child' === $position ? array_merge( array( $element ), $children ) : array_merge( $children, array( $element ) );
					$found            = true;
				}

				$out[] = $node;
				continue;
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::insert( $node['elements'], $anchor, $position, $element, $found );
			}

			$out[] = $node;
		}

		return $out;
	}
}
