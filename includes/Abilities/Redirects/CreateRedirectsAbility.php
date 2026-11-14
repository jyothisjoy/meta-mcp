<?php
/**
 * Ability for adding redirect rules.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Redirects;

use WP\MCP\Abilities\Support\AbilityRegistrar;
use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\RedirectsBackend;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Create Redirects - Adds one or more redirect rules in one call, in the Redirection plugin or Rank Math.
 */
final class CreateRedirectsAbility {

	/**
	 * Largest batch accepted in one call.
	 */
	private const MAX_RULES = 100;

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public static function register(): void {
		$rule = RedirectsBackend::rule_schema();

		$rule['enabled'] = array(
			'type'        => 'boolean',
			'description' => 'Create the rule enabled. Default true.',
		);

		AbilityRegistrar::register(
			'redirects-create',
			array(
				'label'               => 'Create Redirects',
				'description'         => 'Add up to 100 redirect rules in one call, to whichever redirect manager the site uses: the Redirection plugin or Rank Math → Redirections (provider picks one when both are active). E.g. [{"source":"/offers-2/","target":"/offers/"}, {"source":"^/bookingstep1\\.en\\.html.*","target":"/rooms-suites/","regex":true}, {"source":"/old-promo/","code":410}]. Every rule is validated first and none are created if any is invalid. A plain source that already has a redirect is an error by default; set on_existing to "update" to retarget the existing rule instead, or "skip" to leave it. Returns the new rules with their ids and the provider used.',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'redirects'   => array(
							'type'        => 'array',
							'description' => 'The rules to add.',
							'items'       => array(
								'type'       => 'object',
								'properties' => $rule,
								'required'   => array( 'source' ),
							),
						),
						'on_existing' => array(
							'type'        => 'string',
							'enum'        => array( 'error', 'update', 'skip' ),
							'description' => 'What to do when a plain source already has a redirect. Default "error".',
						),
						'provider'    => RedirectsBackend::provider_schema(),
					),
					'required'   => array( 'redirects' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'provider'   => array( 'type' => 'string' ),
						'created'    => array( 'type' => 'array' ),
						'updated'    => array( 'type' => 'array' ),
						'skipped'    => array( 'type' => 'array' ),
						'ids'        => array( 'type' => 'array' ),
						'errors'     => array( 'type' => 'array' ),
						'notes'      => array( 'type' => 'array' ),
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
	 * @return array<string, mixed> The outcome.
	 */
	public static function execute( $input = array() ): array {
		$input   = is_array( $input ) ? $input : array();
		$backend = RedirectsBackend::resolve( $input );

		if ( is_wp_error( $backend ) ) {
			return AbilityRegistrar::failure( $backend );
		}

		$allowed = $backend::require_cap( 'add' );

		if ( is_wp_error( $allowed ) ) {
			return AbilityRegistrar::failure( $allowed );
		}

		$rules = isset( $input['redirects'] ) && is_array( $input['redirects'] ) ? array_values( $input['redirects'] ) : array();

		if ( empty( $rules ) ) {
			return AbilityRegistrar::failure( new WP_Error( 'missing_redirects', 'redirects must contain at least one rule.' ) );
		}

		if ( count( $rules ) > self::MAX_RULES ) {
			return AbilityRegistrar::failure(
				new WP_Error( 'too_many_redirects', sprintf( 'At most %d rules can be added in one call; split the batch.', self::MAX_RULES ) )
			);
		}

		$on_existing = isset( $input['on_existing'] ) && in_array( $input['on_existing'], array( 'error', 'update', 'skip' ), true )
			? (string) $input['on_existing']
			: 'error';

		// Validate the whole batch before writing anything.
		$plan   = array();
		$errors = array();
		$notes  = array();
		$seen   = array();

		foreach ( $rules as $index => $rule ) {
			$rule     = is_array( $rule ) ? $rule : array();
			$prepared = $backend::prepare( $rule );

			if ( is_wp_error( $prepared ) ) {
				$errors[] = self::error_entry( $index, $rule, $prepared->get_error_message() );
				continue;
			}

			$regex = ContentSupport::to_bool( $rule['regex'] ?? false, false );
			$plain = ! $regex && ( ! isset( $rule['match'] ) || 'exact' === $rule['match'] );
			$key   = ( $regex ? 'regex:' : 'plain:' ) . strtolower( trim( (string) ( $rule['source'] ?? '' ), '/' ) );

			if ( isset( $seen[ $key ] ) ) {
				$errors[] = self::error_entry( $index, $rule, sprintf( 'Duplicate of rule %d in this batch.', $seen[ $key ] ) );
				continue;
			}

			$seen[ $key ] = $index;
			$existing     = $plain ? $backend::find_by_source( (string) ( $rule['source'] ?? '' ) ) : array();

			if ( ! empty( $existing ) && 'error' === $on_existing ) {
				$errors[] = self::error_entry(
					$index,
					$rule,
					sprintf(
						'Source already redirected by rule %d (to "%s"). Set on_existing to "update" to retarget it, or "skip".',
						$existing[0]['id'],
						$existing[0]['target']
					)
				);
				continue;
			}

			foreach ( $prepared['notes'] as $note ) {
				$notes[] = sprintf( 'Rule %d: %s', $index, $note );
			}

			$plan[] = array(
				'index'    => $index,
				'rule'     => $rule,
				'prepared' => $prepared,
				'existing' => $existing[0] ?? null,
			);
		}

		if ( ! empty( $errors ) ) {
			return AbilityRegistrar::failure_with_details(
				'No redirects were created because some rules were invalid.',
				'redirects_rejected',
				array(
					'errors'   => $errors,
					'provider' => $backend::provider(),
				)
			);
		}

		$created = array();
		$updated = array();
		$skipped = array();

		foreach ( $plan as $step ) {
			$enabled = ContentSupport::to_bool( $step['rule']['enabled'] ?? null, true );

			if ( null !== $step['existing'] ) {
				if ( 'skip' === $on_existing ) {
					$skipped[] = $step['existing'];
					continue;
				}

				$id       = (int) $step['existing']['id'];
				$prepared = $backend::prepare( $step['rule'], $id );
				$saved    = is_wp_error( $prepared ) ? $prepared : $backend::update( $id, $prepared );

				if ( ! is_wp_error( $saved ) ) {
					$toggled = $backend::set_enabled( $id, $enabled );
					$saved   = is_wp_error( $toggled ) ? $toggled : $backend::get_formatted( $id );
				}

				if ( is_wp_error( $saved ) ) {
					$errors[] = self::error_entry( $step['index'], $step['rule'], $saved->get_error_message() );
					continue;
				}

				$updated[] = $saved;
				continue;
			}

			$item = $backend::create( $step['prepared'], $enabled );

			if ( is_wp_error( $item ) ) {
				$errors[] = self::error_entry( $step['index'], $step['rule'], $item->get_error_message() );
				continue;
			}

			$created[] = $item;
		}

		return array(
			'success'  => empty( $errors ),
			'provider' => $backend::provider(),
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'ids'      => array_values( array_map( static fn( array $r ): int => (int) $r['id'], $created ) ),
			'errors'   => $errors,
			'notes'    => $notes,
		);
	}

	/**
	 * Shapes a per-rule error.
	 *
	 * @param int                  $index   Position in the batch.
	 * @param array<string, mixed> $rule    The rule as given.
	 * @param string               $message Error message.
	 *
	 * @return array<string, mixed> The error entry.
	 */
	private static function error_entry( int $index, array $rule, string $message ): array {
		return array(
			'index'  => $index,
			'source' => (string) ( $rule['source'] ?? '' ),
			'error'  => $message,
		);
	}
}
