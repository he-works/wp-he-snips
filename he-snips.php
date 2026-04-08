<?php
/**
 * Plugin Name: HE SNIPS
 * Plugin URI:  https://github.com/he-works/wp-he-snips
 * Description: PHP, JavaScript, CSS 코드 스니펫을 워드프레스에 쉽게 삽입하고 관리하세요.
 * Version:     1.0.0
 * Author:      HE WORKS.
 * Author URI:  https://github.com/he-works
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: he-snips
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// 워드프레스 외부에서 직접 접근 차단
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 플러그인 상수 정의
define( 'HE_SNIPS_VERSION', '1.0.0' );
define( 'HE_SNIPS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'HE_SNIPS_URL',     plugin_dir_url( __FILE__ ) );
define( 'HE_SNIPS_BASENAME', plugin_basename( __FILE__ ) );
define( 'HE_SNIPS_DB_VERSION', '1.0' );

// 필요한 클래스 파일 불러오기
require_once HE_SNIPS_DIR . 'includes/class-database.php';
require_once HE_SNIPS_DIR . 'includes/class-snippets.php';
require_once HE_SNIPS_DIR . 'includes/class-admin.php';
require_once HE_SNIPS_DIR . 'includes/class-updater.php';

// 플러그인 활성화 시: DB 테이블 생성
register_activation_hook( __FILE__, array( 'He_Snips_Database', 'create_table' ) );

// 플러그인 초기화
add_action( 'plugins_loaded', 'he_snips_init' );

function he_snips_init() {
    // DB 버전이 다를 경우 테이블 업데이트
    if ( get_option( 'he_snips_db_version' ) !== HE_SNIPS_DB_VERSION ) {
        He_Snips_Database::create_table();
    }

    // 관리자 페이지 초기화
    if ( is_admin() ) {
        new He_Snips_Admin();
    }

    // GitHub 자동 업데이트 초기화
    new He_Snips_Updater(
        'he-works/wp-he-snips',       // GitHub 저장소 (소유자/저장소명)
        HE_SNIPS_BASENAME,             // 플러그인 파일 경로
        HE_SNIPS_VERSION               // 현재 버전
    );

    // 프론트엔드에서 스니펫 실행
    $snippets = new He_Snips_Snippets();
    $snippets->run();
}
