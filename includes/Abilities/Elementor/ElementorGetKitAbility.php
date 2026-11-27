<?php
/**
 * Ability for reading the Elementor global kit (site colours, fonts, layout).
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
 * Elementor Get Kit - Reads the active kit's global colours, typography and layout.
 *
 * The kit is the `elementor_library` post Elementor's "Site Settings" panel
 * edits. Widgets that use a global colour or font reference it by `_id`
 * (`globals/colors?id=primary`), so changing a kit item restyles every widget
 * that uses it at once.
 */
final class ElementorGetKitAbility {

	/**
	 * Kit keys in the colours section.
	 */
	public const COLOR_KEYS = array( 'system_colors', 'custom_colors' );

	/**
	 * Kit keys in the typography section.
	 */
	public const TYPOGRAPHY_KEYS = array( 'system_typography', 'custom_typography', 'default_generic_fonts' );

	/**
	 * Kit key patterns in the layout section.
	 */
	public const LAYOUT_PATTERN = '/^(container_width|container_padding|space_between_widgets|page_title_selector|stretched_section_container|active_breakpoints|viewport_|default_page_template|global_image_lightbox|lightbox_)/';

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'elementor-get-kit',
			array(
				'label'               => 'Get Elementor Global Kit',
				'description'         => 'Read the active Elementor kit (Site Settings): the global colours (system_colors: primary, secondary, text, accent; plus custom_colors), global fonts (system_typography and custom_typography, each with its typography_* settings), and layout settings (content width, widget spacing, breakpoints, page title selector). Every item has an _id that widgets reference as a global ("globals/colors?id=primary"), so changing an item through elementor-update-kit restyles every widget that uses it. section limits the output: "colors", "typography", "layout" or "all" (default). include_raw returns every kit setting as stored.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'section'     => array(
							'type'        => 'string',
							'enum'        => array( 'colors', 'typography', 'layout', 'all' ),
							'description' => 'Which part of the kit to return. Default "all".',
						),
						'include_raw' => array(
							'type'        => 'boolean',
							'description' => 'Also return every stored kit setting (large). Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'kit_id'       => array( 'type' => 'integer' ),
						'title'        => array( 'type' => 'string' ),
						'colors'       => array( 'type' => 'object' ),
						'typography'   => array( 'type' => 'object' ),
						'layout'       => array( 'type' => 'object' ),
						'other_keys'   => array( 'type' => 'array' ),
						'raw'          => array( 'type' => 'object' ),
						'edit_url'     => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_read' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => true,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The kit.
	 */
	public static function execute( $input = array() ): array {
		$kit_id = self::require_kit();

		if ( is_wp_error( $kit_id ) ) {
			return AbilityRegistrar::failure( $kit_id );
		}

		$input    = is_array( $input ) ? $input : array();
		$section  = isset( $input['section'] ) ? (string) $input['section'] : 'all';
		$section  = in_array( $section, array( 'colors', 'typography', 'layout', 'all' ), true ) ? $section : 'all';
		$settings = self::settings( $kit_id );

		$front  = (int) get_option( 'page_on_front' );
		$result = array(
			'success'  => true,
			'kit_id'   => $kit_id,
			'title'    => (string) get_the_title( $kit_id ),
			// Site Settings opens from inside the editor of any Elementor page.
			'edit_url' => $front > 0 ? (string) admin_url( 'post.php?post=' . $front . '&action=elementor#e:run:panel/global/open' ) : '',
		);

		if ( in_array( $section, array( 'colors', 'all' ), true ) ) {
			$result['colors'] = (object) self::pick( $settings, self::COLOR_KEYS );
		}

		if ( in_array( $section, array( 'typography', 'all' ), true ) ) {
			$result['typography'] = (object) self::pick( $settings, self::TYPOGRAPHY_KEYS );
		}

		if ( in_array( $section, array( 'layout', 'all' ), true ) ) {
			$layout = array();

			foreach ( $settings as $key => $value ) {
				if ( preg_match( self::LAYOUT_PATTERN, (string) $key ) ) {
					$layout[ $key ] = $value;
				}
			}

			$result['layout'] = (object) $layout;
		}

		if ( 'all' === $section ) {
			$known = array();

			foreach ( array_keys( $settings ) as $key ) {
				$key = (string) $key;

				if ( ! in_array( $key, self::COLOR_KEYS, true ) && ! in_array( $key, self::TYPOGRAPHY_KEYS, true ) && ! preg_match( self::LAYOUT_PATTERN, $key ) ) {
					$known[] = $key;
				}
			}

			$result['other_keys'] = $known;
		}

		if ( ContentSupport::to_bool( $input['include_raw'] ?? null, false ) ) {
			$result['raw'] = (object) $settings;
		}

		return $result;
	}

	/**
	 * Returns the active kit's post id, checking Elementor and the caller's rights.
	 *
	 * @param bool $for_write Whether the caller wants to change the kit.
	 *
	 * @return int|\WP_Error The kit id.
	 */
	public static function require_kit( bool $for_write = false ) {
		$active = ElementorSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$kit_id = 0;

		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) && method_exists( \Elementor\Plugin::$instance->kits_manager, 'get_active_id' ) ) {
				$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
			}
		} catch ( \Throwable $e ) {
			$kit_id = 0;
		}

		if ( $kit_id <= 0 ) {
			$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		}

		if ( $kit_id <= 0 || ! get_post( $kit_id ) ) {
			return new WP_Error( 'kit_not_found', 'This site has no active Elementor kit. Open Elementor → Site Settings once in wp-admin to create it.' );
		}

		// The kit is site configuration, not content: reading it needs edit rights on it, like writing.
		$cap_ok = current_user_can( 'manage_options' ) || current_user_can( 'edit_post', $kit_id );

		// Writing saves raw settings (custom CSS among them): without manage_options,
		// it also needs unfiltered_html, as Elementor's own Site Settings panel does.
		if ( $for_write && ! current_user_can( 'manage_options' ) && ! current_user_can( 'unfiltered_html' ) ) {
			$cap_ok = false;
		}

		if ( ! $cap_ok ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf( 'User lacks the "manage_options" capability (or permission to %s the kit) needed for Elementor Site Settings.', $for_write ? 'edit' : 'read' )
			);
		}

		return $kit_id;
	}

	/**
	 * Reads the kit's stored settings.
	 *
	 * @param int $kit_id Kit post id.
	 *
	 * @return array<string, mixed> The settings.
	 */
	public static function settings( int $kit_id ): array {
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Picks keys from the settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string[]             $keys     Keys.
	 *
	 * @return array<string, mixed> The subset.
	 */
	private static function pick( array $settings, array $keys ): array {
		$out = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		return $out;
	}
}
