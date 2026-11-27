<?php
/**
 * Trims tool results on the content server's REST responses.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - ResponseCompactor
 *
 * The adapter that answers MCP requests may be another plugin's copy (Elementor
 * and Rank Math bundle newer ones, and the newest copy wins), so its result
 * encoding cannot be changed in Meta MCP's own handler. It sends every tool
 * result twice - as JSON text and again as structuredContent - and encodes the
 * text with escaped slashes and \u-escaped non-Latin characters. A language
 * model reads that text, so both cost tokens on every call.
 *
 * This runs on the finished REST response of the content server only: it
 * re-encodes each text result compactly and drops the duplicate
 * structuredContent when the tool declares no outputSchema (Meta MCP's tools do
 * not), which the MCP specification permits.
 */
final class ResponseCompactor {

	/**
	 * Hooks the compactor in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'compact' ), 20, 3 );
	}

	/**
	 * Compacts the tool results in a content-server response.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 *
	 * @return mixed The response.
	 */
	public static function compact( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		/**
		 * Filters the REST route whose tool results are compacted.
		 *
		 * @since 1.8.0
		 *
		 * @param string $route Route, default "/mcp/wp-content".
		 */
		$route = (string) apply_filters( 'mcp_adapter_compact_route', '/mcp/wp-content' );

		if ( 0 !== strpos( (string) $request->get_route(), $route ) ) {
			return $response;
		}

		// The adapter answering may be any version: an array, a DTO or a stdClass
		// tree. A JSON round trip gives one shape and keeps {} as objects.
		$data = json_decode( (string) wp_json_encode( $response->get_data() ) );

		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return $response;
		}

		// A JSON-RPC batch is a list of messages; a single message is an object.
		if ( is_array( $data ) ) {
			foreach ( $data as $i => $message ) {
				$data[ $i ] = self::compact_message( $message );
			}
		} else {
			$data = self::compact_message( $data );
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Compacts one JSON-RPC message, if it is a tool result.
	 *
	 * @param mixed $message Message.
	 *
	 * @return mixed The message.
	 */
	private static function compact_message( $message ) {
		if ( ! is_object( $message ) || ! isset( $message->result ) || ! is_object( $message->result ) || ! isset( $message->result->content ) || ! is_array( $message->result->content ) ) {
			return $message;
		}

		foreach ( $message->result->content as $block ) {
			if ( ! is_object( $block ) || 'text' !== ( $block->type ?? '' ) || ! is_string( $block->text ?? null ) ) {
				continue;
			}

			$text = $block->text;

			if ( '' === $text || ( '{' !== $text[0] && '[' !== $text[0] ) ) {
				continue;
			}

			// Objects stay objects: decoding to arrays would turn {} into [].
			$decoded = json_decode( $text );

			if ( null === $decoded ) {
				continue;
			}

			$compact = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( is_string( $compact ) ) {
				$block->text = $compact;
			}
		}

		// The same data again as structuredContent: only required when the tool
		// declares an outputSchema, which Meta MCP's tools do not.
		if ( property_exists( $message->result, 'structuredContent' ) && ! has_filter( 'mcp_adapter_content_declare_output_schema' ) ) {
			unset( $message->result->structuredContent );
		}

		return $message;
	}
}
