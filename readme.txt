=== HE SNIPS ===
Contributors: he-works
Tags: snippets, code, php, javascript, css, functions
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PHP, JavaScript, CSS 코드 스니펫을 워드프레스에 쉽게 삽입하고 관리하세요.

== Description ==

**HE SNIPS**는 코드를 직접 테마 파일에 수정하지 않고, 관리자 페이지에서 PHP/JS/CSS 코드 스니펫을 손쉽게 관리할 수 있는 플러그인입니다.

= 주요 기능 =

* **PHP 스니펫** - functions.php에 코드를 추가하는 것과 동일한 효과
* **JavaScript 스니펫** - `<head>` 또는 `</body>` 앞 원하는 위치에 삽입
* **CSS 스니펫** - 사이트 전체에 커스텀 스타일 적용
* **다중 스니펫** - 각 타입별로 여러 개의 스니펫 등록 가능
* **활성화/비활성화** - 코드를 삭제하지 않고 토글로 간편하게 ON/OFF
* **코드 에디터** - WordPress 내장 CodeMirror 기반 구문 강조 에디터
* **GitHub 자동 업데이트** - 새 버전이 출시되면 WordPress에서 자동 감지

= 사용 방법 =

1. 플러그인 활성화
2. 사이드바 → HE SNIPS 이동
3. 탭에서 PHP / JS / CSS 선택
4. "새 스니펫 추가" 버튼 클릭
5. 코드 입력 후 저장

== Installation ==

1. `plugin-He-Snips` 폴더를 `/wp-content/plugins/` 디렉토리에 업로드하세요.
2. 워드프레스 관리자 → 플러그인에서 "He Snips"를 활성화하세요.
3. 사이드바 → HE SNIPS에서 스니펫을 관리하세요.

== Frequently Asked Questions ==

= PHP 스니펫에 `<?php` 태그를 넣어야 하나요? =

아니요, `<?php` 태그 없이 PHP 코드만 입력하면 됩니다.

= JS 스니펫에 `<script>` 태그를 넣어야 하나요? =

아니요, `<script>` 태그 없이 순수 JavaScript 코드만 입력하면 됩니다.

= 플러그인을 삭제하면 스니펫도 사라지나요? =

네, "삭제"를 실행하면 데이터베이스에서 모든 스니펫이 제거됩니다.
비활성화만 하면 스니펫 데이터는 보존됩니다.

== Changelog ==

= 1.0.4 =
* 관리자 페이지 내부 URL 수정 (options-general.php → admin.php) — 탭 이동, 저장 리다이렉트, 추가/수정 링크가 올바른 페이지로 연결
* PHP 스니펫 에러 처리 강화 — ParseError뿐 아니라 모든 Throwable(TypeError, Error 등) 포괄 처리로 스니펫 오류 시 사이트 다운 방지
* PHP 스니펫 에러 발생 시 error_log 기록 추가 (프론트엔드 디버깅 지원)
* readme.txt 버전 및 메뉴 경로 안내 수정

= 1.0.3 =
* 관리자 UI 개선
* CodeMirror 에디터 안정화

= 1.0.2 =
* 에디터 레이아웃 개선
* 코드 타입별 구문 강조 최적화

= 1.0.1 =
* 버그 수정 및 안정성 개선

= 1.0.0 =
* 최초 릴리스
* PHP, JS, CSS 스니펫 관리
* GitHub 자동 업데이트 지원

== Upgrade Notice ==

= 1.0.4 =
관리자 페이지 URL 오류 수정 및 PHP 스니펫 에러 처리 강화. 업데이트를 강력히 권장합니다.

= 1.0.0 =
최초 릴리스입니다.
