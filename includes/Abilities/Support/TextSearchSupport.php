<?php
/**
 * Site-wide text search and replace across posts, Elementor, Custom Code and menus.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - TextSearchSupport
 *
 * Visible text on a WordPress site rarely lives only in `post_content`. On an
 * Elementor site it sits inside widget settings in the `_elementor_data` JSON,
 * often in a header, footer or global widget template shared by every page;
 * phone numbers also turn up in `tel:` links, menu item URLs and JSON-LD in
 * Custom Code snippets. A client that can only edit `post_content` therefore
 * cannot fix a wrong phone number, and one that can only address an Elementor
 * element by id first has to find that id.
 *
 * This class finds text in all of those places and reports exactly where -
 * post, field, Elementor element id and the setting path inside it - and can
 * replace it in place, writing each kind of storage back through the same
 * path the dedicated abilities use.
 *
 * Two match modes:
 *
 * - `exact` - the text as given. Whitespace in it matches any run of spaces,
 *   non-breaking spaces or `&nbsp;`, and text containing `&`, `<` or `>` also
 *   matches its HTML-escaped form.
 * - `phone` - the digits of a phone number in any formatting: "+971 2 304 4555",
 *   "+971-2-3044555", "+971 (0)2 304 4555", "tel:+97123044555" and "00971..."
 *   are all the same number. A compact match (as in a `tel:` link) is replaced
 *   with the compact digits of the replacement; a formatted one with the
 *   replacement as written.
 */
final class TextSearchSupport {

	/**
	 * Match modes.
	 */
	public const MODES = array( 'exact', 'phone' );

	/**
	 * Places that can be searched.
	 */
	public const FIELDS = array( 'title', 'excerpt', 'content', 'elementor', 'custom_code', 'menus' );

	/**
	 * Statuses searched. Trash, revisions and auto-drafts are never touched.
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Elementor setting keys that are never searched or rewritten.
	 *
	 * `__dynamic__` holds dynamic tag references whose text is resolved at
	 * render time from somewhere else, `__globals__` references global colours
	 * and fonts, and `_id` is a repeater row's internal id.
	 */
	private const SKIPPED_SETTING_KEYS = array( '__dynamic__', '__globals__', '_id' );

	/**
	 * Number of candidate posts loaded per database round trip.
	 */
	private const BATCH = 100;

	/**
	 * Matches reported per post before the list is cut short.
	 */
	private const MAX_MATCHES_PER_POST = 50;

	/**
	 * Seconds a single call may spend scanning before it returns a cursor.
	 */
	private const TIME_BUDGET = 20.0;

	/**
	 * Input schema properties shared by find-text and replace-text.
	 *
	 * @return array<string, array<string, mixed>> Schema properties.
	 */
	public static function input_properties(): array {
		return array(
			'find'           => array(
				'type'        => 'string',
				'description' => 'The text to look for. In "exact" mode whitespace matches any kind of space (including &nbsp;), and text with &, < or > also matches its HTML-escaped form. In "phone" mode, a phone number in any formatting.',
			),
			'match_mode'     => array(
				'type'        => 'string',
				'enum'        => self::MODES,
				'description' => '"exact" (default) or "phone". Use "phone" for phone numbers: it matches the same digits however they are written ("+971 2 304 4555", "+971-2-3044555", "+971 (0)2 304 4555", "tel:+97123044555", "0097123044555"), including inside tel: and WhatsApp links.',
			),
			'case_sensitive' => array(
				'type'        => 'boolean',
				'description' => 'Exact mode only. Default false.',
			),
			'fields'         => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => self::FIELDS,
				),
				'description' => 'Where to look. Default all: "title", "excerpt", "content" (post_content), "elementor" (every text, link and repeater setting of every Elementor widget, in pages and in templates such as headers, footers and global widgets), "custom_code" (Elementor Pro Custom Code snippets, e.g. JSON-LD schema), "menus" (menu item labels and custom link URLs).',
			),
			'post_types'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Only these post types, e.g. ["page", "elementor_library"]. Default every exposed type. Setting this leaves out Custom Code and menus.',
			),
			'post_ids'       => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => 'Only these posts.',
			),
			'language'       => array(
				'type'        => 'string',
				'description' => 'Polylang language slug, e.g. "ar". Only posts in that language; Custom Code and menus are then left out because they have no language.',
			),
			'after_id'       => array(
				'type'        => 'integer',
				'description' => 'Resume a search that returned more: true, by passing its next_after_id.',
			),
		);
	}

	/**
	 * Reads and validates the shared input.
	 *
	 * @param array<string, mixed> $input     Raw input.
	 * @param int                  $max_posts Default for the number of posts per call.
	 *
	 * @return array{matcher: array<string, mixed>, args: array<string, mixed>}|\WP_Error The matcher and run arguments.
	 */
	public static function parse_input( array $input, int $max_posts ) {
		$matcher = self::matcher(
			(string) ( $input['find'] ?? '' ),
			(string) ( $input['match_mode'] ?? 'exact' ),
			ContentSupport::to_bool( $input['case_sensitive'] ?? false, false )
		);

		if ( is_wp_error( $matcher ) ) {
			return $matcher;
		}

		$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) && ! empty( $input['fields'] )
			? array_values( array_map( 'strval', $input['fields'] ) )
			: self::FIELDS;

		$unknown = array_diff( $fields, self::FIELDS );

		if ( ! empty( $unknown ) ) {
			return new WP_Error( 'invalid_fields', sprintf( 'Unknown fields: %s. Use any of: %s.', implode( ', ', $unknown ), implode( ', ', self::FIELDS ) ) );
		}

		$language = isset( $input['language'] ) ? trim( (string) $input['language'] ) : '';

		if ( '' !== $language && PolylangSupport::is_active() ) {
			$valid = PolylangSupport::validate_language( $language );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		return array(
			'matcher' => $matcher,
			'args'    => array(
				'fields'     => $fields,
				'post_types' => isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_values( array_map( 'strval', $input['post_types'] ) ) : array(),
				'post_ids'   => isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? array_values( array_map( 'intval', $input['post_ids'] ) ) : array(),
				'language'   => $language,
				'after_id'   => max( 0, (int) ( $input['after_id'] ?? 0 ) ),
				'max_posts'  => $max_posts,
			),
		);
	}

	/**
	 * Builds a matcher for the given text.
	 *
	 * @param string $find           Text to look for.
	 * @param string $mode           "exact" or "phone".
	 * @param bool   $case_sensitive Whether case matters (exact mode only).
	 *
	 * @return array<string, mixed>|\WP_Error The matcher.
	 */
	public static function matcher( string $find, string $mode, bool $case_sensitive ) {
		$find = trim( $find );

		if ( '' === $find ) {
			return new WP_Error( 'missing_find', 'find must not be empty.' );
		}

		if ( ! mb_check_encoding( $find, 'UTF-8' ) ) {
			return new WP_Error( 'invalid_find', 'find must be valid UTF-8 text.' );
		}

		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'invalid_match_mode', sprintf( 'match_mode must be one of: %s.', implode( ', ', self::MODES ) ) );
		}

		return 'phone' === $mode ? self::phone_matcher( $find ) : self::exact_matcher( $find, $case_sensitive );
	}

	/**
	 * Builds the matcher for exact text.
	 *
	 * @param string $find           Text to look for.
	 * @param bool   $case_sensitive Whether case matters.
	 *
	 * @return array<string, mixed> The matcher.
	 */
	private static function exact_matcher( string $find, bool $case_sensitive ): array {
		$escaped  = htmlspecialchars( $find, ENT_NOQUOTES, 'UTF-8', false );
		$variants = array( 'p' => $find );

		if ( $escaped !== $find ) {
			$variants['h'] = $escaped;
		}

		$alternatives = array();
		$likes        = array();

		foreach ( $variants as $group => $variant ) {
			$alternatives[] = '(?P<' . $group . '>' . self::flexible_whitespace( $variant ) . ')';

			// The database can only be pre-filtered on a run without whitespace,
			// because the stored text may use any kind of space. The longest such
			// run is the most selective; the PHP pass confirms every candidate.
			$token = self::longest_token( $variant );
			$json  = wp_json_encode( $token );

			$likes[] = $token;

			if ( is_string( $json ) ) {
				// Elementor stores its JSON with "/" and non-ASCII characters escaped.
				$likes[] = substr( $json, 1, -1 );
			}
		}

		return array(
			'mode'      => 'exact',
			'find'      => $find,
			'regex'     => '/' . implode( '|', $alternatives ) . '/u' . ( $case_sensitive ? '' : 'i' ),
			'raw_regex' => null,
			'likes'     => array_values( array_unique( array_filter( $likes, 'strlen' ) ) ),
		);
	}

	/**
	 * Builds the matcher for a phone number in any formatting.
	 *
	 * @param string $find The phone number.
	 *
	 * @return array<string, mixed>|\WP_Error The matcher.
	 */
	private static function phone_matcher( string $find ) {
		$digits = self::canonical_digits( $find );

		if ( strlen( $digits ) < 6 ) {
			return new WP_Error( 'invalid_phone', 'match_mode "phone" needs a number with at least 6 digits.' );
		}

		$chars = str_split( $digits );

		// Between two digits: up to four separators of any common kind,
		// including a "(0)" trunk prefix and URL-encoded or HTML spaces.
		$separator = '(?:[\s\-\.\(\)\/\x{00A0}\x{2010}-\x{2015}\x{202F}]|&nbsp;|&#160;|%20|\(0\)){0,4}';

		// The same, as the characters appear inside stored JSON, where a
		// non-breaking space is " " and a slash is "\/". Only used to skip
		// posts cheaply, so it is deliberately looser than the real pattern.
		$loose = '(?:[^0-9\\\\]|\\\\u[0-9a-fA-F]{4}|\\\\.|%20|\(0\)){0,8}';

		// Where a number may start: after "+" or "00" when preceded by anything
		// but a digit, or bare - but then not right after a digit, a "+", or a
		// digit and one separator, so "2 3044 555" is never found as the tail
		// end of "+971 2 3044 555".
		// A separator before the first digit only counts after a prefix ("+ 971"),
		// otherwise the "/" of "wa.me/971..." would be swallowed into the match.
		$start = '(?:(?<![0-9])(?P<prefix>\+|00)' . $separator . '|(?<![0-9+])(?<![0-9][\s\-\.\/\x{00A0}]))';

		return array(
			'mode'      => 'phone',
			'find'      => $find,
			'digits'    => $digits,
			'regex'     => '/' . $start . implode( $separator, $chars ) . '(?![0-9])/u',
			'raw_regex' => '/' . implode( $loose, $chars ) . '/',
			'likes'     => null,
		);
	}

	/**
	 * Strips a phone number to its digits, without an international "00".
	 *
	 * @param string $phone Phone number as written.
	 *
	 * @return string Digits only.
	 */
	private static function canonical_digits( string $phone ): string {
		$digits = (string) preg_replace( '/\D/', '', $phone );

		return 0 === strpos( $digits, '00' ) ? substr( $digits, 2 ) : $digits;
	}

	/**
	 * Turns literal text into a pattern where any whitespace matches any space.
	 *
	 * @param string $text Literal text.
	 *
	 * @return string Regex fragment.
	 */
	private static function flexible_whitespace( string $text ): string {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = is_array( $tokens ) ? $tokens : array( $text );

		return implode(
			'(?:\s|\x{00A0}|\x{202F}|&nbsp;|&#160;)+',
			array_map( static fn( string $token ): string => preg_quote( $token, '/' ), $tokens )
		);
	}

	/**
	 * Returns the longest whitespace-free run of a string.
	 *
	 * @param string $text Text.
	 *
	 * @return string The longest token.
	 */
	private static function longest_token( string $text ): string {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return $text;
		}

		usort( $tokens, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $tokens[0];
	}

	/**
	 * Finds every match in one string.
	 *
	 * @param string               $subject The string.
	 * @param array<string, mixed> $matcher The matcher.
	 *
	 * @return array<int, array{match: string, context: string}> The matches.
	 */
	public static function scan_string( string $subject, array $matcher ): array {
		if ( '' === $subject ) {
			return array();
		}

		if ( ! preg_match_all( $matcher['regex'], $subject, $found, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$matches = array();

		foreach ( $found[0] as $hit ) {
			$matches[] = array(
				'match'   => (string) $hit[0],
				'context' => self::context( $subject, (int) $hit[1], strlen( (string) $hit[0] ) ),
			);
		}

		return $matches;
	}

	/**
	 * Replaces every match in one string.
	 *
	 * @param string               $subject The string.
	 * @param array<string, mixed> $matcher The matcher.
	 * @param string               $replace Replacement text.
	 *
	 * @return array{0: string, 1: int} The new string and the number of replacements.
	 */
	public static function replace_string( string $subject, array $matcher, string $replace ): array {
		if ( '' === $subject ) {
			return array( $subject, 0 );
		}

		$count  = 0;
		$result = preg_replace_callback(
			$matcher['regex'],
			static fn( array $m ): string => self::replacement_for( $m, $matcher, $replace ),
			$subject,
			-1,
			$count
		);

		return is_string( $result ) ? array( $result, (int) $count ) : array( $subject, 0 );
	}

	/**
	 * Works out what one match should become.
	 *
	 * @param array<int|string, string> $m       The match groups.
	 * @param array<string, mixed>      $matcher The matcher.
	 * @param string                    $replace Replacement as given.
	 *
	 * @return string The replacement for this match.
	 */
	private static function replacement_for( array $m, array $matcher, string $replace ): string {
		$text = (string) $m[0];

		if ( 'phone' === $matcher['mode'] ) {
			// A number written without separators is a tel: link, a schema value
			// or a WhatsApp URL; it has to stay compact or it stops working.
			if ( preg_match( '/^(\+|00)?\d+$/', $text ) ) {
				return (string) ( $m['prefix'] ?? '' ) . self::canonical_digits( $replace );
			}

			if ( false !== strpos( $text, '%20' ) ) {
				return str_replace( ' ', '%20', $replace );
			}

			return $replace;
		}

		if ( isset( $m['h'] ) && '' !== $m['h'] ) {
			return htmlspecialchars( $replace, ENT_NOQUOTES, 'UTF-8', false );
		}

		return $replace;
	}

	/**
	 * Cheap test on a stored value before it is decoded and searched properly.
	 *
	 * @param string               $raw     The stored value, e.g. Elementor's JSON.
	 * @param array<string, mixed> $matcher The matcher.
	 *
	 * @return bool False only when the value certainly holds no match.
	 */
	private static function may_contain( string $raw, array $matcher ): bool {
		if ( '' === $raw ) {
			return false;
		}

		if ( null !== $matcher['raw_regex'] ) {
			$result = preg_match( $matcher['raw_regex'], $raw );

			// On a PCRE error (backtrack limit on a huge blob) look properly.
			return 0 !== $result;
		}

		foreach ( $matcher['likes'] as $like ) {
			if ( false !== stripos( $raw, $like ) ) {
				return true;
			}
		}

		// Case-insensitive JSON escapes cannot be checked cheaply; decode.
		return 1 === preg_match( '/\\\\u[0-9a-f]{4}/i', $raw );
	}

	/**
	 * Builds a short excerpt around a match.
	 *
	 * @param string $subject The string.
	 * @param int    $offset  Byte offset of the match.
	 * @param int    $length  Byte length of the match.
	 *
	 * @return string The match in double square brackets, with up to 60 bytes either side.
	 */
	private static function context( string $subject, int $offset, int $length ): string {
		$start  = max( 0, $offset - 60 );
		$before = mb_strcut( $subject, $start, $offset - $start, 'UTF-8' );
		$after  = mb_strcut( $subject, $offset + $length, 60, 'UTF-8' );
		$text   = ( $start > 0 ? '…' : '' ) . $before . '[[' . substr( $subject, $offset, $length ) . ']]' . $after
			. ( $offset + $length + 60 < strlen( $subject ) ? '…' : '' );

		return (string) preg_replace( '/\s+/u', ' ', $text );
	}

	/**
	 * Searches, and optionally replaces, across the site.
	 *
	 * @param array<string, mixed> $matcher The matcher.
	 * @param array<string, mixed> $args {
	 *     Scope and behaviour.
	 *
	 *     @type string|null $replace     Replacement, or null to only search.
	 *     @type bool        $dry_run     Report what would change without saving.
	 *     @type string[]    $fields      Subset of {@see self::FIELDS}.
	 *     @type string[]    $post_types  Restrict to these content post types.
	 *     @type int[]       $post_ids    Restrict to these posts.
	 *     @type string      $language    Polylang language slug.
	 *     @type int         $max_posts   Stop after this many posts with matches.
	 *     @type int         $after_id    Resume after this post id.
	 * }
	 *
	 * @return array<string, mixed> The outcome.
	 */
	public static function run( array $matcher, array $args ): array {
		$replace  = isset( $args['replace'] ) ? (string) $args['replace'] : null;
		$dry_run  = (bool) ( $args['dry_run'] ?? false );
		$fields   = $args['fields'];
		$language = (string) ( $args['language'] ?? '' );
		$limit    = (int) $args['max_posts'];
		$after_id = (int) ( $args['after_id'] ?? 0 );
		$started  = microtime( true );

		$scope = self::scope( $fields, (array) ( $args['post_types'] ?? array() ), '' !== $language, null !== $replace && ! $dry_run );

		$result = array(
			'posts'                 => array(),
			'posts_matched'         => 0,
			'total_matches'         => 0,
			'replaced'              => 0,
			'scanned'               => 0,
			'skipped_no_permission' => 0,
			'errors'                => array(),
			'more'                  => false,
			'next_after_id'         => 0,
			'notes'                 => $scope['notes'],
			'changed_ids'           => array(),
			'shared_changed'        => false,
		);

		if ( empty( $scope['post_types'] ) ) {
			$result['notes'][] = 'Nothing to search: no post type is in scope for the fields requested.';
			return $result;
		}

		$cursor = $after_id;

		while ( true ) {
			$ids = self::candidate_ids( $scope, $matcher, (array) ( $args['post_ids'] ?? array() ), $cursor );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$cursor = $id;

				if ( $result['posts_matched'] >= $limit || microtime( true ) - $started > self::TIME_BUDGET ) {
					$result['more']          = true;
					$result['next_after_id'] = $id - 1;
					break 2;
				}

				++$result['scanned'];

				$post = get_post( $id );

				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				if ( '' !== $language && ! self::in_language( $post, $language ) ) {
					continue;
				}

				if ( ! self::can_touch( $post, null !== $replace && ! $dry_run ) ) {
					++$result['skipped_no_permission'];
					continue;
				}

				$outcome = self::process_post( $post, $scope, $matcher, $replace, $dry_run );

				if ( empty( $outcome['matches'] ) ) {
					continue;
				}

				++$result['posts_matched'];
				$result['total_matches'] += (int) $outcome['match_count'];
				$result['replaced']      += (int) ( $outcome['replaced'] ?? 0 );

				if ( ! empty( $outcome['error'] ) ) {
					$result['errors'][] = array(
						'post_id' => $post->ID,
						'error'   => $outcome['error'],
					);
				}

				if ( ! empty( $outcome['replaced'] ) ) {
					$result['changed_ids'][] = $post->ID;

					if ( self::is_shared( $post ) ) {
						$result['shared_changed'] = true;
					}
				}

				$result['posts'][] = $outcome;
			}

			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}

		if ( 0 === $result['posts_matched'] && ! $result['more'] ) {
			$result['notes'][] = 'The text was not found in any stored content in scope. If it still shows on the site, it is coming from somewhere this tool cannot edit: an Elementor dynamic tag, a theme or plugin option, a shortcode, a cached page (try purge-cache), or an embedded third-party widget such as a booking engine.';
		}

		return $result;
	}

	/**
	 * Decides which post types and storage to search.
	 *
	 * @param string[] $fields        Requested fields.
	 * @param string[] $post_types    Requested content post types; empty for all exposed ones.
	 * @param bool     $has_language  Whether a language filter is set.
	 * @param bool     $writing       Whether changes will be saved.
	 *
	 * @return array{post_types: string[], content_types: string[], fields: string[], snippets: bool, menus: bool, notes: string[]} The scope.
	 */
	private static function scope( array $fields, array $post_types, bool $has_language, bool $writing ): array {
		$notes   = array();
		$allowed = ContentSupport::allowed_post_types();
		$content = empty( $post_types ) ? $allowed : array_values( array_intersect( $allowed, $post_types ) );
		$content = array_values( array_diff( $content, array( CustomCodeSupport::CPT, 'attachment' ) ) );

		if ( ! empty( $post_types ) && count( $content ) < count( $post_types ) ) {
			$notes[] = sprintf(
				'Ignored post types that are not exposed through MCP: %s.',
				implode( ', ', array_diff( $post_types, $content ) )
			);
		}

		$content_fields = array_intersect( $fields, array( 'title', 'excerpt', 'content', 'elementor' ) );

		if ( empty( $content_fields ) ) {
			$content = array();
		}

		if ( in_array( 'elementor', $fields, true ) && ElementorSupport::is_active() && post_type_exists( 'elementor_library' ) && ! in_array( 'elementor_library', $allowed, true ) ) {
			$notes[] = 'Elementor templates (headers, footers, global widgets, popups) were not searched because the "elementor_library" post type is not exposed on the Meta MCP settings screen. Text shared by every page usually lives there.';
		}

		$snippets = false;

		if ( in_array( 'custom_code', $fields, true ) && empty( $post_types ) ) {
			if ( ! \WP\MCP\Abilities\ContentAbilities::site_tools_enabled() ) {
				$notes[] = 'Custom Code snippets were skipped: the site-wide tools are switched off on the Meta MCP settings screen.';
			} elseif ( $has_language ) {
				$notes[] = 'Custom Code snippets were skipped: they have no language and print on every language their conditions match.';
			} elseif ( CustomCodeSupport::is_available() && current_user_can( 'manage_options' ) && ( ! $writing || current_user_can( 'unfiltered_html' ) ) ) {
				$snippets = true;
			} elseif ( CustomCodeSupport::is_available() ) {
				$notes[] = 'Custom Code snippets were skipped: searching them needs "manage_options", and changing them "unfiltered_html".';
			}
		}

		$menus = false;

		if ( in_array( 'menus', $fields, true ) && empty( $post_types ) ) {
			if ( ! \WP\MCP\Abilities\ContentAbilities::menus_enabled() ) {
				$notes[] = 'Menu items were skipped: the menu tools are switched off on the Meta MCP settings screen.';
			} elseif ( $has_language ) {
				$notes[] = 'Menu items were skipped because a language filter is set; filter menus by location with get-menu instead.';
			} elseif ( current_user_can( 'edit_theme_options' ) && post_type_exists( 'nav_menu_item' ) ) {
				$menus = true;
			} else {
				$notes[] = 'Menu items were skipped: they need the "edit_theme_options" capability.';
			}
		}

		$all = $content;

		if ( $snippets ) {
			$all[] = CustomCodeSupport::CPT;
		}

		if ( $menus ) {
			$all[] = 'nav_menu_item';
		}

		return array(
			'post_types'    => array_values( array_unique( $all ) ),
			'content_types' => $content,
			'fields'        => array_values( $fields ),
			'snippets'      => $snippets,
			'menus'         => $menus,
			'notes'         => $notes,
		);
	}

	/**
	 * Loads the next batch of post ids that may contain a match.
	 *
	 * @param array<string, mixed> $scope    The scope.
	 * @param array<string, mixed> $matcher  The matcher.
	 * @param int[]                $post_ids Restrict to these ids; empty for no restriction.
	 * @param int                  $after_id Only ids above this.
	 *
	 * @return int[] Post ids in ascending order.
	 */
	private static function candidate_ids( array $scope, array $matcher, array $post_ids, int $after_id ): array {
		global $wpdb;

		$types  = $scope['post_types'];
		$params = array();
		$where  = array();

		$where[] = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
		$params  = array_merge( $params, $types );

		$where[] = 'p.post_status IN (' . implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')';
		$params  = array_merge( $params, self::STATUSES );

		$where[]  = 'p.ID > %d';
		$params[] = $after_id;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );

		if ( ! empty( $post_ids ) ) {
			$where[] = 'p.ID IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $post_ids );
		}

		if ( is_array( $matcher['likes'] ) && ! empty( $matcher['likes'] ) ) {
			$columns = array();

			foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content' ) as $field => $column ) {
				if ( in_array( $field, $scope['fields'], true ) || ( 'title' === $field && $scope['menus'] ) ) {
					$columns[] = $column;
				}
			}

			$meta_keys = array();

			if ( in_array( 'elementor', $scope['fields'], true ) ) {
				$meta_keys[] = '_elementor_data';
			}

			if ( $scope['snippets'] ) {
				$meta_keys[] = '_elementor_code';
			}

			if ( $scope['menus'] ) {
				$meta_keys[] = '_menu_item_url';
			}

			$ors = array();

			foreach ( $matcher['likes'] as $like ) {
				$pattern = '%' . $wpdb->esc_like( $like ) . '%';

				foreach ( $columns as $column ) {
					$ors[]    = "p.{$column} LIKE %s";
					$params[] = $pattern;
				}

				if ( ! empty( $meta_keys ) ) {
					$ors[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN (" . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ') AND m.meta_value LIKE %s)';
					$params = array_merge( $params, $meta_keys, array( $pattern ) );
				}
			}

			if ( empty( $ors ) ) {
				return array();
			}

			$where[] = '(' . implode( ' OR ', $ors ) . ')';
		}

		$params[] = self::BATCH;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Placeholders are built above; there is no API for searching meta values.
		$sql = $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.ID ASC LIMIT %d', $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Finds, and optionally replaces, the matches in one post.
	 *
	 * @param \WP_Post             $post    The post.
	 * @param array<string, mixed> $scope   The scope.
	 * @param array<string, mixed> $matcher The matcher.
	 * @param string|null          $replace Replacement, or null to only search.
	 * @param bool                 $dry_run Whether to skip saving.
	 *
	 * @return array<string, mixed> The post's matches and what was done.
	 */
	private static function process_post( WP_Post $post, array $scope, array $matcher, ?string $replace, bool $dry_run ): array {
		$matches  = array();
		$replaced = 0;
		$errors   = array();
		$write    = null !== $replace && ! $dry_run;
		$is_menu  = 'nav_menu_item' === $post->post_type;
		$is_code  = CustomCodeSupport::CPT === $post->post_type;

		// Elementor first: saving through the document API regenerates
		// post_content, so the post fields are re-read afterwards.
		// Only posts actually rendered by Elementor: one switched back to the
		// WordPress editor keeps stale data, and saving it would switch the
		// post back to Elementor.
		$elementor_scanned = ! $is_menu && ! $is_code && in_array( 'elementor', $scope['fields'], true ) && ElementorSupport::is_built_with_elementor( $post->ID );
		$elementor_hits    = 0;

		if ( $elementor_scanned ) {
			$raw = get_post_meta( $post->ID, '_elementor_data', true );
			$raw = is_array( $raw ) ? (string) wp_json_encode( $raw ) : (string) $raw;

			if ( self::may_contain( $raw, $matcher ) ) {
				$elements = ElementorSupport::get_elements( $post->ID );

				if ( is_wp_error( $elements ) ) {
					$errors[] = $elements->get_error_message();
				} elseif ( ! empty( $elements ) ) {
					$hits  = array();
					$count = 0;
					$tree  = self::walk_elements( $elements, $matcher, $replace, $hits, $count );

					$matches        = array_merge( $matches, $hits );
					$elementor_hits = count( $hits );

					if ( $count > 0 && null !== $replace ) {
						if ( $write ) {
							$saved = ElementorSupport::save_elements( $post->ID, $tree );

							if ( is_wp_error( $saved ) ) {
								$errors[] = 'Elementor: ' . $saved->get_error_message();
							} else {
								$replaced += $count;
							}
						} else {
							$replaced += $count;
						}
					}
				}
			}

			clean_post_cache( $post->ID );
			$fresh = get_post( $post->ID );
			$post  = $fresh instanceof WP_Post ? $fresh : $post;
		}

		// Post fields.
		$columns = array();

		if ( $is_menu ) {
			$columns['title'] = 'post_title';
		} elseif ( ! $is_code ) {
			foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content' ) as $field => $column ) {
				if ( in_array( $field, $scope['fields'], true ) ) {
					$columns[ $field ] = $column;
				}
			}
		}

		// On an Elementor page post_content is only a plain-text copy Elementor
		// regenerates from its data, so its matches are the Elementor matches
		// again. They are not reported twice; the copy is still brought in line
		// when the Elementor data changed, in case it was saved without the
		// document API.
		$derived_content = $elementor_scanned && $elementor_hits > 0;
		$postarr         = array();
		$counted         = 0;

		foreach ( $columns as $field => $column ) {
			if ( 'content' === $field && $elementor_scanned && ! $derived_content ) {
				continue;
			}

			$value = (string) $post->{$column};
			$hits  = self::scan_string( $value, $matcher );

			if ( empty( $hits ) ) {
				continue;
			}

			$silent = 'content' === $field && $derived_content;

			if ( ! $silent ) {
				foreach ( $hits as $hit ) {
					$matches[] = array( 'field' => $is_menu ? 'menu_item_title' : $field ) + $hit;
				}
			}

			if ( null !== $replace ) {
				list( $new_value, $count ) = self::replace_string( $value, $matcher, $replace );

				if ( $count > 0 ) {
					$postarr[ $column ] = $new_value;

					if ( ! $silent ) {
						$counted += $count;
					}
				}
			}
		}

		if ( $write && ! empty( $postarr ) ) {
			$postarr['ID'] = $post->ID;
			$updated       = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $updated ) ) {
				$errors[] = $updated->get_error_message();
				$counted  = 0;
			}
		}

		$replaced += $counted;

		// Meta that holds text: a snippet's code, a custom menu link.
		$meta_key = $is_code ? '_elementor_code' : ( $is_menu ? '_menu_item_url' : '' );

		if ( '' !== $meta_key ) {
			$value = (string) get_post_meta( $post->ID, $meta_key, true );
			$hits  = self::scan_string( $value, $matcher );

			foreach ( $hits as $hit ) {
				$matches[] = array( 'field' => $is_code ? 'custom_code' : 'menu_item_url' ) + $hit;
			}

			if ( ! empty( $hits ) && null !== $replace ) {
				list( $new_value, $count ) = self::replace_string( $value, $matcher, $replace );

				if ( $is_menu ) {
					$new_value = esc_url_raw( $new_value );
				}

				if ( $count > 0 && '' !== $new_value ) {
					if ( $write ) {
						update_post_meta( $post->ID, $meta_key, wp_slash( $new_value ) );
					}

					$replaced += $count;
				} elseif ( $count > 0 ) {
					$errors[] = 'The menu item URL would not be a valid URL after the replacement; left unchanged.';
				}
			}
		}

		$summary = self::summarize( $post );

		$summary['match_count'] = count( $matches );
		$summary['matches']     = array_slice( $matches, 0, self::MAX_MATCHES_PER_POST );

		if ( count( $matches ) > self::MAX_MATCHES_PER_POST ) {
			$summary['matches_truncated'] = true;
		}

		if ( null !== $replace ) {
			$summary['replaced'] = max( 0, $replaced );
		}

		if ( ! empty( $errors ) ) {
			$summary['error'] = implode( ' ', $errors );
		}

		return $summary;
	}

	/**
	 * Walks an Elementor tree, collecting and optionally replacing matches.
	 *
	 * @param array<int, mixed>                $elements Elements at this level.
	 * @param array<string, mixed>             $matcher  The matcher.
	 * @param string|null                      $replace  Replacement, or null to only search.
	 * @param array<int, array<string, mixed>> $hits     Accumulated matches, by reference.
	 * @param int                              $count    Accumulated replacements, by reference.
	 *
	 * @return array<int, mixed> The tree, with replacements applied when asked for.
	 */
	private static function walk_elements( array $elements, array $matcher, ?string $replace, array &$hits, int &$count ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$found = array();
				$element['settings'] = self::walk_settings( $element['settings'], '', $matcher, $replace, $found, $count );

				foreach ( $found as $hit ) {
					$hits[] = array(
						'field'       => 'elementor',
						'element_id'  => (string) ( $element['id'] ?? '' ),
						'widget_type' => (string) ( $element['widgetType'] ?? ( $element['elType'] ?? '' ) ),
					) + $hit;
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::walk_elements( $element['elements'], $matcher, $replace, $hits, $count );
			}

			$elements[ $index ] = $element;
		}

		return $elements;
	}

	/**
	 * Walks one element's settings, including repeater rows and link objects.
	 *
	 * @param array<string|int, mixed>         $settings Settings at this level.
	 * @param string                           $path     Dotted path to this level.
	 * @param array<string, mixed>             $matcher  The matcher.
	 * @param string|null                      $replace  Replacement, or null to only search.
	 * @param array<int, array<string, mixed>> $hits     Accumulated matches, by reference.
	 * @param int                              $count    Accumulated replacements, by reference.
	 *
	 * @return array<string|int, mixed> The settings, with replacements applied when asked for.
	 */
	private static function walk_settings( array $settings, string $path, array $matcher, ?string $replace, array &$hits, int &$count ): array {
		foreach ( $settings as $key => $value ) {
			if ( in_array( (string) $key, self::SKIPPED_SETTING_KEYS, true ) ) {
				continue;
			}

			$key_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$settings[ $key ] = self::walk_settings( $value, $key_path, $matcher, $replace, $hits, $count );
				continue;
			}

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			foreach ( self::scan_string( $value, $matcher ) as $hit ) {
				$hits[] = array( 'setting' => $key_path ) + $hit;
			}

			if ( null !== $replace ) {
				list( $new_value, $replaced ) = self::replace_string( $value, $matcher, $replace );

				if ( $replaced > 0 ) {
					$settings[ $key ] = $new_value;
					$count           += $replaced;
				}
			}
		}

		return $settings;
	}

	/**
	 * Whether the current user may read, or change, a post's matches.
	 *
	 * @param \WP_Post $post    The post.
	 * @param bool     $writing Whether changes will be saved.
	 *
	 * @return bool True when permitted.
	 */
	private static function can_touch( WP_Post $post, bool $writing ): bool {
		if ( 'nav_menu_item' === $post->post_type ) {
			return current_user_can( 'edit_theme_options' );
		}

		if ( CustomCodeSupport::CPT === $post->post_type ) {
			return current_user_can( 'manage_options' ) && ( ! $writing || current_user_can( 'unfiltered_html' ) );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Whether a post is in a Polylang language.
	 *
	 * @param \WP_Post $post     The post.
	 * @param string   $language Language slug.
	 *
	 * @return bool True when it is, or when Polylang is inactive.
	 */
	private static function in_language( WP_Post $post, string $language ): bool {
		if ( ! PolylangSupport::is_active() ) {
			return true;
		}

		$assigned = PolylangSupport::get_post_language( $post->ID );

		return null !== $assigned && $assigned['slug'] === $language;
	}

	/**
	 * Whether a post's content shows up on pages other than its own.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return bool True for Elementor templates, Custom Code snippets and menu items.
	 */
	public static function is_shared( WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'elementor_library', CustomCodeSupport::CPT, 'nav_menu_item' ), true );
	}

	/**
	 * Describes a post for the result list.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array<string, mixed> Identifying details, plus where a shared template renders.
	 */
	private static function summarize( WP_Post $post ): array {
		$summary = array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
		);

		if ( 'nav_menu_item' !== $post->post_type && CustomCodeSupport::CPT !== $post->post_type ) {
			$summary['url'] = (string) get_permalink( $post->ID );
		}

		$language = PolylangSupport::get_post_language( $post->ID );

		if ( null !== $language ) {
			$summary['language'] = $language['slug'];
		}

		if ( 'elementor_library' === $post->post_type ) {
			$summary['template_type'] = (string) get_post_meta( $post->ID, '_elementor_template_type', true );
			$summary['conditions']    = ElementorSupport::get_conditions( $post->ID );
			$summary['shared']        = 'Elementor template: a change here shows on every page that uses it.';
		} elseif ( CustomCodeSupport::CPT === $post->post_type ) {
			$summary['conditions'] = ElementorSupport::get_conditions( $post->ID );
			$summary['shared']     = 'Custom Code snippet: prints on every page its conditions match (all pages when it has none).';
		} elseif ( 'nav_menu_item' === $post->post_type ) {
			$menus = wp_get_post_terms( $post->ID, 'nav_menu', array( 'fields' => 'names' ) );

			$summary['menu']   = is_array( $menus ) ? implode( ', ', $menus ) : '';
			$summary['shared'] = 'Menu item: shows wherever the menu is displayed.';
		}

		return $summary;
	}
}
