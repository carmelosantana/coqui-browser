<?php

declare(strict_types=1);

use CoquiBrowser\BrowserToolkit;
use Symfony\Component\Process\Process;

test('browser toolkit can navigate interact capture and persist state', function (): void {
    if (getenv('COQUI_BROWSER_RUN_INTEGRATION') !== '1') {
        $this->markTestSkipped('Set COQUI_BROWSER_RUN_INTEGRATION=1 to run browser integration tests.');
    }

    $workspace = sys_get_temp_dir() . '/coqui-browser-int-' . uniqid();
    mkdir($workspace, 0775, true);

    $fixtures = dirname(__DIR__) . '/Fixtures';
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->not->toBeFalse();
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    expect($address)->toBeString();
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

    $server = new Process(['php', '-S', "127.0.0.1:{$port}", '-t', $fixtures]);
    $server->start();

    try {
        usleep(500000);

        $toolkit = new BrowserToolkit($workspace);
        $tools = [];
        foreach ($toolkit->tools() as $tool) {
            $tools[$tool->name()] = $tool;
        }

        $browser = getenv('COQUI_BROWSER_TEST_BROWSER') ?: 'chromium';
        $baseUrl = "http://127.0.0.1:{$port}/basic-page.html";

        $start = decodeTool($tools['browser_session']->execute([
            'action' => 'start',
            'session' => 'integration',
            'browser' => $browser,
            'headless' => true,
        ]));

        expect($start['started'])->toBeTrue();

        decodeTool($tools['browser_page']->execute([
            'action' => 'open',
            'session' => 'integration',
            'url' => $baseUrl,
        ]));

        $snapshot = decodeTool($tools['browser_capture']->execute([
            'action' => 'snapshot',
            'session' => 'integration',
        ]));

        $buttonRef = findRefByText($snapshot['elements'], 'Reveal dynamic content');
        expect($buttonRef)->not->toBeNull();

        decodeTool($tools['browser_interact']->execute([
            'action' => 'fill',
            'session' => 'integration',
            'label' => 'Name',
            'value' => 'Carmelo',
        ]));

        decodeTool($tools['browser_interact']->execute([
            'action' => 'select',
            'session' => 'integration',
            'label' => 'Flavor',
            'value' => 'mint',
        ]));

        decodeTool($tools['browser_interact']->execute([
            'action' => 'click',
            'session' => 'integration',
            'ref' => $buttonRef,
        ]));

        decodeTool($tools['browser_page']->execute([
            'action' => 'wait',
            'session' => 'integration',
            'wait_action' => 'text',
            'text' => 'Dynamic content loaded',
            'timeout_ms' => 8000,
        ]));

        $cookieResult = decodeTool($tools['browser_storage']->execute([
            'action' => 'add_cookie',
            'session' => 'integration',
            'name' => 'coqui_session',
            'value' => 'active',
            'url' => $baseUrl,
        ]));
        expect($cookieResult['cookies'])->not->toBeEmpty();

        $state = decodeTool($tools['browser_storage']->execute([
            'action' => 'save_state',
            'session' => 'integration',
            'path' => 'integration-state.json',
        ]));
        expect(is_file($state['path']))->toBeTrue();

        $newTab = decodeTool($tools['browser_page']->execute([
            'action' => 'new_tab',
            'session' => 'integration',
            'url' => "http://127.0.0.1:{$port}/secondary-page.html",
        ]));
        expect($newTab['page']['url'])->toContain('secondary-page.html');

        $screenshot = decodeTool($tools['browser_capture']->execute([
            'action' => 'screenshot',
            'session' => 'integration',
            'path' => 'integration.png',
        ]));
        expect(is_file($screenshot['path']))->toBeTrue();

        $pdfResult = $tools['browser_capture']->execute([
            'action' => 'pdf',
            'session' => 'integration',
            'path' => 'integration.pdf',
        ]);

        if ($browser === 'chromium') {
            $pdf = decodeTool($pdfResult);
            expect(is_file($pdf['path']))->toBeTrue();
        } else {
            expect($pdfResult->status->value)->toBe('error')
                ->and($pdfResult->content)->toContain('Chromium');
        }

        $extract = decodeTool($tools['browser_capture']->execute([
            'action' => 'extract',
            'session' => 'integration',
            'mode' => 'page_text',
        ]));
        expect($extract['content'])->toContain('Second page');

        decodeTool($tools['browser_session']->execute([
            'action' => 'close',
            'session' => 'integration',
        ]));
    } finally {
        $server->stop(1);
    }
});

/** @return array<string, mixed> */
function decodeTool($result): array
{
    expect($result->content)->toBeString();
    $decoded = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    return $decoded;
}

function findRefByText(array $elements, string $needle): ?string
{
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        $text = (string) ($element['text'] ?? '');
        if (str_contains($text, $needle)) {
            return is_string($element['ref'] ?? null) ? $element['ref'] : null;
        }
    }

    return null;
}