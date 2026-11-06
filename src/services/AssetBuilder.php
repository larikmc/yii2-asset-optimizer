<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Orchestrates incremental CSS/JS compilation, content hashing, manifest generation,
 * and atomic release activation.
 */
class AssetBuilder
{
    private EsbuildRunner $esbuildRunner;
    private AssetScanner $scanner;
    private ManifestRegistry $manifestRegistry;

    private string $runtimePath;
    private string $optimizedBasePath;
    private string $optimizedBaseUrl;
    private string $buildCacheFile;
    private string $lockFile;

    /**
     * @param EsbuildRunner $esbuildRunner
     * @param AssetScanner $scanner
     * @param ManifestRegistry $manifestRegistry
     * @param string|null $runtimePath Defaults to "@runtime/asset-optimizer".
     * @param string|null $optimizedBasePath Defaults to "@webroot/optimized".
     * @param string|null $optimizedBaseUrl Defaults to "@web/optimized".
     */
    public function __construct(
        EsbuildRunner $esbuildRunner,
        AssetScanner $scanner,
        ManifestRegistry $manifestRegistry,
        ?string $runtimePath = null,
        ?string $optimizedBasePath = null,
        ?string $optimizedBaseUrl = null
    ) {
        $this->esbuildRunner = $esbuildRunner;
        $this->scanner = $scanner;
        $this->manifestRegistry = $manifestRegistry;

        $this->runtimePath = Yii::getAlias($runtimePath ?? '@runtime/asset-optimizer');
        $this->optimizedBasePath = Yii::getAlias($optimizedBasePath ?? '@webroot/optimized');
        $this->optimizedBaseUrl = Yii::getAlias($optimizedBaseUrl ?? '@web/optimized');

        $this->buildCacheFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'build-cache' . DIRECTORY_SEPARATOR . 'cache.json';
        $this->lockFile = $this->runtimePath . DIRECTORY_SEPARATOR . '.build.lock';
    }

    /**
     * Performs an incremental or full build.
     *
     * @param bool $full If true, ignores build cache and rebuilds all assets from scratch.
     * @return array{
     *     buildId: string,
     *     manifest: Manifest,
     *     totalFiles: int,
     *     processedFiles: int,
     *     reusedFiles: int,
     *     originalSizeBytes: int,
     *     optimizedSizeBytes: int,
     *     savingPercent: float,
     *     durationSeconds: float
     * }
     * @throws RuntimeException
     */
    public function build(bool $full = false): array
    {
        $startTime = microtime(true);
        $locker = new FileLocker($this->lockFile);
        $locker->acquire();

        $buildId = Manifest::generateBuildId();
        $stagingDir = $this->runtimePath . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $buildId;

        $this->ensureDirectoriesExist($stagingDir);

        try {
            $cache = $full ? [] : $this->loadBuildCache();
            $scannedItems = $this->scanner->scan();

            $manifest = new Manifest($buildId);
            $manifest->toolchain = [
                'php' => PHP_VERSION,
                'esbuild' => $this->esbuildRunner->isAvailable() ? $this->esbuildRunner->getVersion() : 'unknown',
                'optimizer' => '1.0.0',
            ];

            $processedCount = 0;
            $reusedCount = 0;
            $newCache = [];

            foreach ($scannedItems as $item) {
                $key = $item['key'];
                $srcPath = $item['sourceAbsolutePath'];
                $type = $item['type'];

                if (!file_exists($srcPath)) {
                    throw new RuntimeException("Source asset file does not exist: {$srcPath} (referenced by {$key})");
                }

                $mtime = (int) @filemtime($srcPath);
                $originalSize = (int) @filesize($srcPath);
                $sourceContent = (string) @file_get_contents($srcPath);
                $sourceHash = hash('sha256', $sourceContent);

                $cached = $cache[$key] ?? null;
                $canReuse = false;

                if (
                    !$full &&
                    is_array($cached) &&
                    ($cached['sourceHash'] ?? '') === $sourceHash &&
                    isset($cached['outputRelativePath'])
                ) {
                    $existingOptimizedFile = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cached['outputRelativePath']);
                    if (file_exists($existingOptimizedFile) && filesize($existingOptimizedFile) > 0) {
                        $canReuse = true;
                    }
                }

                if ($canReuse) {
                    // Reuse cached asset record
                    $record = AssetRecord::fromArray($cached);
                    $manifest->addAsset($record);
                    $newCache[$key] = $cached;
                    $reusedCount++;
                    continue;
                }

                // Compile asset through esbuild
                $processedCount++;
                $baseName = pathinfo($srcPath, PATHINFO_FILENAME);
                $cleanBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);

                $stagingOutFile = $stagingDir . DIRECTORY_SEPARATOR . "tmp_{$cleanBaseName}.min.{$type}";

                if ($type === 'css') {
                    $res = $this->esbuildRunner->minifyCss($srcPath, $stagingOutFile, $this->optimizedBaseUrl . '/');
                } else {
                    $res = $this->esbuildRunner->minifyJs($srcPath, $stagingOutFile);
                }

                if ($res['exitCode'] !== 0 || !file_exists($stagingOutFile)) {
                    throw new RuntimeException("Compilation failed for {$srcPath}:\n" . ($res['stderr'] ?: $res['stdout']));
                }

                $compiledContent = (string) file_get_contents($stagingOutFile);
                $contentHash = hash('sha256', $compiledContent);
                $shortHash = substr($contentHash, 0, 12);
                $optimizedSize = strlen($compiledContent);

                $outputRelName = "{$cleanBaseName}.{$shortHash}.min.{$type}";
                $finalDestFile = $this->optimizedBasePath . DIRECTORY_SEPARATOR . $outputRelName;

                // Move compiled file to permanent optimized directory
                AtomicFileSwitch::atomicWrite($finalDestFile, $compiledContent);
                @unlink($stagingOutFile);

                // Copy any auxiliary static files created by esbuild (e.g. in staging/assets/)
                $stagingAssetsDir = $stagingDir . DIRECTORY_SEPARATOR . 'assets';
                $destAssetsDir = $this->optimizedBasePath . DIRECTORY_SEPARATOR . 'assets';
                $dependencies = [];

                if (is_dir($stagingAssetsDir)) {
                    if (!is_dir($destAssetsDir)) {
                        @mkdir($destAssetsDir, 0777, true);
                    }
                    $dirItems = (array) scandir($stagingAssetsDir);
                    foreach ($dirItems as $depFile) {
                        if ($depFile === '.' || $depFile === '..') {
                            continue;
                        }
                        $srcDep = $stagingAssetsDir . DIRECTORY_SEPARATOR . $depFile;
                        $dstDep = $destAssetsDir . DIRECTORY_SEPARATOR . $depFile;
                        if (is_file($srcDep)) {
                            AtomicFileSwitch::atomicWrite($dstDep, (string) file_get_contents($srcDep));
                            @unlink($srcDep);
                            $dependencies[] = 'assets/' . $depFile;
                            $manifest->dependencies[] = 'assets/' . $depFile;
                        }
                    }
                }

                $integrity = 'sha384-' . base64_encode(hash('sha384', $compiledContent, true));

                $record = new AssetRecord();
                $record->key = $key;
                $record->type = $type;
                $record->bundleClass = $item['bundleClass'];
                $record->sourceRelativePath = $item['sourceRelativePath'];
                $record->outputRelativePath = $outputRelName;
                $record->url = rtrim($this->optimizedBaseUrl, '/') . '/' . $outputRelName;
                $record->contentHash = $contentHash;
                $record->shortHash = $shortHash;
                $record->sourceHash = $sourceHash;
                $record->originalSize = $originalSize;
                $record->optimizedSize = $optimizedSize;
                $record->integrity = $integrity;
                $record->dependencies = $dependencies;

                $manifest->addAsset($record);
                $newCache[$key] = $record->toArray();
            }

            // Save immutable release manifest
            $manifestsDir = $this->runtimePath . DIRECTORY_SEPARATOR . 'manifests';
            $manifestFile = $manifestsDir . DIRECTORY_SEPARATOR . "manifest-{$buildId}.json";
            $manifestJson = $manifest->toJson(true);
            AtomicFileSwitch::atomicWrite($manifestFile, $manifestJson);

            // Copy to current.json
            $currentJsonFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.json';
            AtomicFileSwitch::atomicWrite($currentJsonFile, $manifestJson);

            // Atomically switch active version pointer
            $versionFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.version';
            AtomicFileSwitch::switchVersion($versionFile, $buildId);

            // Invalidate runtime manifest cache
            $this->manifestRegistry->invalidateCache();

            // Save build cache
            $this->saveBuildCache($newCache);

            $duration = round(microtime(true) - $startTime, 4);

            return [
                'buildId' => $buildId,
                'manifest' => $manifest,
                'totalFiles' => count($scannedItems),
                'processedFiles' => $processedCount,
                'reusedFiles' => $reusedCount,
                'originalSizeBytes' => $manifest->summary['originalSizeBytes'],
                'optimizedSizeBytes' => $manifest->summary['optimizedSizeBytes'],
                'savingPercent' => $manifest->summary['savingPercent'],
                'durationSeconds' => $duration,
            ];
        } finally {
            $this->removeDirectoryRecursive($stagingDir);
            $locker->release();
        }
    }

    private function ensureDirectoriesExist(string $stagingDir): void
    {
        $dirs = [
            $this->runtimePath,
            $this->runtimePath . DIRECTORY_SEPARATOR . 'build-cache',
            $this->runtimePath . DIRECTORY_SEPARATOR . 'manifests',
            $stagingDir,
            $this->optimizedBasePath,
            $this->optimizedBasePath . DIRECTORY_SEPARATOR . 'assets',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory: {$dir}");
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadBuildCache(): array
    {
        if (!file_exists($this->buildCacheFile)) {
            return [];
        }

        try {
            $content = (string) file_get_contents($this->buildCacheFile);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data['files'] ?? null) ? $data['files'] : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $files
     */
    private function saveBuildCache(array $files): void
    {
        $payload = [
            'updatedAt' => date('c'),
            'files' => $files,
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            AtomicFileSwitch::atomicWrite($this->buildCacheFile, $json);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = (array) scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
