<?php
/**
 * Elementor Pro Custom Code snippet helpers.
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
 * Class - CustomCodeSupport
 *
 * Elementor Pro's Custom Code (Elementor → Custom Code) stores each snippet as
 * an `elementor_snippet` post. The code, location, priority and options are
 * `_elementor_*` meta, which the generic meta ability refuses, and the display
 * conditions are Theme Builder conditions that only take effect once Elementor
 * Pro's conditions cache is rebuilt.
 *
 * This writes the same meta Elementor Pro's own meta box writes
 * (`Custom_Code_Metabox::save_post_meta()`), then rebuilds the conditions cache
 * the way that meta box does. Elementor Pro limits the screen to
 * `manage_options`, and only stores raw code for users with `unfiltered_html`;
 * the same rules apply here.
 */
final class CustomCodeSupport {

	/**
	 * Snippet post type.
	 */
	public const CPT = 'elementor_snippet';

	/**
	 * Elementor document type of a snippet.
	 */
	public const DOCUMENT_TYPE = 'code_snippet';

	/**
	 * Where a snippet can print.
	 */
	public const LOCATIONS = array( 'elementor_head', 'elementor_body_start', 'elementor_body_end' );

	/**
	 * Statuses a snippet may be given.
	 */
	public const STATUSES = array( 'publish', 'draft', 'private' );

	/**
	 * Whether Custom Code snippets exist on this site.
	 *
	 * Elementor Pro registers the post type only when its licence includes
	 * Custom Code or snippets already exist.
	 *
	 * @return bool True when the post type is registered.
	 */
	public static function is_available(): bool {
		return post_type_exists( self::CPT );
	}

	/**
	 * Guard used by every Custom Code ability.
	 *
	 * @return true|\WP_Error True when usable.
	 */
	public static function require_available() {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'custom_code_not_available',
				'Elementor Pro Custom Code is not available on this site. It needs Elementor Pro with the Custom Code feature.'
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_capability', 'Elementor Custom Code requires the "manage_options" capability.' );
		}

		return true;
	}

	/**
	 * Loads a snippet.
	 *
	 * @param mixed $post_id Snippet post ID.
	 *
	 * @return \WP_Post|\WP_Error The snippet.
	 */
	public static function get( $post_id ) {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || self::CPT !== $post->post_type ) {
			return new WP_Error( 'snippet_not_found', sprintf( 'No Elementor Custom Code snippet found with ID %d.', $post_id ) );
		}

		return $post;
	}

	/**
	 * Shapes a snippet for a response.
	 *
	 * @param \WP_Post $post         The snippet.
	 * @param bool     $include_code Whether to include the code itself.
	 *
	 * @return array<string, mixed> The snippet.
	 */
	public static function format( WP_Post $post, bool $include_code ): array {
		$code    = (string) get_post_meta( $post->ID, '_elementor_code', true );
		$options = get_post_meta( $post->ID, '_elementor_extra_options', true );
		$data    = array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'location'      => (string) get_post_meta( $post->ID, '_elementor_location', true ),
			'priority'      => (int) get_post_meta( $post->ID, '_elementor_priority', true ),
			'ensure_jquery' => is_array( $options ) && in_array( 'ensure_jquery', $options, true ),
			'conditions'    => ElementorSupport::get_conditions( $post->ID ),
			'code_length'   => strlen( $code ),
			'modified'      => $post->post_modified,
			'edit_link'     => (string) get_edit_post_link( $post->ID, 'raw' ),
		);

		$data['prints_on'] = empty( $data['conditions'] )
			? 'every page (no display conditions)'
			: 'pages matching its display conditions';

		if ( $include_code ) {
			$data['code'] = $code;
		}

		return $data;
	}

	/**
	 * Writes a snippet's fields.
	 *
	 * Only keys present in `$input` change.
	 *
	 * @param \WP_Post             $post  The snippet.
	 * @param array<string, mixed> $input Fields: code, location, priority, ensure_jquery, conditions.
	 *
	 * @return true|\WP_Error True on success.
	 */
	public static function write( WP_Post $post, array $input ) {
		if ( array_key_exists( 'code', $input ) ) {
			update_post_meta( $post->ID, '_elementor_code', wp_slash( (string) $input['code'] ) );
		}

		if ( array_key_exists( 'location', $input ) ) {
			update_post_meta( $post->ID, '_elementor_location', (string) $input['location'] );
		}

		if ( array_key_exists( 'priority', $input ) ) {
			update_post_meta( $post->ID, '_elementor_priority', (string) (int) $input['priority'] );
		}

		if ( array_key_exists( 'ensure_jquery', $input ) ) {
			update_post_meta(
				$post->ID,
				'_elementor_extra_options',
				ContentSupport::to_bool( $input['ensure_jquery'], false ) ? array( 'ensure_jquery' ) : array()
			);
		}

		// Elementor resolves the snippet's document class from this; without it
		// the snippet is not a Theme Builder document and its conditions are
		// left out of the cache.
		if ( '' === (string) get_post_meta( $post->ID, '_elementor_template_type', true ) ) {
			update_post_meta( $post->ID, '_elementor_template_type', self::DOCUMENT_TYPE );
		}

		if ( array_key_exists( 'conditions', $input ) ) {
			// Validates, saves and rebuilds Elementor Pro's conditions cache.
			$written = ElementorSupport::set_conditions( $post->ID, (array) $input['conditions'] );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		} else {
			// The cache is keyed by location, so a moved snippet needs a rebuild too.
			ElementorSupport::flush_conditions_cache();
		}

		return true;
	}

	/**
	 * Validates the writable fields before anything is saved.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return true|\WP_Error True when valid.
	 */
	public static function validate( array $input ) {
		if ( array_key_exists( 'code', $input ) ) {
			if ( ! is_string( $input['code'] ) ) {
				return new WP_Error( 'invalid_code', 'code must be a string.' );
			}

			// Elementor Pro would silently run the code through wp_kses_post for
			// such a user, stripping every <script>. Refuse instead of saving
			// something other than what was sent.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new WP_Error(
					'unfiltered_html_required',
					'Writing snippet code requires the "unfiltered_html" capability; without it Elementor strips scripts from the code.'
				);
			}
		}

		if ( array_key_exists( 'location', $input ) && ! in_array( $input['location'], self::LOCATIONS, true ) ) {
			return new WP_Error( 'invalid_location', sprintf( 'location must be one of: %s.', implode( ', ', self::LOCATIONS ) ) );
		}

		if ( array_key_exists( 'priority', $input ) ) {
			$priority = (int) $input['priority'];

			if ( $priority < 1 || $priority > 10 ) {
				return new WP_Error( 'invalid_priority', 'priority must be between 1 and 10.' );
			}
		}

		if ( array_key_exists( 'conditions', $input ) ) {
			if ( ! is_array( $input['conditions'] ) ) {
				return new WP_Error( 'invalid_conditions', 'conditions must be an array of condition strings.' );
			}

			$conditions = ElementorSupport::clean_conditions( $input['conditions'] );

			if ( is_wp_error( $conditions ) ) {
				return $conditions;
			}
		}

		if ( array_key_exists( 'status', $input ) && ! in_array( $input['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', sprintf( 'status must be one of: %s.', implode( ', ', self::STATUSES ) ) );
		}

		return true;
	}
}
