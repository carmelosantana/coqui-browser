<?php

declare(strict_types=1);

use CoquiBrowser\Runtime\SessionRegistry;

test('empty registry returns no sessions', function (): void {
    $registry = new SessionRegistry();

    expect($registry->all())->toBe([])
        ->and($registry->has('missing'))->toBeFalse();
});