<?php

declare(strict_types=1);

namespace CoquiBrowser;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBrowser\Runtime\BrowserManager;
use CoquiBrowser\Runtime\EnvironmentChecker;
use CoquiBrowser\Runtime\SessionRegistry;

final class BrowserToolkit implements ToolkitInterface
{
    private readonly BrowserManager $manager;

    public function __construct(
        string $workspacePath,
        ?BrowserManager $manager = null,
    ) {
        $this->manager = $manager ?? new BrowserManager(
            workspacePath: $workspacePath,
            environmentChecker: new EnvironmentChecker($workspacePath),
            sessions: new SessionRegistry(),
        );
    }

    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self($workspacePath);
    }

    public function tools(): array
    {
        return [
            $this->browserSessionTool(),
            $this->browserPageTool(),
            $this->browserInteractTool(),
            $this->browserCaptureTool(),
            $this->browserStorageTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <BROWSER-PLAYWRIGHT-GUIDELINES>
            Use the browser tools when the task needs a real browser with JavaScript execution.

            Preferred workflow:
            1. `browser_session` action `setup` once per machine if browsers are not installed.
            2. `browser_page` action `open` to navigate.
            3. `browser_capture` action `snapshot` to inspect the page and collect refs.
            4. `browser_interact` using a `ref`, selector, role/name pair, or visible text.
            5. `browser_capture` for screenshots, PDFs, or extraction.
            6. `browser_storage` for cookies and storage-state save/load when handling auth flows.

            Notes:
            - This toolkit supports multiple tabs through `page_id`.
            - `snapshot` refs are ephemeral to the current page state. Re-snapshot after major changes.
            - Prefer role/name, text, label, or refs over brittle CSS selectors.
            - PDF export is only available for Chromium sessions because that is a Playwright limitation.
            - `browser_interact` action `evaluate` is powerful and should be used only when locator-based actions are insufficient.
            - Artifacts are saved under `.workspace/browser-playwright/`.
            </BROWSER-PLAYWRIGHT-GUIDELINES>
            GUIDELINES;
    }

    private function browserSessionTool(): Tool
    {
        return new Tool(
            name: 'browser_session',
            description: 'Manage the Playwright PHP browser environment and browser sessions.',
            parameters: [
                new EnumParameter('action', 'Session action to perform.', ['setup', 'status', 'start', 'list', 'close', 'close_all', 'reset_state'], true),
                new StringParameter('session', 'Browser session name. Defaults to the workspace-scoped session.', false),
                new EnumParameter('browser', 'Browser engine for start.', ['chromium', 'firefox', 'webkit'], false),
                new BoolParameter('headless', 'Launch in headless mode. Defaults to true.', false),
                new NumberParameter('timeout_ms', 'Default timeout in milliseconds for the session.', false, integer: true, minimum: 1000),
                new NumberParameter('viewport_width', 'Viewport width for new sessions.', false, integer: true, minimum: 320),
                new NumberParameter('viewport_height', 'Viewport height for new sessions.', false, integer: true, minimum: 240),
                new StringParameter('user_agent', 'Optional user agent for the browser context.', false),
                new StringParameter('storage_state_path', 'Optional storage state JSON file to preload when starting a session.', false),
                new BoolParameter('with_deps', 'Install OS dependencies during setup. Mostly for Linux CI.', false),
            ],
            callback: fn (array $input): ToolResult => self::encode(match ($input['action']) {
                'setup' => $this->manager->setup((bool) ($input['with_deps'] ?? false)),
                'status' => isset($input['session']) && is_string($input['session']) && $input['session'] !== ''
                    ? $this->manager->sessionStatus($input['session'])
                    : $this->manager->environmentStatus(),
                'start' => $this->manager->startSession($input),
                'list' => $this->manager->listSessions(),
                'close' => $this->manager->closeSession($input['session'] ?? null),
                'close_all' => $this->manager->closeAllSessions(),
                'reset_state' => $this->manager->resetState($input['session'] ?? null),
                default => throw new \RuntimeException('Unsupported browser_session action.'),
            }),
        );
    }

    private function browserPageTool(): Tool
    {
        return new Tool(
            name: 'browser_page',
            description: 'Navigate pages, manage tabs, and wait for browser state changes.',
            parameters: [
                new EnumParameter('action', 'Page action to perform.', ['open', 'new_tab', 'list_tabs', 'switch_tab', 'close_tab', 'back', 'forward', 'reload', 'wait', 'status', 'set_content'], true),
                new StringParameter('session', 'Browser session name.', false),
                new StringParameter('page_id', 'Target page id. Defaults to the active page.', false),
                new StringParameter('url', 'URL to open.', false),
                new StringParameter('wait_until', 'Navigation load state. Defaults to load.', false),
                new EnumParameter('wait_action', 'Wait mode.', ['selector', 'url', 'text', 'load'], false),
                new StringParameter('selector', 'Selector for wait action or other page operations.', false),
                new StringParameter('url_pattern', 'URL or URL pattern for wait action url.', false),
                new StringParameter('text', 'Visible text for wait action text.', false),
                new StringParameter('load_state', 'Load state for wait action load.', false),
                new NumberParameter('timeout_ms', 'Timeout for wait or session start.', false, integer: true, minimum: 1000),
                new StringParameter('html', 'HTML content for set_content.', false),
                new EnumParameter('browser', 'Browser engine for auto-started sessions.', ['chromium', 'firefox', 'webkit'], false),
                new BoolParameter('headless', 'Headless mode for auto-started sessions.', false),
            ],
            callback: fn (array $input): ToolResult => self::encode(match ($input['action']) {
                'open' => $this->manager->open($input),
                'new_tab' => $this->manager->newTab($input),
                'list_tabs' => $this->manager->listTabs($input),
                'switch_tab' => $this->manager->switchTab($input),
                'close_tab' => $this->manager->closeTab($input),
                'back' => $this->manager->navigateHistory('back', $input),
                'forward' => $this->manager->navigateHistory('forward', $input),
                'reload' => $this->manager->navigateHistory('reload', $input),
                'wait' => $this->manager->waitFor($input),
                'status' => $this->manager->pageStatus($input),
                'set_content' => $this->manager->setContent($input),
                default => throw new \RuntimeException('Unsupported browser_page action.'),
            }),
        );
    }

    private function browserInteractTool(): Tool
    {
        return new Tool(
            name: 'browser_interact',
            description: 'Interact with browser elements using refs, selectors, roles, labels, or visible text.',
            parameters: [
                new EnumParameter('action', 'Interaction action to perform.', ['click', 'dblclick', 'fill', 'type', 'press', 'hover', 'check', 'uncheck', 'select', 'clear', 'focus', 'blur', 'upload', 'drag', 'evaluate', 'press_page'], true),
                new StringParameter('session', 'Browser session name.', false),
                new StringParameter('page_id', 'Target page id. Defaults to the active page.', false),
                new StringParameter('ref', 'Element ref from browser_capture snapshot.', false),
                new StringParameter('selector', 'Direct selector target.', false),
                new StringParameter('role', 'ARIA role target.', false),
                new StringParameter('name', 'Accessible name used with role targeting.', false),
                new StringParameter('text', 'Visible text target.', false),
                new StringParameter('label', 'Label target.', false),
                new StringParameter('placeholder', 'Placeholder target.', false),
                new StringParameter('test_id', 'data-testid target.', false),
                new StringParameter('title', 'Title attribute target.', false),
                new NumberParameter('nth', 'Optional zero-based index for matched targets.', false, integer: true, minimum: 0),
                new StringParameter('frame_selector', 'Optional iframe selector to scope the target.', false),
                new StringParameter('value', 'Value for fill, type, or select.', false),
                new StringParameter('key', 'Keyboard key for press actions.', false),
                new StringParameter('expression', 'JavaScript expression for evaluate.', false),
                new StringParameter('file_path', 'Single file path for upload.', false),
                new ArrayParameter('file_paths', 'Multiple file paths for upload.', false, new StringParameter('item', 'File path', true)),
                new StringParameter('target_ref', 'Drag target ref.', false),
                new StringParameter('target_selector', 'Drag target selector.', false),
                new StringParameter('target_role', 'Drag target role.', false),
                new StringParameter('target_name', 'Drag target accessible name.', false),
                new StringParameter('target_text', 'Drag target text.', false),
                new StringParameter('target_frame_selector', 'Optional iframe selector for the drag target.', false),
                new EnumParameter('browser', 'Browser engine for auto-started sessions.', ['chromium', 'firefox', 'webkit'], false),
                new BoolParameter('headless', 'Headless mode for auto-started sessions.', false),
            ],
            callback: fn (array $input): ToolResult => self::encode($this->manager->interact((string) $input['action'], $input)),
        );
    }

    private function browserCaptureTool(): Tool
    {
        return new Tool(
            name: 'browser_capture',
            description: 'Inspect and capture the page with structured snapshots, screenshots, PDFs, and extracted content.',
            parameters: [
                new EnumParameter('action', 'Capture action to perform.', ['snapshot', 'screenshot', 'pdf', 'extract'], true),
                new StringParameter('session', 'Browser session name.', false),
                new StringParameter('page_id', 'Target page id. Defaults to the active page.', false),
                new StringParameter('path', 'Optional output file name.', false),
                new BoolParameter('full_page', 'Capture the full page when taking screenshots.', false),
                new EnumParameter('mode', 'Extract mode.', ['page_html', 'page_text', 'html', 'text', 'value', 'attribute'], false),
                new StringParameter('attribute_name', 'Attribute name for extract mode attribute.', false),
                new StringParameter('ref', 'Element ref from browser_capture snapshot.', false),
                new StringParameter('selector', 'Direct selector target.', false),
                new StringParameter('role', 'ARIA role target.', false),
                new StringParameter('name', 'Accessible name used with role targeting.', false),
                new StringParameter('text', 'Visible text target.', false),
                new StringParameter('label', 'Label target.', false),
                new StringParameter('placeholder', 'Placeholder target.', false),
                new StringParameter('test_id', 'data-testid target.', false),
                new StringParameter('title', 'Title attribute target.', false),
                new NumberParameter('nth', 'Optional zero-based index for matched targets.', false, integer: true, minimum: 0),
                new StringParameter('frame_selector', 'Optional iframe selector to scope the target.', false),
                new StringParameter('format', 'PDF page format. Defaults to A4.', false),
                new EnumParameter('browser', 'Browser engine for auto-started sessions.', ['chromium', 'firefox', 'webkit'], false),
                new BoolParameter('headless', 'Headless mode for auto-started sessions.', false),
            ],
            callback: fn (array $input): ToolResult => self::encode(match ($input['action']) {
                'snapshot' => $this->manager->snapshot($input),
                'screenshot' => $this->manager->screenshot($input),
                'pdf' => $this->manager->pdf($input),
                'extract' => $this->manager->extract($input),
                default => throw new \RuntimeException('Unsupported browser_capture action.'),
            }),
        );
    }

    private function browserStorageTool(): Tool
    {
        return new Tool(
            name: 'browser_storage',
            description: 'Manage cookies and Playwright storage state files for browser sessions.',
            parameters: [
                new EnumParameter('action', 'Storage action to perform.', ['list_cookies', 'add_cookie', 'delete_cookie', 'clear_cookies', 'save_state', 'load_state'], true),
                new StringParameter('session', 'Browser session name.', false),
                new StringParameter('name', 'Cookie name.', false),
                new StringParameter('value', 'Cookie value.', false),
                new StringParameter('url', 'Cookie URL.', false),
                new StringParameter('domain', 'Cookie domain.', false),
                new StringParameter('path', 'Cookie path or output path.', false),
                new NumberParameter('expires', 'Cookie expiration timestamp.', false, integer: true),
                new BoolParameter('http_only', 'Whether the cookie is HTTP only.', false),
                new BoolParameter('secure', 'Whether the cookie is secure.', false),
                new EnumParameter('same_site', 'Cookie SameSite policy.', ['Strict', 'Lax', 'None'], false),
                new EnumParameter('browser', 'Browser engine for auto-started sessions.', ['chromium', 'firefox', 'webkit'], false),
                new BoolParameter('headless', 'Headless mode for auto-started sessions.', false),
            ],
            callback: fn (array $input): ToolResult => self::encode(match ($input['action']) {
                'list_cookies' => $this->manager->listCookies($input),
                'add_cookie' => $this->manager->addCookie($input),
                'delete_cookie' => $this->manager->deleteCookie($input),
                'clear_cookies' => $this->manager->clearCookies($input),
                'save_state' => $this->manager->saveState($input),
                'load_state' => $this->manager->loadState($input),
                default => throw new \RuntimeException('Unsupported browser_storage action.'),
            }),
        );
    }

    private static function encode(mixed $payload): ToolResult
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ToolResult::success($json !== false ? $json : '{}');
    }
}