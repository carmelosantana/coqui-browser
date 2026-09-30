<?php

declare(strict_types=1);

namespace CoquiBrowser\Runtime;

use Playwright\Frame\FrameLocatorInterface;
use Playwright\Locator\LocatorInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;
use Playwright\Configuration\PlaywrightConfig;

final class BrowserManager
{
    private const SNAPSHOT_SCRIPT = <<<'JS'
() => {
  const unique = new Set();
  const results = [];
  const interactiveSelectors = [
    'a[href]',
    'button',
    'input:not([type="hidden"])',
    'select',
    'textarea',
    '[role]',
    '[contenteditable="true"]',
    '[tabindex]',
    'summary',
    'label',
    '[data-testid]'
  ];
  const contentSelectors = ['h1', 'h2', 'h3', 'h4', 'p', 'li', 'th', 'td'];
  const normalize = (value) => (value || '').replace(/\s+/g, ' ').trim();
  const visible = (element) => {
    const style = window.getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
  };
  const inferRole = (element) => {
    const explicitRole = element.getAttribute('role');
    if (explicitRole) return explicitRole;
    const tag = element.tagName.toLowerCase();
    if (tag === 'a') return 'link';
    if (tag === 'button') return 'button';
    if (tag === 'select') return 'combobox';
    if (tag === 'textarea') return 'textbox';
    if (tag === 'input') return element.getAttribute('type') || 'input';
    return tag;
  };
  const buildSelector = (element) => {
    if (element.id) return `#${CSS.escape(element.id)}`;
    if (element.getAttribute('data-testid')) return `[data-testid="${CSS.escape(element.getAttribute('data-testid'))}"]`;
    const segments = [];
    let current = element;
    while (current && current.nodeType === Node.ELEMENT_NODE && current !== document.body) {
      let segment = current.tagName.toLowerCase();
      if (current.getAttribute('name')) {
        segment += `[name="${CSS.escape(current.getAttribute('name'))}"]`;
      } else if (current.getAttribute('aria-label')) {
        segment += `[aria-label="${CSS.escape(current.getAttribute('aria-label'))}"]`;
      } else {
        const siblings = Array.from(current.parentElement ? current.parentElement.children : []).filter((child) => child.tagName === current.tagName);
        if (siblings.length > 1) {
          segment += `:nth-of-type(${siblings.indexOf(current) + 1})`;
        }
      }
      segments.unshift(segment);
      current = current.parentElement;
    }
    return segments.join(' > ');
  };
  const collect = (selector, limit) => {
    for (const element of document.querySelectorAll(selector)) {
      if (results.length >= limit) break;
      const selectorPath = buildSelector(element);
      if (!selectorPath || unique.has(selectorPath) || !visible(element)) continue;
      unique.add(selectorPath);
      results.push({
        selector: selectorPath,
        tag: element.tagName.toLowerCase(),
        role: inferRole(element),
        text: normalize(element.innerText || element.textContent || element.value || ''),
        name: normalize(element.getAttribute('aria-label') || element.getAttribute('name') || element.getAttribute('placeholder') || ''),
        type: normalize(element.getAttribute('type') || ''),
        value: normalize(element.value || ''),
        test_id: normalize(element.getAttribute('data-testid') || ''),
        placeholder: normalize(element.getAttribute('placeholder') || ''),
        title: normalize(element.getAttribute('title') || ''),
        href: normalize(element.getAttribute('href') || ''),
        frame: null
      });
    }
  };
  collect(interactiveSelectors.join(','), 120);
  collect(contentSelectors.join(','), 180);
  return results;
}
JS;

    public function __construct(
        private readonly string $workspacePath,
        private readonly EnvironmentChecker $environmentChecker,
        private readonly SessionRegistry $sessions = new SessionRegistry(),
    ) {
    }

    public static function defaultSessionName(string $workspacePath): string
    {
        return 'coqui-browser-' . substr(md5(rtrim($workspacePath, '/')), 0, 8);
    }

    /** @return array<string, mixed> */
    public function setup(bool $withDeps = false): array
    {
        return $this->environmentChecker->setupBrowsers($withDeps);
    }

    /** @return array<string, mixed> */
    public function environmentStatus(): array
    {
        return $this->environmentChecker->status($this->sessionSummaries());
    }

    /** @param array<string, mixed> $options */
    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    public function startSession(array $options = []): array
    {
        $sessionName = $this->sessionName($options['session'] ?? null);

        if ($this->sessions->has($sessionName)) {
            return $this->sessionStatus($sessionName);
        }

        $this->environmentChecker->ensureDirectories();

        $browserType = $this->normalizeBrowser($options['browser'] ?? 'chromium');
        $headless = $this->asBool($options['headless'] ?? true, true);
        $timeoutMs = $this->asInt($options['timeout_ms'] ?? 30000, 30000);

        $config = new PlaywrightConfig(
            headless: $headless,
            timeoutMs: $timeoutMs,
            screenshotDir: $this->environmentChecker->artifactRoot() . '/screenshots',
        );

        $client = PlaywrightFactory::create($config);
        $builder = match ($browserType) {
            'firefox' => $client->firefox(),
            'webkit' => $client->webkit(),
            default => $client->chromium(),
        };

        $browser = $builder->withHeadless($headless)->launch();
        $context = $browser->newContext($this->contextOptions($options));

        $session = new BrowserSession(
            name: $sessionName,
            browserType: $browserType,
            headless: $headless,
            client: $client,
            browser: $browser,
            context: $context,
            artifactRoot: $this->environmentChecker->artifactRoot() . '/' . $sessionName,
            defaultTimeoutMs: $timeoutMs,
        );

        $this->ensureSessionDirectories($session);
        $pageId = $session->addPage($context->newPage());

        $this->sessions->add($session);

        return [
            'started' => true,
            'session' => $sessionName,
            'browser' => $browserType,
            'headless' => $headless,
            'page_id' => $pageId,
        ];
    }

    /** @return array<string, mixed> */
    public function sessionStatus(?string $sessionName = null): array
    {
        $session = $this->session($sessionName);

        return [
            'session' => $session->name,
            'browser' => $session->browserType,
            'headless' => $session->headless,
            'artifact_root' => $session->artifactRoot,
            'active_page_id' => $session->activePageId(),
            'pages' => $this->pageSummaries($session),
        ];
    }

    /** @return array<string, mixed> */
    public function listSessions(): array
    {
        return ['sessions' => $this->sessionSummaries()];
    }

    /** @return array<string, mixed> */
    public function closeSession(?string $sessionName = null): array
    {
        $session = $this->session($sessionName);
        $session->close();
        $this->sessions->remove($session->name);

        return ['closed' => true, 'session' => $session->name];
    }

    /** @return array<string, mixed> */
    public function closeAllSessions(): array
    {
        $closed = $this->sessions->closeAll();

        return ['closed' => $closed];
    }

    /** @return array<string, mixed> */
    public function resetState(?string $sessionName = null): array
    {
        $session = $this->session($sessionName);
        $session->context->clearCookies();

        foreach (glob($session->artifactRoot . '/state/*.json') ?: [] as $stateFile) {
            @unlink($stateFile);
        }

        return ['reset' => true, 'session' => $session->name];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function open(array $input): array
    {
        $url = $this->requiredString($input, 'url');
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $page->goto($url, ['waitUntil' => $input['wait_until'] ?? 'load']);

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function newTab(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $session->addPage($session->context->newPage());
        $page = $session->page($pageId);

        if (isset($input['url']) && is_string($input['url']) && $input['url'] !== '') {
            $page->goto($input['url']);
        }

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function switchTab(array $input): array
    {
        $session = $this->session($input['session'] ?? null);
        $pageId = $this->requiredString($input, 'page_id');
        $session->setActivePage($pageId);

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function closeTab(array $input): array
    {
        $session = $this->session($input['session'] ?? null);
        $pageId = $this->requiredString($input, 'page_id');
        $session->closePage($pageId);

        return ['closed' => true, 'page_id' => $pageId, 'active_page_id' => $session->activePageId()];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function pageStatus(array $input): array
    {
        $session = $this->session($input['session'] ?? null);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function listTabs(array $input): array
    {
        $session = $this->session($input['session'] ?? null);

        return [
            'session' => $session->name,
            'active_page_id' => $session->activePageId(),
            'pages' => $this->pageSummaries($session),
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function navigateHistory(string $direction, array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);

        match ($direction) {
            'back' => $page->goBack(),
            'forward' => $page->goForward(),
            'reload' => $page->reload(),
            default => throw new \RuntimeException("Unsupported navigation action: {$direction}"),
        };

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function setContent(array $input): array
    {
        $html = $this->requiredString($input, 'html');
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $page->setContent($html);

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function waitFor(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $timeout = $this->asInt($input['timeout_ms'] ?? 10000, 10000);
        $waitAction = $this->requiredString($input, 'wait_action');

        match ($waitAction) {
            'selector' => $page->waitForSelector($this->requiredString($input, 'selector'), ['timeout' => $timeout]),
            'url' => $page->waitForURL($this->requiredString($input, 'url_pattern'), ['timeout' => $timeout]),
            'text' => $page->getByText($this->requiredString($input, 'text'))->waitFor(['timeout' => $timeout]),
            'load' => $page->waitForLoadState($input['load_state'] ?? 'load', ['timeout' => $timeout]),
            default => throw new \RuntimeException("Unsupported wait action: {$waitAction}"),
        };

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function interact(string $action, array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);

        if ($action === 'evaluate') {
            $expression = $this->requiredString($input, 'expression');
            $result = $page->evaluate($expression);

            return [
                'session' => $session->name,
                'page_id' => $pageId,
                'result' => $result,
            ];
        }

        if ($action === 'press_page') {
            $page->keyboard()->press($this->requiredString($input, 'key'));

            return $this->pageInfo($session, $pageId);
        }

        $locator = $this->resolveLocator($session, $input, $pageId);

        match ($action) {
            'click' => $locator->click(),
            'dblclick' => $locator->dblclick(),
            'fill' => $locator->fill((string) ($input['value'] ?? '')),
            'type' => $locator->type($this->requiredString($input, 'value')),
            'press' => $locator->press($this->requiredString($input, 'key')),
            'hover' => $locator->hover(),
            'check' => $locator->check(),
            'uncheck' => $locator->uncheck(),
            'select' => $locator->selectOption($this->requiredString($input, 'value')),
            'clear' => $locator->clear(),
            'focus' => $locator->focus(),
            'blur' => $locator->blur(),
            'upload' => $locator->setInputFiles($this->fileInputs($input)),
            'drag' => $locator->dragTo($this->resolveSecondaryLocator($session, $input, $pageId)),
            default => throw new \RuntimeException("Unsupported interaction action: {$action}"),
        };

        return $this->pageInfo($session, $pageId);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function snapshot(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);

        $raw = $page->evaluate(self::SNAPSHOT_SCRIPT);
        if (!is_array($raw)) {
            throw new \RuntimeException('Snapshot evaluation returned an invalid payload.');
        }

        $elements = [];
        $refs = [];
        $counter = 1;

        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['selector']) || !is_string($item['selector'])) {
                continue;
            }

            $ref = 'e' . $counter;
            $counter++;
            $refs[$ref] = $item['selector'];
            $item['ref'] = $ref;
            $elements[] = $item;
        }

        $session->storeRefs($pageId, $refs);

        return [
            'session' => $session->name,
            'page_id' => $pageId,
            'url' => $page->url(),
            'title' => $page->title(),
            'count' => count($elements),
            'elements' => $elements,
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function screenshot(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $path = $this->capturePath($session, 'screenshots', $input['path'] ?? null, 'png');
        $options = ['fullPage' => $this->asBool($input['full_page'] ?? false, false)];

        if ($this->hasLocatorTarget($input)) {
            $locator = $this->resolveLocator($session, $input, $pageId);
            $locator->screenshot($path, $options);
        } else {
            $page->screenshot($path, $options);
        }

        return [
            'session' => $session->name,
            'page_id' => $pageId,
            'path' => $path,
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function pdf(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $path = $this->capturePath($session, 'pdf', $input['path'] ?? null, 'pdf');
        $page->pdf($path, ['format' => $input['format'] ?? 'A4']);

        return [
            'session' => $session->name,
            'page_id' => $pageId,
            'path' => $path,
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function extract(array $input): array
    {
        $session = $this->ensureSession($input);
        $pageId = $this->pageId($session, $input['page_id'] ?? null);
        $page = $session->page($pageId);
        $mode = $this->requiredString($input, 'mode');

        $content = match ($mode) {
            'page_html' => $page->content(),
            'page_text' => $page->evaluate('() => document.body.innerText'),
            'html' => $this->resolveLocator($session, $input, $pageId)->innerHTML(),
            'text' => $this->resolveLocator($session, $input, $pageId)->innerText(),
            'value' => $this->resolveLocator($session, $input, $pageId)->inputValue(),
            'attribute' => $this->resolveLocator($session, $input, $pageId)->getAttribute($this->requiredString($input, 'attribute_name')),
            default => throw new \RuntimeException("Unsupported extract mode: {$mode}"),
        };

        return [
            'session' => $session->name,
            'page_id' => $pageId,
            'mode' => $mode,
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function listCookies(array $input): array
    {
        $session = $this->ensureSession($input);
        $urls = [];

        if (isset($input['url']) && is_string($input['url']) && $input['url'] !== '') {
            $urls[] = $input['url'];
        }

        foreach ($session->pages() as $page) {
            $url = $page->url();
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique($urls));

        return [
            'session' => $session->name,
            'cookies' => $session->context->cookies($urls === [] ? null : $urls),
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function addCookie(array $input): array
    {
        $session = $this->ensureSession($input);
        $cookie = [
            'name' => $this->requiredString($input, 'name'),
            'value' => $this->requiredString($input, 'value'),
            'path' => (string) ($input['path'] ?? '/'),
        ];

        if (isset($input['url']) && is_string($input['url']) && $input['url'] !== '') {
            $parts = parse_url($input['url']);
            $host = $parts === false ? null : ($parts['host'] ?? null);

            if (!is_string($host) || $host === '') {
                throw new \RuntimeException('Unable to derive cookie domain from url.');
            }

            $cookie['domain'] = $host;
            $cookie['path'] = '/';
        } elseif (isset($input['domain']) && is_string($input['domain']) && $input['domain'] !== '') {
            $cookie['domain'] = $input['domain'];
        } else {
            throw new \RuntimeException('Either url or domain is required to add a cookie.');
        }

        if (isset($input['expires'])) {
            $cookie['expires'] = $this->asInt($input['expires'], time() + 3600);
        }
        if (isset($input['http_only'])) {
            $cookie['httpOnly'] = $this->asBool($input['http_only'], false);
        }
        if (isset($input['secure'])) {
            $cookie['secure'] = $this->asBool($input['secure'], false);
        }
        if (isset($input['same_site']) && in_array($input['same_site'], ['Strict', 'Lax', 'None'], true)) {
            $cookie['sameSite'] = $input['same_site'];
        }

        $session->context->addCookies([$cookie]);

        return $this->listCookies($input);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function deleteCookie(array $input): array
    {
        $session = $this->ensureSession($input);
        $session->context->deleteCookie($this->requiredString($input, 'name'));

        return $this->listCookies($input);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function clearCookies(array $input): array
    {
        $session = $this->ensureSession($input);
        $session->context->clearCookies();

        return $this->listCookies($input);
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function saveState(array $input): array
    {
        $session = $this->ensureSession($input);
        $filename = $this->basename($input['path'] ?? null, 'state-' . date('Ymd-His') . '.json');
        $path = $session->statePath($filename);
        $session->context->saveStorageState($path);

        return [
            'session' => $session->name,
            'path' => $path,
        ];
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function loadState(array $input): array
    {
        $session = $this->ensureSession($input);
        $path = $this->requiredString($input, 'path');
        if (!is_file($path)) {
            throw new \RuntimeException("State file does not exist: {$path}");
        }

        $session->context->loadStorageState($path);

        return [
            'session' => $session->name,
            'path' => $path,
            'loaded' => true,
        ];
    }

    private function sessionName(mixed $session): string
    {
        if (is_string($session) && trim($session) !== '') {
            return trim($session);
        }

        return self::defaultSessionName($this->workspacePath);
    }

    private function session(mixed $sessionName = null): BrowserSession
    {
        return $this->sessions->get($this->sessionName($sessionName));
    }

    /** @param array<string, mixed> $input */
    private function ensureSession(array $input): BrowserSession
    {
        $sessionName = $this->sessionName($input['session'] ?? null);
        if (!$this->sessions->has($sessionName)) {
            $this->startSession($input);
        }

        return $this->sessions->get($sessionName);
    }

    /** @param array<string, mixed> $options */
    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    private function contextOptions(array $options): array
    {
        $context = [];

        if (isset($options['storage_state_path']) && is_string($options['storage_state_path']) && $options['storage_state_path'] !== '') {
            $context['storageState'] = $options['storage_state_path'];
        }
        if (isset($options['user_agent']) && is_string($options['user_agent']) && $options['user_agent'] !== '') {
            $context['userAgent'] = $options['user_agent'];
        }

        $width = isset($options['viewport_width']) ? $this->asInt($options['viewport_width'], 1440) : null;
        $height = isset($options['viewport_height']) ? $this->asInt($options['viewport_height'], 900) : null;
        if ($width !== null || $height !== null) {
            $context['viewport'] = [
                'width' => $width ?? 1440,
                'height' => $height ?? 900,
            ];
        }

        return $context;
    }

    private function ensureSessionDirectories(BrowserSession $session): void
    {
        $paths = [
            $session->artifactRoot,
            $session->artifactRoot . '/screenshots',
            $session->artifactRoot . '/pdf',
            $session->artifactRoot . '/state',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Failed to create directory: {$path}");
            }
        }
    }

    private function normalizeBrowser(mixed $browser): string
    {
        $browser = is_string($browser) ? strtolower(trim($browser)) : 'chromium';

        return match ($browser) {
            'chromium', 'firefox', 'webkit' => $browser,
            default => throw new \RuntimeException("Unsupported browser: {$browser}"),
        };
    }

    private function pageId(BrowserSession $session, mixed $pageId): string
    {
        if (is_string($pageId) && $pageId !== '') {
            return $pageId;
        }

        return $session->activePageId();
    }

    /** @return array<int, array<string, mixed>> */
    private function pageSummaries(BrowserSession $session): array
    {
        $pages = [];

        foreach ($session->pages() as $pageId => $page) {
            $pages[] = $this->pageSummary($session, $pageId, $page);
        }

        return $pages;
    }

    /** @return array<int, array<string, mixed>> */
    private function sessionSummaries(): array
    {
        $sessions = [];

        foreach ($this->sessions->all() as $session) {
            $sessions[] = [
                'session' => $session->name,
                'browser' => $session->browserType,
                'headless' => $session->headless,
                'active_page_id' => $session->activePageId(),
                'page_count' => count($session->pages()),
            ];
        }

        return $sessions;
    }

    /** @return array<string, mixed> */
    private function pageInfo(BrowserSession $session, string $pageId): array
    {
        $page = $session->page($pageId);

        return [
            'session' => $session->name,
            'page' => $this->pageSummary($session, $pageId, $page),
        ];
    }

    /** @return array<string, mixed> */
    private function pageSummary(BrowserSession $session, string $pageId, PageInterface $page): array
    {
        return [
            'page_id' => $pageId,
            'active' => $session->activePageId() === $pageId,
            'url' => $page->url(),
            'title' => $page->title(),
            'viewport' => $page->viewportSize(),
            'closed' => $page->isClosed(),
        ];
    }

    /** @param array<string, mixed> $input */
    private function resolveLocator(BrowserSession $session, array $input, string $pageId): LocatorInterface
    {
        $page = $session->page($pageId);
        $scope = $this->scopeForInput($page, $input);

        if (isset($input['ref']) && is_string($input['ref']) && $input['ref'] !== '') {
            $selector = $session->selectorForRef($pageId, $input['ref']);
            return $scope->locator($selector);
        }
        if (isset($input['selector']) && is_string($input['selector']) && $input['selector'] !== '') {
            return $scope->locator($input['selector']);
        }
        if (isset($input['role']) && is_string($input['role']) && $input['role'] !== '') {
            $options = [];
            if (isset($input['name']) && is_string($input['name']) && $input['name'] !== '') {
                $options['name'] = $input['name'];
            }
            $locator = $scope->getByRole($input['role'], $options);

            return $this->applyNth($locator, $input['nth'] ?? null);
        }
        if (isset($input['text']) && is_string($input['text']) && $input['text'] !== '') {
            return $this->applyNth($scope->getByText($input['text']), $input['nth'] ?? null);
        }
        if (isset($input['label']) && is_string($input['label']) && $input['label'] !== '') {
            return $this->applyNth($scope->getByLabel($input['label']), $input['nth'] ?? null);
        }
        if (isset($input['placeholder']) && is_string($input['placeholder']) && $input['placeholder'] !== '') {
            return $this->applyNth($scope->getByPlaceholder($input['placeholder']), $input['nth'] ?? null);
        }
        if (isset($input['test_id']) && is_string($input['test_id']) && $input['test_id'] !== '') {
            return $this->applyNth($scope->getByTestId($input['test_id']), $input['nth'] ?? null);
        }
        if (isset($input['title']) && is_string($input['title']) && $input['title'] !== '') {
            return $this->applyNth($scope->getByTitle($input['title']), $input['nth'] ?? null);
        }

        throw new \RuntimeException('A target is required. Provide ref, selector, role/name, text, label, placeholder, test_id, or title.');
    }

    /** @param array<string, mixed> $input */
    private function resolveSecondaryLocator(BrowserSession $session, array $input, string $pageId): LocatorInterface
    {
        $secondary = [
            'selector' => $input['target_selector'] ?? null,
            'ref' => $input['target_ref'] ?? null,
            'role' => $input['target_role'] ?? null,
            'name' => $input['target_name'] ?? null,
            'text' => $input['target_text'] ?? null,
            'frame_selector' => $input['target_frame_selector'] ?? null,
        ];

        return $this->resolveLocator($session, array_filter($secondary, static fn ($value): bool => $value !== null && $value !== ''), $pageId);
    }

    /** @param array<string, mixed> $input */
    private function hasLocatorTarget(array $input): bool
    {
        foreach (['ref', 'selector', 'role', 'text', 'label', 'placeholder', 'test_id', 'title'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input
     *  @return array<int, string>|string
     */
    private function fileInputs(array $input): array|string
    {
        if (isset($input['file_paths']) && is_array($input['file_paths']) && $input['file_paths'] !== []) {
            $paths = array_values(array_filter($input['file_paths'], 'is_string'));
            if ($paths !== []) {
                return $paths;
            }
        }

        return $this->requiredString($input, 'file_path');
    }

    private function applyNth(LocatorInterface $locator, mixed $nth): LocatorInterface
    {
        if ($nth === null || $nth === '') {
            return $locator;
        }

        return $locator->nth($this->asInt($nth, 0));
    }

    /** @param array<string, mixed> $input */
    /** @param array<string, mixed> $input */
    private function scopeForInput(PageInterface $page, array $input): PageInterface|FrameLocatorInterface
    {
        if (isset($input['frame_selector']) && is_string($input['frame_selector']) && $input['frame_selector'] !== '') {
            return $page->frameLocator($input['frame_selector']);
        }

        return $page;
    }

    private function capturePath(BrowserSession $session, string $type, mixed $path, string $extension): string
    {
        $filename = $this->basename($path, $type . '-' . date('Ymd-His') . '.' . $extension);
        $directory = $type === 'pdf' ? $session->artifactRoot . '/pdf' : $session->artifactRoot . '/screenshots';

        return $directory . '/' . $filename;
    }

    private function basename(mixed $path, string $fallback): string
    {
        if (is_string($path) && trim($path) !== '') {
            return basename($path);
        }

        return $fallback;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
            throw new \RuntimeException("{$key} is required.");
        }

        return trim($input[$key]);
    }

    private function asBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    private function asInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}