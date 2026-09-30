<?php

declare(strict_types=1);

namespace CoquiBrowser\Runtime;

use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightClient;

final class BrowserSession
{
    /** @var array<string, PageInterface> */
    private array $pages = [];

    /** @var array<string, array<string, string>> */
    private array $pageRefs = [];

    private int $pageCounter = 0;

    private ?string $activePageId = null;

    public function __construct(
        public readonly string $name,
        public readonly string $browserType,
        public readonly bool $headless,
        public readonly PlaywrightClient $client,
        public readonly BrowserInterface $browser,
        public readonly BrowserContextInterface $context,
        public readonly string $artifactRoot,
        public readonly int $defaultTimeoutMs,
    ) {
    }

    public function addPage(PageInterface $page): string
    {
        $this->pageCounter++;
        $pageId = 'page-' . $this->pageCounter;
        $this->pages[$pageId] = $page;
        $this->activePageId = $pageId;

        return $pageId;
    }

    public function ensurePage(): string
    {
        if ($this->activePageId !== null && isset($this->pages[$this->activePageId])) {
            return $this->activePageId;
        }

        $pageId = $this->addPage($this->context->newPage());

        return $pageId;
    }

    public function activePageId(): string
    {
        return $this->ensurePage();
    }

    public function setActivePage(string $pageId): void
    {
        if (!isset($this->pages[$pageId])) {
            throw new \RuntimeException("Unknown page id: {$pageId}");
        }

        $this->activePageId = $pageId;
        $this->pages[$pageId]->bringToFront();
    }

    public function page(string $pageId): PageInterface
    {
        if (!isset($this->pages[$pageId])) {
            throw new \RuntimeException("Unknown page id: {$pageId}");
        }

        return $this->pages[$pageId];
    }

    /** @return array<string, PageInterface> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function closePage(string $pageId): void
    {
        $page = $this->page($pageId);
        $page->close();
        unset($this->pages[$pageId], $this->pageRefs[$pageId]);

        if ($this->activePageId === $pageId) {
            $this->activePageId = array_key_last($this->pages);
        }
    }

    /** @param array<string, string> $refMap */
    public function storeRefs(string $pageId, array $refMap): void
    {
        $this->pageRefs[$pageId] = $refMap;
    }

    public function selectorForRef(string $pageId, string $ref): string
    {
        $ref = ltrim(trim($ref), '@');

        if (!isset($this->pageRefs[$pageId][$ref])) {
            throw new \RuntimeException("Unknown ref: {$ref}. Run browser_capture snapshot first.");
        }

        return $this->pageRefs[$pageId][$ref];
    }

    public function statePath(string $basename): string
    {
        return $this->artifactRoot . '/state/' . $basename;
    }

    public function screenshotPath(string $basename): string
    {
        return $this->artifactRoot . '/screenshots/' . $basename;
    }

    public function pdfPath(string $basename): string
    {
        return $this->artifactRoot . '/pdf/' . $basename;
    }

    public function close(): void
    {
        try {
            $this->browser->close();
        } finally {
            $this->client->close();
        }
    }
}