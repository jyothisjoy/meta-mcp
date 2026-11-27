<?php
/**
 * Ability for setting Polylang string translations.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Polylang;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Polylang Set String Translations - Writes translations of registered strings.
 */
final class SetStringTranslationsAbility {

	/**
	 * Largest batch accepted.
	 */
	private const MAX_ITEMS = 200;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'polylang-set-string-translations',
			array(
				'label'               => 'Set Polylang String Translations',
				'description'         => 'Set translations of Polylang strings (Languages → Translations): site title, tagline, widget titles, theme and plugin strings. Pass up to 200 items of {string: the original exactly as polylang-list-strings shows it, language: slug, translation: text}. An empty translation removes it, so the original shows in that language. Only strings that are registered (or already have a stored translation) are accepted, and nothing is saved if any item is rejected. Translations go through Polylang\'s own sanitising filter. Set dry_run to preview. Page caches are not purged; call purge-cache afterwards. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'translations' => array(
							'type'        => 'array',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'string'      => array(
										'type'        => 'string',
										'description' => 'The original string.',
									),
									'language'    => array(
										'type'        => 'string',
										'description' => 'Language slug, e.g. "ar".',
									),
									'translation' => array(
										'type'        => 'string',
										'description' => 'The translation; empty to remove it.',
									),
								),
								'required'   => array( 'string', 'language', 'translation' ),
							),
							'description' => 'The translations to set.',
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'description' => 'Validate and report without saving. Default false.',
						),
					),
					'required'   => array( 'translations' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'results'    => array( 'type' => 'array' ),
						'changed'    => array( 'type' => 'integer' ),
						'unchanged'  => array( 'type' => 'integer' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => true,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$ready = ListStringTranslationsAbility::require_ready();

		if ( is_wp_error( $ready ) ) {
			return AbilityRegistrar::failure( $ready );
		}

		$items = isset( $input['translations'] ) && is_array( $input['translations'] ) ? array_values( $input['translations'] ) : array();

		if ( empty( $items ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_translations', 'translations must list at least one {string, language, translation}.' ) );
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			return AbilityRegistrar::failure( new WP_Error( 'too_many_items', sprintf( 'At most %d translations per call.', self::MAX_ITEMS ) ) );
		}

		$dry_run    = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$languages  = ListStringTranslationsAbility::language_objects();
		$registered = array();

		foreach ( ListStringTranslationsAbility::registered_strings() as $string ) {
			$registered[ (string) $string['string'] ] = $string;
		}

		$mos     = array();
		$errors  = array();
		$planned = array();

		foreach ( $items as $i => $item ) {
			$item     = is_array( $item ) ? $item : array();
			$original = isset( $item['string'] ) ? (string) $item['string'] : '';
			$slug     = isset( $item['language'] ) ? (string) $item['language'] : '';
			$label    = sprintf( '#%d', (int) $i );

			if ( '' === $original ) {
				$errors[ $label ] = 'string is required.';
				continue;
			}

			if ( ! isset( $languages[ $slug ] ) ) {
				$errors[ $label ] = sprintf( 'Unknown language "%s". Available: %s.', $slug, implode( ', ', array_keys( $languages ) ) );
				continue;
			}

			if ( ! array_key_exists( 'translation', $item ) || ! is_scalar( $item['translation'] ) ) {
				$errors[ $label ] = 'translation must be a string (empty to remove it).';
				continue;
			}

			if ( ! isset( $mos[ $slug ] ) ) {
				$mos[ $slug ] = ListStringTranslationsAbility::load_mo( $languages[ $slug ] );
			}

			$before = ListStringTranslationsAbility::translation_of( $mos[ $slug ], $original );
			$known  = isset( $registered[ $original ] ) || isset( $mos[ $slug ]->entries[ $original ] );

			if ( ! $known ) {
				$errors[ $label ] = sprintf( '"%s" is not a registered Polylang string. Copy the original exactly from polylang-list-strings.', self::excerpt( $original ) );
				continue;
			}

			$translation = (string) $item['translation'];
			$meta        = $registered[ $original ] ?? array();

			/** This filter is documented in Polylang's settings/table-string.php */
			$translation = (string) apply_filters(
				'pll_sanitize_string_translation',
				$translation,
				(string) ( $meta['name'] ?? '' ),
				(string) ( $meta['context'] ?? '' ),
				$original,
				$before
			);

			$planned[] = array(
				'string'      => $original,
				'language'    => $slug,
				'before'      => $before,
				'translation' => $translation,
				'changed'     => $before !== $translation,
			);
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some translations were rejected.',
				'translations_rejected',
				array( 'errors' => $errors )
			);
		}

		$changed   = 0;
		$unchanged = 0;
		$dirty     = array();

		foreach ( $planned as $plan ) {
			if ( ! $plan['changed'] ) {
				++$unchanged;
				continue;
			}

			++$changed;
			$dirty[ $plan['language'] ] = true;

			if ( $dry_run ) {
				continue;
			}

			$mo = $mos[ $plan['language'] ];

			if ( '' === $plan['translation'] ) {
				unset( $mo->entries[ $plan['string'] ] );
			} else {
				$mo->add_entry( $mo->make_entry( $plan['string'], $plan['translation'] ) );
			}
		}

		if ( ! $dry_run ) {
			foreach ( array_keys( $dirty ) as $slug ) {
				$mos[ $slug ]->export_to_db( $languages[ $slug ] );
			}

			// Re-read what was stored so the report reflects the database.
			foreach ( $planned as $k => $plan ) {
				if ( isset( $dirty[ $plan['language'] ] ) ) {
					$fresh                    = ListStringTranslationsAbility::load_mo( $languages[ $plan['language'] ] );
					$planned[ $k ]['stored']  = ListStringTranslationsAbility::translation_of( $fresh, $plan['string'] );
				}
			}
		}

		return array(
			'success'   => true,
			'dry_run'   => $dry_run,
			'results'   => $planned,
			'changed'   => $changed,
			'unchanged' => $unchanged,
		);
	}

	/**
	 * Shortens a string for an error message.
	 *
	 * @param string $text Text.
	 *
	 * @return string Excerpt.
	 */
	private static function excerpt( string $text ): string {
		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '...' : $text;
	}
}
