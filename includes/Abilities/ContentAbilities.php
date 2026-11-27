<?php
/**
 * Bootstrap for the content, menu, Elementor and Polylang abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP\MCP\Abilities\Comments\DeleteCommentAbility;
use WP\MCP\Abilities\Comments\ListCommentsAbility;
use WP\MCP\Abilities\Comments\ReplyCommentAbility;
use WP\MCP\Abilities\Comments\UpdateCommentAbility;
use WP\MCP\Abilities\Content\CreatePostAbility;
use WP\MCP\Abilities\Content\DeletePostAbility;
use WP\MCP\Abilities\Content\FindTextAbility;
use WP\MCP\Abilities\Content\GetPostAbility;
use WP\MCP\Abilities\Content\IntegrationsStatusAbility;
use WP\MCP\Abilities\Content\ListMediaAbility;
use WP\MCP\Abilities\Content\ListPostsAbility;
use WP\MCP\Abilities\Content\ListPostTypesAbility;
use WP\MCP\Abilities\Content\ReplaceMediaAbility;
use WP\MCP\Abilities\Content\ReplaceTextAbility;
use WP\MCP\Abilities\Content\ResolveUrlAbility;
use WP\MCP\Abilities\Content\SetPostMetaAbility;
use WP\MCP\Abilities\Content\SetPostStatusAbility;
use WP\MCP\Abilities\Content\SetPostTermsAbility;
use WP\MCP\Abilities\Content\UpdateMediaAbility;
use WP\MCP\Abilities\Content\UpdatePostAbility;
use WP\MCP\Abilities\Content\UploadMediaAbility;
use WP\MCP\Abilities\Elementor\ElementorGetKitAbility;
use WP\MCP\Abilities\Elementor\ElementorUpdateKitAbility;
use WP\MCP\Abilities\Elementor\GetCustomCodeAbility;
use WP\MCP\Abilities\Elementor\GetElementorConditionsAbility;
use WP\MCP\Abilities\Elementor\GetElementorDataAbility;
use WP\MCP\Abilities\Elementor\GetElementorStructureAbility;
use WP\MCP\Abilities\Elementor\ListCustomCodeAbility;
use WP\MCP\Abilities\Elementor\ListElementorTemplatesAbility;
use WP\MCP\Abilities\Elementor\SetCustomCodeAbility;
use WP\MCP\Abilities\Elementor\SetElementorConditionsAbility;
use WP\MCP\Abilities\Elementor\SetElementorDataAbility;
use WP\MCP\Abilities\Elementor\InsertElementorElementAbility;
use WP\MCP\Abilities\Elementor\UpdateElementorElementAbility;
use WP\MCP\Abilities\Forms\GetFormSubmissionAbility;
use WP\MCP\Abilities\Forms\ListFormSubmissionsAbility;
use WP\MCP\Abilities\Menus\AddMenuItemAbility;
use WP\MCP\Abilities\Menus\CreateMenuAbility;
use WP\MCP\Abilities\Menus\DeleteMenuAbility;
use WP\MCP\Abilities\Menus\DeleteMenuItemAbility;
use WP\MCP\Abilities\Menus\GetMenuAbility;
use WP\MCP\Abilities\Menus\ListMenuLocationsAbility;
use WP\MCP\Abilities\Menus\ListMenusAbility;
use WP\MCP\Abilities\Menus\SetMenuItemsAbility;
use WP\MCP\Abilities\Menus\SetMenuLocationsAbility;
use WP\MCP\Abilities\Menus\UpdateMenuAbility;
use WP\MCP\Abilities\Menus\UpdateMenuItemAbility;
use WP\MCP\Abilities\Polylang\CreatePostTranslationAbility;
use WP\MCP\Abilities\Polylang\GetPostTranslationsAbility;
use WP\MCP\Abilities\Polylang\LinkPostTranslationsAbility;
use WP\MCP\Abilities\Polylang\ListLanguagesAbility;
use WP\MCP\Abilities\Polylang\ListStringTranslationsAbility;
use WP\MCP\Abilities\Polylang\SetStringTranslationsAbility;
use WP\MCP\Abilities\Polylang\SetPostLanguageAbility;
use WP\MCP\Abilities\Polylang\SetTermLanguageAbility;
use WP\MCP\Abilities\Polylang\SyncPostTranslationAbility;
use WP\MCP\Abilities\Redirects\CreateRedirectsAbility;
use WP\MCP\Abilities\Redirects\DeleteRedirectsAbility;
use WP\MCP\Abilities\Redirects\ListRedirectsAbility;
use WP\MCP\Abilities\Redirects\UpdateRedirectAbility;
use WP\MCP\Abilities\Revisions\GetRevisionAbility;
use WP\MCP\Abilities\Revisions\ListRevisionsAbility;
use WP\MCP\Abilities\Revisions\RestoreRevisionAbility;
use WP\MCP\Abilities\Seo\SeoGetPostsAbility;
use WP\MCP\Abilities\Seo\SeoGetSettingsAbility;
use WP\MCP\Abilities\Seo\SeoRegenerateSitemapAbility;
use WP\MCP\Abilities\Seo\SeoUpdatePostsAbility;
use WP\MCP\Abilities\Seo\SeoUpdateSettingsAbility;
use WP\MCP\Abilities\Site\GetSiteSettingsAbility;
use WP\MCP\Abilities\Site\LitespeedGetSettingsAbility;
use WP\MCP\Abilities\Site\LitespeedUpdateSettingsAbility;
use WP\MCP\Abilities\Site\SiteHealthAbility;
use WP\MCP\Abilities\Site\PurgeCacheAbility;
use WP\MCP\Abilities\Site\UpdateSiteSettingsAbility;
use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\LitespeedSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP\MCP\Abilities\Support\SeoBackend;
use WP\MCP\Abilities\Support\RedirectsBackend;
use WP\MCP\Abilities\Support\Settings;
use WP\MCP\Abilities\Terms\CreateTermAbility;
use WP\MCP\Abilities\Terms\DeleteTermAbility;
use WP\MCP\Abilities\Terms\GetTermAbility;
use WP\MCP\Abilities\Terms\ListTermsAbility;
use WP\MCP\Abilities\Terms\UpdateTermAbility;
use WP\MCP\Abilities\Users\CreateUserAbility;
use WP\MCP\Abilities\Users\DeleteUserAbility;
use WP\MCP\Abilities\Users\GetUserAbility;
use WP\MCP\Abilities\Users\ListUsersAbility;
use WP\MCP\Abilities\Users\UpdateUserAbility;
use WP\MCP\Servers\ContentServerFactory;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - ContentAbilities
 *
 * Registers the abilities that let an MCP client read, write and publish
 * WordPress content, build and rearrange navigation menus, edit Elementor
 * layouts, and manage Polylang translations.
 *
 * The Elementor and Polylang abilities are registered even when those plugins
 * are inactive, so a client sees a clear "Elementor is not active" message
 * rather than a tool that silently does not exist. Sites that would rather hide
 * them entirely can use the `mcp_adapter_register_inactive_integrations` filter.
 */
final class ContentAbilities {

	/**
	 * The content abilities, grouped by the integration they belong to.
	 */
	private const ABILITY_CLASSES = array(
		'core'      => array(
			IntegrationsStatusAbility::class,
			ListPostTypesAbility::class,
			ListPostsAbility::class,
			GetPostAbility::class,
			ResolveUrlAbility::class,
			FindTextAbility::class,
			ReplaceTextAbility::class,
			CreatePostAbility::class,
			UpdatePostAbility::class,
			SetPostStatusAbility::class,
			DeletePostAbility::class,
			SetPostTermsAbility::class,
			SetPostMetaAbility::class,
			UploadMediaAbility::class,
			ListMediaAbility::class,
			UpdateMediaAbility::class,
			ReplaceMediaAbility::class,
			ListTermsAbility::class,
			GetTermAbility::class,
			CreateTermAbility::class,
			UpdateTermAbility::class,
			DeleteTermAbility::class,
			ListRevisionsAbility::class,
			GetRevisionAbility::class,
			RestoreRevisionAbility::class,
		),
		'comments'  => array(
			ListCommentsAbility::class,
			UpdateCommentAbility::class,
			ReplyCommentAbility::class,
			DeleteCommentAbility::class,
		),
		'forms'     => array(
			ListFormSubmissionsAbility::class,
			GetFormSubmissionAbility::class,
		),
		'menus'     => array(
			ListMenusAbility::class,
			GetMenuAbility::class,
			CreateMenuAbility::class,
			UpdateMenuAbility::class,
			DeleteMenuAbility::class,
			AddMenuItemAbility::class,
			UpdateMenuItemAbility::class,
			DeleteMenuItemAbility::class,
			SetMenuItemsAbility::class,
			ListMenuLocationsAbility::class,
			SetMenuLocationsAbility::class,
		),
		'users'     => array(
			ListUsersAbility::class,
			GetUserAbility::class,
			CreateUserAbility::class,
			UpdateUserAbility::class,
			DeleteUserAbility::class,
		),
		'elementor' => array(
			GetElementorStructureAbility::class,
			GetElementorDataAbility::class,
			SetElementorDataAbility::class,
			UpdateElementorElementAbility::class,
			InsertElementorElementAbility::class,
			ListElementorTemplatesAbility::class,
			GetElementorConditionsAbility::class,
			SetElementorConditionsAbility::class,
			ElementorGetKitAbility::class,
			ElementorUpdateKitAbility::class,
		),
		'site'        => array(
			GetSiteSettingsAbility::class,
			UpdateSiteSettingsAbility::class,
			PurgeCacheAbility::class,
			SiteHealthAbility::class,
		),
		'litespeed'   => array(
			LitespeedGetSettingsAbility::class,
			LitespeedUpdateSettingsAbility::class,
		),
		'seo'         => array(
			SeoGetPostsAbility::class,
			SeoUpdatePostsAbility::class,
			SeoGetSettingsAbility::class,
			SeoUpdateSettingsAbility::class,
			SeoRegenerateSitemapAbility::class,
		),
		'redirects'   => array(
			ListRedirectsAbility::class,
			CreateRedirectsAbility::class,
			UpdateRedirectAbility::class,
			DeleteRedirectsAbility::class,
		),
		'custom_code' => array(
			ListCustomCodeAbility::class,
			GetCustomCodeAbility::class,
			SetCustomCodeAbility::class,
		),
		'polylang'  => array(
			ListLanguagesAbility::class,
			GetPostTranslationsAbility::class,
			SetPostLanguageAbility::class,
			LinkPostTranslationsAbility::class,
			CreatePostTranslationAbility::class,
			SetTermLanguageAbility::class,
			SyncPostTranslationAbility::class,
			ListStringTranslationsAbility::class,
			SetStringTranslationsAbility::class,
		),
	);

	/**
	 * Hooks the abilities and the content server into WordPress.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		// The hooks are registered unconditionally, because this runs while the
		// plugin file loads and nothing else has had a chance to add a filter
		// yet. Whether to actually register anything is decided when the hooks
		// fire, by which time every plugin is loaded.
		if ( is_admin() ) {
			\WP\MCP\Admin\SettingsPage::bootstrap();
		}

		add_action( 'wp_abilities_api_categories_init', array( AbilityRegistrar::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		// Smaller tool results on the content server, whichever adapter copy answers.
		\WP\MCP\Abilities\Support\ResponseCompactor::register();

		// Priority 20 so the default server is registered first and the route
		// order stays predictable.
		add_action( 'mcp_adapter_init', array( ContentServerFactory::class, 'create' ), 20 );
	}

	/**
	 * Registers every enabled ability.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		foreach ( self::enabled_groups() as $group ) {
			foreach ( self::ABILITY_CLASSES[ $group ] as $ability_class ) {
				$ability_class::register();
			}
		}
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * The Abilities API registry initializes lazily: `wp_abilities_api_init`
	 * fires the first time something reads the registry, which may not have
	 * happened yet when a server factory runs. Touching `wp_get_abilities()`
	 * here forces that initialization, so the names are always complete
	 * regardless of which server factory runs first.
	 *
	 * @return string[] Fully qualified ability names.
	 */
	public static function ability_names(): array {
		if ( function_exists( 'wp_get_abilities' ) ) {
			wp_get_abilities();
		}

		return AbilityRegistrar::registered();
	}

	/**
	 * Whether the navigation menu abilities are registered on this site.
	 *
	 * Menus are a core feature rather than an integration, so there is nothing
	 * to detect: the only question is whether the site wants its navigation
	 * reachable from MCP at all. That is a settings choice, because a menu is
	 * site-wide structure and a site may reasonably expose its content while
	 * keeping its navigation off limits.
	 *
	 * Whether the `nav_menu` taxonomy actually exists is deliberately checked
	 * when an ability runs rather than here. Abilities can be registered before
	 * `init` has registered the core taxonomies, and answering "no menus here"
	 * because of registration order would hide the tools on a perfectly ordinary
	 * site.
	 *
	 * @return bool True when they should be registered.
	 */
	public static function menus_enabled(): bool {
		/**
		 * Filters whether the MCP navigation menu abilities are registered.
		 *
		 * Defaults to the value set on the Meta MCP settings screen.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Whether to register the menu abilities.
		 */
		return (bool) apply_filters( 'mcp_adapter_menu_abilities_enabled', Settings::menus_enabled() );
	}

	/**
	 * Whether the user abilities are registered on this site.
	 *
	 * Off by default, unlike every other group. The rest of this plugin edits
	 * content; these tools edit the people who own it, and can lock someone out
	 * of their own site. That is worth an explicit decision rather than
	 * something a site inherits by installing an update.
	 *
	 * @return bool True when they should be registered.
	 */
	private static function users_enabled(): bool {
		/**
		 * Filters whether the MCP user abilities are registered.
		 *
		 * Defaults to the value set on the Meta MCP settings screen.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $enabled Whether to register the user abilities.
		 */
		return (bool) apply_filters( 'mcp_adapter_user_abilities_enabled', Settings::users_enabled() );
	}

	/**
	 * Whether the comment moderation abilities are registered on this site.
	 *
	 * @return bool True when they should be registered.
	 */
	private static function comments_enabled(): bool {
		/**
		 * Filters whether the MCP comment abilities are registered.
		 *
		 * Defaults to the value set on the Meta MCP settings screen.
		 *
		 * @since 1.8.0
		 *
		 * @param bool $enabled Whether to register the comment abilities.
		 */
		return (bool) apply_filters( 'mcp_adapter_comment_abilities_enabled', Settings::comments_enabled() );
	}

	/**
	 * Whether the form submission abilities are registered on this site.
	 *
	 * Off by default: submissions are what visitors typed into forms, not content.
	 *
	 * @return bool True when they should be registered.
	 */
	private static function forms_enabled(): bool {
		/**
		 * Filters whether the MCP form submission abilities are registered.
		 *
		 * Defaults to the value set on the Meta MCP settings screen.
		 *
		 * @since 1.8.0
		 *
		 * @param bool $enabled Whether to register the form submission abilities.
		 */
		return (bool) apply_filters( 'mcp_adapter_form_abilities_enabled', Settings::forms_enabled() );
	}

	/**
	 * Whether the site-wide abilities are registered on this site.
	 *
	 * Covers site settings, cache purging, Rank Math, Redirection and Elementor
	 * Custom Code: everything that changes the whole site rather than one post.
	 *
	 * @return bool True when they should be registered.
	 */
	public static function site_tools_enabled(): bool {
		/**
		 * Filters whether the MCP site-wide abilities are registered.
		 *
		 * Defaults to the value set on the Meta MCP settings screen.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $enabled Whether to register the site, SEO, redirect and custom code abilities.
		 */
		return (bool) apply_filters( 'mcp_adapter_site_abilities_enabled', Settings::site_tools_enabled() );
	}

	/**
	 * Whether the content abilities are enabled on this site.
	 *
	 * @return bool True when they should be registered.
	 */
	private static function is_enabled(): bool {
		/**
		 * Filters whether the MCP content abilities are registered at all.
		 *
		 * Return false to run the adapter with only its upstream abilities.
		 *
		 * @since 0.6.1
		 *
		 * @param bool $enabled Whether to register the content abilities. Default true.
		 */
		return (bool) apply_filters( 'mcp_adapter_content_abilities_enabled', true );
	}

	/**
	 * Decides which ability groups to register on this site.
	 *
	 * @return string[] Group keys.
	 */
	private static function enabled_groups(): array {
		/**
		 * Filters whether abilities for inactive integrations are still registered.
		 *
		 * Off by default since 1.8.0: every listed tool costs a client tokens at
		 * the start of each session, and content-integrations-status already says
		 * which plugins are missing. Return true to list them anyway, so a client
		 * is told plainly that Elementor or Polylang is missing.
		 *
		 * @since 0.6.1
		 *
		 * @param bool $register Whether to register abilities for inactive plugins. Default false.
		 */
		$register_inactive = (bool) apply_filters( 'mcp_adapter_register_inactive_integrations', false );

		$groups = array( 'core' );

		if ( self::menus_enabled() ) {
			$groups[] = 'menus';
		}

		if ( self::users_enabled() ) {
			$groups[] = 'users';
		}

		if ( self::comments_enabled() ) {
			$groups[] = 'comments';
		}

		if ( self::forms_enabled() && ( $register_inactive || ElementorSupport::is_pro_active() ) ) {
			$groups[] = 'forms';
		}

		if ( $register_inactive || ElementorSupport::is_active() ) {
			$groups[] = 'elementor';
		}

		if ( $register_inactive || PolylangSupport::is_active() ) {
			$groups[] = 'polylang';
		}

		if ( self::site_tools_enabled() ) {
			$groups[] = 'site';

			if ( $register_inactive || SeoBackend::any_active() ) {
				$groups[] = 'seo';
			}

			if ( $register_inactive || RedirectsBackend::any_active() ) {
				$groups[] = 'redirects';
			}

			if ( $register_inactive || ElementorSupport::is_pro_active() ) {
				$groups[] = 'custom_code';
			}

			if ( $register_inactive || LitespeedSupport::is_active() ) {
				$groups[] = 'litespeed';
			}
		}

		return $groups;
	}
}
