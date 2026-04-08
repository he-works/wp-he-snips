<?php
/**
 * He Snips - 플러그인 완전 삭제 처리
 *
 * 워드프레스 관리자에서 플러그인을 "삭제"할 때 실행됩니다.
 * (비활성화와 다름 - 비활성화는 이 파일을 실행하지 않음)
 * DB 테이블과 옵션을 모두 제거합니다.
 */

// 직접 접근 방지: WordPress가 호출한 경우에만 실행
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 필요한 클래스 로드
require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';

// DB 테이블 삭제
He_Snips_Database::drop_table();
