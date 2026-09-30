<?php

declare(strict_types=1);

namespace CoquiBrowser\Runtime;

final class SessionRegistry
{
    /** @var array<string, BrowserSession> */
    private array $sessions = [];

    public function has(string $name): bool
    {
        return isset($this->sessions[$name]);
    }

    public function add(BrowserSession $session): void
    {
        $this->sessions[$session->name] = $session;
    }

    public function get(string $name): BrowserSession
    {
        if (!isset($this->sessions[$name])) {
            throw new \RuntimeException("Unknown browser session: {$name}");
        }

        return $this->sessions[$name];
    }

    /** @return array<string, BrowserSession> */
    public function all(): array
    {
        return $this->sessions;
    }

    public function remove(string $name): void
    {
        unset($this->sessions[$name]);
    }

    public function closeAll(): int
    {
        $closed = 0;

        foreach ($this->sessions as $name => $session) {
            $session->close();
            unset($this->sessions[$name]);
            $closed++;
        }

        return $closed;
    }
}