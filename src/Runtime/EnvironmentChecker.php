<?php

declare(strict_types=1);

namespace CoquiBrowser\Runtime;

use Symfony\Component\Process\Process;

final class EnvironmentChecker
{
    public function __construct(
        private readonly string $workspacePath,
        private readonly ?string $packageRoot = null,
    ) {
    }

    public function packageRoot(): string
    {
        return $this->packageRoot ?? dirname(__DIR__, 2);
    }

    public function artifactRoot(): string
    {
        return rtrim($this->workspacePath, '/') . '/browser-playwright';
    }

    public function ensureDirectories(): void
    {
        $paths = [
            $this->artifactRoot(),
            $this->artifactRoot() . '/screenshots',
            $this->artifactRoot() . '/pdf',
            $this->artifactRoot() . '/state',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException("Failed to create directory: {$path}");
            }
        }
    }

    /** @return array{available: bool, version: string|null} */
    public function nodeStatus(): array
    {
        try {
            $process = new Process(['node', '--version']);
            $process->mustRun();

            return ['available' => true, 'version' => trim($process->getOutput())];
        } catch (\Throwable) {
            return ['available' => false, 'version' => null];
        }
    }

    /** @return array{available: bool, version: string|null} */
    public function npmStatus(): array
    {
        try {
            $process = new Process(['npm', '--version']);
            $process->mustRun();

            return ['available' => true, 'version' => trim($process->getOutput())];
        } catch (\Throwable) {
            return ['available' => false, 'version' => null];
        }
    }

    public function installerPath(): string
    {
        $installer = $this->packageRoot() . '/vendor/bin/playwright-install';

        if (!is_file($installer)) {
            throw new \RuntimeException('Playwright installer not found. Run composer install in the toolkit package first.');
        }

        return $installer;
    }

    /** @return array<string, mixed> */
    /** @param array<int, array<string, mixed>> $sessions
     *  @return array<string, mixed>
     */
    public function status(array $sessions = []): array
    {
        $node = $this->nodeStatus();
        $npm = $this->npmStatus();

        return [
            'workspace_path' => $this->workspacePath,
            'artifact_root' => $this->artifactRoot(),
            'node' => $node,
            'npm' => $npm,
            'installer_exists' => is_file($this->packageRoot() . '/vendor/bin/playwright-install'),
            'session_count' => count($sessions),
            'sessions' => array_values($sessions),
        ];
    }

    /** @return array<string, mixed> */
    public function setupBrowsers(bool $withDeps = false): array
    {
        $node = $this->nodeStatus();
        if ($node['available'] === false) {
            throw new \RuntimeException('Node.js 20+ is required to install Playwright browsers.');
        }

        $this->ensureDirectories();

        $command = [$this->installerPath(), '--browsers'];
        if ($withDeps) {
            $command[] = '--with-deps';
        }

        $process = new Process($command, $this->packageRoot());
        $process->setTimeout(900);
        $process->mustRun();

        return [
            'installed' => true,
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }
}