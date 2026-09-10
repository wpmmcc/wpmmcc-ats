# WPMMCC ATS — WordPress 다국어 플러그인

**WordPress를 실용적으로 다국어화: 스캔, 연결, 번역.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | **한국어** | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | [Português (Brasil)](README.pt-BR.md) | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS는 단일 언어 WordPress 사이트를 다국어 사이트로 바꾸는 플러그인입니다. 콘텐츠 플러그인·테마·메뉴에서 번역 가능한 필드를 스캔하고, 재사용 가능한 번역 규칙을 만들고, 언어 간 사이트 관계를 관리하며, wp-admin 안에서 완결되는 수동 번역 에디터를 제공합니다. 자동 번역은 함께 제공되는 WPTSALL Client로 가능합니다. 계정도, 라이선스도, 벤더 종속도 없습니다.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## 기능
- 콘텐츠 스캔 — 글·텍소노미·meta·서드파티 콘텐츠 플러그인·테마의 번역 가능 필드 탐지
- 사이트 관계 — 원본 언어와 대상 언어를 연결하고, 전용 URL 접두어를 가진 가상 사이트 지원
- 수동 번역 에디터 — WordPress 관리 화면 안에서 모든 번역 완결
- 번역 규칙 — 수동 에디터와 클라이언트 API가 같은 규칙 세트 공유
- Protocol v2 클라이언트 API — WPTSALL Client가 작업을 가져와 결과를 다시 기록
- 언어 팩 — 영어 내장, 간체 중국어는 번역 진행 중(아래 참고)

## 요구 사항
- WordPress 6.2 이상
- PHP 7.4 이상
- 계정·구독·라이선스 키 불필요

## 설치
1. 이 저장소의 Releases 페이지에서 최신 ZIP 다운로드(또는 소스를 직접 zip)
2. wp-admin의 플러그인 → 새로 추가 → 플러그인 업로드에서 ZIP을 올리고 활성화
3. wp-admin의 WPMMCC ATS 메뉴 열기

## 빠른 시작(수동 번역, 클라이언트 불필요)
1. WPMMCC ATS 관리 페이지에서 콘텐츠 플러그인을 스캔하거나 번역 규칙 세트 생성
2. 사이트 관계 추가: 원본 언어와 대상 언어를 선택하면 가상 사이트에 /en_us/ 같은 전용 URL 접두어가 붙습니다
3. 수동 번역 에디터에서 글을 열어 번역하고, 저장하면 대상 사이트에 반영됩니다

## 자동 번역
자동 번역에는 함께 제공되는 WPTSALL Client(WebUI 또는 Desktop)를 설치하세요. 기기 토큰으로 사이트에 직접 연결해 번역 작업을 가져오고, 설정한 번역 제공자를 호출한 뒤 결과를 다시 기록합니다. 클라이언트 저장소: https://github.com/wpmmcc/wptsall-client

## Languages
영어가 내장 원본 언어입니다. 간체 중국어(zh_CN) 팩은 languages/에서 번역을 진행 중입니다. 카탈로그 컴파일 후 설정 → 일반에서 사이트 언어를 선택하면 자동으로 로드됩니다. 다른 언어 추가는 languages/wpmmcc-ats.pot을 PO 편집기로 번역해 기여해 주세요.

## License
GPL-2.0-or-later. [LICENSE](../LICENSE) 참고.

