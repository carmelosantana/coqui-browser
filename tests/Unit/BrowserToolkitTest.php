<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBrowser\BrowserToolkit;
use CoquiBrowser\Runtime\BrowserManager;

test('toolkit implements ToolkitInterface', function (): void {
    $toolkit = new BrowserToolkit(sys_get_temp_dir());

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('toolkit exposes expected tool names', function (): void {
    $toolkit = new BrowserToolkit(sys_get_temp_dir());

    $names = array_map(static fn ($tool): string => $tool->name(), $toolkit->tools());

    expect($names)->toBe([
        'browser_session',
        'browser_page',
        'browser_interact',
        'browser_capture',
        'browser_storage',
    ]);
});

test('guidelines mention snapshot and storage state workflow', function (): void {
    $toolkit = new BrowserToolkit(sys_get_temp_dir());

    expect($toolkit->guidelines())
        ->toContain('browser_capture')
        ->toContain('snapshot')
        ->toContain('browser_storage');
});

test('fromEnv creates a toolkit instance', function (): void {
    $toolkit = BrowserToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(BrowserToolkit::class);
});

test('default session name is deterministic', function (): void {
    $first = BrowserManager::defaultSessionName('/tmp/workspace-a');
    $second = BrowserManager::defaultSessionName('/tmp/workspace-a');
    $third = BrowserManager::defaultSessionName('/tmp/workspace-b');

    expect($first)->toBe($second)
        ->and($first)->not->toBe($third)
        ->and($first)->toStartWith('coqui-browser-');
});