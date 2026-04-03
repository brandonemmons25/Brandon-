<?php
defined( 'ABSPATH' ) || exit;

class BLS_Database {

    const RESULTS_TABLE    = 'bls_results';
    const BUTTON_MAP_TABLE = 'bls_button_map';

    /**
     * Create/upgrade plugin tables on activation.
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $results_table = $wpdb->prefix . self::RESULTS_TABLE;
        $map_table     = $wpdb->prefix . self::BUTTON_MAP_TABLE;

        $sql_results = "CREATE TABLE {$results_table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            post_title    TEXT NOT NULL,
            post_type     VARCHAR(50)  NOT NULL DEFAULT 'post',
            post_status   VARCHAR(20)  NOT NULL DEFAULT 'publish',
            post_url      TEXT         NOT NULL DEFAULT '',
            button_text   TEXT         NOT NULL DEFAULT '',
            button_html   MEDIUMTEXT   NOT NULL DEFAULT '',
            has_link      TINYINT(1)   NOT NULL DEFAULT 0,
            link_url      TEXT         NOT NULL DEFAULT '',
            has_title     TINYINT(1)   NOT NULL DEFAULT 0,
            title_text    VARCHAR(255) NOT NULL DEFAULT '',
            opens_new_tab TINYINT(1)   NOT NULL DEFAULT 0,
            button_type   VARCHAR(30)  NOT NULL DEFAULT 'classic',
            scan_date     DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY post_id   (post_id),
            KEY has_link  (has_link),
            KEY has_title (has_title),
            KEY button_type (button_type)
        ) {$charset};";

        $sql_map = "CREATE TABLE {$map_table} (
            id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            button_text      TEXT        NOT NULL DEFAULT '',
            button_text_hash CHAR(32)    NOT NULL DEFAULT '',
            assigned_url     TEXT        NOT NULL DEFAULT '',
            assigned_title   VARCHAR(255) NOT NULL DEFAULT '',
            opens_new_tab    TINYINT(1)  NOT NULL DEFAULT 0,
            apply_count      INT(11)     NOT NULL DEFAULT 0,
            created_at       DATETIME    NOT NULL,
            updated_at       DATETIME    NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY button_text_hash (button_text_hash)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_results );
        dbDelta( $sql_map );

        update_option( 'bls_db_version', BLS_VERSION );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'bls_scheduled_scan' );
    }

    // -------------------------------------------------------------------------
    // Results helpers
    // -------------------------------------------------------------------------

    public static function clear_results() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::RESULTS_TABLE );
    }

    public static function insert_result( array $data ) {
        global $wpdb;
        $data['scan_date'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . self::RESULTS_TABLE, $data );
    }

    /**
     * Return paginated scan results with optional filters.
     */
    public static function get_results( array $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . self::RESULTS_TABLE;

        $defaults = [
            'per_page'   => 50,
            'page'       => 1,
            'has_link'   => '',   // '' | '0' | '1'
            'has_title'  => '',
            'post_type'  => '',
            'search'     => '',
            'orderby'    => 'post_title',
            'order'      => 'ASC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['has_link'] !== '' ) {
            $where[]  = 'has_link = %d';
            $params[] = (int) $args['has_link'];
        }
        if ( $args['has_title'] !== '' ) {
            $where[]  = 'has_title = %d';
            $params[] = (int) $args['has_title'];
        }
        if ( ! empty( $args['post_type'] ) ) {
            $where[]  = 'post_type = %s';
            $params[] = $args['post_type'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(post_title LIKE %s OR button_text LIKE %s OR link_url LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $allowed_orderby = [ 'post_title', 'post_type', 'has_link', 'has_title', 'button_text', 'scan_date' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'post_title';
        $order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $where_sql = implode( ' AND ', $where );
        $offset    = ( (int) $args['page'] - 1 ) * (int) $args['per_page'];

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        $all_params      = array_merge( $params, [ (int) $args['per_page'], $offset ] );
        $total           = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );
        $rows            = empty( $all_params ) ? $wpdb->get_results( $data_sql ) : $wpdb->get_results( $wpdb->prepare( $data_sql, $all_params ) );

        return compact( 'rows', 'total' );
    }

    /**
     * Summary counts for the dashboard.
     */
    public static function get_summary() {
        global $wpdb;
        $table = $wpdb->prefix . self::RESULTS_TABLE;
        return $wpdb->get_row(
            "SELECT
                COUNT(*)                                 AS total_buttons,
                SUM(has_link = 1)                        AS with_link,
                SUM(has_link = 0)                        AS without_link,
                SUM(has_link = 1 AND has_title = 0)      AS missing_title,
                SUM(has_link = 1 AND has_title = 1)      AS complete,
                COUNT(DISTINCT post_id)                  AS posts_scanned
            FROM {$table}",
            ARRAY_A
        );
    }

    /**
     * Trend data: group by button_text, aggregate counts.
     */
    public static function get_trends( $limit = 100 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::RESULTS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                button_text,
                COUNT(*)                          AS occurrences,
                COUNT(DISTINCT post_id)           AS unique_pages,
                SUM(has_link)                     AS linked_count,
                COUNT(*) - SUM(has_link)          AS unlinked_count,
                SUM(has_link = 1 AND has_title=0) AS missing_title_count,
                GROUP_CONCAT(DISTINCT link_url ORDER BY link_url SEPARATOR '|||') AS unique_urls,
                GROUP_CONCAT(DISTINCT post_id    ORDER BY post_id SEPARATOR ',')  AS post_ids
            FROM {$table}
            WHERE button_text != ''
            GROUP BY button_text
            ORDER BY occurrences DESC
            LIMIT %d",
            $limit
        ) );
    }

    // -------------------------------------------------------------------------
    // Button Map helpers
    // -------------------------------------------------------------------------

    public static function get_button_map( $limit = 200 ) {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}" . self::BUTTON_MAP_TABLE . " ORDER BY updated_at DESC LIMIT {$limit}"
        );
    }

    public static function upsert_button_map( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::BUTTON_MAP_TABLE;
        $hash  = md5( strtolower( trim( $data['button_text'] ) ) );
        $now   = current_time( 'mysql' );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE button_text_hash = %s", $hash
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'assigned_url'   => $data['assigned_url'],
                    'assigned_title' => $data['assigned_title'],
                    'opens_new_tab'  => (int) $data['opens_new_tab'],
                    'updated_at'     => $now,
                ],
                [ 'button_text_hash' => $hash ]
            );
            return (int) $existing;
        } else {
            $wpdb->insert( $table, [
                'button_text'      => $data['button_text'],
                'button_text_hash' => $hash,
                'assigned_url'     => $data['assigned_url'],
                'assigned_title'   => $data['assigned_title'],
                'opens_new_tab'    => (int) $data['opens_new_tab'],
                'apply_count'      => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ] );
            return (int) $wpdb->insert_id;
        }
    }

    public static function delete_button_map_entry( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::BUTTON_MAP_TABLE, [ 'id' => (int) $id ] );
    }

    public static function get_button_map_entry_by_hash( $hash ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::BUTTON_MAP_TABLE . " WHERE button_text_hash = %s",
            $hash
        ) );
    }

    public static function increment_apply_count( $id, $count ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}" . self::BUTTON_MAP_TABLE . " SET apply_count = apply_count + %d WHERE id = %d",
            $count, $id
        ) );
    }

    /**
     * Return distinct post types found in scan results.
     */
    public static function get_scanned_post_types() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->prefix}" . self::RESULTS_TABLE );
    }

    public static function get_last_scan_date() {
        global $wpdb;
        return $wpdb->get_var( "SELECT MAX(scan_date) FROM {$wpdb->prefix}" . self::RESULTS_TABLE );
    }
}
