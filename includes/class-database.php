<?php
/**
 * He Snips - 데이터베이스 클래스
 * 스니펫을 저장하는 커스텀 DB 테이블을 생성하고 관리합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class He_Snips_Database {

    /**
     * 테이블 이름을 반환합니다.
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'he_snips';
    }

    /**
     * 플러그인 활성화 시 테이블을 생성합니다.
     */
    public static function create_table() {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255)    NOT NULL DEFAULT '',
            description TEXT            NULL,
            code        LONGTEXT        NOT NULL,
            type        VARCHAR(10)     NOT NULL DEFAULT 'php',
            js_position VARCHAR(10)     NOT NULL DEFAULT 'footer',
            active      TINYINT(1)      NOT NULL DEFAULT 1,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type   (type),
            KEY active (active)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // DB 버전 저장 (업데이트 감지용)
        update_option( 'he_snips_db_version', HE_SNIPS_DB_VERSION );
    }

    /**
     * 플러그인 완전 삭제 시 테이블을 제거합니다. (uninstall.php에서 호출)
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query( "DROP TABLE IF EXISTS $table_name" ); // phpcs:ignore
        delete_option( 'he_snips_db_version' );
    }
}
