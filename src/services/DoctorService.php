<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use Yii;

/**
 * Diagnostic service that inspects the host environment, tools, permissions, and caches.
 */
class DoctorService
{
    private EsbuildRunner $esbuildRunner;
    private string $runtimePath;
    private string $optimizedBasePath;

    public function __construct(
        EsbuildRunner $esbuildRunner,
        ?string $runtimePath = null,
        ?string $optimizedBasePath = null
    ) {
        $this->esbuildRunner = $esbuildRunner;
        $this->runtimePath = Yii::getAlias($runtimePath ?? '@runtime/asset-optimizer');
        $this->optimizedBasePath = Yii::getAlias($optimizedBasePath ?? '@webroot/optimized');
    }

    /**
     * @return array<string, array{status: 'ok'|'warning'|'error', message: string, detail?: string}>
     */
    public function diagnose(): array
    {
        $checks = [];

        // 1. PHP Version
        if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
            $checks['php_version'] = [
                'status' => 'ok',
                'message' => 'PHP version ' . PHP_VERSION . ' is supported (>= 8.0.0).',
            ];
        } else {
            $checks['php_version'] = [
                'status' => 'error',
                'message' => 'PHP version ' . PHP_VERSION . ' is outdated. Requires PHP >= 8.0.0.',
            ];
        }

        // 2. JSON Extension
        if (extension_loaded('json')) {
            $checks['ext_json'] = [
                'status' => 'ok',
                'message' => 'ext-json is loaded.',
            ];
        } else {
            $checks['ext_json'] = [
                'status' => 'error',
                'message' => 'ext-json is missing.',
            ];
        }

        // 3. APCu Extension
        if (extension_loaded('apcu') && (bool) ini_get('apc.enabled')) {
            $checks['ext_apcu'] = [
                'status' => 'ok',
                'message' => 'APCu is enabled. High-speed in-memory manifest caching is active.',
            ];
        } else {
            $checks['ext_apcu'] = [
                'status' => 'warning',
                'message' => 'APCu is not enabled. The optimizer will use filesystem JSON caching.',
                'detail' => 'Install ext-apcu and set apc.enabled=1 for optimal performance under high traffic.',
            ];
        }

        // 4. OPcache status
        if (extension_loaded('Zend OPcache')) {
            $valTimestamps = ini_get('opcache.validate_timestamps');
            $checks['opcache'] = [
                'status' => 'ok',
                'message' => "Zend OPcache is active (opcache.validate_timestamps={$valTimestamps}).",
                'detail' => 'Manifest is stored in JSON, so opcache_reset() is NOT required when assets update.',
            ];
        } else {
            $checks['opcache'] = [
                'status' => 'warning',
                'message' => 'Zend OPcache is not enabled.',
            ];
        }

        // 5. esbuild Toolchain
        if ($this->esbuildRunner->isAvailable()) {
            $checks['esbuild'] = [
                'status' => 'ok',
                'message' => 'esbuild executable resolved successfully: ' . $this->esbuildRunner->getVersion(),
            ];
        } else {
            $checks['esbuild'] = [
                'status' => 'error',
                'message' => 'esbuild is not available or executable.',
                'detail' => 'Ensure Node.js and esbuild are installed or specify custom path in configuration.',
            ];
        }

        // 6. Runtime directory permissions
        $runtimeDir = $this->runtimePath;
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0777, true);
        }
        if (is_writable($runtimeDir)) {
            $checks['runtime_permissions'] = [
                'status' => 'ok',
                'message' => "Runtime directory is writable: {$runtimeDir}",
            ];
        } else {
            $checks['runtime_permissions'] = [
                'status' => 'error',
                'message' => "Runtime directory is not writable: {$runtimeDir}",
            ];
        }

        // 7. Optimized web directory permissions
        $optimizedDir = $this->optimizedBasePath;
        if (!is_dir($optimizedDir)) {
            @mkdir($optimizedDir, 0777, true);
        }
        if (is_writable($optimizedDir)) {
            $checks['web_permissions'] = [
                'status' => 'ok',
                'message' => "Public optimized directory is writable: {$optimizedDir}",
            ];
        } else {
            $checks['web_permissions'] = [
                'status' => 'error',
                'message' => "Public optimized directory is not writable: {$optimizedDir}",
            ];
        }

        return $checks;
    }
}
