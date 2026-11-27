<?php
/**
 * Ability for reading a read-only health and inventory report of the site.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Site Health - Reports versions, updates, plugins, theme, caching, cron and
 * search engine visibility in one call.
 *
 * Nothing here changes the site. It exists so an agent auditing a site does not
 * have to scrape wp-admin to learn which plugins run, which need updates, or
 * whether "Discourage search engines" was left on after a launch.
 */
final class SiteHealthAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'site-health',
			array(
				'label'               => 'Site Health',
				'description'         => 'Read-only health and inventory report: WordPress version and whether a core update is available, PHP and database versions, memory limit, debug flags, HTTPS, search engine visibility (blog_public: false means "Discourage search engines" is on and the whole site is noindexed), persistent object cache, OPcache, overdue cron events, the active theme (and parent) with update status, every installed plugin with version, active state, available update and auto-update, and the must-use plugin count. Set include_tests true to also run WordPress Site Health\'s direct tests (slower). Update availability comes from WordPress\'s last update check, so it can be up to 12 hours old. Requires "manage_options" or "view_site_health_checks".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'include_plugins' => array(
							'type'        => 'boolean',
							'description' => 'Include the plugin inventory. Default true.',
						),
						'include_tests'   => array(
							'type'        => 'boolean',
							'description' => 'Also run WordPress Site Health direct tests and return their status and label. Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'wordpress'   => array( 'type' => 'object' ),
						'server'      => array( 'type' => 'object' ),
						'site'        => array( 'type' => 'object' ),
						'cache'       => array( 'type' => 'object' ),
						'cron'        => array( 'type' => 'object' ),
						'theme'       => array( 'type' => 'object' ),
						'plugins'     => array( 'type' => 'array' ),
						'plugin_summary' => array( 'type' => 'object' ),
						'mu_plugins'  => array( 'type' => 'integer' ),
						'tests'       => array( 'type' => 'array' ),
						'warnings'    => array( 'type' => 'array' ),
						'error'       => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The report.
	 */
	public static function execute( $input = array() ): array {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'view_site_health_checks' ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" or "view_site_health_checks" capability needed for the site health report.' )
			);
		}

		$input = is_array( $input ) ? $input : array();

		self::load_admin_includes();

		$warnings = array();
		$site     = self::site();

		if ( ! $site['search_engines_allowed'] ) {
			$warnings[] = 'Search engine visibility is off (Settings → Reading → "Discourage search engines"): the whole site is noindexed.';
		}

		if ( ! $site['https'] ) {
			$warnings[] = 'The site URL is not HTTPS.';
		}

		$wordpress = self::wordpress();

		if ( ! empty( $wordpress['update_available'] ) ) {
			$warnings[] = sprintf( 'WordPress %s is available (running %s).', (string) $wordpress['update_version'], (string) $wordpress['version'] );
		}

		if ( ! empty( $wordpress['debug']['WP_DEBUG_DISPLAY'] ) && ! empty( $wordpress['debug']['WP_DEBUG'] ) ) {
			$warnings[] = 'WP_DEBUG and WP_DEBUG_DISPLAY are on: PHP notices may be printed on public pages.';
		}

		$cron = self::cron();

		if ( $cron['overdue'] > 0 ) {
			$warnings[] = sprintf( '%d cron event(s) are more than an hour overdue; WP-Cron may not be running.', $cron['overdue'] );
		}

		$result = array(
			'success'    => true,
			'wordpress'  => $wordpress,
			'server'     => self::server(),
			'site'       => $site,
			'cache'      => self::cache(),
			'cron'       => $cron,
			'theme'      => self::theme(),
			'mu_plugins' => function_exists( 'get_mu_plugins' ) ? count( get_mu_plugins() ) : 0,
		);

		if ( ContentSupport::to_bool( $input['include_plugins'] ?? null, true ) ) {
			$plugins                  = self::plugins();
			$result['plugins']        = $plugins;
			$result['plugin_summary'] = array(
				'total'             => count( $plugins ),
				'active'            => count( array_filter( $plugins, static fn( array $p ): bool => $p['active'] ) ),
				'updates_available' => count( array_filter( $plugins, static fn( array $p ): bool => '' !== $p['update_version'] ) ),
			);

			if ( $result['plugin_summary']['updates_available'] > 0 ) {
				$warnings[] = sprintf( '%d plugin update(s) available.', $result['plugin_summary']['updates_available'] );
			}
		}

		if ( ContentSupport::to_bool( $input['include_tests'] ?? null, false ) ) {
			$result['tests'] = self::tests();
		}

		$result['warnings'] = $warnings;

		return $result;
	}

	/**
	 * Loads the wp-admin helpers this report uses; they are not loaded on REST requests.
	 *
	 * @return void
	 */
	private static function load_admin_includes(): void {
		$files = array( 'plugin.php', 'update.php', 'theme.php', 'misc.php' );

		foreach ( $files as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * WordPress core facts.
	 *
	 * @return array<string, mixed> Core info.
	 */
	private static function wordpress(): array {
		global $wp_version;

		$update_version = '';
		$core           = get_site_transient( 'update_core' );

		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $offer ) {
				if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
					$update_version = (string) $offer->current;
					break;
				}
			}
		}

		return array(
			'version'          => (string) $wp_version,
			'update_available' => '' !== $update_version,
			'update_version'   => $update_version,
			'last_update_check' => is_object( $core ) && isset( $core->last_checked ) ? gmdate( 'Y-m-d H:i:s', (int) $core->last_checked ) : '',
			'multisite'        => is_multisite(),
			'locale'           => get_locale(),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'debug'            => array(
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'SCRIPT_DEBUG'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			),
			'auto_updates_core' => defined( 'WP_AUTO_UPDATE_CORE' ) ? (string) var_export( WP_AUTO_UPDATE_CORE, true ) : 'default', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Reported, not logged.
			'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
		);
	}

	/**
	 * Server facts.
	 *
	 * @return array<string, mixed> Server info.
	 */
	private static function server(): array {
		global $wpdb;

		$db_version = '';
		$db_server  = '';

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			$db_server = (string) $wpdb->db_server_info();
		}

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
			$db_version = (string) $wpdb->db_version();
		}

		return array(
			'php_version'        => PHP_VERSION,
			'database'           => false !== stripos( $db_server, 'mariadb' ) ? 'MariaDB' : 'MySQL',
			'database_version'   => $db_version,
			'database_server'    => $db_server,
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '',
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'      => (string) ini_get( 'post_max_size' ),
			'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}

	/**
	 * Site-level facts.
	 *
	 * @return array<string, mixed> Site info.
	 */
	private static function site(): array {
		$home = (string) home_url( '/' );

		return array(
			'home_url'               => $home,
			'site_url'               => (string) site_url( '/' ),
			'https'                  => 0 === strpos( $home, 'https://' ),
			'search_engines_allowed' => '0' !== (string) get_option( 'blog_public', '1' ),
			'blog_public'            => (string) get_option( 'blog_public', '1' ),
			'permalink_structure'    => (string) get_option( 'permalink_structure', '' ),
			'timezone'               => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string' ),
		);
	}

	/**
	 * Object cache and OPcache.
	 *
	 * @return array<string, mixed> Cache info.
	 */
	private static function cache(): array {
		$opcache = array( 'available' => false );

		if ( function_exists( 'opcache_get_status' ) ) {
			// opcache_get_status can be disabled by opcache.restrict_api and then warns.
			$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Restricted API emits a warning.

			if ( is_array( $status ) ) {
				$memory  = isset( $status['memory_usage'] ) && is_array( $status['memory_usage'] ) ? $status['memory_usage'] : array();
				$stats   = isset( $status['opcache_statistics'] ) && is_array( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();
				$used    = (float) ( $memory['used_memory'] ?? 0 );
				$free    = (float) ( $memory['free_memory'] ?? 0 );
				$wasted  = (float) ( $memory['wasted_memory'] ?? 0 );
				$total   = $used + $free + $wasted;
				$opcache = array(
					'available'          => true,
					'enabled'            => ! empty( $status['opcache_enabled'] ),
					'cache_full'         => ! empty( $status['cache_full'] ),
					'memory_used_pct'    => $total > 0 ? round( ( $used + $wasted ) / $total * 100, 1 ) : 0,
					'memory_total_mb'    => round( $total / 1048576, 1 ),
					'cached_scripts'     => (int) ( $stats['num_cached_scripts'] ?? 0 ),
					'hit_rate'           => isset( $stats['opcache_hit_rate'] ) ? round( (float) $stats['opcache_hit_rate'], 2 ) : 0,
					'oom_restarts'       => (int) ( $stats['oom_restarts'] ?? 0 ),
				);
			} else {
				$opcache = array(
					'available' => true,
					'enabled'   => (bool) ini_get( 'opcache.enable' ),
					'note'      => 'Status is not readable (opcache.restrict_api).',
				);
			}
		}

		return array(
			'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			'object_cache_dropin'     => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
			'advanced_cache_dropin'   => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'wp_cache_constant'       => defined( 'WP_CACHE' ) && WP_CACHE,
			'opcache'                 => $opcache,
		);
	}

	/**
	 * Cron health.
	 *
	 * @return array<string, mixed> Cron info.
	 */
	private static function cron(): array {
		$events  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$events  = is_array( $events ) ? $events : array();
		$now     = time();
		$total   = 0;
		$overdue = 0;
		$hooks   = array();

		foreach ( $events as $timestamp => $by_hook ) {
			if ( ! is_array( $by_hook ) ) {
				continue;
			}

			foreach ( $by_hook as $hook => $instances ) {
				$count  = is_array( $instances ) ? count( $instances ) : 1;
				$total += $count;

				if ( (int) $timestamp < $now - HOUR_IN_SECONDS ) {
					$overdue += $count;

					if ( count( $hooks ) < 10 ) {
						$hooks[] = (string) $hook;
					}
				}
			}
		}

		return array(
			'disabled'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'scheduled'      => $total,
			'overdue'        => $overdue,
			'overdue_hooks'  => array_values( array_unique( $hooks ) ),
		);
	}

	/**
	 * The active theme.
	 *
	 * @return array<string, mixed> Theme info.
	 */
	private static function theme(): array {
		$theme   = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$resp    = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();

		$describe = static function ( \WP_Theme $t ) use ( $resp ): array {
			$slug   = $t->get_stylesheet();
			$update = isset( $resp[ $slug ]['new_version'] ) ? (string) $resp[ $slug ]['new_version'] : '';

			return array(
				'name'           => (string) $t->get( 'Name' ),
				'slug'           => $slug,
				'version'        => (string) $t->get( 'Version' ),
				'update_version' => $update,
			);
		};

		$info               = $describe( $theme );
		$info['block_theme'] = method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false;
		$parent             = $theme->parent();
		$info['parent']     = $parent instanceof \WP_Theme ? $describe( $parent ) : null;

		return $info;
	}

	/**
	 * Installed plugins.
	 *
	 * @return array<int, array<string, mixed>> Plugin list.
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$installed   = get_plugins();
		$updates     = get_site_transient( 'update_plugins' );
		$resp        = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$auto        = (array) get_site_option( 'auto_update_plugins', array() );
		$list        = array();

		foreach ( $installed as $file => $data ) {
			$file   = (string) $file;
			$update = isset( $resp[ $file ] ) && is_object( $resp[ $file ] ) && isset( $resp[ $file ]->new_version ) ? (string) $resp[ $file ]->new_version : '';

			$list[] = array(
				'file'           => $file,
				'name'           => (string) ( $data['Name'] ?? $file ),
				'version'        => (string) ( $data['Version'] ?? '' ),
				'active'         => function_exists( 'is_plugin_active' ) ? is_plugin_active( $file ) : false,
				'network_active' => is_multisite() && function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( $file ) : false,
				'update_version' => $update,
				'auto_update'    => in_array( $file, $auto, true ),
				'requires_php'   => (string) ( $data['RequiresPHP'] ?? '' ),
				'update_uri'     => (string) ( $data['UpdateURI'] ?? '' ),
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				if ( $a['active'] !== $b['active'] ) {
					return $a['active'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $list;
	}

	/**
	 * Runs WordPress Site Health direct tests, when the class can be loaded.
	 *
	 * @return array<int, array<string, mixed>> Test results.
	 */
	private static function tests(): array {
		// Several direct tests use WP_Filesystem, plugin and update helpers that only
		// wp-admin loads; without them those tests report errors instead of results.
		foreach ( array( 'file.php', 'plugin.php', 'update.php', 'misc.php' ) as $include ) {
			if ( is_readable( ABSPATH . 'wp-admin/includes/' . $include ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $include;
			}
		}

		if ( ! class_exists( '\WP_Site_Health' ) ) {
			$path = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( '\WP_Site_Health' ) || ! method_exists( '\WP_Site_Health', 'get_tests' ) ) {
			return array(
				array(
					'test'   => 'unavailable',
					'status' => 'skipped',
					'label'  => 'WP_Site_Health could not be loaded on this request.',
				),
			);
		}

		$results = array();

		try {
			$health = \WP_Site_Health::get_instance();
			$tests  = \WP_Site_Health::get_tests();
			$direct = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();

			foreach ( $direct as $key => $test ) {
				$callback = null;

				if ( isset( $test['test'] ) && is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$callback = array( $health, 'get_test_' . $test['test'] );
				} elseif ( isset( $test['test'] ) && is_callable( $test['test'] ) ) {
					$callback = $test['test'];
				}

				if ( null === $callback ) {
					continue;
				}

				try {
					$outcome = call_user_func( $callback );
				} catch ( \Throwable $e ) {
					$results[] = array(
						'test'   => (string) $key,
						'status' => 'error',
						'label'  => $e->getMessage(),
					);
					continue;
				}

				if ( ! is_array( $outcome ) ) {
					continue;
				}

				$results[] = array(
					'test'   => (string) ( $outcome['test'] ?? $key ),
					'status' => (string) ( $outcome['status'] ?? '' ),
					'label'  => wp_strip_all_tags( (string) ( $outcome['label'] ?? '' ) ),
					'badge'  => isset( $outcome['badge']['label'] ) ? (string) $outcome['badge']['label'] : '',
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = array(
				'test'   => 'site_health',
				'status' => 'error',
				'label'  => $e->getMessage(),
			);
		}

		// Most useful first: critical, then recommended, then good.
		$order = array(
			'critical'    => 0,
			'recommended' => 1,
			'good'        => 2,
		);

		usort(
			$results,
			static fn( array $a, array $b ): int => ( $order[ $a['status'] ] ?? 3 ) <=> ( $order[ $b['status'] ] ?? 3 )
		);

		return $results;
	}
}
