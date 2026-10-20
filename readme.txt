=== Meta MCP ===
Contributors:      jyothisjoy
Tags:              mcp, ai, abilities-api, model-context-protocol, menus, elementor, polylang
Requires at least: 6.9
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.5.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Model Context Protocol server for WordPress, with tools for editing and publishing content, navigation menus, Elementor layouts and Polylang translations.

== Description ==

Meta MCP bridges WordPress's Abilities API with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io) specification, so AI agents can work with a WordPress site through a standard interface. It is a fork of the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.6.1, extended with a second MCP server dedicated to content editing.

This plugin is not distributed through WordPress.org. Its `Update URI` points at [its own GitHub repository](https://github.com/jyothisjoy/meta-mcp), and updates are pulled from the releases published there, so WordPress.org can never overwrite it.

**Adapter features (from upstream):**

* **Ability-to-MCP Conversion** – Automatically converts WordPress abilities into MCP tools, resources, and prompts.
* **Multi-Server Management** – Create and manage multiple MCP servers with unique configurations.
* **Extensible Transport Layer** – Built-in HTTP and STDIO transports, plus support for custom transport protocols.
* **Flexible Error Handling** – Default WordPress-compatible error logging with support for custom, server-specific handlers.
* **Observability** – Zero-overhead metrics tracking with configurable handlers.
* **Permission Control** – Granular, configurable permission checking for all exposed functionality.

**Content editing (added in this fork):**

A second MCP server at `/wp-json/mcp/wp-content` exposes 54 first-class tools:

* **Content** – Query, read, create, update, publish, schedule and delete posts of any post type, plus taxonomy terms, custom fields, media uploads and media alt text, titles and captions.
* **Users** – List, read, create, edit and delete user accounts, with roles and profile fields. Switched off by default, and no tool ever reads or sets a password.
* **Navigation menus** – List and read the site's menus as a nested tree, create and rename them, add, edit, move and remove individual items, write a whole menu structure in one call, and assign menus to the theme's locations. Items may point at a page, post, category, tag, post type archive or a custom URL, and every target is checked before it is written.
* **Elementor** – Read a layout as a flat, id-addressable list; patch individual widgets by element id; or read and replace the whole element tree. Writes route through Elementor's document API so the generated CSS and companion metas stay consistent.
* **Polylang and Polylang Pro** – Languages, per-post language assignment, linking translations, creating a translation that carries over terms, meta and the Elementor layout, term translations, and on-demand structure sync.
* **Rank Math SEO** – Read and write per-page SEO titles, descriptions, canonicals and robots on up to 100 posts in one call, find pages whose own robots or canonical override the site defaults, and change the Titles & Meta, Sitemap and General settings.
* **Redirection** – List, create (singly or in bulk, exact or regex, 301/302/307/308 or 410), edit, enable, disable and delete redirect rules.
* **Elementor Custom Code** – List, read, create and edit Elementor Pro Custom Code snippets, including their code, location, priority and display conditions.
* **Site** – Read and change the Site Title, Tagline, homepage and other General/Reading settings from a fixed allowlist, and purge the page cache (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround) and Elementor's CSS.

Elementor, Polylang, Rank Math and Redirection are optional. When they are inactive, the relevant tools report that plainly instead of failing. The Rank Math, Redirection, Custom Code and site tools share a "Site-wide tools" switch on the settings screen.

Menu editing is gated on the same `edit_theme_options` capability WordPress uses for Appearance → Menus, and can be switched off entirely on the settings screen. On a block theme, the menu tools also report the Navigation blocks the theme uses, which are ordinary `wp_navigation` posts the content tools can already read and write.

== Installation ==

Copy the `meta-mcp` directory into `wp-content/plugins/` and activate it from the Plugins screen.

Requires WordPress 6.9 or newer (the Abilities API is included in core).

== Frequently Asked Questions ==

= Will this be overwritten by updates to the WordPress MCP Adapter? =

No. It uses its own plugin slug, so WordPress.org has nothing to match it against, and its `Update URI` header points at its own GitHub repository, which tells WordPress never to accept an update for it from WordPress.org.

= Where do updates come from, then? =

From the releases at [github.com/jyothisjoy/meta-mcp](https://github.com/jyothisjoy/meta-mcp). The plugin checks for a newer release roughly twice a day and, when it finds one, offers the update on Plugins → Installed Plugins exactly like any other plugin. There is nothing to configure.

To turn updates off on a particular site, add `define( 'META_MCP_DISABLE_UPDATE_CHECKER', true );` to its `wp-config.php`.

= Does this work with the menus under Appearance → Menus? =

Yes. Those classic menus have their own set of tools, because a menu item stores what it points at in post meta rather than in its title and content, so editing one as an ordinary post would corrupt it. Block themes that use Navigation blocks instead are reported by the same tools, and those blocks are readable and writable through the normal content tools.

= Do I need Elementor or Polylang? =

No. The content tools work on any WordPress site. The Elementor and Polylang tools activate only when those plugins are present; call the `content-integrations-status` tool to see what a given site supports.

== Changelog ==

= 1.5.0 =
* Added Rank Math abilities. `seo-get-posts` reads a post's SEO title, description, focus keyword, canonical, robots and social fields, plus what Rank Math will actually print and where it comes from; its filters find posts whose own robots or canonical override the site default. `seo-update-posts` writes those fields on up to 100 posts in one call, with a dry run, and never half-updates a post. Clearing `canonical_url` makes a page canonical to itself; clearing `robots` hands it back to the post type default. `seo-get-settings` and `seo-update-settings` read and change the Titles & Meta, Sitemap and General screens, refuse unknown keys so typos are caught, and save nothing if any key is refused.
* Added Redirection abilities: `redirects-list`, `redirects-create` (bulk, exact or regex, 301/302/303/307/308, 404/410, with duplicate detection and an option to retarget the existing rule), `redirects-update` and `redirects-delete`. They go through Redirection's own models, so its validation and cache flush apply.
* Added Elementor Pro Custom Code abilities: `elementor-list-custom-code`, `elementor-get-custom-code` and `elementor-set-custom-code` read and write a snippet's code, location, priority, "always load jQuery" option and display conditions. Writing code requires `unfiltered_html`, as in wp-admin.
* Added `get-site-settings` and `update-site-settings` for Site Title, Tagline, homepage and other General/Reading settings. Only a fixed list is writable (extendable with `mcp_adapter_site_settings`); the site URL, admin email, registration and default role never are.
* Added `update-media` to set alt text, title, caption and description on existing media, by attachment id or by file URL (resized and "-scaled" variants are resolved), up to 100 at a time.
* Added `purge-cache`, which purges LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache or SiteGround Optimizer for the whole site or specific posts, and clears Elementor's generated CSS on a full purge.
* New "Site-wide tools" switch on the settings screen (on by default) and `mcp_adapter_site_abilities_enabled` filter covering all of the above except `update-media`.
* Fixed: changing Elementor display conditions (`elementor-set-conditions`, and copying a header or footer in `polylang-create-post-translation`) deleted Elementor Pro's conditions cache. Elementor Pro never rebuilds that cache on its own, so every Elementor header, footer and conditioned Custom Code snippet stopped rendering until a template was next saved in wp-admin. The cache is now regenerated through Elementor Pro, as its own conditions editor does.
* `set-post-meta` now points `rank_math_*` keys to `seo-update-posts`, alt text to `update-media`, and Custom Code fields to `elementor-set-custom-code`, instead of a generic "protected" error. Keys a site has allowlisted with `mcp_adapter_content_writable_protected_meta` are still written directly.
* `content-integrations-status` now reports the site-wide tools, Rank Math, Redirection, Custom Code and any page cache plugin.
* Fixed a fatal error that took the whole site down when Meta MCP and the official MCP Adapter plugin were both active: both declared `WP\MCP\Autoloader`. Meta MCP's loader is now `WP\MCP\MetaMcpAutoloader`, and the two run side by side on the official plugin's adapter when it is newer.
* Fixed lost MCP sessions on sites running Rank Math. Rank Math bundles adapter 0.5.0 behind a plain Composer autoloader that jumps ahead of Meta MCP's, so the handlers, transport and session storage of every MCP server on the site came from 0.5.0, which lacks 0.6.0's fix for concurrent session writes. When two clients connected at once (for example a content server and an Elementor server for the same site starting together), about half of their sessions were dropped with "Session not found". A new guard keeps `WP\MCP` classes on the newest bundled copy chosen by the Jetpack autoloader. It only touches that namespace, and can be switched off with `META_MCP_DISABLE_ADAPTER_GUARD` or the `mcp_adapter_prefer_newest_adapter` filter. Elementor's, Rank Math's and WooCommerce's own MCP servers were tested against the result and keep working.

= 1.4.1 =
* Added `elementor-get-conditions` and `elementor-set-conditions`, which read and write an Elementor Pro Theme Builder template's display conditions. Elementor picks the header, footer or archive template for a request purely from these, so a template with none is never rendered and whichever template does match takes its place.
* On a multilingual site that is what leaves a translated page wearing the source language's header, with every link in its navigation pointing back at the source language. There was no way to repair it through MCP: `MetaSupport` blocks every `_elementor*` key, and the one code path that did copy conditions also overwrote the whole translated layout.
* `elementor-set-conditions` takes `copy_from_post_id` to lift the conditions off another template, rewriting any post or term ids they name to the target's language and reporting the ones with no translation in `untranslated_ids`. A condition naming a specific post, such as `include/singular/page/12`, would otherwise be copied onto a translation it can never match.

= 1.4.0 =
* Added user abilities: list users, get user, create user, update user and delete user, covering roles and every profile field.
* The user tools are **off by default** and have their own switch on the settings screen, alongside a `mcp_adapter_user_abilities_enabled` filter. Managing accounts is a different kind of power from editing content, so a site opts into it rather than inheriting it from an update.
* No ability reads or sets a password, and `user_pass` and `user_activation_key` never appear in a response. A new account gets a random password that is discarded; its owner reaches it through a password-set link emailed to them, either with `notify` on create or `send_password_reset` on update. Neither is sent unless it is asked for.
* Every call is checked against the real WordPress capability for the specific user being touched, and the things core's own user screens refuse are refused here too: deleting your own account, changing your own role, and an ordinary administrator modifying a network super admin.
* `delete-user` requires an explicit `confirm`, takes a `reassign` target for the deleted user's content, and says plainly that on multisite the account is only removed from the current site.
* `content-integrations-status` now reports whether the user tools are enabled, which roles exist, the site's default role, and what the current user may do with accounts.

= 1.3.1 =
* Fixed copied Elementor headers and footers losing their template type and display conditions. `copy_document()` wrote both before saving the elements, but Elementor's document API rewrites `_elementor_template_type` from the document it just saved, and Elementor Pro's theme documents drop conditions that are not part of the save payload. Both are now written afterwards.
* This is what made a translated header or footer inert: it was stored as a plain document with no conditions, so Elementor never rendered it, while the language it belonged to stopped falling back to the source template — leaving that language with no header at all.

= 1.3.0 =
* Added automatic updates from the plugin's GitHub releases, using Plugin Update Checker 5.7. Updates appear on Plugins → Installed Plugins like any other plugin, and nothing needs configuring on the site.
* `Update URI` now points at the plugin's GitHub repository rather than `false`. WordPress.org still cannot claim the slug or overwrite the plugin.
* Update checks are skipped on front-end requests, and can be turned off entirely with `define( 'META_MCP_DISABLE_UPDATE_CHECKER', true );`.

= 1.2.0 =
* Added navigation menu abilities: list menus, get menu, create, update and delete a menu, add, update and delete menu items, set a whole menu structure from a nested tree, list theme menu locations and assign menus to them.
* Menu targets (pages, posts, terms, archives and custom URLs) are validated before anything is written, and a bulk structure write is refused in full rather than leaving a half-built menu behind.
* Menu writing requires the `edit_theme_options` capability and can be turned off on the settings screen, or with the `mcp_adapter_menu_abilities_enabled` filter.
* `content-integrations-status` now reports the menu count, the theme's menu locations and their assignments, whether the theme is a block theme, and any Navigation block menus.
* Polylang sites: menu locations can be assigned per language, and the per-language assignments are reported alongside the site-wide ones.

= 1.0.0 =
* Forked from WordPress MCP Adapter 0.6.1 under its own plugin identity, with updates from WordPress.org disabled.
* Added a content MCP server at `/wp-json/mcp/wp-content` with 23 tools.
* Added content abilities: list post types, list posts, get, create, update, set status, delete, set terms, set meta, upload media, integrations status.
* Added Elementor abilities: get structure, get data, set data, update element, list templates.
* Added Polylang abilities: list languages, get post translations, set post language, link translations, create translation, set term language, sync to translations.
