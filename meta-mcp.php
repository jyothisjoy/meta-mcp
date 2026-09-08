<?php
/**
 * Meta MCP
 *
 * A fork of the WordPress MCP Adapter (0.6.1), extended with abilities for
 * reading, editing and publishing content of any post type, building and
 * rearranging navigation menus, editing Elementor layouts, and managing
 * Polylang and Polylang Pro translations.
 *
 * Because this carries local changes, it deliberately does not share a slug with
 * the upstream plugin, and its `Update URI` points at its own GitHub repository,
 * so no update from WordPress.org can overwrite it. Updates are pulled from
 * releases on that repository; see `includes/Updater.php`.
 *
 * @package     meta-mcp
 * @author      Jyothis Joy
 * @copyright   2026 Jyothis Joy
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Meta MCP
 * Description:       Model Context Protocol server for WordPress. Exposes abilities as MCP tools, and adds content, navigation menu, Elementor and Polylang editing and publishing tools.
 * Version:           1.3.0
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Jyothis Joy
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       meta-mcp
 * Update URI:        https://github.com/jyothisjoy/meta-mcp
 */

declare (strict_types = 1);

namespace WP\MCP;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * Shortcut constant to the path of this file.
	 */
	define( 'META_MCP_DIR', plugin_dir_path( __FILE__ ) );

	/**
	 * Version of the plugin.
	 */
	define( 'META_MCP_VERSION', '1.3.0' );

	/**
	 * Version of the upstream MCP Adapter this fork is based on.
	 */
	define( 'META_MCP_UPSTREAM_VERSION', '0.6.1' );
}

constants();

// Updates come from GitHub releases rather than WordPress.org. Registered
// before the autoloader check so that a broken or missing `vendor` directory,
// which stops the plugin from booting, can still be repaired by an update.
require_once __DIR__ . '/includes/Updater.php';
Updater::register( __FILE__ );

require_once __DIR__ . '/includes/Autoloader.php';

// If autoloader failed, we cannot proceed.
if ( ! Autoloader::autoload() ) {
	return;
}

// Classes added on top of the upstream plugin are not in the generated Composer
// classmap, so register a PSR-4 fallback for them before the plugin boots.
require_once __DIR__ . '/includes/Abilities/Support/ExtensionAutoloader.php';
Abilities\Support\ExtensionAutoloader::register();

// Load the plugin.
if ( class_exists( Plugin::class ) ) {
	Plugin::instance();

	// Content, navigation menu, Elementor and Polylang abilities, exposed
	// through their own MCP server at /wp-json/mcp/wp-content.
	Abilities\ContentAbilities::bootstrap();
}
