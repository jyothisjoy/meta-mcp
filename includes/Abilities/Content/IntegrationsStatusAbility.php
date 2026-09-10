<?php
/**
 * Ability for reporting which content integrations are available.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Content;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\MenuSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP\MCP\Abilities\Support\Settings;
use WP\MCP\Abilities\Support\UserSupport;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Integrations Status - Reports what this site supports before anything is attempted.
 *
 * Without this, a client discovers that Elementor or Polylang is missing only
 * by calling an ability and reading the failure. One cheap call up front tells
 * it which half of the toolset is actually usable here.
 */
final class IntegrationsStatusAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'content-integrations-status',
			array(
				'label'               => 'Content Integrations Status',
				'description'         => 'Report what this WordPress site supports: whether Elementor, Elementor Pro, Polylang and Polylang Pro are active and at which versions, whether MCP content writing is enabled, whether the user abilities are switched on and which roles exist, what navigation menus and theme menu locations exist, and what the current user is allowed to do. Call this first to find out which content abilities will work here.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'plugin_version' => array( 'type' => 'string' ),
						'wordpress'    => array( 'type' => 'object' ),
						'menus'        => array( 'type' => 'object' ),
						'users'        => array( 'type' => 'object' ),
						'elementor'    => array( 'type' => 'object' ),
						'polylang'     => array( 'type' => 'object' ),
						'permissions'  => array( 'type' => 'object' ),
						'writes_enabled' => array( 'type' => 'boolean' ),
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
	 * @param array $input Input parameters (unused).
	 *
	 * @return array<string, mixed> The site capabilities report.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the ability callback.
	public static function execute( $input = array() ): array {
		$user = wp_get_current_user();

		return array(
			'success'        => true,
			'plugin_version' => defined( 'META_MCP_VERSION' ) ? (string) constant( 'META_MCP_VERSION' ) : 'unknown',
			'writes_enabled' => ! is_wp_error( ContentSupport::require_writes_enabled() ),
			'wordpress'      => array(
				'version'    => get_bloginfo( 'version' ),
				'site_name'  => get_bloginfo( 'name' ),
				'site_url'   => home_url(),
				'post_types' => ContentSupport::allowed_post_types(),
			),
			'menus'          => array(
				'menu_count'         => count( MenuSupport::all_menus() ),
				'theme_locations'    => MenuSupport::registered_locations(),
				'assigned_locations' => MenuSupport::stored_locations(),
				'block_theme'        => MenuSupport::is_block_theme(),
				'navigation_posts'   => MenuSupport::navigation_posts(),
				'can_manage'         => current_user_can( MenuSupport::MANAGE_CAPABILITY ),
			),
			'users'          => array(
				'enabled'        => Settings::users_enabled(),
				'roles'          => UserSupport::role_slugs(),
				'default_role'   => (string) get_option( 'default_role' ),
				'can_list'       => current_user_can( 'list_users' ),
				'can_create'     => current_user_can( 'create_users' ),
				'can_promote'    => current_user_can( 'promote_users' ),
				'can_delete'     => current_user_can( 'delete_users' ),
				'multisite'      => is_multisite(),
				'password_note'  => 'No ability reads or sets a password. A new or locked-out account is reached by emailing its owner a link to set their own.',
			),
			'elementor'      => array(
				'active'               => ElementorSupport::is_active(),
				'pro_active'           => ElementorSupport::is_pro_active(),
				'version'              => ElementorSupport::version(),
				'enabled_post_types'   => ElementorSupport::is_active() ? ElementorSupport::supported_post_types() : array(),
				'library_available'    => post_type_exists( 'elementor_library' ),
			),
			'polylang'       => array(
				'active'            => PolylangSupport::is_active(),
				'pro_active'        => PolylangSupport::is_pro(),
				'version'           => PolylangSupport::version(),
				'languages'         => PolylangSupport::language_slugs(),
				'default_language'  => function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '',
				'synchronised_fields' => PolylangSupport::sync_settings(),
			),
			'permissions'    => array(
				'user_id'         => (int) $user->ID,
				'user_login'      => $user->user_login,
				'roles'           => array_values( (array) $user->roles ),
				'can_edit_posts'  => current_user_can( 'edit_posts' ),
				'can_publish'     => current_user_can( 'publish_posts' ),
				'can_edit_pages'  => current_user_can( 'edit_pages' ),
				'can_upload'      => current_user_can( 'upload_files' ),
				'can_manage_terms' => current_user_can( 'manage_categories' ),
				'can_manage_menus' => current_user_can( MenuSupport::MANAGE_CAPABILITY ),
				'can_manage_users' => current_user_can( 'edit_users' ),
			),
		);
	}
}
