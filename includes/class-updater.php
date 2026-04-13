<?php
/**
 * He Snips - GitHub 자동 업데이트 클래스
 *
 * GitHub 저장소의 최신 릴리스(또는 태그)를 확인하고,
 * 새 버전이 있으면 WordPress 업데이트 알림을 표시합니다.
 *
 * ▶ 사용 방법 (두 가지 중 하나):
 *   A) GitHub에서 태그만 올리는 경우: git tag v1.0.5 → git push origin v1.0.5
 *      → 태그 API에서 자동으로 새 버전을 감지합니다.
 *   B) GitHub Release를 만드는 경우: 릴리스에 he-snips.zip을 첨부하면
 *      더 정확한 업데이트 정보를 제공할 수 있습니다.
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

    /** @var int 캐시 유효 시간 (6시간) */
    private $cache_ttl = 21600;

    /**
     * @param string $repo           'he-works/wp-he-snips' 형식
     * @param string $plugin_file    plugin_basename() 결과
     * @param string $current_version 현재 버전 문자열
     */
    public function __construct( $repo, $plugin_file, $current_version ) {
        $this->repo            = $repo;
        $this->plugin_file     = $plugin_file;
        $this->current_version = $current_version;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection',             array( $this, 'fix_source_dir' ), 10, 4 );
    }

    // =========================================================
    // GitHub API 공통 요청 인자
    // =========================================================

    private function get_request_args() {
        return array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        );
    }

    // =========================================================
    // GitHub에서 최신 버전 정보 가져오기
    // =========================================================

    /**
     * 최신 버전 정보를 반환합니다.
     * Releases → Tags 순서로 시도합니다.
     *
     * @return object|false
     */
    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // 1차 시도: GitHub Releases API
        $release = $this->fetch_from_releases();

        // 2차 시도: GitHub Tags API (Release 없이 태그만 올린 경우)
        if ( ! $release ) {
            $release = $this->fetch_from_tags();
        }

        if ( $release ) {
            set_transient( $this->cache_key, $release, $this->cache_ttl );
        }

        return $release;
    }

    /**
     * GitHub Releases API에서 최신 릴리스를 가져옵니다.
     * (GitHub 저장소 → Releases 탭에 릴리스가 있는 경우)
     *
     * @return object|false
     */
    private function fetch_from_releases() {
        $url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get( $url, $this->get_request_args() );

        if ( is_wp_error( $response ) ) {
            return false;
        }
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        if ( ! isset( $release->tag_name ) ) {
            return false;
        }

        return $release;
    }

    /**
     * GitHub Tags API에서 최신 태그를 가져옵니다.
     * (git tag → git push 만 한 경우에도 감지 가능)
     *
     * @return object|false
     */
    private function fetch_from_tags() {
        $url      = 'https://api.github.com/repos/' . $this->repo . '/tags';
        $response = wp_remote_get( $url, $this->get_request_args() );

        if ( is_wp_error( $response ) ) {
            return false;
        }
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $tags = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $tags ) || ! isset( $tags[0]->name ) ) {
            return false;
        }

        // 태그 정보를 릴리스 형식으로 변환
        $tag = $tags[0];
        return (object) array(
            'tag_name'     => $tag->name,
            'zipball_url'  => $tag->zipball_url,
            'assets'       => array(),
            'body'         => '',
            'published_at' => '',
        );
    }

    /**
     * 릴리스에서 zip 파일 다운로드 URL을 반환합니다.
     * 릴리스에 첨부된 .zip 파일 → 없으면 소스 zipball 사용
     *
     * @param object $release
     * @return string|false
     */
    private function get_zip_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( substr( $asset->name, -4 ) === '.zip' ) {
                    return $asset->browser_download_url;
                }
            }
        }
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
            'name'              => 'HE SNIPS',
            'slug'              => dirname( $this->plugin_file ),
            'version'           => ltrim( $release->tag_name, 'v' ),
            'author'            => '<a href="https://github.com/he-works">HE WORKS.</a>',
            'homepage'          => 'https://github.com/' . $this->repo,
            'short_description' => 'PHP, JavaScript, CSS 코드 스니펫을 워드프레스에 쉽게 삽입하고 관리하세요.',
            'sections'          => array(
                'description' => $release->body ?? '최신 릴리스입니다.',
                'changelog'   => $release->body ?? '',
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
     * 올바른 플러그인 폴더명으로 교정합니다.
     *
     * @param string $source       압축 해제된 폴더 경로
     * @param string $remote_source 임시 디렉토리 경로
     * @param object $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        // 이 플러그인 업데이트가 아니면 통과
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $correct_name = dirname( $this->plugin_file ); // 'plugin-He-Snips'
        $new_source   = trailingslashit( $remote_source ) . $correct_name . '/';

        // 이미 올바른 폴더명이면 그대로 반환
        if ( trailingslashit( $source ) === $new_source ) {
            return $source;
        }

        // 목적지에 이미 같은 이름의 폴더가 있으면 제거
        if ( $wp_filesystem->exists( $new_source ) ) {
            $wp_filesystem->delete( $new_source, true );
        }

        if ( $wp_filesystem->move( $source, $new_source ) ) {
            return $new_source;
        }

        return $source;
    }

    /**
     * 캐시를 강제로 지웁니다.
     * 관리자 → 업데이트 페이지에서 "지금 확인"을 눌러도 지워집니다.
     */
    public static function clear_cache() {
        delete_transient( 'he_snips_update_cache' );
    }
}
