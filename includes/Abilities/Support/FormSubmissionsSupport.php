<?php
/**
 * Read access to Elementor Pro form submissions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities\Support;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - FormSubmissionsSupport
 *
 * Elementor Pro's Submissions feature stores each form entry in
 * `{prefix}e_submissions` and its fields in `{prefix}e_submissions_values`.
 * There is no public PHP API for reading them outside its admin screen, so
 * the tables are read directly, always through `$wpdb->prepare()`.
 *
 * Submissions hold guests' personal data. The visitor's IP address and user
 * agent are never returned, the group is off by default, and every read needs
 * `manage_options`.
 */
final class FormSubmissionsSupport {

	/**
	 * Largest page size accepted.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Statuses a list can be narrowed to.
	 */
	public const STATUSES = array( 'all', 'new', 'read', 'trash' );

	/**
	 * Columns that are never returned.
	 */
	private const PRIVATE_COLUMNS = array( 'user_ip', 'user_agent', 'hash_id' );

	/**
	 * Cached column names of the submissions table.
	 *
	 * @var string[]|null
	 */
	private static ?array $columns = null;

	/**
	 * The submissions table name.
	 *
	 * @return string Table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'e_submissions';
	}

	/**
	 * The submission values table name.
	 *
	 * @return string Table name.
	 */
	public static function values_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'e_submissions_values';
	}

	/**
	 * Checks that submissions can be read on this site by this user.
	 *
	 * @return true|\WP_Error True when available.
	 */
	public static function require_available() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_capability', 'Reading form submissions requires the "manage_options" capability.' );
		}

		if ( ! ElementorSupport::is_pro_active() ) {
			return new WP_Error( 'elementor_pro_not_active', 'Form submissions are an Elementor Pro feature, and Elementor Pro (or PRO Elements) is not active on this site.' );
		}

		if ( ! self::table_exists( self::table() ) || ! self::table_exists( self::values_table() ) ) {
			return new WP_Error(
				'submissions_unavailable',
				'This site has no Elementor form submissions table. Submissions are only stored when Elementor Pro\'s "Form Submissions" feature is on (Elementor → Settings → Features) and a form has been sent since; forms that only send email keep no copy.'
			);
		}

		return true;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool True when it exists.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check, nothing to cache.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Column names of the submissions table, which vary between Elementor Pro versions.
	 *
	 * @return string[] Column names.
	 */
	private static function columns(): array {
		if ( null === self::$columns ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
			$rows          = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( self::table() ) . '`' );
			self::$columns = array_map( 'strval', is_array( $rows ) ? $rows : array() );
		}

		return self::$columns;
	}

	/**
	 * Lists submissions.
	 *
	 * @param array<string, mixed> $args Filters: form_name, post_id, status, search, after, before, per_page, page.
	 *
	 * @return array<string, mixed> Submissions and totals.
	 */
	public static function list( array $args ): array {
		global $wpdb;

		$table   = self::table();
		$values  = self::values_table();
		$columns = self::columns();
		$where   = array( '1=1' );
		$params  = array();

		if ( ! empty( $args['form_name'] ) && in_array( 'form_name', $columns, true ) ) {
			$where[]  = 's.form_name = %s';
			$params[] = (string) $args['form_name'];
		}

		if ( ! empty( $args['post_id'] ) && in_array( 'post_id', $columns, true ) ) {
			$where[]  = 's.post_id = %d';
			$params[] = (int) $args['post_id'];
		}

		$status = isset( $args['status'] ) ? (string) $args['status'] : 'all';
		$where  = array_merge( $where, self::status_clauses( $status, $columns ) );

		$date_column = in_array( 'created_at_gmt', $columns, true ) ? 'created_at_gmt' : ( in_array( 'created_at', $columns, true ) ? 'created_at' : '' );

		if ( '' !== $date_column ) {
			foreach ( array( 'after' => '>=', 'before' => '<=' ) as $key => $operator ) {
				if ( empty( $args[ $key ] ) ) {
					continue;
				}

				$time = strtotime( (string) $args[ $key ] );

				if ( false !== $time ) {
					$where[]  = "s.{$date_column} {$operator} %s";
					$params[] = gmdate( 'Y-m-d H:i:s', $time );
				}
			}
		}

		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$where[]  = "EXISTS ( SELECT 1 FROM `{$values}` v WHERE v.submission_id = s.id AND v.value LIKE %s )";
			$params[] = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$order_by = '' !== $date_column ? "s.{$date_column} DESC, s.id DESC" : 's.id DESC';
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM `{$table}` s WHERE {$sql_where}";
		$list_sql  = "SELECT s.* FROM `{$table}` s WHERE {$sql_where} ORDER BY {$order_by} LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb->prefix; every value goes through prepare().
		$total = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();
		$ids  = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$vals = self::values_for( $ids );

		$submissions = array();

		foreach ( $rows as $row ) {
			$submissions[] = self::format( $row, $vals[ (int) $row['id'] ] ?? array() );
		}

		return array(
			'submissions' => $submissions,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'forms'       => self::form_names(),
		);
	}

	/**
	 * Reads one submission.
	 *
	 * @param int $id Submission ID.
	 *
	 * @return array<string, mixed>|\WP_Error The submission.
	 */
	public static function get( int $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'submission_not_found', sprintf( 'No form submission with ID %d.', $id ) );
		}

		$vals = self::values_for( array( $id ) );

		return self::format( $row, $vals[ $id ] ?? array() );
	}

	/**
	 * The distinct form names with their submission counts.
	 *
	 * @return array<int, array{form_name: string, count: int}> Forms.
	 */
	private static function form_names(): array {
		global $wpdb;

		if ( ! in_array( 'form_name', self::columns(), true ) ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT form_name, COUNT(*) AS c FROM `{$table}` GROUP BY form_name ORDER BY c DESC LIMIT 50", ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'form_name' => (string) $row['form_name'],
				'count'     => (int) $row['c'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * WHERE clauses for a status filter.
	 *
	 * Elementor Pro keeps trash in `status` and read/unread in `is_read`.
	 *
	 * @param string   $status  Requested status.
	 * @param string[] $columns Table columns.
	 *
	 * @return string[] Clauses.
	 */
	private static function status_clauses( string $status, array $columns ): array {
		$has_status = in_array( 'status', $columns, true );
		$has_read   = in_array( 'is_read', $columns, true );
		$clauses    = array();

		if ( 'trash' === $status ) {
			return $has_status ? array( "s.status = 'trash'" ) : array( '1=0' );
		}

		if ( $has_status ) {
			$clauses[] = "s.status <> 'trash'";
		}

		if ( 'new' === $status && $has_read ) {
			$clauses[] = 's.is_read = 0';
		} elseif ( 'read' === $status && $has_read ) {
			$clauses[] = 's.is_read = 1';
		}

		return $clauses;
	}

	/**
	 * Field values for a set of submissions.
	 *
	 * @param int[] $ids Submission IDs.
	 *
	 * @return array<int, array<string, string>> Values keyed by submission, then field key.
	 */
	private static function values_for( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::values_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Table name from $wpdb->prefix; IDs are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT submission_id, `key`, `value` FROM `{$table}` WHERE submission_id IN ({$placeholders}) ORDER BY id ASC", $ids ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['submission_id'] ][ (string) $row['key'] ] = (string) $row['value'];
		}

		return $out;
	}

	/**
	 * Shapes a submission row for output, leaving out private columns.
	 *
	 * @param array<string, mixed>  $row    Submission row.
	 * @param array<string, string> $values Field values.
	 *
	 * @return array<string, mixed> The submission.
	 */
	private static function format( array $row, array $values ): array {
		foreach ( self::PRIVATE_COLUMNS as $column ) {
			unset( $row[ $column ] );
		}

		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$status  = (string) ( $row['status'] ?? '' );

		return array(
			'id'              => (int) $row['id'],
			'form_name'       => (string) ( $row['form_name'] ?? '' ),
			'element_id'      => (string) ( $row['element_id'] ?? '' ),
			'post_id'         => $post_id,
			'post_title'      => $post_id > 0 ? (string) get_the_title( $post_id ) : '',
			'page_url'        => (string) ( $row['referer'] ?? '' ),
			'page_title'      => (string) ( $row['referer_title'] ?? '' ),
			'status'          => 'trash' === $status ? 'trash' : ( ! empty( $row['is_read'] ) ? 'read' : 'new' ),
			'user_id'         => (int) ( $row['user_id'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'created_at_gmt'  => (string) ( $row['created_at_gmt'] ?? '' ),
			'actions_count'   => (int) ( $row['actions_count'] ?? 0 ),
			'actions_succeeded' => (int) ( $row['actions_succeeded_count'] ?? 0 ),
			'fields'          => (object) $values,
		);
	}
}
