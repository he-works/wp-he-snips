<?php
/**
 * He Snips - 관리자 페이지 클래스
 * 워드프레스 관리자 화면에서 스니펫을 관리하는 UI를 담당합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class He_Snips_Admin {

    public function __construct() {
        add_action( 'admin_init',            array( $this, 'process_save' ) );   // 헤더 전송 전에 저장 처리
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_he_snips_toggle', array( $this, 'ajax_toggle' ) );
        add_action( 'wp_ajax_he_snips_delete', array( $this, 'ajax_delete' ) );
        add_filter( 'plugin_action_links_' . HE_SNIPS_BASENAME, array( $this, 'plugin_action_links' ) );
        add_filter( 'plugin_row_meta',                           array( $this, 'plugin_row_meta' ), 10, 2 );
    }

    // =========================================================
    // 저장 처리 (admin_init: 헤더 전송 전이라 wp_redirect 정상 동작)
    // =========================================================

    public function process_save() {
        if ( ! isset( $_POST['he_snips_save'] ) ) {
            return;
        }

        // #2: wp_verify_nonce 에 wp_unslash 적용 (WPCS 권장)
        if ( ! isset( $_POST['he_snips_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['he_snips_nonce'] ), 'he_snips_save' ) ) {
            wp_die( '보안 검사 실패. 다시 시도해 주세요.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '접근 권한이 없습니다.' );
        }

        // #6: $_POST 값에 wp_unslash 적용
        $type = sanitize_key( wp_unslash( $_POST['type'] ?? 'php' ) );

        $data = array(
            'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'code'        => wp_unslash( $_POST['code'] ?? '' ),
            'type'        => $type,
            'js_position' => sanitize_key( wp_unslash( $_POST['js_position'] ?? 'footer' ) ),
            'active'      => ( isset( $_POST['active'] ) && wp_unslash( $_POST['active'] ) === '1' ) ? 1 : 0,
        );

        $id = absint( $_POST['snippet_id'] ?? 0 );

        if ( $id > 0 ) {
            He_Snips_Snippets::update( $id, $data );
            $message = 'updated';
        } else {
            He_Snips_Snippets::insert( $data );
            $message = 'added';
        }

        wp_redirect( admin_url( 'admin.php?page=he-snips&tab=' . $type . '&saved=' . $message ) );
        exit;
    }

    // =========================================================
    // 메뉴 등록
    // =========================================================

    public function register_menu() {
        /*
         * 1뎁스 최하단에 배치합니다.
         * WordPress 기본 메뉴 position 값:
         *   2=대시보드, 10=미디어, 20=페이지, 25=댓글, 60=외모, 65=플러그인,
         *   70=사용자, 75=도구, 80=설정, 99=구분선
         * 100 이상을 사용하면 "설정" 아래 최하단에 위치합니다.
         */
        add_menu_page(
            'HE SNIPS',                    // 페이지 제목 (<title>)
            'HE SNIPS',                    // 사이드바 메뉴 텍스트
            'manage_options',              // 권한
            'he-snips',                    // 메뉴 슬러그
            array( $this, 'render_page' ), // 콜백
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' ),
            100                            // position: 설정(80) 아래 최하단
        );
    }

    // =========================================================
    // 에셋(CSS/JS) 등록
    // =========================================================

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'he-snips' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'he-snips-admin',
            HE_SNIPS_URL . 'assets/css/admin.css',
            array(),
            HE_SNIPS_VERSION
        );

        wp_enqueue_script(
            'he-snips-admin',
            HE_SNIPS_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            HE_SNIPS_VERSION,
            true
        );

        wp_localize_script( 'he-snips-admin', 'heSnips', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'he_snips_nonce' ),
            'deleteMsg' => '이 스니펫을 정말 삭제하시겠습니까?',
        ) );

        // #6: $_GET 값에도 wp_unslash 적용
        if ( isset( $_GET['action'] ) && in_array( sanitize_key( wp_unslash( $_GET['action'] ) ), array( 'add', 'edit' ), true ) ) {
            $type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'php';

            if ( isset( $_GET['id'] ) ) {
                $snippet = He_Snips_Snippets::get_one( absint( wp_unslash( $_GET['id'] ) ) );
                if ( $snippet ) {
                    $type = $snippet->type;
                }
            }

            $this->enqueue_codemirror( $type );
        }
    }

    /**
     * CodeMirror 에디터를 로드합니다.
     *
     * ※ PHP 모드: 'text/x-php' (순수 PHP 모드)
     *   'application/x-httpd-php'는 HTML+PHP 혼합 모드로,
     *   <?php 태그 없는 순수 PHP 코드를 HTML로 인식해 색상이 나오지 않습니다.
     */
    private function enqueue_codemirror( $type ) {
        $mode_map = array(
            'php' => 'text/x-php',
            'js'  => 'text/javascript',
            'css' => 'text/css',
        );

        $settings = wp_enqueue_code_editor( array(
            'type'       => $mode_map[ $type ] ?? 'text/plain',
            'codemirror' => array(
                'indentUnit'        => 4,
                'tabSize'           => 4,
                'lineNumbers'       => true,
                'lineWrapping'      => false,
                'matchBrackets'     => true,
                'autoCloseBrackets' => true,
            ),
        ) );

        if ( false === $settings ) {
            return; // 사용자가 코드 에디터를 비활성화한 경우
        }

        // JS 폴백 초기화에서 사용할 수 있도록 설정값을 전역 변수로 전달
        wp_localize_script( 'he-snips-admin', 'heSnipsCodeSettings', $settings );

        // CodeMirror 기본 초기화 스크립트 (code-editor 스크립트 바로 뒤에 실행)
        $json_settings = wp_json_encode( $settings );
        wp_add_inline_script(
            'code-editor',
            "jQuery(function(\$){
                if (typeof wp !== 'undefined' && wp.codeEditor && !window.heSnipsEditor) {
                    try {
                        var editor = wp.codeEditor.initialize(\$('#he-snips-code'), {$json_settings});
                        window.heSnipsEditor = editor.codemirror;
                    } catch(e) {}
                }
            });"
        );
    }

    // =========================================================
    // 페이지 라우팅
    // =========================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '접근 권한이 없습니다.' );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

        switch ( $action ) {
            case 'add':
                $this->render_editor_page();
                break;
            case 'edit':
                $this->render_editor_page( absint( $_GET['id'] ?? 0 ) );
                break;
            default:
                $this->render_list_page();
                break;
        }
    }

    // =========================================================
    // Ajax: 토글
    // =========================================================

    public function ajax_toggle() {
        check_ajax_referer( 'he_snips_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '권한 없음' );
        }

        $id     = absint( $_POST['id'] ?? 0 );
        $result = He_Snips_Snippets::toggle_active( $id );

        if ( $result ) {
            $snippet = He_Snips_Snippets::get_one( $id );
            wp_send_json_success( array( 'active' => (int) $snippet->active ) );
        } else {
            wp_send_json_error( '처리 실패' );
        }
    }

    // =========================================================
    // Ajax: 삭제
    // =========================================================

    public function ajax_delete() {
        check_ajax_referer( 'he_snips_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '권한 없음' );
        }

        $id     = absint( $_POST['id'] ?? 0 );
        $result = He_Snips_Snippets::delete( $id );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( '삭제 실패' );
        }
    }

    // =========================================================
    // 목록 페이지 렌더링
    // =========================================================

    private function render_list_page() {
        // #6: $_GET unslash
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
        $tabs = array(
            'all' => array( 'label' => '전체',        'hint' => '모든 스니펫' ),
            'php' => array( 'label' => 'PHP',         'hint' => 'functions.php 역할' ),
            'js'  => array( 'label' => 'JavaScript',  'hint' => '헤더·푸터에 삽입' ),
            'css' => array( 'label' => 'CSS',         'hint' => '전체 스타일 적용' ),
        );
        // 전체 탭이면 null(전체 조회), 나머지는 타입 필터
        $filter_type = ( $active_tab === 'all' ) ? null : $active_tab;
        $snippets    = He_Snips_Snippets::get_all( $filter_type );
        $saved       = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : '';
        // 새 스니펫 추가 버튼: 전체 탭에서는 php 기본값으로
        $add_type = ( $active_tab === 'all' ) ? 'php' : $active_tab;
        ?>
        <div class="wrap he-snips-wrap">

            <div class="he-snips-page-header">
                <div class="he-snips-page-header-left">
                    <div class="he-snips-logo-mark"><span>&lt;/&gt;</span></div>
                    <div>
                        <h1 class="he-snips-page-title">HE SNIPS</h1>
                        <p class="he-snips-page-subtitle">코드 스니펫 관리자</p>
                    </div>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=he-snips&action=add&type=' . $add_type ) ); ?>"
                   class="he-snips-btn he-snips-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    새 스니펫 추가
                </a>
            </div>

            <?php if ( $saved === 'added' ) : ?>
                <div class="he-snips-alert he-snips-alert-success">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    스니펫이 추가되었습니다.
                </div>
            <?php elseif ( $saved === 'updated' ) : ?>
                <div class="he-snips-alert he-snips-alert-success">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    스니펫이 수정되었습니다.
                </div>
            <?php endif; ?>

            <div class="he-snips-tab-nav">
                <?php foreach ( $tabs as $slug => $info ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=he-snips&tab=' . $slug ) ); ?>"
                       class="he-snips-tab <?php echo $active_tab === $slug ? 'is-active' : ''; ?>">
                        <?php if ( $slug === 'all' ) : ?>
                            <span class="he-snips-badge he-snips-badge-all"><?php echo esc_html( $info['label'] ); ?></span>
                        <?php else : ?>
                            <span class="he-snips-badge he-snips-badge-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $info['label'] ); ?></span>
                        <?php endif; ?>
                        <span class="he-snips-tab-hint"><?php echo esc_html( $info['hint'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="he-snips-card">
                <?php if ( empty( $snippets ) ) : ?>
                    <div class="he-snips-empty">
                        <div class="he-snips-empty-icon">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        </div>
                        <p class="he-snips-empty-title">스니펫이 없습니다</p>
                        <p class="he-snips-empty-desc">첫 번째 스니펫을 추가해서 시작해 보세요.</p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=he-snips&action=add&type=' . $add_type ) ); ?>"
                           class="he-snips-btn he-snips-btn-primary">
                            + 새 스니펫 추가
                        </a>
                    </div>
                <?php else : ?>
                    <div class="he-snips-snippet-list">
                        <?php foreach ( $snippets as $snippet ) : ?>
                            <div class="he-snips-snippet-item <?php echo $snippet->active ? 'is-active' : 'is-inactive'; ?>"
                                 data-id="<?php echo absint( $snippet->id ); ?>">

                                <div class="he-snips-snippet-info">
                                    <div class="he-snips-snippet-meta">
                                        <span class="he-snips-badge he-snips-badge-<?php echo esc_attr( $snippet->type ); ?>"><?php echo strtoupper( $snippet->type ); ?></span>
                                        <?php if ( $snippet->type === 'js' ) : ?>
                                            <span class="he-snips-pos-tag he-snips-pos-<?php echo esc_attr( $snippet->js_position ); ?>">
                                                <?php echo $snippet->js_position === 'header' ? '&lt;head&gt;' : '&lt;/body&gt;'; ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="he-snips-snippet-date"><?php echo esc_html( date_i18n( 'Y.m.d', strtotime( $snippet->updated_at ) ) ); ?></span>
                                    </div>
                                    <strong class="he-snips-snippet-title"><?php echo esc_html( $snippet->title ); ?></strong>
                                    <?php if ( $snippet->description ) : ?>
                                        <p class="he-snips-snippet-desc"><?php echo esc_html( $snippet->description ); ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="he-snips-snippet-actions">
                                    <button class="he-snips-pill-toggle <?php echo $snippet->active ? 'is-on' : 'is-off'; ?>"
                                            data-id="<?php echo absint( $snippet->id ); ?>">
                                        <span class="he-snips-label-current"><?php echo $snippet->active ? '활성' : '비활성'; ?></span>
                                        <span class="he-snips-label-hover"><?php echo $snippet->active ? '비활성으로' : '활성으로'; ?></span>
                                    </button>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=he-snips&action=edit&id=' . $snippet->id ) ); ?>"
                                       class="he-snips-btn he-snips-btn-ghost he-snips-btn-sm">
                                        수정
                                    </a>
                                    <button class="he-snips-btn he-snips-btn-danger he-snips-btn-sm he-snips-delete"
                                            data-id="<?php echo absint( $snippet->id ); ?>">
                                        삭제
                                    </button>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <p class="he-snips-footer">HE SNIPS by <strong>HE WORKS.</strong> &mdash; v<?php echo esc_html( HE_SNIPS_VERSION ); ?></p>
        </div>
        <?php
    }

    // =========================================================
    // 편집기 페이지 렌더링 (추가 / 수정)
    // =========================================================

    private function render_editor_page( $id = 0 ) {
        $snippet  = null;
        $is_edit  = false;
        // #6: $_GET unslash
        $type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'php';

        if ( $id > 0 ) {
            $snippet = He_Snips_Snippets::get_one( $id );
            if ( ! $snippet ) {
                wp_die( '스니펫을 찾을 수 없습니다.' );
            }
            $is_edit = true;
            $type    = $snippet->type;
        }

        // #5: escape-on-output 원칙 — 출력 직전에 이스케이프 (변수에 미리 담아두지 않음)
        $title       = $snippet ? $snippet->title       : '';
        $description = $snippet ? $snippet->description : '';
        $code        = $snippet ? $snippet->code        : '';
        $active      = $snippet ? (bool) $snippet->active : true;
        $js_position = $snippet ? $snippet->js_position  : 'footer';

        $type_hints = array(
            'php' => '&lt;?php 태그 없이 PHP 코드만 입력하세요. functions.php에 추가하는 것과 동일하게 동작합니다.',
            'js'  => '&lt;script&gt; 태그 없이 순수 JavaScript 코드만 입력하세요.',
            'css' => '&lt;style&gt; 태그 없이 순수 CSS 코드만 입력하세요.',
        );
        ?>
        <div class="wrap he-snips-wrap">

            <!-- ① 페이지 헤더 -->
            <div class="he-snips-page-header">
                <div class="he-snips-page-header-left">
                    <div class="he-snips-logo-mark"><span>&lt;/&gt;</span></div>
                    <div>
                        <h1 class="he-snips-page-title">HE SNIPS</h1>
                        <p class="he-snips-page-subtitle"><?php echo $is_edit ? '스니펫 수정' : '새 스니펫 추가'; ?></p>
                    </div>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=he-snips&tab=' . $type ) ); ?>"
                   class="he-snips-btn he-snips-btn-ghost">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                    목록으로
                </a>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=he-snips' ) ); ?>" id="he-snips-editor-form">
                <?php wp_nonce_field( 'he_snips_save', 'he_snips_nonce' ); ?>
                <input type="hidden" name="he_snips_save" value="1">
                <input type="hidden" name="snippet_id" value="<?php echo absint( $id ); ?>">
                <input type="hidden" name="active" id="he-snips-active-input" value="<?php echo $active ? '1' : '0'; ?>">

                <!-- ② 기본 정보 (제목+설명): 헤더 바로 아래, 전체 폭 -->
                <div class="he-snips-panel he-snips-info-panel">
                    <div class="he-snips-panel-body he-snips-info-panel-body">
                        <div class="he-snips-field he-snips-field-title">
                            <label class="he-snips-label" for="he-snips-title">
                                제목 <span class="he-snips-required">*</span>
                            </label>
                            <input type="text" id="he-snips-title" name="title" value="<?php echo esc_attr( $title ); ?>"
                                   class="he-snips-input" required placeholder="스니펫 이름을 입력하세요">
                        </div>
                        <div class="he-snips-field he-snips-field-desc">
                            <label class="he-snips-label" for="he-snips-description">
                                설명 <span class="he-snips-optional">(선택)</span>
                            </label>
                            <textarea id="he-snips-description" name="description"
                                      class="he-snips-input he-snips-textarea"
                                      rows="2"
                                      placeholder="이 스니펫이 무엇을 하는지 설명하세요"><?php echo esc_textarea( $description ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ③ 에디터 + 사이드바 (2컬럼) -->
                <div class="he-snips-editor-layout">

                    <!-- 왼쪽: 코드 에디터 -->
                    <div class="he-snips-editor-main">
                        <div class="he-snips-editor-topbar">
                            <span class="he-snips-badge he-snips-badge-<?php echo esc_attr( $type ); ?>"><?php echo strtoupper( $type ); ?></span>
                            <span class="he-snips-editor-hint"><?php echo $type_hints[ $type ]; ?></span>
                        </div>
                        <div class="he-snips-editor-body">
                            <textarea id="he-snips-code" name="code" rows="28" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $code ); ?></textarea>
                        </div>
                        <div class="he-snips-resize-handle" title="드래그하여 높이 조절">
                            <span class="he-snips-resize-grip"></span>
                        </div>
                    </div>

                    <!-- 오른쪽: 설정 사이드바 -->
                    <div class="he-snips-editor-sidebar">

                        <!-- 1. 코드 타입 -->
                        <div class="he-snips-panel">
                            <div class="he-snips-panel-header">
                                <span class="he-snips-panel-title">코드 타입</span>
                            </div>
                            <div class="he-snips-panel-body">
                                <label class="he-snips-type-option <?php echo $type === 'php' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="type" value="php" <?php checked( $type, 'php' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-badge he-snips-badge-php">PHP</span>
                                    <span class="he-snips-type-desc">functions.php 역할</span>
                                </label>
                                <label class="he-snips-type-option <?php echo $type === 'js' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="type" value="js" <?php checked( $type, 'js' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-badge he-snips-badge-js">JS</span>
                                    <span class="he-snips-type-desc">JavaScript 삽입</span>
                                </label>
                                <label class="he-snips-type-option <?php echo $type === 'css' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="type" value="css" <?php checked( $type, 'css' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-badge he-snips-badge-css">CSS</span>
                                    <span class="he-snips-type-desc">스타일 삽입</span>
                                </label>
                                <?php if ( $is_edit ) : ?>
                                    <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 2. JavaScript 삽입 위치 (JS 타입일 때만) -->
                        <div class="he-snips-panel" id="he-snips-js-position-box" <?php echo $type !== 'js' ? 'style="display:none;"' : ''; ?>>
                            <div class="he-snips-panel-header">
                                <span class="he-snips-panel-title">삽입 위치</span>
                            </div>
                            <div class="he-snips-panel-body">
                                <label class="he-snips-position-option <?php echo $js_position === 'header' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="js_position" value="header" <?php checked( $js_position, 'header' ); ?>>
                                    <div class="he-snips-position-text">
                                        <strong>&lt;head&gt; 안</strong>
                                        <small>페이지 상단에 삽입됩니다</small>
                                    </div>
                                </label>
                                <label class="he-snips-position-option <?php echo $js_position !== 'header' ? 'is-selected' : ''; ?>">
                                    <input type="radio" name="js_position" value="footer" <?php checked( $js_position, 'footer' ); ?>>
                                    <div class="he-snips-position-text">
                                        <strong>&lt;/body&gt; 앞</strong>
                                        <small>페이지 하단에 삽입됩니다 (권장)</small>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- 3. 저장 -->
                        <div class="he-snips-panel">
                            <div class="he-snips-panel-header">
                                <span class="he-snips-panel-title">저장</span>
                            </div>
                            <div class="he-snips-panel-body">
                                <div class="he-snips-active-row">
                                    <span class="he-snips-active-label-text">활성화</span>
                                    <button type="button"
                                            id="he-snips-active-btn"
                                            class="he-snips-toggle-btn <?php echo $active ? 'is-on' : 'is-off'; ?>">
                                        <span class="he-snips-toggle-track">
                                            <span class="he-snips-toggle-thumb"></span>
                                        </span>
                                        <span class="he-snips-toggle-label"><?php echo $active ? '활성' : '비활성'; ?></span>
                                    </button>
                                </div>
                                <button type="submit" class="he-snips-btn he-snips-btn-primary he-snips-btn-block">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php echo $is_edit ? '수정 저장' : '스니펫 추가'; ?>
                                </button>
                            </div>
                        </div>

                    </div><!-- .he-snips-editor-sidebar -->
                </div><!-- .he-snips-editor-layout -->
            </form>

        </div>
        <?php
    }

    // =========================================================
    // 플러그인 목록 페이지 링크
    // =========================================================

    /**
     * 플러그인 목록에서 플러그인 이름 옆 액션 링크 (왼쪽) — "설정" 바로가기 추가
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=he-snips' ) ) . '">설정</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 플러그인 목록에서 설명 행 메타 링크 (오른쪽) — "기부" 링크 추가
     *
     * @param array  $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta( $links, $file ) {
        if ( HE_SNIPS_BASENAME === $file ) {
            $links[] = '<a href="https://github.com/sponsors/he-works" target="_blank" rel="noopener noreferrer">❤ 기부</a>';
        }
        return $links;
    }
}
