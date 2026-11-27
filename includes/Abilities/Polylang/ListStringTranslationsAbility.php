<?php
/**
 * Ability for listing Polylang string translations.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Polylang List Strings - Lists registered strings and their translations
 * (Languages → Translations in wp-admin).
 *
 * These are the texts that do not live in a post: the site title and tagline,
 * date formats, widget titles, theme and plugin strings registered with
 * `pll_register_string()`. Each language keeps its translations in a
 * `polylang_mo_<term id>` post, read and written through `PLL_MO`.
 */
final class ListStringTranslationsAbility {

	/**
	 * Largest page size accepted.
	 */
	private const MAX_PER_PAGE = 200;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-list-strings',
			array(
				'label'               => 'List Polylang String Translations',
				'description'         => 'List the strings Polylang translates outside posts (Languages → Translations): site title and tagline, date and time formats, widget titles, and strings themes and plugins register. Each entry gives the original string, its name and group, and the translation for each language ("" when untranslated, which makes the site show the original). Filter with search (matches original, name or translation), group (e.g. "Polylang", "Widget", a theme name) or language (only that language\'s translation, and untranslated_only to find gaps). Change translations with polylang-set-string-translations. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'            => array(
							'type'        => 'string',
							'description' => 'Case-insensitive text matched against the original, the name and the translations.',
						),
						'group'             => array(
							'type'        => 'string',
							'description' => 'Only strings in this group (context), exact match.',
						),
						'language'          => array(
							'type'        => 'string',
							'description' => 'Only return this language\'s translation (slug, e.g. "ar").',
						),
						'untranslated_only' => array(
							'type'        => 'boolean',
							'description' => 'With language, only strings that have no translation in it yet.',
						),
						'per_page'          => array(
							'type'        => 'integer',
							'description' => 'Strings per page. Default 50, maximum 200.',
						),
						'page'              => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'strings'     => array( 'type' => 'array' ),
						'groups'      => array( 'type' => 'array' ),
						'languages'   => array( 'type' => 'array' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'note'        => array( 'type' => 'string' ),
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
	 * @return array<string, mixed> The strings.
	 */
	public static function execute( $input = array() ): array {
		$ready = self::require_ready();

		if ( is_wp_error( $ready ) ) {
			return AbilityRegistrar::failure( $ready );
		}

		$input     = is_array( $input ) ? $input : array();
		$languages = self::language_objects();

		if ( ! empty( $input['language'] ) ) {
			$slug = (string) $input['language'];
			$valid = PolylangSupport::validate_language( $slug );

			if ( is_wp_error( $valid ) ) {
				return AbilityRegistrar::failure( $valid );
			}

			$languages = array_intersect_key( $languages, array( $slug => true ) );
		}

		$search      = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$group       = isset( $input['group'] ) ? (string) $input['group'] : '';
		$untranslated = ! empty( $input['language'] ) && ! empty( $input['untranslated_only'] );
		$per_page    = isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $input['per_page'] ) ) : 50;
		$page        = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$mos = array();

		foreach ( $languages as $slug => $language ) {
			$mos[ $slug ] = self::load_mo( $language );
		}

		$strings = self::registered_strings();
		$groups  = array();
		$rows    = array();

		foreach ( $strings as $string ) {
			$context = (string) ( $string['context'] ?? '' );

			$groups[ $context ] = true;

			if ( '' !== $group && $group !== $context ) {
				continue;
			}

			$original     = (string) ( $string['string'] ?? '' );
			$translations = array();

			foreach ( $mos as $slug => $mo ) {
				$translations[ $slug ] = self::translation_of( $mo, $original );
			}

			if ( $untranslated && '' !== reset( $translations ) ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = strtolower( $original . "\n" . (string) ( $string['name'] ?? '' ) . "\n" . implode( "\n", $translations ) );

				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$rows[] = array(
				'string'       => $original,
				'name'         => (string) ( $string['name'] ?? '' ),
				'group'        => $context,
				'multiline'    => ! empty( $string['multiline'] ),
				'translations' => (object) $translations,
			);
		}

		$total = count( $rows );

		return array(
			'success'     => true,
			'strings'     => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'groups'      => array_values( array_map( 'strval', array_keys( $groups ) ) ),
			'languages'   => array_values( array_map( 'strval', array_keys( $languages ) ) ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'note'        => 'Strings a plugin registers only on its own admin screens may be missing here until that screen has been opened once.',
		);
	}

	/**
	 * Checks Polylang and the caller's rights.
	 *
	 * @return true|\WP_Error True when usable.
	 */
	public static function require_ready() {
		$active = PolylangSupport::require_active();

		if ( is_wp_error( $active ) ) {
			return $active;
		}

		if ( ! class_exists( '\PLL_MO' ) ) {
			return new WP_Error( 'polylang_strings_unavailable', 'Polylang\'s string translation store (PLL_MO) is not available in this Polylang version.' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" capability needed for Polylang string translations.' );
		}

		return true;
	}

	/**
	 * Polylang language objects keyed by slug.
	 *
	 * @return array<string, object> Languages.
	 */
	public static function language_objects(): array {
		$objects = array();

		if ( ! function_exists( 'pll_languages_list' ) ) {
			return $objects;
		}

		$list = pll_languages_list( array( 'fields' => '' ) );

		foreach ( (array) $list as $language ) {
			if ( is_object( $language ) && isset( $language->slug ) ) {
				$objects[ (string) $language->slug ] = $language;
			}
		}

		return $objects;
	}

	/**
	 * Loads one language's translations.
	 *
	 * @param object $language Polylang language.
	 *
	 * @return \PLL_MO The translations.
	 */
	public static function load_mo( $language ) {
		$mo = new \PLL_MO();
		$mo->import_from_db( $language );

		return $mo;
	}

	/**
	 * The stored translation of a string, or "" when there is none.
	 *
	 * @param \PLL_MO $mo       Translations.
	 * @param string  $original Original string.
	 *
	 * @return string The translation.
	 */
	public static function translation_of( $mo, string $original ): string {
		if ( isset( $mo->entries[ $original ] ) && is_object( $mo->entries[ $original ] ) ) {
			$entry = $mo->entries[ $original ];

			if ( isset( $entry->translations[0] ) ) {
				return (string) $entry->translations[0];
			}
		}

		return '';
	}

	/**
	 * Every registered string, the way Polylang's Strings screen collects them.
	 *
	 * @return array<int, array<string, mixed>> Strings, each with name, string, context, multiline.
	 */
	public static function registered_strings(): array {
		if ( ! class_exists( '\PLL_Admin_Strings' ) ) {
			$path = defined( 'POLYLANG_DIR' ) ? constant( 'POLYLANG_DIR' ) . '/admin/admin-strings.php' : '';

			if ( '' !== $path && is_readable( $path ) ) {
				require_once $path;
			}
		}

		$strings = array();

		if ( class_exists( '\PLL_Admin_Strings' ) && method_exists( '\PLL_Admin_Strings', 'get_strings' ) ) {
			try {
				$strings = (array) \PLL_Admin_Strings::get_strings();
			} catch ( \Throwable $e ) {
				$strings = array();
			}
		}

		// Deduplicate on the original + group, as the Strings screen does.
		$unique = array();

		foreach ( $strings as $string ) {
			if ( ! is_array( $string ) || ! isset( $string['string'] ) || '' === (string) $string['string'] ) {
				continue;
			}

			$key = md5( (string) $string['string'] . '|' . (string) ( $string['context'] ?? '' ) );

			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $string;
			}
		}

		return array_values( $unique );
	}
}
