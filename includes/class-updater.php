<?php
/**
 * He Snips - GitHub 자동 업데이트 클래스
 *
 * GitHub 저장소의 최신 릴리스를 확인하고,
 * 새 버전이 있으면 WordPress 업데이트 알림을 표시합니다.
 *
 * 사용 방법:
 *   1. GitHub에서 새 버전을 릴리스할 때 태그를 v1.0.1 형식으로 만드세요.
 *   2. 릴리스에 플러그인 zip 파일을 첨부하세요 (파일명: he-snips.zip).
 *   3. WordPress 관리자 → 업데이트 페이지에서 자동으로 감지됩니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class He_Snips_Updater {

    /** @var string GitHub 저장소 (소유자/저장소명) */
    private $repo;

    /** @var string 플러그인 파일 경로 (플러그인명/메인파일.php) */
    private $plugin_file;

    /** @var string 현재 설치된 버전 */
    private $current_version;

    /** @var string GitHub API 응답 캐시 키 */
    private $cache_key = 'he_snips_update_cache';

    /** @var int 캐시 유효 시간 (12시간) */
    private $cache_ttl = 43200;

    /**
     * @param string $repo           'he-works/wp-he-snips' 형식
     * @param string $plugin_file    plugin_basename() 결과
     * @param string $current_version 현재 버전 문자열
     */
    public function __construct( $repo, $plugin_file, $current_version ) {
        $this->repo            = $repo;
        $this->plugin_file     = $plugin_file;
        $this->current_version = $current_version;

        // WordPress 플러그인 업데이트 체크 필터에 연결
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // 플러그인 정보 팝업 필터
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );

        // 업데이트 후 폴더명 정정
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
    }

    // =========================================================
    // GitHub에서 최신 릴리스 정보 가져오기
    // =========================================================

    /**
     * GitHub API를 통해 최신 릴리스 정보를 가져옵니다.
     * 캐시를 사용하여 불필요한 API 호출을 줄입니다.
     *
     * @return object|false
     */
    private function get_latest_release() {
        // 캐시 확인
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // GitHub API 호출
        $api_url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get( $api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        // 오류 처리
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return false;
        }

        $body    = wp_remote_retrieve_body( $response );
        $release = json_decode( $body );

        if ( ! isset( $release->tag_name ) ) {
            return false;
        }

        // 캐시 저장
        set_transient( $this->cache_key, $release, $this->cache_ttl );

        return $release;
    }

    /**
     * 릴리스에서 zip 파일 다운로드 URL을 가져옵니다.
     *
     * @param object $release
     * @return string|false
     */
    private function get_zip_url( $release ) {
        // 첨부 파일(asset) 중 .zip 파일 찾기
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( substr( $asset->name, -4 ) === '.zip' ) {
                    return $asset->browser_download_url;
                }
            }
        }

        // asset이 없으면 소스코드 zip 사용
        return $release->zipball_url ?? false;
    }

    // =========================================================
    // WordPress 업데이트 시스템 연동
    // =========================================================

    /**
     * WordPress가 플러그인 업데이트를 체크할 때 실행됩니다.
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        // 버전 비교 (태그에서 'v' 접두사 제거)
        $latest_version = ltrim( $release->tag_name, 'v' );

        if ( version_compare( $this->current_version, $latest_version, '<' ) ) {
            $zip_url = $this->get_zip_url( $release );

            if ( $zip_url ) {
                $transient->response[ $this->plugin_file ] = (object) array(
                    'slug'        => dirname( $this->plugin_file ),
                    'plugin'      => $this->plugin_file,
                    'new_version' => $latest_version,
                    'url'         => 'https://github.com/' . $this->repo,
                    'package'     => $zip_url,
                    'icons'       => array(),
                    'banners'     => array(),
                    'tested'      => get_bloginfo( 'version' ),
                );
            }
        }

        return $transient;
    }

    /**
     * 플러그인 정보 팝업에 GitHub 정보를 표시합니다.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_file ) ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'HE SNIPS',
            'slug'          => dirname( $this->plugin_file ),
            'version'       => ltrim( $release->tag_name, 'v' ),
            'author'        => '<a href="https://github.com/he-works">HE WORKS.</a>',
            'homepage'      => 'https://github.com/' . $this->repo,
            'short_description' => 'PHP, JavaScript, CSS 코드 스니펫을 워드프레스에 쉽게 삽입하고 관리하세요.',
            'sections'      => array(
                'description'  => $release->body ?? '최신 릴리스입니다.',
                'changelog'    => $release->body ?? '',
            ),
            'download_link' => $this->get_zip_url( $release ),
            'requires'      => '5.0',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '7.4',
            'last_updated'  => $release->published_at ?? '',
        );
    }

    /**
     * GitHub에서 다운로드한 zip을 압축 해제하면 폴더명이
     * 'he-works-wp-he-snips-abc123' 같은 형태가 됩니다.
     * 올바른 폴더명(plugin-He-Snips)으로 교정합니다.
     *
     * @param string $source       압축 해제된 폴더 경로
     * @param string $remote_source
     * @param object $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        // 이 플러그인 업데이트인지 확인
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        $correct_name = dirname( $this->plugin_file ); // 'plugin-He-Snips'
        $new_source   = trailingslashit( $remote_source ) . $correct_name . '/';

        global $wp_filesystem;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }

        return $source;
    }

    /**
     * 캐시를 강제로 지웁니다. (새로고침할 때 유용)
     */
    public static function clear_cache() {
        delete_transient( 'he_snips_update_cache' );
    }
}
