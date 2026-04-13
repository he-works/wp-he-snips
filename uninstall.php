<?php
/**
 * He Snips - 플러그인 완전 삭제 처리
 *
 * 워드프레스 관리자에서 플러그인을 "삭제"할 때 실행됩니다.
 * (비활성화와 다름 - 비활성화는 이 파일을 실행하지 않음)
 *
 * ※ 데이터 보호 정책:
 *   스니펫 데이터는 기본적으로 보존됩니다.
 *   HE SNIPS 설정 페이지에서 "삭제 시 데이터도 제거" 옵션을 켠 경우에만
 *   DB 테이블과 옵션이 삭제됩니다.
 */

// 직접 접근 방지: WordPress가 호출한 경우에만 실행
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// "삭제 시 데이터 제거" 옵션이 켜진 경우에만 테이블 삭제
if ( get_option( 'he_snips_delete_on_uninstall', false ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';
    He_Snips_Database::drop_table();
    delete_option( 'he_snips_delete_on_uninstall' );
}
