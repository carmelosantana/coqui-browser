# Coqui Browser

`carmelosantana/coqui-browser` is a Playwright PHP browser toolkit for Coqui. It replaces the legacy `coquibot/coqui-toolkit-browser` package with a direct PHP integration that supports multi-tab sessions, structured page snapshots, browser storage state, screenshots, PDFs, and richer interaction against modern web apps.

## Requirements

- PHP 8.4+
- Node.js 20+
- Coqui / `carmelosantana/php-agents`
- Playwright browser binaries installed through `vendor/bin/playwright-install`

## Install

```bash
composer require carmelosantana/coqui-browser
vendor/bin/playwright-install --browsers
```

For Linux CI or fresh containers, prefer:

```bash
vendor/bin/playwright-install --with-deps --browsers
```

## Migration

If you still have the legacy npm-backed browser toolkit installed, remove it before adopting this package:

```bash
composer remove coquibot/coqui-toolkit-browser
composer require carmelosantana/coqui-browser
```

This new toolkit uses a different runtime model and writes artifacts under `.workspace/browser-playwright/` instead of the legacy `.workspace/browser/` path.

## Tools

- `browser_session`: environment setup, session lifecycle, and status
- `browser_page`: navigation, tabs, waits, and current page state
- `browser_interact`: click, fill, type, press, drag, upload, and evaluate
- `browser_capture`: structured snapshots, screenshots, PDFs, and extraction
- `browser_storage`: cookies and storage-state save/load helpers

## Browser Notes

- Chromium, Firefox, and WebKit are supported for navigation, interaction, snapshots, screenshots, and storage-state workflows.
- PDF generation is Chromium-only because Playwright itself only supports `page.pdf()` on headless Chromium.

## Typical Workflow

1. Call `browser_session` with `action: setup` once on a machine to install browsers.
2. Call `browser_page` with `action: open` and a URL.
3. Call `browser_capture` with `action: snapshot` to inspect the page and get element refs.
4. Call `browser_interact` with a `ref`, selector, role/name pair, or text target.
5. Use `browser_capture` for screenshots, PDFs, or extraction.
6. Use `browser_storage` to save or load login state when needed.

## Testing

```bash
composer install
composer test
COQUI_BROWSER_RUN_INTEGRATION=1 composer test:integration
composer analyse
```

Integration tests launch real browsers through Playwright PHP and use the fixtures in `tests/Fixtures/`.
