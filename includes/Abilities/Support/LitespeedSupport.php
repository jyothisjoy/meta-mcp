<?php
/**
 * LiteSpeed Cache settings helpers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - LitespeedSupport
 *
 * LiteSpeed Cache stores every setting as its own option named
 * `litespeed.conf.<id>` (for example `litespeed.conf.optm-css_min`), typed:
 * switches are booleans or 0/1/2 integers, lists are arrays. This helper reads
 * them, and writes through LiteSpeed's own `Conf::update_confs()` when it is
 * available so the plugin's side effects (purging, regenerating the .htaccess
 * rules, rescheduling crawlers) happen exactly as they do on its settings screen.
 *
 * Settings holding credentials (QUIC.cloud and CDN API keys, tokens, account
 * emails) are never returned or written: their value is redacted in reads and
 * refused in writes.
 */
final class LitespeedSupport {

	/**
	 * Prefix of every LiteSpeed setting option.
	 */
	public const PREFIX = 'litespeed.conf.';

	/**
	 * Patterns that mark a setting as holding a secret or account detail.
	 */
	private const SECRET_PATTERN = '/(key|token|pass|pswd|secret|api|credential|email|auth|hash|^qc|cloudflare|_sk$|nonce)/i';

	/**
	 * Whether LiteSpeed Cache is active.
	 *
	 * @return bool True when loaded.
	 */
	public static function is_active(): bool {
		return defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' );
	}

	/**
	 * LiteSpeed Cache version.
	 *
	 * @return string Version or empty.
	 */
	public static function version(): string {
		return defined( 'LSCWP_V' ) ? (string) constant( 'LSCWP_V' ) : '';
	}

	/**
	 * Guard used by the LiteSpeed abilities.
	 *
	 * @return true|\WP_Error True when usable.
	 */
	public static function require_active() {
		if ( ! self::is_active() ) {
			return new WP_Error(
				'litespeed_not_active',
				'LiteSpeed Cache is not active on this site. Install and activate the LiteSpeed Cache plugin to use this ability.'
			);
		}

		return true;
	}

	/**
	 * Checks the capability LiteSpeed's settings screen requires.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public static function require_can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" capability needed for LiteSpeed Cache settings.' );
	}

	/**
	 * Whether a setting id holds a secret and must be hidden.
	 *
	 * @param string $id Setting id without the prefix.
	 *
	 * @return bool True when secret.
	 */
	public static function is_secret( string $id ): bool {
		return 1 === preg_match( self::SECRET_PATTERN, $id );
	}

	/**
	 * Reads every stored LiteSpeed setting.
	 *
	 * @return array<string, mixed> Values keyed by setting id (secrets included, unredacted).
	 */
	public static function all(): array {
		$typed = self::typed_options();

		if ( ! empty( $typed ) ) {
			return $typed;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only way to enumerate LiteSpeed's per-setting options.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);

		$settings = array();

		foreach ( (array) $names as $name ) {
			$name = (string) $name;
			$id   = substr( $name, strlen( self::PREFIX ) );

			if ( '' === $id || '_version' === $id ) {
				continue;
			}

			$settings[ $id ] = get_option( $name );
		}

		return $settings;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $id Setting id.
	 *
	 * @return mixed The value, or null when not stored.
	 */
	public static function get( string $id ) {
		$typed = self::typed_options();

		if ( ! empty( $typed ) ) {
			return array_key_exists( $id, $typed ) ? $typed[ $id ] : null;
		}

		$sentinel = '__mcp_missing__';
		$value    = get_option( self::PREFIX . $id, $sentinel );

		return $sentinel === $value ? null : $value;
	}

	/**
	 * LiteSpeed's own, type-cast view of its settings.
	 *
	 * The raw options lose their types on the way through the database (a false
	 * boolean comes back as ""), so LiteSpeed's in-memory copy is preferred: it
	 * is cast against the plugin's defaults. Only setting ids that are stored as
	 * options are kept, so runtime-only values never look writable.
	 *
	 * @param bool $refresh Re-read LiteSpeed's copy (after a save).
	 *
	 * @return array<string, mixed> Values keyed by setting id, or empty when unavailable.
	 */
	public static function typed_options( bool $refresh = false ): array {
		static $cache = null;

		if ( null !== $cache && ! $refresh ) {
			return $cache;
		}

		$cache   = array();
		$options = null;

		foreach ( array( '\LiteSpeed\Conf', '\LiteSpeed\Root' ) as $class ) {
			if ( null === $options && class_exists( $class ) && is_callable( array( $class, 'get_options' ) ) ) {
				try {
					$options = call_user_func( array( $class, 'get_options' ) );
				} catch ( \Throwable $e ) {
					$options = null;
				}
			}
		}

		if ( ! is_array( $options ) ) {
			return $cache;
		}

		$defaults = self::defaults();

		foreach ( $options as $id => $value ) {
			$id = (string) $id;

			if ( '' === $id || '_version' === $id || ( ! empty( $defaults ) && ! array_key_exists( $id, $defaults ) ) ) {
				continue;
			}

			$cache[ $id ] = $value;
		}

		ksort( $cache );

		return $cache;
	}

	/**
	 * LiteSpeed's default option values, which define each setting's type.
	 *
	 * @return array<string, mixed> Defaults keyed by id, or empty when unreadable.
	 */
	private static function defaults(): array {
		if ( ! class_exists( '\LiteSpeed\Base' ) ) {
			return array();
		}

		try {
			$reflection = new \ReflectionClass( '\LiteSpeed\Base' );

			if ( $reflection->hasProperty( '_default_options' ) ) {
				$property = $reflection->getProperty( '_default_options' );
				$property->setAccessible( true );
				$value = $property->isStatic() ? $property->getValue() : null;

				return is_array( $value ) ? $value : array();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return array();
	}

	/**
	 * Reads a setting straight from the database, cast to a given type.
	 *
	 * Used after a save, when LiteSpeed's in-memory copy may still hold the old value.
	 *
	 * @param string $id   Setting id.
	 * @param string $type bool, int, string or array.
	 *
	 * @return mixed The value.
	 */
	public static function stored( string $id, string $type ) {
		wp_cache_delete( self::PREFIX . $id, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$raw = get_option( self::PREFIX . $id );

		switch ( $type ) {
			case 'bool':
				return (bool) $raw;
			case 'int':
				return (int) $raw;
			case 'array':
				return is_array( $raw ) ? array_values( $raw ) : ( '' === $raw || false === $raw ? array() : (array) $raw );
		}

		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * The type a stored value has, for reporting and coercion.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return string bool, int, string or array.
	 */
	public static function type_of( $value ): string {
		if ( is_bool( $value ) ) {
			return 'bool';
		}

		if ( is_int( $value ) ) {
			return 'int';
		}

		if ( is_array( $value ) ) {
			return 'array';
		}

		return 'string';
	}

	/**
	 * Coerces an incoming value to the stored type.
	 *
	 * @param mixed $value  Incoming value.
	 * @param mixed $before Stored value.
	 *
	 * @return mixed|\WP_Error The coerced value.
	 */
	public static function coerce( $value, $before ) {
		if ( null === $value ) {
			return new WP_Error( 'invalid_value', 'null is not accepted; pass the value the setting should hold.' );
		}

		switch ( self::type_of( $before ) ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}

				if ( in_array( $value, array( 0, 1, '0', '1', 'true', 'false', 'on', 'off' ), true ) ) {
					return in_array( $value, array( 1, '1', 'true', 'on' ), true );
				}

				return new WP_Error( 'invalid_value', 'Expected a boolean.' );

			case 'int':
				if ( is_bool( $value ) ) {
					return $value ? 1 : 0;
				}

				if ( is_numeric( $value ) && (string) (int) $value === (string) $value ) {
					return (int) $value;
				}

				if ( is_int( $value ) ) {
					return $value;
				}

				return new WP_Error( 'invalid_value', 'Expected an integer (LiteSpeed switches use 0 = off, 1 = on, 2 = a third mode where offered).' );

			case 'array':
				if ( is_string( $value ) ) {
					$value = preg_split( '/\r\n|\r|\n/', $value );
				}

				if ( ! is_array( $value ) ) {
					return new WP_Error( 'invalid_value', 'Expected a list of strings, or a newline-separated string.' );
				}

				$clean = array();

				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) ) {
						return new WP_Error( 'invalid_value', 'List items must be strings.' );
					}

					$item = trim( (string) $item );

					if ( '' !== $item ) {
						$clean[] = current_user_can( 'unfiltered_html' ) ? $item : wp_strip_all_tags( $item );
					}
				}

				return $clean;
		}

		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'invalid_value', 'Expected a string.' );
		}

		$value = (string) $value;

		return current_user_can( 'unfiltered_html' ) ? $value : wp_strip_all_tags( $value );
	}

	/**
	 * Saves validated values.
	 *
	 * @param array<string, mixed> $values Coerced values keyed by setting id.
	 *
	 * @return string How the values were saved: "litespeed" or "options".
	 */
	public static function save( array $values ): string {
		$method = 'options';

		if ( class_exists( '\LiteSpeed\Conf' ) && is_callable( array( '\LiteSpeed\Conf', 'cls' ) ) ) {
			try {
				$conf = \LiteSpeed\Conf::cls();

				if ( is_object( $conf ) && method_exists( $conf, 'update_confs' ) ) {
					$conf->update_confs( $values );
					$method = 'litespeed';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Whatever update_confs did, make sure each value really landed: it skips
		// ids it does not know about in some versions.
		$typed = 'litespeed' === $method ? self::typed_options( true ) : array();

		foreach ( $values as $id => $value ) {
			$id = (string) $id;

			if ( ! array_key_exists( $id, $typed ) || $typed[ $id ] !== $value ) {
				update_option( self::PREFIX . $id, $value );
				$method = 'litespeed' === $method ? 'litespeed+options' : $method;
			}
		}

		self::typed_options( true );

		return $method;
	}
}
