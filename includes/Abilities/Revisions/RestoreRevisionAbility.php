<?php
/**
 * Ability for restoring a post to one of its revisions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Revisions;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Restore Revision - Puts a post back to the state saved in a revision.
 */
final class RestoreRevisionAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'restore-revision',
			array(
				'label'               => 'Restore Revision',
				'description'         => 'Restore a post to a revision: its title, content and excerpt, and, for an Elementor page, the Elementor layout stored with that revision (Elementor restores it and its CSS is regenerated). The current state is saved as a new revision first, so a restore can itself be undone by restoring that one. Status, slug, terms and SEO fields are not changed. confirm must be true. Page caches are not purged; call purge-cache afterwards.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id' => array(
							'type'        => 'integer',
							'description' => 'The revision to restore, from list-revisions.',
						),
						'confirm'     => array(
							'type'        => 'boolean',
							'description' => 'Must be true. A published post changes immediately.',
						),
					),
					'required'   => array( 'revision_id', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'post_id'            => array( 'type' => 'integer' ),
						'restored_from'      => array( 'type' => 'integer' ),
						'backup_revision_id' => array( 'type' => 'integer' ),
						'restored_fields'    => array( 'type' => 'array' ),
						'elementor_restored' => array( 'type' => 'boolean' ),
						'post'               => array( 'type' => 'object' ),
						'error'              => array( 'type' => 'string' ),
						'error_code'         => array( 'type' => 'string' ),
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
		$input = is_array( $input ) ? $input : array();

		if ( ! ContentSupport::to_bool( $input['confirm'] ?? null, false ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'confirmation_required', 'Restoring a revision replaces the current title, content and layout. Pass confirm: true.' ) );
		}

		$loaded = RevisionSupport::require_revision( $input['revision_id'] ?? 0 );

		if ( is_wp_error( $loaded ) ) {
			return AbilityRegistrar::failure( $loaded );
		}

		list( $revision, $post ) = $loaded;

		$changed = array();

		foreach ( RevisionSupport::FIELDS as $field ) {
			if ( (string) $revision->{$field} !== (string) $post->{$field} ) {
				$changed[] = str_replace( 'post_', '', $field );
			}
		}

		$had_elementor = '' !== (string) get_post_meta( $revision->ID, '_elementor_data', true );
		$before_layout = (string) get_post_meta( $post->ID, '_elementor_data', true );

		// Keep the current state as a revision, so this restore is reversible.
		$backup = wp_save_post_revision( $post->ID );

		$restored = wp_restore_post_revision( $revision->ID );

		if ( ! $restored || is_wp_error( $restored ) ) {
			return AbilityRegistrar::failure(
				is_wp_error( $restored ) ? $restored : new WP_Error( 'restore_failed', sprintf( 'Could not restore revision %d.', $revision->ID ) )
			);
		}

		$after_layout       = (string) get_post_meta( $post->ID, '_elementor_data', true );
		$elementor_restored = $had_elementor && $after_layout !== $before_layout;

		if ( ElementorSupport::is_active() && ElementorSupport::is_built_with_elementor( $post->ID ) ) {
			ElementorSupport::regenerate_css( $post->ID );
		}

		clean_post_cache( $post->ID );

		return array(
			'success'            => true,
			'post_id'            => $post->ID,
			'restored_from'      => (int) $revision->ID,
			'backup_revision_id' => is_int( $backup ) ? $backup : 0,
			'restored_fields'    => $changed,
			'elementor_restored' => $elementor_restored,
			'post'               => ContentSupport::format_post( ContentSupport::refresh( $post ), array( 'include_content' => false ) ),
		);
	}
}
