<?php
/**
 * He Snips - 스니펫 클래스
 * 스니펫 CRUD(생성/읽기/수정/삭제)와 프론트엔드 실행을 담당합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class He_Snips_Snippets {

    // =========================================================
    // CRUD 메서드 (데이터베이스 조작)
    // =========================================================

    /**
     * 모든 스니펫을 가져옵니다.
     *
     * @param string|null $type  'php', 'js', 'css' 또는 null(전체)
     * @return array
     */
    public static function get_all( $type = null ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        if ( $type ) {
            return $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM $table WHERE type = %s ORDER BY id DESC", $type ) // phpcs:ignore
            );
        }

        return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" ); // phpcs:ignore
    }

    /**
     * 활성화된 스니펫만 가져옵니다.
     *
     * @param string      $type      'php', 'js', 'css'
     * @param string|null $position  JS의 경우 'header' 또는 'footer'
     * @return array
     */
    public static function get_active( $type, $position = null ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        if ( $position && $type === 'js' ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE type = %s AND js_position = %s AND active = 1 ORDER BY id ASC", // phpcs:ignore
                    $type,
                    $position
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE type = %s AND active = 1 ORDER BY id ASC", // phpcs:ignore
                $type
            )
        );
    }

    /**
     * 특정 스니펫 하나를 가져옵니다.
     *
     * @param int $id
     * @return object|null
     */
    public static function get_one( $id ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", absint( $id ) ) // phpcs:ignore
        );
    }

    /**
     * 새 스니펫을 저장합니다.
     *
     * @param array $data
     * @return int|false  삽입된 행의 ID 또는 false
     */
    public static function insert( $data ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        $result = $wpdb->insert(
            $table,
            array(
                'title'       => sanitize_text_field( $data['title'] ),
                'description' => sanitize_textarea_field( $data['description'] ?? '' ),
                'code'        => $data['code'],  // 코드는 sanitize 하지 않음 (의도된 코드)
                'type'        => in_array( $data['type'], array( 'php', 'js', 'css' ), true ) ? $data['type'] : 'php',
                'js_position' => in_array( $data['js_position'] ?? 'footer', array( 'header', 'footer' ), true ) ? $data['js_position'] : 'footer',
                'active'      => isset( $data['active'] ) ? 1 : 0,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 기존 스니펫을 수정합니다.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update( $id, $data ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        $result = $wpdb->update(
            $table,
            array(
                'title'       => sanitize_text_field( $data['title'] ),
                'description' => sanitize_textarea_field( $data['description'] ?? '' ),
                'code'        => $data['code'],
                'type'        => in_array( $data['type'], array( 'php', 'js', 'css' ), true ) ? $data['type'] : 'php',
                'js_position' => in_array( $data['js_position'] ?? 'footer', array( 'header', 'footer' ), true ) ? $data['js_position'] : 'footer',
                'active'      => isset( $data['active'] ) ? 1 : 0,
            ),
            array( 'id' => absint( $id ) ),
            array( '%s', '%s', '%s', '%s', '%s', '%d' ),
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * 스니펫을 삭제합니다.
     *
     * @param int $id
     * @return bool
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = He_Snips_Database::get_table_name();

        return (bool) $wpdb->delete(
            $table,
            array( 'id' => absint( $id ) ),
            array( '%d' )
        );
    }

    /**
     * 활성화/비활성화를 전환합니다.
     *
     * @param int $id
     * @return bool
     */
    public static function toggle_active( $id ) {
        global $wpdb;
        $table   = He_Snips_Database::get_table_name();
        $snippet = self::get_one( $id );

        if ( ! $snippet ) {
            return false;
        }

        $new_state = $snippet->active ? 0 : 1;

        return (bool) $wpdb->update(
            $table,
            array( 'active' => $new_state ),
            array( 'id'     => absint( $id ) ),
            array( '%d' ),
            array( '%d' )
        );
    }

    // =========================================================
    // 프론트엔드 실행 메서드
    // =========================================================

    /**
     * 워드프레스 훅에 스니펫 실행 로직을 등록합니다.
     */
    public function run() {
        // PHP 스니펫: 가능한 빠른 시점에 실행 (init 훅)
        add_action( 'init', array( $this, 'execute_php_snippets' ), 1 );

        // JS 스니펫 (헤더): wp_head 바로 앞
        add_action( 'wp_head', array( $this, 'output_js_header_snippets' ), 999 );

        // JS 스니펫 (푸터): wp_footer 가장 마지막
        add_action( 'wp_footer', array( $this, 'output_js_footer_snippets' ), 999 );

        // CSS 스니펫: wp_head에 삽입
        add_action( 'wp_head', array( $this, 'output_css_snippets' ), 998 );
    }

    /**
     * 활성화된 PHP 스니펫을 실행합니다.
     */
    public function execute_php_snippets() {
        $snippets = self::get_active( 'php' );

        foreach ( $snippets as $snippet ) {
            try {
                // phpcs:ignore Squiz.PHP.Eval.Discouraged
                eval( $snippet->code );
            } catch ( ParseError $e ) {
                // 관리자에게만 오류 표시
                if ( current_user_can( 'manage_options' ) ) {
                    add_action( 'admin_notices', function() use ( $snippet, $e ) {
                        echo '<div class="notice notice-error"><p>';
                        echo '<strong>He Snips 오류</strong> - 스니펫 "' . esc_html( $snippet->title ) . '" 실행 실패: ';
                        echo esc_html( $e->getMessage() );
                        echo '</p></div>';
                    } );
                }
            }
        }
    }

    /**
     * JS 스니펫 (헤더 위치)을 출력합니다.
     */
    public function output_js_header_snippets() {
        $snippets = self::get_active( 'js', 'header' );
        $this->output_inline_scripts( $snippets );
    }

    /**
     * JS 스니펫 (푸터 위치)을 출력합니다.
     */
    public function output_js_footer_snippets() {
        $snippets = self::get_active( 'js', 'footer' );
        $this->output_inline_scripts( $snippets );
    }

    /**
     * JS 스니펫 배열을 <script> 태그로 출력합니다.
     *
     * @param array $snippets
     */
    private function output_inline_scripts( $snippets ) {
        if ( empty( $snippets ) ) {
            return;
        }

        foreach ( $snippets as $snippet ) {
            echo "\n<script id=\"he-snips-js-" . absint( $snippet->id ) . "\">\n";
            echo $snippet->code . "\n"; // phpcs:ignore
            echo "</script>\n";
        }
    }

    /**
     * CSS 스니펫을 <style> 태그로 출력합니다.
     */
    public function output_css_snippets() {
        $snippets = self::get_active( 'css' );

        if ( empty( $snippets ) ) {
            return;
        }

        echo "\n<style id=\"he-snips-css\">\n";
        foreach ( $snippets as $snippet ) {
            echo "/* Snippet: " . esc_html( $snippet->title ) . " */\n";
            echo $snippet->code . "\n"; // phpcs:ignore
        }
        echo "</style>\n";
    }
}
