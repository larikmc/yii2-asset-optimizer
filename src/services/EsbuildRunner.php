<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use RuntimeException;

/**
 * Executes esbuild commands across Windows and Linux platforms.
 */
class EsbuildRunner
{
    private ?string $esbuildBin;
    private ?string $cachedVersion = null;

    /**
     * @param string|null $esbuildBin Custom path to esbuild binary or command (e.g. "npx esbuild" or "/usr/local/bin/esbuild").
     */
    public function __construct(?string $esbuildBin = null)
    {
        $this->esbuildBin = $esbuildBin;
    }

    /**
     * Resolves the command string or path to esbuild.
     */
    public function resolveBinary(): string
    {
        if ($this->esbuildBin !== null && $this->esbuildBin !== '') {
            return $this->esbuildBin;
        }

        // 1. Check local node_modules in project
        $localPaths = [
            dirname(__DIR__, 2) . '/node_modules/.bin/esbuild.cmd',
            dirname(__DIR__, 2) . '/node_modules/.bin/esbuild',
            'D:/DevTools/nodejs/node-v24.19.0-win-x64/npx.cmd esbuild',
        ];

        foreach ($localPaths as $path) {
            $checkPath = explode(' ', $path)[0];
            if (file_exists($checkPath)) {
                return $this->esbuildBin = $path;
            }
        }

        // 2. Check if esbuild or npx is available in PATH
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $testCmd = $isWindows ? 'where esbuild 2>nul' : 'which esbuild 2>/dev/null';
        $output = @shell_exec($testCmd);
        if ($output !== null && trim($output) !== '') {
            $lines = explode("\n", trim($output));
            return $this->esbuildBin = trim($lines[0]);
        }

        // 3. Fallback to npx
        $npxCmd = $isWindows ? 'where npx 2>nul' : 'which npx 2>/dev/null';
        $npxOutput = @shell_exec($npxCmd);
        if ($npxOutput !== null && trim($npxOutput) !== '') {
            return $this->esbuildBin = 'npx esbuild';
        }

        return $this->esbuildBin = 'npx esbuild';
    }

    /**
     * Checks if esbuild is available and runnable.
     */
    public function isAvailable(): bool
    {
        try {
            $version = $this->getVersion();
            return $version !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns the detected esbuild version string.
     */
    public function getVersion(): string
    {
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }

        $result = $this->execute(['--version']);
        if ($result['exitCode'] === 0) {
            $this->cachedVersion = trim($result['stdout']);
            return $this->cachedVersion;
        }

        throw new RuntimeException("Failed to determine esbuild version: " . ($result['stderr'] ?: $result['stdout']));
    }

    /**
     * Minifies a JavaScript file.
     * By default, individual files are minified without bundling to preserve global scopes and jQuery plugins.
     *
     * @param string $inputFile Absolute path to input JS.
     * @param string $outputFile Absolute path to target output JS.
     * @param array<string, string|bool> $options
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function minifyJs(string $inputFile, string $outputFile, array $options = []): array
    {
        $args = [
            $inputFile,
            '--minify',
            '--target=' . ($options['target'] ?? 'es2018'),
            '--outfile=' . $outputFile,
        ];

        if (!empty($options['sourcemap'])) {
            $args[] = '--sourcemap=external';
        }

        return $this->execute($args);
    }

    /**
     * Minifies a CSS file with dependency bundling (resolving @import and url()).
     * Images and fonts are exported with content hashes.
     *
     * @param string $inputFile Absolute path to input CSS.
     * @param string $outputFile Absolute path to target output CSS.
     * @param string $publicPath Public URL base for assets (e.g. "/optimized/").
     * @param array<string, string|bool> $options
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function minifyCss(string $inputFile, string $outputFile, string $publicPath = '/optimized/', array $options = []): array
    {
        $args = [
            $inputFile,
            '--bundle',
            '--minify',
            '--loader:.woff2=file',
            '--loader:.woff=file',
            '--loader:.ttf=file',
            '--loader:.eot=file',
            '--loader:.svg=file',
            '--loader:.png=file',
            '--loader:.jpg=file',
            '--loader:.jpeg=file',
            '--loader:.gif=file',
            '--loader:.webp=file',
            '--asset-names=assets/[name].[hash]',
            '--public-path=' . rtrim($publicPath, '/') . '/',
            '--outfile=' . $outputFile,
        ];

        if (!empty($options['sourcemap'])) {
            $args[] = '--sourcemap=external';
        }

        return $this->execute($args);
    }

    /**
     * Executes an esbuild command with arguments.
     *
     * @param string[] $args Arguments to pass to esbuild.
     * @param string|null $cwd Working directory.
     * @return array{exitCode: int, stdout: string, stderr: string, command: string}
     */
    public function execute(array $args, ?string $cwd = null): array
    {
        $bin = $this->resolveBinary();

        // Prepare command line with proper quoting for Windows / Linux
        $escapedArgs = array_map(function ($arg) {
            return escapeshellarg((string) $arg);
        }, $args);

        $command = $bin . ' ' . implode(' ', $escapedArgs);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Ensure PATH includes known node directory if on Windows
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $nodeDir = 'D:\\DevTools\\nodejs\\node-v24.19.0-win-x64';
            if (is_dir($nodeDir)) {
                $currentPath = $env['PATH'] ?? (getenv('PATH') ?: '');
                if (!str_contains($currentPath, $nodeDir)) {
                    $env['PATH'] = $nodeDir . ';' . $currentPath;
                }
            }
        }

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to launch esbuild process: {$command}");
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'command' => $command,
        ];
    }
}
