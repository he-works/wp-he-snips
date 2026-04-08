/**
 * He Snips - 관리자 페이지 JavaScript
 * jQuery를 사용해 삭제 확인, 활성화 토글, 타입 변경 시 UI 업데이트를 처리합니다.
 */

(function ($) {
    'use strict';

    // =========================================================
    // DOM 준비 완료 후 실행
    // =========================================================
    $(function () {
        initToggle();
        initDelete();
        initTypeSelector();
        initJsPositionSelector();
    });

    // =========================================================
    // 활성화/비활성화 토글 (Ajax)
    // =========================================================
    function initToggle() {
        $(document).on('click', '.he-snips-toggle', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var id   = $btn.data('id');
            var $row = $btn.closest('tr');

            // 중복 클릭 방지
            if ($btn.hasClass('is-loading')) return;
            $btn.addClass('is-loading').prop('disabled', true);

            $.ajax({
                url:    heSnips.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'he_snips_toggle',
                    nonce:  heSnips.nonce,
                    id:     id,
                },
                success: function (response) {
                    if (response.success) {
                        var isActive = response.data.active === 1;

                        // 버튼 텍스트와 스타일 업데이트
                        $btn.text(isActive ? '활성' : '비활성');
                        $btn.toggleClass('button-primary',   isActive);
                        $btn.toggleClass('button-secondary', !isActive);

                        // 행 스타일 업데이트
                        $row.toggleClass('is-active',   isActive);
                        $row.toggleClass('is-inactive', !isActive);
                    } else {
                        alert('상태 변경에 실패했습니다.');
                    }
                },
                error: function () {
                    alert('서버 오류가 발생했습니다.');
                },
                complete: function () {
                    $btn.removeClass('is-loading').prop('disabled', false);
                }
            });
        });
    }

    // =========================================================
    // 삭제 (Ajax + 확인 다이얼로그)
    // =========================================================
    function initDelete() {
        $(document).on('click', '.he-snips-delete', function (e) {
            e.preventDefault();

            if (!confirm(heSnips.deleteMsg)) {
                return;
            }

            var $btn = $(this);
            var id   = $btn.data('id');
            var $row = $btn.closest('tr');

            $btn.prop('disabled', true).text('삭제 중...');

            $.ajax({
                url:    heSnips.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'he_snips_delete',
                    nonce:  heSnips.nonce,
                    id:     id,
                },
                success: function (response) {
                    if (response.success) {
                        // 애니메이션으로 행 제거
                        $row.fadeOut(300, function () {
                            $(this).remove();

                            // 남은 스니펫이 없으면 빈 상태 표시
                            if ($('.he-snips-row').length === 0) {
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
    // 타입 선택 시 선택된 스타일 적용 + JS 위치 박스 토글
    // =========================================================
    function initTypeSelector() {
        $(document).on('change', 'input[name="type"]', function () {
            var type = $(this).val();

            // 선택 스타일 업데이트
            $('.he-snips-type-option').removeClass('selected');
            $(this).closest('.he-snips-type-option').addClass('selected');

            // JS일 때만 위치 박스 표시
            if (type === 'js') {
                $('#he-snips-js-position-box').slideDown(200);
            } else {
                $('#he-snips-js-position-box').slideUp(200);
            }
        });
    }

    // =========================================================
    // JS 위치 선택 시 선택된 스타일 적용
    // =========================================================
    function initJsPositionSelector() {
        $(document).on('change', 'input[name="js_position"]', function () {
            $('.he-snips-position-option').removeClass('selected');
            $(this).closest('.he-snips-position-option').addClass('selected');
        });
    }

})(jQuery);
