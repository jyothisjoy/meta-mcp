<?php
/**
 * Ability for changing the Elementor global kit (site colours, fonts, layout).
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Elementor;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Elementor Update Kit - Changes global colours, fonts and other kit settings.
 */
final class ElementorUpdateKitAbility {

	/**
	 * The item lists this ability merges by _id.
	 */
	private const LISTS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$item_list = static function ( string $description ): array {
			return array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object' ),
				'description' => $description,
			);
		};

		AbilityRegistrar::register(
			'elementor-update-kit',
			array(
				'label'               => 'Update Elementor Global Kit',
				'description'         => 'Change the active Elementor kit (Site Settings). Colour and font lists merge by _id: an existing _id gets only the keys you pass (e.g. {"_id":"primary","color":"#0A3D62"}); a new or missing _id appends a custom item; {"_id":"...","_delete":true} removes a custom item. System items (primary, secondary, text, accent) can be changed, not added or removed. Fonts use Elementor typography keys, e.g. {"_id":"primary","typography_font_family":"Playfair Display","typography_font_weight":"600"}. settings merges other top-level kit keys, e.g. {"container_width":{"unit":"px","size":1200}}; read keys with elementor-get-kit first. Elementor CSS is regenerated; call purge-cache afterwards. dry_run previews. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'system_colors'     => $item_list( 'System colour changes, by _id (primary, secondary, text, accent).' ),
						'custom_colors'     => $item_list( 'Custom colour changes: update by _id, append new items, or {"_id","_delete":true}.' ),
						'system_typography' => $item_list( 'System font changes, by _id (primary, secondary, text, accent).' ),
						'custom_typography' => $item_list( 'Custom font changes: update by _id, append new items, or {"_id","_delete":true}.' ),
						'settings'          => array(
							'type'        => 'object',
							'description' => 'Other top-level kit settings to set, replacing each key\'s value. Not for the four lists above.',
						),
						'dry_run'           => array(
							'type'        => 'boolean',
							'description' => 'Report what would change without saving. Default false.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'kit_id'     => array( 'type' => 'integer' ),
						'changes'    => array( 'type' => 'array' ),
						'saved_via'  => array( 'type' => 'string' ),
						'css_cleared' => array( 'type' => 'boolean' ),
						'result'     => array( 'type' => 'object' ),
						'error'      => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( AbilityRegistrar::class, 'allow_write' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'readonly'            => false,
				'destructive'         => false,
				'idempotent'          => false,
			)
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return array<string, mixed> The change report.
	 */
	public static function execute( $input = array() ): array {
		$kit_id = ElementorGetKitAbility::require_kit( true );

		if ( is_wp_error( $kit_id ) ) {
			return AbilityRegistrar::failure( $kit_id );
		}

		$input    = is_array( $input ) ? $input : array();
		$dry_run  = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$settings = ElementorGetKitAbility::settings( $kit_id );
		$changes  = array();
		$errors   = array();

		foreach ( self::LISTS as $list ) {
			if ( ! isset( $input[ $list ] ) ) {
				continue;
			}

			if ( ! is_array( $input[ $list ] ) ) {
				$errors[ $list ] = 'Expected a list of items.';
				continue;
			}

			$merged = self::merge_list(
				isset( $settings[ $list ] ) && is_array( $settings[ $list ] ) ? array_values( $settings[ $list ] ) : array(),
				array_values( $input[ $list ] ),
				0 === strpos( $list, 'system_' ),
				$list,
				$changes
			);

			if ( is_wp_error( $merged ) ) {
				$errors[ $list ] = $merged->get_error_message();
				continue;
			}

			$settings[ $list ] = $merged;
		}

		if ( isset( $input['settings'] ) ) {
			if ( ! is_array( $input['settings'] ) ) {
				$errors['settings'] = 'Expected an object of kit keys.';
			} else {
				foreach ( $input['settings'] as $key => $value ) {
					$key = (string) $key;

					if ( in_array( $key, self::LISTS, true ) ) {
						$errors[ $key ] = sprintf( 'Pass %s as its own input so items are merged by _id.', $key );
						continue;
					}

					if ( '' === $key || '_' === $key[0] || 'post_status' === $key ) {
						$errors[ $key ] = 'This key cannot be set.';
						continue;
					}

					$before = $settings[ $key ] ?? null;

					if ( $before === $value ) {
						continue;
					}

					$settings[ $key ] = $value;
					$changes[]        = array(
						'key'    => $key,
						'action' => null === $before ? 'added' : 'updated',
						'before' => $before,
						'after'  => $value,
					);
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some changes were rejected.',
				'kit_changes_rejected',
				array( 'errors' => $errors )
			);
		}

		if ( empty( $changes ) ) {
			return array(
				'success'     => true,
				'dry_run'     => $dry_run,
				'kit_id'      => $kit_id,
				'changes'     => array(),
				'saved_via'   => '',
				'css_cleared' => false,
			);
		}

		if ( $dry_run ) {
			return array(
				'success'     => true,
				'dry_run'     => true,
				'kit_id'      => $kit_id,
				'changes'     => $changes,
				'saved_via'   => '',
				'css_cleared' => false,
			);
		}

		$saved_via = self::save( $kit_id, $settings );
		$cleared   = self::clear_css( $kit_id );
		$after     = ElementorGetKitAbility::settings( $kit_id );
		$touched   = array();

		foreach ( $changes as $change ) {
			$key             = isset( $change['list'] ) ? (string) $change['list'] : (string) $change['key'];
			$touched[ $key ] = $after[ $key ] ?? null;
		}

		return array(
			'success'     => true,
			'dry_run'     => false,
			'kit_id'      => $kit_id,
			'changes'     => $changes,
			'saved_via'   => $saved_via,
			'css_cleared' => $cleared,
			'result'      => (object) $touched,
		);
	}

	/**
	 * Merges incoming items into a kit list by _id.
	 *
	 * @param array<int, mixed>               $current   Stored items.
	 * @param array<int, mixed>               $incoming  Incoming items.
	 * @param bool                            $is_system Whether this is a system list.
	 * @param string                          $list      List name, for the change log.
	 * @param array<int, array<string,mixed>> $changes   Change log, appended to.
	 *
	 * @return array<int, mixed>|\WP_Error The merged list.
	 */
	private static function merge_list( array $current, array $incoming, bool $is_system, string $list, array &$changes ) {
		$index = array();

		foreach ( $current as $i => $item ) {
			if ( is_array( $item ) && isset( $item['_id'] ) ) {
				$index[ (string) $item['_id'] ] = $i;
			}
		}

		foreach ( $incoming as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_item', 'Each item must be an object.' );
			}

			$id     = isset( $item['_id'] ) ? sanitize_key( (string) $item['_id'] ) : '';
			$delete = ! empty( $item['_delete'] );
			unset( $item['_delete'] );

			if ( '' !== $id && isset( $index[ $id ] ) ) {
				$pos = $index[ $id ];

				if ( $delete ) {
					if ( $is_system ) {
						return new WP_Error( 'cannot_delete_system', sprintf( 'System item "%s" cannot be removed; change its value instead.', $id ) );
					}

					$changes[] = array(
						'list'   => $list,
						'_id'    => $id,
						'action' => 'deleted',
						'before' => $current[ $pos ],
					);
					$current[ $pos ] = null;
					continue;
				}

				$before = $current[ $pos ];
				$after  = array_merge( (array) $before, $item, array( '_id' => $id ) );

				if ( $before !== $after ) {
					$current[ $pos ] = $after;
					$changes[]       = array(
						'list'   => $list,
						'_id'    => $id,
						'action' => 'updated',
						'before' => $before,
						'after'  => $after,
					);
				}

				continue;
			}

			if ( $delete ) {
				return new WP_Error( 'item_not_found', sprintf( 'No item with _id "%s" to delete.', $id ) );
			}

			if ( $is_system ) {
				return new WP_Error(
					'unknown_system_item',
					sprintf( 'System item "%s" does not exist. System items are fixed (usually primary, secondary, text, accent); add new ones to the custom list.', $id )
				);
			}

			if ( '' === $id ) {
				$id = ElementorSupport::generate_id();
			}

			$item['_id'] = $id;

			if ( ! isset( $item['title'] ) ) {
				$item['title'] = 'New item';
			}

			$current[]    = $item;
			$index[ $id ] = count( $current ) - 1;
			$changes[]    = array(
				'list'   => $list,
				'_id'    => $id,
				'action' => 'added',
				'after'  => $item,
			);
		}

		return array_values( array_filter( $current, static fn( $item ): bool => null !== $item ) );
	}

	/**
	 * Saves the kit settings through Elementor, falling back to the meta.
	 *
	 * @param int                  $kit_id   Kit id.
	 * @param array<string, mixed> $settings Full settings.
	 *
	 * @return string "document" or "meta".
	 */
	private static function save( int $kit_id, array $settings ): string {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $kit_id, false );

				if ( $document && method_exists( $document, 'save' ) ) {
					$document->save( array( 'settings' => $settings ) );

					// Confirm the lists actually landed; some versions drop unknown keys.
					$stored = ElementorGetKitAbility::settings( $kit_id );
					$ok     = true;

					foreach ( self::LISTS as $list ) {
						if ( isset( $settings[ $list ] ) && ( $stored[ $list ] ?? null ) != $settings[ $list ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Elementor may cast scalars.
							$ok = false;
						}
					}

					if ( $ok ) {
						return 'document';
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		update_post_meta( $kit_id, '_elementor_page_settings', wp_slash( $settings ) );

		return 'meta';
	}

	/**
	 * Clears Elementor's generated CSS so global changes reach every page.
	 *
	 * @param int $kit_id Kit id.
	 *
	 * @return bool True when the cache was cleared.
	 */
	private static function clear_css( int $kit_id ): bool {
		ElementorSupport::regenerate_css( $kit_id );

		try {
			if ( class_exists( '\Elementor\Plugin' ) ) {
				$elementor = \Elementor\Plugin::instance();

				if ( isset( $elementor->files_manager ) && is_object( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
					$elementor->files_manager->clear_cache();

					return true;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return false;
	}
}
