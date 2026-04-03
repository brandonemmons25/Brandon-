<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database helpers for the Gravity Forms confirmation scanner.
 * Kept separate from BLS_Database to avoid class bloat.
 */
class BLS_GF_Database {

    const TABLE = 'bls_gf_results';

    public static function install() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id             INT(11)      NOT NULL DEFAULT 0,
            form_title          VARCHAR(255) NOT NULL DEFAULT '',
            confirmation_id     VARCHAR(64)  NOT NULL DEFAULT '',
            confirmation_name   VARCHAR(255) NOT NULL DEFAULT '',
            confirmation_type   VARCHAR(20)  NOT NULL DEFAULT 'message',
            redirect_url        TEXT         NOT NULL DEFAULT '',
            redirect_page_id    BIGINT(20)   NOT NULL DEFAULT 0,
            redirect_page_title VARCHAR(255) NOT NULL DEFAULT '',
            redirect_page_slug  VARCHAR(255) NOT NULL DEFAULT '',
            redirect_page_url   TEXT         NOT NULL DEFAULT '',
            is_redirect         TINYINT(1)   NOT NULL DEFAULT 0,
            is_thank_you_page   TINYINT(1)   NOT NULL DEFAULT 0,
            is_child_page       TINYINT(1)   NOT NULL DEFAULT 0,
            host_page_ids       TEXT         NOT NULL DEFAULT '',
            host_page_titles    TEXT         NOT NULL DEFAULT '',
            passes              TINYINT(1)   NOT NULL DEFAULT 0,
            fail_reasons        TEXT         NOT NULL DEFAULT '',
            scan_date           DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY form_id  (form_id),
            KEY passes   (passes)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function clear_results() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::TABLE );
    }

    public static function insert_result( array $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE, $data );
    }

    /**
     * Return all rows, optionally filtered.
     *
     * @param array $args { passes?: ''|'0'|'1', search?: string }
     */
    public static function get_results( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where  = [ '1=1' ];
        $params = [];

        if ( isset( $args['passes'] ) && $args['passes'] !== '' ) {
            $where[]  = 'passes = %d';
            $params[] = (int) $args['passes'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(form_title LIKE %s OR confirmation_name LIKE %s OR redirect_page_slug LIKE %s OR host_page_titles LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY passes ASC, form_title ASC";

        return empty( $params )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    public static function get_summary(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (array) $wpdb->get_row(
            "SELECT
                COUNT(*)              AS total_confirmations,
                COUNT(DISTINCT form_id) AS total_forms,
                SUM(passes = 1)       AS passing,
                SUM(passes = 0)       AS failing,
                SUM(is_redirect = 0)  AS inline_message,
                SUM(is_redirect = 1 AND is_thank_you_page = 0) AS no_thank_you,
                SUM(is_redirect = 1 AND is_thank_you_page = 1 AND is_child_page = 0) AS not_child
            FROM {$table}",
            ARRAY_A
        );
    }

    public static function get_last_scan_date(): ?string {
        global $wpdb;
        return $wpdb->get_var( 'SELECT MAX(scan_date) FROM ' . $wpdb->prefix . self::TABLE );
    }
}
