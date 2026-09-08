<?php
/**
 * Update checker for the plugin.
 *
 * This plugin is not distributed through WordPress.org, so updates are pulled
 * from its GitHub releases via Plugin Update Checker (PUC). Publishing a
 * release whose tag carries a version higher than the `Version:` header in
 * `meta-mcp.php` makes the update appear on Plugins → Installed Plugins, and
 * `Update URI` points at the same repository so nothing from WordPress.org can
 * claim the slug.
 *
 * If a release has a zip attached as an asset, that zip is installed;
 * otherwise PUC falls back to GitHub's generated source archive. Attaching the
 * zip built by `npm run plugin-zip` is preferable, since the generated archive
 * carries development files that do not belong on a live site.
 *
 * The repository is public, so no authentication is involved: sites check for
 * updates against the anonymous GitHub API and need no configuration at all.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Updater
 */
final class Updater {

	/**
	 * Repository the updates are pulled from.
	 */
	private const REPOSITORY_URL = 'https://github.com/jyothisjoy/meta-mcp/';

	/**
	 * Directory and slug the plugin is installed under.
	 */
	private const SLUG = 'meta-mcp';

	/**
	 * Relative path to the vendored PUC bootstrap file.
	 */
	private const LIBRARY_FILE = 'vendor/plugin-update-checker/plugin-update-checker.php';

	/**
	 * Whether the update checker has already been built.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers the update checker.
	 *
	 * Skipped entirely on front-end requests: update checks only ever run in
	 * the admin, during cron, or under WP-CLI, so there is no reason to load
	 * the library while serving MCP or page requests.
	 *
	 * Define `META_MCP_DISABLE_UPDATE_CHECKER` as true in `wp-config.php` to
	 * turn updates off on a given site, which sites under managed deployment
	 * may want.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 *
	 * @return void
	 */
	public static function register( string $plugin_file ): void {
		if ( self::$booted ) {
			return;
		}

		if ( defined( 'META_MCP_DISABLE_UPDATE_CHECKER' ) && META_MCP_DISABLE_UPDATE_CHECKER ) {
			return;
		}

		$is_cli = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! is_admin() && ! wp_doing_cron() && ! $is_cli ) {
			return;
		}

		$library = META_MCP_DIR . self::LIBRARY_FILE;

		if ( ! is_readable( $library ) ) {
			return;
		}

		require_once $library;

		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		self::$booted = true;

		$update_checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			$plugin_file,
			self::SLUG
		);

		if ( ! method_exists( $update_checker, 'getVcsApi' ) ) {
			return;
		}

		$api = $update_checker->getVcsApi();

		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			// Install a zip attached to the release when there is one, and fall
			// back to GitHub's generated source archive when there is not.
			$api->enableReleaseAssets();
		}
	}
}
