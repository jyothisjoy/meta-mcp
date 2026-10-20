<?php
/**
 * Keeps the MCP adapter's shared classes resolving to the newest bundled copy.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - AdapterClassGuard
 *
 * Several plugins bundle the WordPress MCP Adapter under the same `WP\MCP`
 * class names: Meta MCP, Elementor, Rank Math, WooCommerce and the official
 * MCP Adapter plugin. The adapter is meant to be shared through the Jetpack
 * autoloader, which loads the newest version of each class among the copies it
 * knows about. Rank Math instead registers a plain Composer autoloader, and
 * Composer puts itself at the front of the autoloader queue. Because Rank Math
 * loads after Meta MCP, its older adapter (0.5.0) then supplies every adapter
 * class loaded afterwards: the handlers, transport, sessions and servers that
 * Meta MCP, Elementor and Rank Math all run on. That copy lacks 0.6.0's fix for
 * concurrent session writes.
 *
 * This puts a small loader back at the front of the queue after each plugin
 * file loads. It only answers for `WP\MCP\` classes, and only by asking the
 * Jetpack autoloader, so Jetpack's own "newest version wins" choice decides
 * which copy is used - including the official MCP Adapter plugin when it is
 * newer than Meta MCP's. Nothing outside that namespace is affected, and a
 * class Jetpack does not know falls through to the other loaders as before.
 */
final class AdapterClassGuard {

	/**
	 * Namespace prefix the guard answers for.
	 */
	private const PREFIX = 'WP\\MCP\\';

	/**
	 * The Jetpack autoloader's class loader callback, once found.
	 *
	 * @var callable|null
	 */
	private static $jetpack = null;

	/**
	 * Hooks the guard in. Called while Meta MCP's main file loads.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Escape hatch for a site that needs the old behaviour back.
		if ( defined( 'META_MCP_DISABLE_ADAPTER_GUARD' ) && META_MCP_DISABLE_ADAPTER_GUARD ) {
			return;
		}

		self::promote();

		// Each later plugin may register a prepended Composer autoloader while
		// its file loads; move back in front as soon as it has finished.
		add_action( 'plugin_loaded', array( self::class, 'promote' ), PHP_INT_MAX );
		add_action( 'plugins_loaded', array( self::class, 'promote' ), PHP_INT_MIN );
	}

	/**
	 * Moves the guard to the front of the autoloader queue.
	 *
	 * @return void
	 */
	public static function promote(): void {
		/**
		 * Filters whether Meta MCP keeps adapter classes on the newest bundled copy.
		 *
		 * Return false to leave autoloader order alone, so whichever plugin
		 * registered its autoloader last supplies the adapter classes. Only a
		 * filter added before Meta MCP loads (a must-use plugin) can stop the
		 * very first promotion.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $enabled Whether the guard runs. Default true.
		 */
		if ( ! apply_filters( 'mcp_adapter_prefer_newest_adapter', true ) ) {
			return;
		}

		if ( null === self::find_jetpack_loader() ) {
			return;
		}

		$loader    = array( self::class, 'load' );
		$functions = spl_autoload_functions();

		if ( is_array( $functions ) && isset( $functions[0] ) && $functions[0] === $loader ) {
			return;
		}

		spl_autoload_unregister( $loader );
		spl_autoload_register( $loader, true, true );
	}

	/**
	 * Loads a `WP\MCP\` class through the Jetpack autoloader.
	 *
	 * @param string $class_name Requested class.
	 *
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$jetpack = self::find_jetpack_loader();

		if ( null !== $jetpack ) {
			$jetpack( $class_name );
		}
	}

	/**
	 * Finds the Jetpack autoloader's registered loader.
	 *
	 * Its namespace carries a version hash, so it is looked up among the
	 * registered autoloaders rather than named. Only one Jetpack autoloader is
	 * active per request: the newest one among all plugins that ship it.
	 *
	 * @return callable|null The loader, or null when none is registered.
	 */
	private static function find_jetpack_loader(): ?callable {
		if ( null !== self::$jetpack ) {
			return self::$jetpack;
		}

		$functions = spl_autoload_functions();

		if ( ! is_array( $functions ) ) {
			return null;
		}

		foreach ( $functions as $function ) {
			if ( ! is_array( $function ) || ! isset( $function[0], $function[1] ) || ! is_string( $function[0] ) ) {
				continue;
			}

			if ( 'load_class' === $function[1] && 0 === strpos( $function[0], 'Automattic\\Jetpack\\Autoloader\\' ) && '\\PHP_Autoloader' === substr( $function[0], -15 ) ) {
				self::$jetpack = $function;

				return self::$jetpack;
			}
		}

		return null;
	}
}
