=== Meta MCP ===
Contributors:      jyothisjoy
Tags:              mcp, ai, abilities-api, model-context-protocol, menus, elementor, polylang
Requires at least: 6.9
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.2.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Model Context Protocol server for WordPress, with tools for editing and publishing content, navigation menus, Elementor layouts and Polylang translations.

== Description ==

Meta MCP bridges WordPress's Abilities API with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io) specification, so AI agents can work with a WordPress site through a standard interface. It is a fork of the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.6.1, extended with a second MCP server dedicated to content editing.

This plugin is maintained privately and declares `Update URI: false`, so it is never updated from WordPress.org.

**Adapter features (from upstream):**

* **Ability-to-MCP Conversion** – Automatically converts WordPress abilities into MCP tools, resources, and prompts.
* **Multi-Server Management** – Create and manage multiple MCP servers with unique configurations.
* **Extensible Transport Layer** – Built-in HTTP and STDIO transports, plus support for custom transport protocols.
* **Flexible Error Handling** – Default WordPress-compatible error logging with support for custom, server-specific handlers.
* **Observability** – Zero-overhead metrics tracking with configurable handlers.
* **Permission Control** – Granular, configurable permission checking for all exposed functionality.

**Content editing (added in this fork):**

A second MCP server at `/wp-json/mcp/wp-content` exposes 34 first-class tools:

* **Content** – Query, read, create, update, publish, schedule and delete posts of any post type, plus taxonomy terms, custom fields and media uploads.
* **Navigation menus** – List and read the site's menus as a nested tree, create and rename them, add, edit, move and remove individual items, write a whole menu structure in one call, and assign menus to the theme's locations. Items may point at a page, post, category, tag, post type archive or a custom URL, and every target is checked before it is written.
* **Elementor** – Read a layout as a flat, id-addressable list; patch individual widgets by element id; or read and replace the whole element tree. Writes route through Elementor's document API so the generated CSS and companion metas stay consistent.
* **Polylang and Polylang Pro** – Languages, per-post language assignment, linking translations, creating a translation that carries over terms, meta and the Elementor layout, term translations, and on-demand structure sync.

Elementor and Polylang are optional. When they are inactive, the relevant tools report that plainly instead of failing.

Menu editing is gated on the same `edit_theme_options` capability WordPress uses for Appearance → Menus, and can be switched off entirely on the settings screen. On a block theme, the menu tools also report the Navigation blocks the theme uses, which are ordinary `wp_navigation` posts the content tools can already read and write.

== Installation ==

Copy the `meta-mcp` directory into `wp-content/plugins/` and activate it from the Plugins screen.

Requires WordPress 6.9 or newer (the Abilities API is included in core).

== Frequently Asked Questions ==

= Will this be overwritten by updates to the WordPress MCP Adapter? =

No. It uses its own plugin slug, so WordPress.org has nothing to match it against, and it declares `Update URI: false`, which tells WordPress never to accept an update for it from WordPress.org.

= Does this work with the menus under Appearance → Menus? =

Yes. Those classic menus have their own set of tools, because a menu item stores what it points at in post meta rather than in its title and content, so editing one as an ordinary post would corrupt it. Block themes that use Navigation blocks instead are reported by the same tools, and those blocks are readable and writable through the normal content tools.

= Do I need Elementor or Polylang? =

No. The content tools work on any WordPress site. The Elementor and Polylang tools activate only when those plugins are present; call the `content-integrations-status` tool to see what a given site supports.

== Changelog ==

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
