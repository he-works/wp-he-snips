/**
 * HE SNIPS — Admin JavaScript
 */

(function ($) {
    'use strict';

    $(function () {
        initListToggle();
        initDelete();
        initTypeSelector();
        initJsPositionSelector();
        initEditorActiveBtn();
    });

    // =========================================================
    // 목록 페이지: 활성화/비활성화 토글 스위치 (Ajax)
    // =========================================================
    function initListToggle() {
        $(document).on('click', '.he-snips-snippet-item .he-snips-toggle-btn', function (e) {
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
                        setToggleState($btn, isActive);
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
            // 선택 스타일
            $('.he-snips-type-option').removeClass('is-selected');
            $(this).closest('.he-snips-type-option').addClass('is-selected');

            // JS 위치 패널 표시/숨김
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
    // 편집기 페이지: 활성화 토글 버튼 (hidden input 값 연동)
    // =========================================================
    function initEditorActiveBtn() {
        $('#he-snips-active-btn').on('click', function () {
            var $btn   = $(this);
            var isNowOn = $btn.hasClass('is-on');

            // 상태 반전
            setToggleState($btn, !isNowOn);

            // hidden input 값 업데이트
            $('#he-snips-active-input').val(isNowOn ? '0' : '1');
        });
    }

    // =========================================================
    // 공통: 토글 버튼 상태 적용 헬퍼
    // =========================================================
    function setToggleState($btn, isActive) {
        $btn.toggleClass('is-on',  isActive);
        $btn.toggleClass('is-off', !isActive);
        $btn.find('.he-snips-toggle-label').text(isActive ? '활성' : '비활성');
        $btn.attr('title', isActive ? '클릭하여 비활성화' : '클릭하여 활성화');
    }

})(jQuery);
