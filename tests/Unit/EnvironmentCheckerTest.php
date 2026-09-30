<?php

declare(strict_types=1);

use CoquiBrowser\Runtime\EnvironmentChecker;

test('artifact root is namespaced away from the legacy toolkit', function (): void {
    $checker = new EnvironmentChecker('/tmp/coqui-workspace');

    expect($checker->artifactRoot())->toBe('/tmp/coqui-workspace/browser-playwright');
});

test('ensureDirectories creates browser workspace folders', function (): void {
    $workspace = sys_get_temp_dir() . '/coqui-browser-' . uniqid();
    $checker = new EnvironmentChecker($workspace);

    $checker->ensureDirectories();

    expect(is_dir($workspace . '/browser-playwright/screenshots'))->toBeTrue()
        ->and(is_dir($workspace . '/browser-playwright/pdf'))->toBeTrue()
        ->and(is_dir($workspace . '/browser-playwright/state'))->toBeTrue();
});