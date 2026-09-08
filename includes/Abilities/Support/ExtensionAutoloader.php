<?php
/**
 * PSR-4 fallback autoloader for classes added on top of the upstream plugin.
 *
 * The bundled Jetpack autoloader resolves `WP\MCP\*` from a generated classmap
 * (`vendor/composer/jetpack_autoload_classmap.php`). Classes added by this fork
 * are not in that classmap, and `composer dump-autoload` is not always available
 * on a deployed site, so this registers a last-resort PSR-4 resolver for the
 * same namespace. It is appended rather than prepended, so the generated
 * classmap keeps winning for every upstream class.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - ExtensionAutoloader
 */
final class ExtensionAutoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	private const PREFIX = 'WP\\MCP\\';

	/**
	 * Whether the autoloader has already been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers the fallback autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		spl_autoload_register(
			static function ( string $class_name ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
				$file     = META_MCP_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

				// Guard against path traversal through a crafted class name.
				$real_base = realpath( META_MCP_DIR . 'includes' );
				$real_file = realpath( $file );

				if ( false === $real_base || false === $real_file ) {
					return;
				}

				if ( 0 !== strpos( $real_file, $real_base ) ) {
					return;
				}

				require_once $real_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is validated above.
			},
			true,
			false
		);
	}
}
