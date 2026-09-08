<?php
/**
 * Ability for adding media to the library.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upload Media - Sideloads a file into the media library.
 *
 * Downloads go through `download_url()`, which uses the safe HTTP API and so
 * refuses loopback and private-network hosts. Base64 payloads are written
 * through `wp_handle_sideload()` so WordPress applies its own MIME and
 * extension checks rather than trusting the supplied filename.
 */
final class UploadMediaAbility {

	/**
	 * Largest accepted base64 payload, before decoding.
	 */
	private const MAX_BASE64_BYTES = 12582912;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'upload-media',
			array(
				'label'               => 'Upload Media',
				'description'         => 'Add an image or file to the media library, either by downloading a public URL or from base64 data, and optionally attach it to a post and set it as that post\'s featured image. Returns the attachment ID to use as featured_media elsewhere.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source_url'       => array(
							'type'        => 'string',
							'description' => 'Public URL to download the file from. Either source_url or base64_data is required.',
						),
						'base64_data'      => array(
							'type'        => 'string',
							'description' => 'File contents as base64. Requires filename. Limited to roughly 12MB encoded.',
						),
						'filename'         => array(
							'type'        => 'string',
							'description' => 'File name including extension, e.g. "hero.jpg". Required with base64_data; derived from the URL otherwise.',
						),
						'title'            => array(
							'type'        => 'string',
							'description' => 'Title for the attachment. Defaults to the file name.',
						),
						'alt_text'         => array(
							'type'        => 'string',
							'description' => 'Alternative text for images. Strongly recommended for accessibility.',
						),
						'caption'          => array(
							'type'        => 'string',
							'description' => 'Caption for the attachment.',
						),
						'description'      => array(
							'type'        => 'string',
							'description' => 'Long description for the attachment.',
						),
						'attach_to_post'   => array(
							'type'        => 'integer',
							'description' => 'Post ID to attach the media to.',
						),
						'set_as_featured'  => array(
							'type'        => 'boolean',
							'description' => 'Also set the uploaded file as the featured image of attach_to_post. Default false.',
						),
						'language'         => array(
							'type'        => 'string',
							'description' => 'Polylang language slug to assign to the attachment. Ignored when Polylang is inactive or media translation is disabled.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'attachment' => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Checks that the user may upload files.
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public static function check_permission() {
		$allowed = AbilityRegistrar::allow_write();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'insufficient_capability', 'User lacks the "upload_files" capability.' );
		}

		return true;
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The created attachment.
	 */
	public static function execute( $input = array() ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = self::stage_file( $input );

		if ( is_wp_error( $file ) ) {
			return AbilityRegistrar::failure( $file );
		}

		$parent_id = isset( $input['attach_to_post'] ) ? (int) $input['attach_to_post'] : 0;

		if ( $parent_id > 0 ) {
			$parent = ContentSupport::require_can_edit( $parent_id );

			if ( is_wp_error( $parent ) ) {
				self::cleanup( $file['tmp_name'] );

				return AbilityRegistrar::failure( $parent );
			}
		}

		$post_data = array_filter(
			array(
				'post_title'   => isset( $input['title'] ) ? (string) $input['title'] : '',
				'post_excerpt' => isset( $input['caption'] ) ? (string) $input['caption'] : '',
				'post_content' => isset( $input['description'] ) ? (string) $input['description'] : '',
			),
			static fn( string $value ): bool => '' !== $value
		);

		$attachment_id = media_handle_sideload( $file, $parent_id, null, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			self::cleanup( $file['tmp_name'] );

			return AbilityRegistrar::failure( $attachment_id );
		}

		$attachment_id = (int) $attachment_id;

		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( (string) $input['alt_text'] ) );
		}

		$notes = array();

		if ( $parent_id > 0 && ContentSupport::to_bool( $input['set_as_featured'] ?? null, false ) ) {
			set_post_thumbnail( $parent_id, $attachment_id );
			$notes['featured_image_set_on'] = $parent_id;
		}

		if ( ! empty( $input['language'] ) && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $attachment_id, (string) $input['language'] );
			$notes['language'] = (string) $input['language'];
		}

		return array(
			'success'    => true,
			'attachment' => array_merge(
				array(
					'id'        => $attachment_id,
					'title'     => get_the_title( $attachment_id ),
					'url'       => (string) wp_get_attachment_url( $attachment_id ),
					'mime_type' => (string) get_post_mime_type( $attachment_id ),
					'alt_text'  => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
					'parent'    => $parent_id,
				),
				$notes
			),
		);
	}

	/**
	 * Places the incoming file in a temporary location for sideloading.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array{name: string, tmp_name: string}|\WP_Error The sideload descriptor, or WP_Error.
	 */
	private static function stage_file( array $input ) {
		$source_url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';
		$base64     = isset( $input['base64_data'] ) ? (string) $input['base64_data'] : '';

		if ( '' === $source_url && '' === $base64 ) {
			return new WP_Error( 'missing_source', 'Either source_url or base64_data is required.' );
		}

		if ( '' !== $source_url ) {
			return self::stage_from_url( $source_url, isset( $input['filename'] ) ? (string) $input['filename'] : '' );
		}

		return self::stage_from_base64( $base64, isset( $input['filename'] ) ? (string) $input['filename'] : '' );
	}

	/**
	 * Downloads a remote file into a temporary path.
	 *
	 * @param string $source_url Remote URL.
	 * @param string $filename   Optional file name override.
	 *
	 * @return array{name: string, tmp_name: string}|\WP_Error The sideload descriptor, or WP_Error.
	 */
	private static function stage_from_url( string $source_url, string $filename ) {
		if ( ! wp_http_validate_url( $source_url ) ) {
			return new WP_Error(
				'invalid_source_url',
				'source_url must be a valid, publicly reachable http or https URL.'
			);
		}

		$tmp = download_url( $source_url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( '' === $filename ) {
			$path     = (string) wp_parse_url( $source_url, PHP_URL_PATH );
			$filename = basename( $path );
		}

		if ( '' === $filename || ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			self::cleanup( $tmp );

			return new WP_Error(
				'indeterminate_filename',
				'Could not determine a file name with an extension from source_url. Pass filename explicitly.'
			);
		}

		return array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);
	}

	/**
	 * Writes a base64 payload into a temporary path.
	 *
	 * @param string $base64   Base64 encoded contents, optionally as a data URI.
	 * @param string $filename File name including extension.
	 *
	 * @return array{name: string, tmp_name: string}|\WP_Error The sideload descriptor, or WP_Error.
	 */
	private static function stage_from_base64( string $base64, string $filename ) {
		if ( '' === $filename ) {
			return new WP_Error( 'missing_filename', 'filename is required when uploading base64_data.' );
		}

		// Accept a full data URI as well as bare base64.
		if ( 0 === strpos( $base64, 'data:' ) ) {
			$comma = strpos( $base64, ',' );

			if ( false === $comma ) {
				return new WP_Error( 'invalid_base64', 'base64_data looks like a data URI but has no comma separator.' );
			}

			$base64 = substr( $base64, $comma + 1 );
		}

		$base64 = preg_replace( '/\s+/', '', $base64 );

		if ( strlen( (string) $base64 ) > self::MAX_BASE64_BYTES ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'base64_data exceeds the %d byte limit.', self::MAX_BASE64_BYTES )
			);
		}

		$decoded = base64_decode( (string) $base64, true );

		if ( false === $decoded || '' === $decoded ) {
			return new WP_Error( 'invalid_base64', 'base64_data could not be decoded.' );
		}

		$filename  = sanitize_file_name( $filename );
		$file_type = wp_check_filetype( $filename );

		if ( empty( $file_type['ext'] ) || empty( $file_type['type'] ) ) {
			return new WP_Error(
				'disallowed_file_type',
				sprintf( 'The file type of "%s" is not allowed on this site.', $filename )
			);
		}

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'tempfile_failed', 'Could not create a temporary file for the upload.' );
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		$written = $wp_filesystem
			? $wp_filesystem->put_contents( $tmp, $decoded )
			: false;

		if ( ! $written ) {
			self::cleanup( $tmp );

			return new WP_Error( 'write_failed', 'Could not write the decoded file to disk.' );
		}

		return array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
	}

	/**
	 * Removes a temporary file.
	 *
	 * @param string $path Temporary file path.
	 *
	 * @return void
	 */
	private static function cleanup( string $path ): void {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		wp_delete_file( $path );
	}
}
