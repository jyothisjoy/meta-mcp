<?php
/**
 * Ability for replacing the file behind an existing media item.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SiteSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Replace Media - Swaps the file of an attachment while keeping its ID.
 *
 * Pages, Elementor widgets and featured images point at an attachment ID (and
 * usually at its URL too), so uploading a new image and repointing everything
 * is slow and easy to get half done. Replacing the file in place keeps every
 * reference valid. With keep_filename (the default) the new file is written to
 * the very same path, so even hard-coded URLs keep working; the old resized
 * copies are removed and new ones generated.
 */
final class ReplaceMediaAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'replace-media',
			array(
				'label'               => 'Replace Media File',
				'description'         => 'Replace the file of an existing media library item, keeping its attachment ID so every page, Elementor widget and featured image that uses it shows the new file. With keep_filename true (default) the new file overwrites the old one at the same path, so its URL is unchanged; the file must then have the same extension (jpg/jpeg count as the same). With keep_filename false the new file gets its own name and URL and the old files are removed, so run replace-text from old_url to url afterwards for any hard-coded links. The new file must be of the same kind (an image for an image). Resized copies are regenerated. Browsers and CDNs may keep showing the old image at an unchanged URL until their cache expires; the page cache of the post it is attached to is purged.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id'         => array(
							'type'        => 'integer',
							'description' => 'The media item to replace.',
						),
						'source_url'            => array(
							'type'        => 'string',
							'description' => 'Public URL to download the new file from. Either source_url or base64_data is required.',
						),
						'base64_data'           => array(
							'type'        => 'string',
							'description' => 'New file contents as base64. Requires filename. Limited to roughly 12MB encoded.',
						),
						'filename'              => array(
							'type'        => 'string',
							'description' => 'File name of the new file including extension. Required with base64_data; derived from the URL otherwise. With keep_filename true it is only used to check the file type.',
						),
						'keep_filename'         => array(
							'type'        => 'boolean',
							'description' => 'Write the new file over the old one so the URL stays the same. Default true.',
						),
						'regenerate_thumbnails' => array(
							'type'        => 'boolean',
							'description' => 'Generate the resized copies for the new image. Default true.',
						),
					),
					'required'   => array( 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'attachment' => array( 'type' => 'object' ),
						'old_url'    => array( 'type' => 'string' ),
						'url_changed' => array( 'type' => 'boolean' ),
						'renamed_sizes' => array( 'type' => 'array' ),
						'purged'     => array( 'type' => 'array' ),
						'notes'      => array( 'type' => 'array' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( UploadMediaAbility::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => true,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The replaced attachment.
	 */
	public static function execute( $input = array() ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$input         = is_array( $input ) ? $input : array();
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return AbilityRegistrar::failure( new WP_Error( 'attachment_not_found', sprintf( 'No media item with ID %d.', $attachment_id ) ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'insufficient_capability', 'User cannot edit this media item.' ) );
		}

		$old_path = (string) get_attached_file( $attachment_id );

		if ( '' === $old_path || ! file_exists( $old_path ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'file_missing', 'The current file of this media item is missing on disk, so it cannot be replaced in place. Upload it again with upload-media instead.' ) );
		}

		$keep_filename = ContentSupport::to_bool( $input['keep_filename'] ?? null, true );
		$regenerate    = ContentSupport::to_bool( $input['regenerate_thumbnails'] ?? null, true );

		if ( $keep_filename && empty( $input['filename'] ) && ! empty( $input['base64_data'] ) ) {
			$input['filename'] = basename( $old_path );
		}

		$file = UploadMediaAbility::stage_file( $input );

		if ( is_wp_error( $file ) ) {
			return AbilityRegistrar::failure( $file );
		}

		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$new_ext = (string) ( $checked['ext'] ?? '' );
		$new_mime = (string) ( $checked['type'] ?? '' );

		if ( '' === $new_ext || '' === $new_mime ) {
			UploadMediaAbility::cleanup( $file['tmp_name'] );

			return AbilityRegistrar::failure( new WP_Error( 'disallowed_file_type', sprintf( 'The file type of "%s" is not allowed on this site, or its contents do not match its extension.', $file['name'] ) ) );
		}

		$old_mime = (string) get_post_mime_type( $attachment_id );

		if ( strtok( $old_mime, '/' ) !== strtok( $new_mime, '/' ) ) {
			UploadMediaAbility::cleanup( $file['tmp_name'] );

			return AbilityRegistrar::failure(
				new WP_Error( 'mime_family_mismatch', sprintf( 'The media item is %s but the new file is %s. Replace it with a file of the same kind, or upload the new file separately.', $old_mime, $new_mime ) )
			);
		}

		$old_ext = strtolower( (string) pathinfo( $old_path, PATHINFO_EXTENSION ) );

		if ( $keep_filename && self::normalise_ext( $old_ext ) !== self::normalise_ext( $new_ext ) ) {
			UploadMediaAbility::cleanup( $file['tmp_name'] );

			return AbilityRegistrar::failure(
				new WP_Error( 'extension_mismatch', sprintf( 'keep_filename needs a .%s file, because the URL ends in .%s; the new file is .%s. Pass keep_filename false to give it a new name and URL.', $old_ext, $old_ext, $new_ext ) )
			);
		}

		$old_url  = (string) wp_get_attachment_url( $attachment_id );
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$backups  = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		$placed = $keep_filename
			? self::overwrite( $attachment_id, $file['tmp_name'], $old_path, $old_meta, $backups )
			: self::place_new( $attachment_id, $file, $old_path, $old_meta, $backups );

		if ( is_wp_error( $placed ) ) {
			UploadMediaAbility::cleanup( $file['tmp_name'] );

			return AbilityRegistrar::failure( $placed );
		}

		delete_post_meta( $attachment_id, '_wp_attachment_backup_sizes' );

		if ( $new_mime !== $old_mime ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $new_mime,
				)
			);
		}

		$notes = array();
		$meta  = self::build_metadata( $attachment_id, $placed, $regenerate, $notes );

		wp_update_attachment_metadata( $attachment_id, $meta );
		clean_post_cache( $attachment_id );

		$parent_id = (int) $attachment->post_parent;
		$new_url   = (string) wp_get_attachment_url( $attachment_id );

		// Resized copies are named after their dimensions, so a new aspect ratio
		// renames them (-300x200 becomes -300x225) and any page that hard-coded the
		// old size URL now points at a deleted file. List each change for replace-text.
		$renamed = array();
		$old_dir = trailingslashit( dirname( $old_url ) );
		$new_dir = trailingslashit( dirname( $new_url ) );

		foreach ( (array) ( $old_meta['sizes'] ?? array() ) as $size => $old_size ) {
			$new_size = $meta['sizes'][ $size ] ?? null;

			if ( is_array( $old_size ) && ! empty( $old_size['file'] ) && ( ! is_array( $new_size ) || ( $new_size['file'] ?? '' ) !== $old_size['file'] ) ) {
				$renamed[] = array(
					'size'    => (string) $size,
					'old_url' => $old_dir . $old_size['file'],
					'new_url' => is_array( $new_size ) && ! empty( $new_size['file'] ) ? $new_dir . $new_size['file'] : $new_url,
				);
			}
		}

		if ( ! empty( $renamed ) ) {
			$notes[] = sprintf( '%d resized copies have new file names. If a page hard-codes an old_url from renamed_sizes, run replace-text with it and its new_url.', count( $renamed ) );
		}

		// The image can appear on any page, not only its parent: purge everything.
		$purge  = SiteSupport::purge_caches( array() );
		$purged = $purge['purged'];

		if ( $new_url === $old_url ) {
			$notes[] = 'The URL is unchanged, so browsers and CDNs may show the old file until their cache expires.';
		} else {
			$notes[] = sprintf( 'The URL changed. Run replace-text with find "%s" and replace "%s" to update hard-coded links.', $old_url, $new_url );
		}

		/**
		 * Fires after MCP replaced the file of a media item.
		 *
		 * @since 1.8.0
		 *
		 * @param int    $attachment_id The attachment.
		 * @param string $old_url       Its URL before.
		 * @param string $new_url       Its URL now.
		 */
		do_action( 'mcp_adapter_media_replaced', $attachment_id, $old_url, $new_url );

		return array(
			'success'     => true,
			'attachment'  => array(
				'id'        => $attachment_id,
				'title'     => get_the_title( $attachment_id ),
				'url'       => $new_url,
				'mime_type' => (string) get_post_mime_type( $attachment_id ),
				'width'     => (int) ( $meta['width'] ?? 0 ),
				'height'    => (int) ( $meta['height'] ?? 0 ),
				'filesize'  => (int) ( $meta['filesize'] ?? ( file_exists( $placed ) ? filesize( $placed ) : 0 ) ),
				'sizes'     => isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
				'parent'    => $parent_id,
			),
			'old_url'       => $old_url,
			'url_changed'   => $new_url !== $old_url,
			'renamed_sizes' => $renamed,
			'purged'      => $purged,
			'notes'       => $notes,
		);
	}

	/**
	 * Writes the new file over the old one, keeping the same path.
	 *
	 * The old file is copied aside first and put back if anything fails, so an
	 * error never leaves the media item without a file.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param string       $tmp           Staged new file.
	 * @param string       $old_path      Current file path.
	 * @param array<mixed> $old_meta      Current attachment metadata.
	 * @param mixed        $backups       Stored backup sizes from image edits.
	 *
	 * @return string|\WP_Error The file path.
	 */
	private static function overwrite( int $attachment_id, string $tmp, string $old_path, array $old_meta, $backups ) {
		$backup = wp_tempnam( basename( $old_path ) );

		if ( ! $backup || ! @copy( $old_path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
			return new WP_Error( 'backup_failed', 'Could not make a safety copy of the current file, so nothing was changed.' );
		}

		self::delete_derived_files( $old_path, $old_meta, $backups, true );

		if ( ! @copy( $tmp, $old_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
			@copy( $backup, $old_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort restore.
			UploadMediaAbility::cleanup( $backup );

			return new WP_Error( 'write_failed', 'Could not write the new file over the old one; the old file was put back.' );
		}

		UploadMediaAbility::cleanup( $backup );
		UploadMediaAbility::cleanup( $tmp );

		// Match the permissions WordPress gives uploads.
		$stat  = @stat( dirname( $old_path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Permissions are cosmetic.
		$perms = $stat ? ( $stat['mode'] & 0000666 ) : 0644;
		@chmod( $old_path, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Permissions are cosmetic.

		unset( $attachment_id );

		return $old_path;
	}

	/**
	 * Stores the new file under its own name and removes the old files.
	 *
	 * @param int                                  $attachment_id Attachment ID.
	 * @param array{name: string, tmp_name: string} $file          Staged file.
	 * @param string                               $old_path      Current file path.
	 * @param array<mixed>                         $old_meta      Current attachment metadata.
	 * @param mixed                                $backups       Stored backup sizes from image edits.
	 *
	 * @return string|\WP_Error The new file path.
	 */
	private static function place_new( int $attachment_id, array $file, string $old_path, array $old_meta, $backups ) {
		$time = null;
		$date = get_post_field( 'post_date', $attachment_id );

		if ( is_string( $date ) && '' !== $date ) {
			$time = substr( $date, 0, 7 );
			$time = str_replace( '-', '/', $time );
		}

		// wp_handle_sideload() takes the file array by reference, so it must be a variable.
		$sideload = array(
			'name'     => $file['name'],
			'tmp_name' => $file['tmp_name'],
			'error'    => 0,
			'size'     => (int) filesize( $file['tmp_name'] ),
		);

		$handled = wp_handle_sideload( $sideload, array( 'test_form' => false ), $time );

		if ( isset( $handled['error'] ) ) {
			return new WP_Error( 'sideload_failed', (string) $handled['error'] );
		}

		$new_path = (string) $handled['file'];

		update_attached_file( $attachment_id, $new_path );
		self::delete_derived_files( $old_path, $old_meta, $backups, false );

		return $new_path;
	}

	/**
	 * Deletes the resized copies (and, unless kept, the main file) of an attachment.
	 *
	 * @param string       $path      Main file path.
	 * @param array<mixed> $meta      Attachment metadata.
	 * @param mixed        $backups   Stored backup sizes.
	 * @param bool         $keep_main Leave the main file in place.
	 *
	 * @return void
	 */
	private static function delete_derived_files( string $path, array $meta, $backups, bool $keep_main ): void {
		$dir   = trailingslashit( dirname( $path ) );
		$files = array();

		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[] = $dir . basename( (string) $size['file'] );
				}
			}
		}

		if ( is_array( $backups ) ) {
			foreach ( $backups as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[] = $dir . basename( (string) $size['file'] );
				}
			}
		}

		// The original of a "-scaled" big image.
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . basename( (string) $meta['original_image'] );
		}

		if ( ! $keep_main ) {
			$files[] = $path;
		}

		foreach ( array_unique( $files ) as $file ) {
			if ( $keep_main && $file === $path ) {
				continue;
			}

			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Builds the attachment metadata for the new file.
	 *
	 * Big-image scaling is switched off while regenerating so WordPress does not
	 * write a "-scaled" copy and move the attachment to a new URL.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $path          New file path.
	 * @param bool     $regenerate    Generate resized copies.
	 * @param string[] $notes         Notes for the response, appended to.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private static function build_metadata( int $attachment_id, string $path, bool $regenerate, array &$notes ): array {
		if ( $regenerate ) {
			add_filter( 'big_image_size_threshold', '__return_false', 999 );
			$meta = wp_generate_attachment_metadata( $attachment_id, $path );
			remove_filter( 'big_image_size_threshold', '__return_false', 999 );

			if ( is_array( $meta ) && ! empty( $meta ) ) {
				return $meta;
			}

			$notes[] = 'WordPress could not generate metadata for the new file; only its size was recorded.';
		} else {
			$notes[] = 'Resized copies were not regenerated; pages that use a thumbnail size will fall back to the full image.';
		}

		$uploads  = wp_get_upload_dir();
		$relative = 0 === strpos( $path, trailingslashit( $uploads['basedir'] ) )
			? substr( $path, strlen( trailingslashit( $uploads['basedir'] ) ) )
			: basename( $path );

		$meta = array(
			'file'     => $relative,
			'filesize' => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'sizes'    => array(),
		);

		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-images return false.

		if ( is_array( $size ) ) {
			$meta['width']  = (int) $size[0];
			$meta['height'] = (int) $size[1];
		}

		return $meta;
	}

	/**
	 * Treats equivalent extensions as the same.
	 *
	 * @param string $ext Extension.
	 *
	 * @return string The normalised extension.
	 */
	private static function normalise_ext( string $ext ): string {
		$ext = strtolower( $ext );

		return in_array( $ext, array( 'jpg', 'jpeg', 'jpe' ), true ) ? 'jpg' : $ext;
	}
}
