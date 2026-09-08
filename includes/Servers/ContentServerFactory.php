<?php
/**
 * Factory for the WordPress content MCP server.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers;

use WP\MCP\Abilities\ContentAbilities;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Factory for the content MCP server.
 *
 * The default server exposes three generic tools (discover, describe, execute)
 * that a client has to chain to do anything. Content editing benefits from the
 * opposite: each operation is its own tool with its own schema, so a client sees
 * `create-post` with a typed `status` enum rather than a free-form ability name.
 * That is what this second server provides, at `/wp-json/mcp/wp-content`.
 */
class ContentServerFactory {

	/**
	 * Creates the content server.
	 *
	 * @return void
	 */
	public static function create(): void {
		$tools = ContentAbilities::ability_names();

		if ( empty( $tools ) ) {
			return;
		}

		$defaults = array(
			'server_id'              => 'mcp-adapter-content-server',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'wp-content',
			'server_name'            => 'WordPress Content Server',
			'server_description'     => 'Read, edit and publish WordPress content of any post type, build and rearrange navigation menus, edit Elementor layouts, and manage Polylang translations.',
			'server_version'         => 'v1.0.0',
			'mcp_transports'         => array( HttpTransport::class ),
			'error_handler'          => ErrorLogMcpErrorHandler::class,
			'observability_handler'  => NullMcpObservabilityHandler::class,
			'tools'                  => $tools,
			'resources'              => array(),
			'prompts'                => array(),
		);

		/**
		 * Filters the content MCP server configuration.
		 *
		 * Takes the same shape as `mcp_adapter_default_server_config`. Use it to
		 * change the route, swap in an observability handler, or narrow the tool
		 * list to a subset of the registered content abilities.
		 *
		 * @since 0.6.1
		 *
		 * @param array $config The server configuration.
		 */
		$config = apply_filters( 'mcp_adapter_content_server_config', $defaults );

		if ( ! is_array( $config ) ) {
			$config = $defaults;
		}

		$config = wp_parse_args( $config, $defaults );

		$result = McpAdapter::instance()->create_server(
			$config['server_id'],
			$config['server_route_namespace'],
			$config['server_route'],
			$config['server_name'],
			$config['server_description'],
			$config['server_version'],
			$config['mcp_transports'],
			$config['error_handler'],
			$config['observability_handler'],
			$config['tools'],
			$config['resources'],
			$config['prompts']
		);

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				'MCP Adapter: Failed to create the content server. Error: %s (Code: %s)',
				esc_html( $result->get_error_message() ),
				esc_html( (string) $result->get_error_code() )
			),
			'0.6.1'
		);
	}
}
