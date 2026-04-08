/**
 * HE SNIPS — Admin JavaScript
 */

(function ($) {
    'use strict';

    // 기본 에디터 높이 (px)
    var DEFAULT_EDITOR_HEIGHT = 520;

    $(function () {
        initListToggle();
        initDelete();
        initTypeSelector();
        initJsPositionSelector();
        initEditorActiveBtn();
        initEditorResize();
    });

    // CodeMirror 폴백: DOM ready 이후에도 미초기화된 경우 window.load에서 재시도
    $(window).on('load', function () {
        initCodeMirrorFallback();
    });

    // =========================================================
    // 목록 페이지: 알약 토글 버튼 (Ajax)
    // =========================================================
    function initListToggle() {
        $(document).on('click', '.he-snips-pill-toggle', function (e) {
            e.preventDefault();

            var $btn  = $(this);
            var id    = $btn.data('id');
            var $item = $btn.closest('.he-snips-snippet-item');

            if ($btn.hasClass('is-loading')) return;
            $btn.addClass('is-loading').css('opacity', 0.5);

            $.ajax({
                url:    heSnips.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'he_snips_toggle',
                    nonce:  heSnips.nonce,
                    id:     id,
                },
                success: function (res) {
                    if (res.success) {
                        var isActive = res.data.active === 1;
                        setPillState($btn, isActive);
                        $item.toggleClass('is-active',   isActive);
                        $item.toggleClass('is-inactive', !isActive);
                    } else {
                        alert('상태 변경에 실패했습니다.');
                    }
                },
                error: function () {
                    alert('서버 오류가 발생했습니다.');
                },
                complete: function () {
                    $btn.removeClass('is-loading').css('opacity', 1);
                }
            });
        });
    }

    /**
     * 알약 토글 버튼 상태 적용
     * - label-current: 현재 상태 텍스트 (기본 보임)
     * - label-hover:   호버 시 보이는 반대 상태 텍스트
     */
    function setPillState($btn, isActive) {
        $btn.toggleClass('is-on',  isActive);
        $btn.toggleClass('is-off', !isActive);
        $btn.find('.he-snips-label-current').text(isActive ? '활성'    : '비활성');
        $btn.find('.he-snips-label-hover').text(  isActive ? '비활성으로' : '활성으로');
    }

    // =========================================================
    // 목록 페이지: 삭제 (Ajax)
    // =========================================================
    function initDelete() {
        $(document).on('click', '.he-snips-delete', function (e) {
            e.preventDefault();

            if (!confirm(heSnips.deleteMsg)) return;

            var $btn  = $(this);
            var id    = $btn.data('id');
            var $item = $btn.closest('.he-snips-snippet-item');

            $btn.prop('disabled', true).text('삭제 중…');

            $.ajax({
                url:    heSnips.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'he_snips_delete',
                    nonce:  heSnips.nonce,
                    id:     id,
                },
                success: function (res) {
                    if (res.success) {
                        $item.fadeOut(250, function () {
                            $(this).remove();
                            if ($('.he-snips-snippet-item').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        alert('삭제에 실패했습니다.');
                        $btn.prop('disabled', false).text('삭제');
                    }
                },
                error: function () {
                    alert('서버 오류가 발생했습니다.');
                    $btn.prop('disabled', false).text('삭제');
                }
            });
        });
    }

    // =========================================================
    // 편집기 페이지: 코드 타입 선택 시 UI 업데이트
    // =========================================================
    function initTypeSelector() {
        $(document).on('change', 'input[name="type"]', function () {
            $('.he-snips-type-option').removeClass('is-selected');
            $(this).closest('.he-snips-type-option').addClass('is-selected');

            if ($(this).val() === 'js') {
                $('#he-snips-js-position-box').slideDown(180);
            } else {
                $('#he-snips-js-position-box').slideUp(180);
            }
        });
    }

    // =========================================================
    // 편집기 페이지: JS 위치 선택 스타일
    // =========================================================
    function initJsPositionSelector() {
        $(document).on('change', 'input[name="js_position"]', function () {
            $('.he-snips-position-option').removeClass('is-selected');
            $(this).closest('.he-snips-position-option').addClass('is-selected');
        });
    }

    // =========================================================
    // 편집기 페이지: 활성화 슬라이드 토글 (저장 패널)
    // =========================================================
    function initEditorActiveBtn() {
        $('#he-snips-active-btn').on('click', function () {
            var $btn    = $(this);
            var isNowOn = $btn.hasClass('is-on');

            $btn.toggleClass('is-on',  !isNowOn);
            $btn.toggleClass('is-off',  isNowOn);
            $btn.find('.he-snips-toggle-label').text(isNowOn ? '비활성' : '활성');
            $('#he-snips-active-input').val(isNowOn ? '0' : '1');
        });
    }

    // =========================================================
    // 편집기 페이지: 에디터 높이 드래그 조절
    // =========================================================
    function initEditorResize() {
        var $handle = $('.he-snips-resize-handle');
        if (!$handle.length) return;

        var $body = $('.he-snips-editor-body');
        var MIN_H = 200;
        var MAX_H = 1200;
        var startY, startH;

        // 저장된 높이 복원
        var savedH = localStorage.getItem('heSnipsEditorH');
        applyHeight(savedH ? parseInt(savedH, 10) : DEFAULT_EDITOR_HEIGHT);

        $handle.on('mousedown', function (e) {
            startY = e.pageY;
            startH = $body.outerHeight();
            $('body').addClass('he-snips-resizing');

            $(document).on('mousemove.hsResize', function (e) {
                var newH = Math.min(MAX_H, Math.max(MIN_H, startH + (e.pageY - startY)));
                applyHeight(newH);
            });

            $(document).on('mouseup.hsResize', function () {
                $(document).off('.hsResize');
                $('body').removeClass('he-snips-resizing');
                localStorage.setItem('heSnipsEditorH', $body.outerHeight());
            });

            e.preventDefault();
        });

        // 터치 지원
        $handle.on('touchstart', function (e) {
            startY = e.originalEvent.touches[0].pageY;
            startH = $body.outerHeight();

            $(document).on('touchmove.hsResize', function (e) {
                var newH = Math.min(MAX_H, Math.max(MIN_H, startH + (e.originalEvent.touches[0].pageY - startY)));
                applyHeight(newH);
                e.preventDefault();
            });

            $(document).on('touchend.hsResize', function () {
                $(document).off('.hsResize');
                localStorage.setItem('heSnipsEditorH', $body.outerHeight());
            });
        });

        function applyHeight(h) {
            $body.css('height', h + 'px');
            if (window.heSnipsEditor) {
                window.heSnipsEditor.setSize(null, h);
                window.heSnipsEditor.refresh();
            }
        }
    }

    // =========================================================
    // CodeMirror 폴백 초기화
    // PHP inline script로 초기화가 안 된 경우(수정 페이지 등) 재시도
    // =========================================================
    function initCodeMirrorFallback() {
        // 이미 초기화됐으면 무시
        if (window.heSnipsEditor) {
            // 저장된 높이로 동기화만 해 줌
            var savedH = parseInt(localStorage.getItem('heSnipsEditorH') || DEFAULT_EDITOR_HEIGHT, 10);
            window.heSnipsEditor.setSize(null, savedH);
            window.heSnipsEditor.refresh();
            return;
        }

        var $textarea = $('#he-snips-code');
        if (!$textarea.length) return;                  // 에디터 페이지가 아님
        if (typeof wp === 'undefined' || !wp.codeEditor) return; // CodeMirror 미로드

        try {
            var editor = wp.codeEditor.initialize($textarea);
            if (editor && editor.codemirror) {
                window.heSnipsEditor = editor.codemirror;

                var savedH = parseInt(localStorage.getItem('heSnipsEditorH') || DEFAULT_EDITOR_HEIGHT, 10);
                window.heSnipsEditor.setSize(null, savedH);
                window.heSnipsEditor.refresh();
            }
        } catch (e) {
            // 초기화 실패 시 textarea 폴백 사용 (이미 보임)
        }
    }

})(jQuery);
