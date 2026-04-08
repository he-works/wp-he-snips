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
        // 메뉴 등록
        add_action( 'admin_menu', array( $this, 'register_menu' ) );

        // CSS/JS 불러오기
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Ajax: 활성화 토글
        add_action( 'wp_ajax_he_snips_toggle', array( $this, 'ajax_toggle' ) );

        // Ajax: 삭제
        add_action( 'wp_ajax_he_snips_delete', array( $this, 'ajax_delete' ) );
    }

    // =========================================================
    // 메뉴 등록
    // =========================================================

    public function register_menu() {
        add_options_page(
            'He Snips',           // 페이지 제목
            'He Snips',           // 메뉴 제목
            'manage_options',     // 권한 (관리자만)
            'he-snips',           // 메뉴 슬러그
            array( $this, 'render_page' ) // 출력 함수
        );
    }

    // =========================================================
    // 에셋(CSS/JS) 등록
    // =========================================================

    public function enqueue_assets( $hook ) {
        // He Snips 페이지에서만 로드
        if ( strpos( $hook, 'he-snips' ) === false ) {
            return;
        }

        // 관리자 CSS
        wp_enqueue_style(
            'he-snips-admin',
            HE_SNIPS_URL . 'assets/css/admin.css',
            array(),
            HE_SNIPS_VERSION
        );

        // 관리자 JS
        wp_enqueue_script(
            'he-snips-admin',
            HE_SNIPS_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            HE_SNIPS_VERSION,
            true
        );

        // Ajax URL과 nonce를 JS에 전달
        wp_localize_script( 'he-snips-admin', 'heSnips', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'he_snips_nonce' ),
            'deleteMsg' => '이 스니펫을 정말 삭제하시겠습니까?',
        ) );

        // 편집 페이지에서만 CodeMirror 로드
        if ( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'add', 'edit' ), true ) ) {
            $type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'php';

            // 편집 중인 스니펫이 있으면 해당 타입으로
            if ( isset( $_GET['id'] ) ) {
                $snippet = He_Snips_Snippets::get_one( absint( $_GET['id'] ) );
                if ( $snippet ) {
                    $type = $snippet->type;
                }
            }

            $this->enqueue_codemirror( $type );
        }
    }

    /**
     * WordPress 내장 CodeMirror를 불러옵니다.
     *
     * @param string $type 'php', 'js', 'css'
     */
    private function enqueue_codemirror( $type ) {
        $mode_map = array(
            'php' => 'application/x-httpd-php',
            'js'  => 'text/javascript',
            'css' => 'text/css',
        );

        $settings = wp_enqueue_code_editor( array(
            'type' => $mode_map[ $type ] ?? 'text/plain',
        ) );

        // CodeMirror 초기화 스크립트
        if ( $settings ) {
            wp_add_inline_script(
                'code-editor',
                'jQuery(function($){
                    if (typeof wp !== "undefined" && wp.codeEditor) {
                        var editor = wp.codeEditor.initialize($("#he-snips-code"), ' . wp_json_encode( $settings ) . ');
                        window.heSnipsEditor = editor.codemirror;
                    }
                });'
            );
        }
    }

    // =========================================================
    // 페이지 라우팅
    // =========================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '접근 권한이 없습니다.' );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'add':
                $this->render_editor_page();
                break;
            case 'edit':
                $this->render_editor_page( absint( $_GET['id'] ?? 0 ) );
                break;
            default:
                // 폼 저장 처리
                if ( isset( $_POST['he_snips_save'] ) ) {
                    $this->handle_save();
                }
                $this->render_list_page();
                break;
        }
    }

    // =========================================================
    // 저장 처리
    // =========================================================

    private function handle_save() {
        // Nonce 검증
        if ( ! isset( $_POST['he_snips_nonce'] ) || ! wp_verify_nonce( $_POST['he_snips_nonce'], 'he_snips_save' ) ) {
            wp_die( '보안 검사 실패. 다시 시도해 주세요.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '접근 권한이 없습니다.' );
        }

        $data = array(
            'title'       => sanitize_text_field( $_POST['title'] ?? '' ),
            'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'code'        => wp_unslash( $_POST['code'] ?? '' ),
            'type'        => sanitize_key( $_POST['type'] ?? 'php' ),
            'js_position' => sanitize_key( $_POST['js_position'] ?? 'footer' ),
            'active'      => isset( $_POST['active'] ) ? 1 : 0,
        );

        $id = absint( $_POST['snippet_id'] ?? 0 );

        if ( $id > 0 ) {
            He_Snips_Snippets::update( $id, $data );
            $message = 'updated';
        } else {
            He_Snips_Snippets::insert( $data );
            $message = 'added';
        }

        wp_redirect( admin_url( 'options-general.php?page=he-snips&saved=' . $message ) );
        exit;
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
            $snippet  = He_Snips_Snippets::get_one( $id );
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
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'php';
        $tabs = array(
            'php' => 'PHP',
            'js'  => 'JavaScript',
            'css' => 'CSS',
        );
        $snippets = He_Snips_Snippets::get_all( $active_tab );
        $saved    = isset( $_GET['saved'] ) ? sanitize_key( $_GET['saved'] ) : '';
        ?>
        <div class="wrap he-snips-wrap">
            <h1 class="wp-heading-inline">
                <span class="he-snips-logo">He Snips</span>
            </h1>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips&action=add&type=' . $active_tab ) ); ?>" class="page-title-action">
                + 새 스니펫 추가
            </a>
            <hr class="wp-header-end">

            <?php if ( $saved === 'added' ) : ?>
                <div class="notice notice-success is-dismissible"><p>스니펫이 추가되었습니다.</p></div>
            <?php elseif ( $saved === 'updated' ) : ?>
                <div class="notice notice-success is-dismissible"><p>스니펫이 수정되었습니다.</p></div>
            <?php endif; ?>

            <!-- 탭 -->
            <nav class="nav-tab-wrapper he-snips-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips&tab=' . $slug ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <span class="he-snips-tab-badge he-snips-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- 스니펫 목록 -->
            <div class="he-snips-list-container">
                <?php if ( empty( $snippets ) ) : ?>
                    <div class="he-snips-empty">
                        <p>아직 스니펫이 없습니다. <a href="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips&action=add&type=' . $active_tab ) ); ?>">첫 번째 스니펫을 추가해 보세요!</a></p>
                    </div>
                <?php else : ?>
                    <table class="widefat he-snips-table">
                        <thead>
                            <tr>
                                <th>제목</th>
                                <?php if ( $active_tab === 'js' ) : ?>
                                    <th>위치</th>
                                <?php endif; ?>
                                <th>상태</th>
                                <th>수정일</th>
                                <th>작업</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $snippets as $snippet ) : ?>
                                <tr class="he-snips-row <?php echo $snippet->active ? 'is-active' : 'is-inactive'; ?>" data-id="<?php echo absint( $snippet->id ); ?>">
                                    <td class="he-snips-title">
                                        <strong><?php echo esc_html( $snippet->title ); ?></strong>
                                        <?php if ( $snippet->description ) : ?>
                                            <p class="he-snips-desc"><?php echo esc_html( $snippet->description ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ( $active_tab === 'js' ) : ?>
                                        <td>
                                            <span class="he-snips-position he-snips-position-<?php echo esc_attr( $snippet->js_position ); ?>">
                                                <?php echo $snippet->js_position === 'header' ? '&lt;head&gt; 안' : '&lt;/body&gt; 앞'; ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <button class="he-snips-toggle button <?php echo $snippet->active ? 'button-primary' : 'button-secondary'; ?>"
                                                data-id="<?php echo absint( $snippet->id ); ?>">
                                            <?php echo $snippet->active ? '활성' : '비활성'; ?>
                                        </button>
                                    </td>
                                    <td class="he-snips-date">
                                        <?php echo esc_html( date_i18n( 'Y.m.d H:i', strtotime( $snippet->updated_at ) ) ); ?>
                                    </td>
                                    <td class="he-snips-actions">
                                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips&action=edit&id=' . $snippet->id ) ); ?>" class="button">
                                            수정
                                        </a>
                                        <button class="he-snips-delete button button-link-delete"
                                                data-id="<?php echo absint( $snippet->id ); ?>">
                                            삭제
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <p class="he-snips-footer">He Snips by <strong>HE WORKS.</strong> &mdash; v<?php echo esc_html( HE_SNIPS_VERSION ); ?></p>
        </div>
        <?php
    }

    // =========================================================
    // 편집기 페이지 렌더링 (추가 / 수정)
    // =========================================================

    private function render_editor_page( $id = 0 ) {
        $snippet  = null;
        $is_edit  = false;
        $type     = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'php';

        if ( $id > 0 ) {
            $snippet = He_Snips_Snippets::get_one( $id );
            if ( ! $snippet ) {
                wp_die( '스니펫을 찾을 수 없습니다.' );
            }
            $is_edit = true;
            $type    = $snippet->type;
        }

        $title       = $snippet ? esc_attr( $snippet->title )       : '';
        $description = $snippet ? esc_textarea( $snippet->description ) : '';
        $code        = $snippet ? $snippet->code                     : '';
        $active      = $snippet ? (bool) $snippet->active            : true;
        $js_position = $snippet ? $snippet->js_position              : 'footer';
        ?>
        <div class="wrap he-snips-wrap">
            <h1>
                <span class="he-snips-logo">He Snips</span>
                &mdash; <?php echo $is_edit ? '스니펫 수정' : '새 스니펫 추가'; ?>
            </h1>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips&tab=' . $type ) ); ?>" class="page-title-action">
                ← 목록으로
            </a>
            <hr class="wp-header-end">

            <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=he-snips' ) ); ?>" id="he-snips-editor-form">
                <?php wp_nonce_field( 'he_snips_save', 'he_snips_nonce' ); ?>
                <input type="hidden" name="he_snips_save" value="1">
                <input type="hidden" name="snippet_id" value="<?php echo absint( $id ); ?>">

                <div class="he-snips-editor-layout">
                    <!-- 왼쪽: 코드 에디터 -->
                    <div class="he-snips-editor-main">
                        <div class="he-snips-editor-header">
                            <span class="he-snips-type-badge he-snips-<?php echo esc_attr( $type ); ?>"><?php echo strtoupper( $type ); ?></span>
                            <span class="he-snips-editor-hint">
                                <?php
                                if ( $type === 'php' ) echo 'functions.php에 추가하는 것과 동일하게 동작합니다. &lt;?php 태그 없이 입력하세요.';
                                elseif ( $type === 'js' ) echo '&lt;script&gt; 태그 없이 순수 JavaScript만 입력하세요.';
                                else echo '&lt;style&gt; 태그 없이 순수 CSS만 입력하세요.';
                                ?>
                            </span>
                        </div>
                        <textarea id="he-snips-code" name="code" rows="25" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $code ); ?></textarea>
                    </div>

                    <!-- 오른쪽: 설정 패널 -->
                    <div class="he-snips-editor-sidebar">

                        <!-- 저장 박스 -->
                        <div class="postbox he-snips-box">
                            <div class="postbox-header"><h2>저장</h2></div>
                            <div class="inside">
                                <label class="he-snips-active-label">
                                    <input type="checkbox" name="active" value="1" <?php checked( $active ); ?>>
                                    활성화
                                </label>
                                <button type="submit" class="button button-primary button-large" style="width:100%;margin-top:10px;">
                                    <?php echo $is_edit ? '수정 저장' : '스니펫 추가'; ?>
                                </button>
                            </div>
                        </div>

                        <!-- 기본 정보 박스 -->
                        <div class="postbox he-snips-box">
                            <div class="postbox-header"><h2>기본 정보</h2></div>
                            <div class="inside">
                                <label>
                                    <span>제목 <span class="required">*</span></span>
                                    <input type="text" name="title" value="<?php echo $title; ?>" class="widefat" required placeholder="스니펫 이름을 입력하세요">
                                </label>
                                <label style="margin-top:10px;display:block;">
                                    <span>설명 (선택)</span>
                                    <textarea name="description" class="widefat" rows="3" placeholder="이 스니펫이 무엇을 하는지 간단히 설명하세요"><?php echo $description; ?></textarea>
                                </label>
                            </div>
                        </div>

                        <!-- 타입 설정 박스 -->
                        <div class="postbox he-snips-box">
                            <div class="postbox-header"><h2>코드 타입</h2></div>
                            <div class="inside">
                                <label class="he-snips-type-option <?php echo $type === 'php' ? 'selected' : ''; ?>">
                                    <input type="radio" name="type" value="php" <?php checked( $type, 'php' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-type-badge he-snips-php">PHP</span>
                                    <small>functions.php 역할</small>
                                </label>
                                <label class="he-snips-type-option <?php echo $type === 'js' ? 'selected' : ''; ?>">
                                    <input type="radio" name="type" value="js" <?php checked( $type, 'js' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-type-badge he-snips-js">JS</span>
                                    <small>JavaScript 삽입</small>
                                </label>
                                <label class="he-snips-type-option <?php echo $type === 'css' ? 'selected' : ''; ?>">
                                    <input type="radio" name="type" value="css" <?php checked( $type, 'css' ); ?> <?php echo $is_edit ? 'disabled' : ''; ?>>
                                    <span class="he-snips-type-badge he-snips-css">CSS</span>
                                    <small>스타일 삽입</small>
                                </label>
                                <?php if ( $is_edit ) : ?>
                                    <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- JS 위치 설정 (JS 타입일 때만) -->
                        <div class="postbox he-snips-box" id="he-snips-js-position-box" <?php echo $type !== 'js' ? 'style="display:none;"' : ''; ?>>
                            <div class="postbox-header"><h2>JavaScript 삽입 위치</h2></div>
                            <div class="inside">
                                <label class="he-snips-position-option <?php echo $js_position === 'header' ? 'selected' : ''; ?>">
                                    <input type="radio" name="js_position" value="header" <?php checked( $js_position, 'header' ); ?>>
                                    <strong>&lt;head&gt; 안 (Header)</strong>
                                    <small>페이지 상단 &lt;/head&gt; 바로 앞에 삽입됩니다</small>
                                </label>
                                <label class="he-snips-position-option <?php echo $js_position !== 'header' ? 'selected' : ''; ?>">
                                    <input type="radio" name="js_position" value="footer" <?php checked( $js_position, 'footer' ); ?>>
                                    <strong>&lt;/body&gt; 앞 (Footer)</strong>
                                    <small>페이지 하단 &lt;/body&gt; 바로 앞에 삽입됩니다 (권장)</small>
                                </label>
                            </div>
                        </div>

                    </div><!-- .he-snips-editor-sidebar -->
                </div><!-- .he-snips-editor-layout -->
            </form>
        </div>
        <?php
    }
}
