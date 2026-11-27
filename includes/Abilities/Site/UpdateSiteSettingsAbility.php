<?php
/**
 * Ability for changing site-wide WordPress settings.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Site;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\SiteSupport;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Update Site Settings - Changes Settings → General / Reading values.
 */
final class UpdateSiteSettingsAbility {

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		AbilityRegistrar::register(
			'update-site-settings',
			array(
				'label'               => 'Update Site Settings',
				'description'         => 'Change WordPress settings listed by get-site-settings (General, Reading incl. blog_public = search visibility, Permalinks, Discussion, Media sizes, WPLANG, privacy page), e.g. {"blogname":"Red Castle Hotel"}. Site URL, admin email, registration and default role are never changeable. All values are validated first; one rejected value means nothing is saved. Permalink changes rebuild rewrite rules. dry_run previews; warnings flag consequential changes. Requires "manage_options".',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'Option names and their new values.',
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => 'Validate and report what would change without saving. Default false.',
						),
					),
					'required'   => array( 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'changed'    => array( 'type' => 'object' ),
						'unchanged'  => array( 'type' => 'array' ),
						'errors'     => array( 'type' => 'object' ),
						'warnings'   => array( 'type' => 'object' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'rewrite_flushed' => array( 'type' => 'boolean' ),
						'rewrite_rebuild' => array( 'type' => array( 'object', 'null' ) ),
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
	 * @return array<string, mixed> The change report.
	 */
	public static function execute( $input = array() ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'insufficient_capability', 'User lacks the "manage_options" capability needed for site settings.' )
			);
		}

		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		if ( empty( $values ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_settings', 'settings must contain at least one option.' ) );
		}

		$kinds   = SiteSupport::settings();
		$clean   = array();
		$errors  = array();

		foreach ( $values as $option => $value ) {
			$option = (string) $option;

			if ( ! isset( $kinds[ $option ] ) ) {
				$errors[ $option ] = sprintf( '"%s" is not a setting this connection may change. Allowed: %s.', $option, implode( ', ', array_keys( $kinds ) ) );
				continue;
			}

			$cleaned = SiteSupport::clean_value( (string) $kinds[ $option ], $value );

			if ( is_wp_error( $cleaned ) ) {
				$errors[ $option ] = $cleaned->get_error_message();
				continue;
			}

			$clean[ $option ] = $cleaned;
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'Nothing was saved because some settings were rejected.',
				'settings_rejected',
				array( 'errors' => $errors )
			);
		}

		$dry_run   = ContentSupport::to_bool( $input['dry_run'] ?? null, false );
		$changed   = array();
		$unchanged = array();
		$warnings  = array();
		$rewrite   = false;

		foreach ( $clean as $option => $value ) {
			$before = get_option( $option );

			// Compare loosely: WordPress returns most options as strings.
			if ( (string) $before === (string) $value ) {
				$unchanged[] = $option;
				continue;
			}

			$warning = SiteSupport::change_warning( $option, $value );

			if ( '' !== $warning ) {
				$warnings[ $option ] = $warning;
			}

			if ( $dry_run ) {
				$changed[ $option ] = array(
					'before' => $before,
					'after'  => $value,
				);
				continue;
			}

			if ( in_array( $option, SiteSupport::REWRITE_SETTINGS, true ) ) {
				self::save_rewrite_setting( $option, (string) $value );
				$rewrite = true;
			} else {
				update_option( $option, $value );
			}

			$changed[ $option ] = array(
				'before' => $before,
				'after'  => get_option( $option ),
			);
		}

		$rebuild = $rewrite ? SiteSupport::rebuild_rewrite_rules() : null;

		return array(
			'success'         => true,
			'dry_run'         => $dry_run,
			'changed'         => (object) $changed,
			'unchanged'       => $unchanged,
			'warnings'        => (object) $warnings,
			'rewrite_flushed' => $rewrite,
			'rewrite_rebuild' => $rebuild,
			'errors'          => (object) array(),
		);
	}

	/**
	 * Saves a permalink setting the way Settings → Permalinks does, through WP_Rewrite.
	 *
	 * @param string $option permalink_structure, category_base or tag_base.
	 * @param string $value  The cleaned value.
	 *
	 * @return void
	 */
	private static function save_rewrite_setting( string $option, string $value ): void {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			update_option( $option, $value );
			return;
		}

		if ( 'permalink_structure' === $option ) {
			$wp_rewrite->set_permalink_structure( $value );
		} elseif ( 'category_base' === $option ) {
			$wp_rewrite->set_category_base( $value );
		} else {
			$wp_rewrite->set_tag_base( $value );
		}
	}
}
