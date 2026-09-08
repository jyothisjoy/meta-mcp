<?php
/**
 * Shared registration boilerplate for the content abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - AbilityRegistrar
 *
 * Every content, Elementor and Polylang ability shares the same category, the
 * same MCP exposure flag and the same "authenticated user first" permission
 * preamble. Centralising that here keeps each ability file down to its schema
 * and its actual behaviour.
 */
final class AbilityRegistrar {

	/**
	 * Ability namespace used by this plugin.
	 */
	public const NAMESPACE_PREFIX = 'mcp-adapter';

	/**
	 * Category the content abilities are registered under.
	 */
	public const CATEGORY = 'mcp-adapter-content';

	/**
	 * Names of every ability registered through this class.
	 *
	 * @var string[]
	 */
	private static array $registered = array();

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'MCP Content', 'meta-mcp' ),
				'description' => __( 'Abilities for reading, editing and publishing WordPress content, including Elementor documents and Polylang translations.', 'meta-mcp' ),
			)
		);
	}

	/**
	 * Registers an ability with the shared defaults applied.
	 *
	 * @param string $slug Ability slug, without the namespace prefix.
	 * @param array  $args {
	 *     Ability definition.
	 *
	 *     @type string   $label               Human-readable label.
	 *     @type string   $description         What the ability does, written for an LLM client.
	 *     @type array    $input_schema        JSON schema for the input.
	 *     @type array    $output_schema       JSON schema for the output.
	 *     @type callable $permission_callback Permission check.
	 *     @type callable $execute_callback    Execution callback.
	 *     @type bool     $readonly            Whether the ability only reads. Default false.
	 *     @type bool     $destructive         Whether the ability can destroy data. Default false.
	 *     @type bool     $idempotent          Whether repeating the call is safe. Default false.
	 * }
	 *
	 * @return void
	 */
	public static function register( string $slug, array $args ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$name = self::NAMESPACE_PREFIX . '/' . $slug;

		$definition = array(
			'label'               => $args['label'],
			'description'         => $args['description'],
			'category'            => self::CATEGORY,
			'permission_callback' => $args['permission_callback'],
			'execute_callback'    => $args['execute_callback'],
			'meta'                => array(
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations' => array(
					'readonly'    => (bool) ( $args['readonly'] ?? false ),
					'destructive' => (bool) ( $args['destructive'] ?? false ),
					'idempotent'  => (bool) ( $args['idempotent'] ?? false ),
				),
			),
		);

		if ( isset( $args['input_schema'] ) ) {
			$definition['input_schema'] = $args['input_schema'];
		}

		if ( isset( $args['output_schema'] ) ) {
			$definition['output_schema'] = $args['output_schema'];
		}

		wp_register_ability( $name, $definition );

		self::$registered[] = $name;
	}

	/**
	 * Returns the names of every ability registered through this class.
	 *
	 * @return string[] Fully qualified ability names.
	 */
	public static function registered(): array {
		return self::$registered;
	}

	/**
	 * Standard permission preamble for read abilities.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function allow_read() {
		$authenticated = ContentSupport::require_authentication();

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'insufficient_capability', 'User lacks the "read" capability.' );
		}

		return true;
	}

	/**
	 * Standard permission preamble for write abilities.
	 *
	 * Per-post and per-post-type capabilities are still checked inside each
	 * ability; this only establishes that the caller is a real, authenticated
	 * user on a site where MCP writes are enabled.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function allow_write() {
		$authenticated = ContentSupport::require_authentication();

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		$writes_enabled = ContentSupport::require_writes_enabled();

		if ( is_wp_error( $writes_enabled ) ) {
			return $writes_enabled;
		}

		return true;
	}

	/**
	 * Wraps a WP_Error into the standard failure envelope.
	 *
	 * Abilities return a `success` flag rather than raising, so a client gets an
	 * actionable message instead of a transport-level error.
	 *
	 * @param \WP_Error $error The error.
	 *
	 * @return array{success: false, error: string, error_code: string} The failure envelope.
	 */
	public static function failure( WP_Error $error ): array {
		return array(
			'success'    => false,
			'error'      => $error->get_error_message(),
			'error_code' => (string) $error->get_error_code(),
		);
	}
}
