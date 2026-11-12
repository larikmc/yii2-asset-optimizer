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
     * @var bool Whether to combine all CSS and JS into single common bundles.
     */
    public bool $combineAssets = false;

    /**
     * @var string Base name for combined bundle files (e.g. 'all' -> 'all.hash.min.css').
     */
    public string $combinedBundleName = 'all';

    /**
     * @var string[] Bundle classes that should NOT be merged into the combined bundle.
     */
    public array $uncombinedBundles = [];

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
                'optimizer' => '1.1.0',
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
                $dependencies = $this->copyStagingAssets($stagingDir, $manifest);

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

            if ($this->combineAssets) {
                $this->buildCombinedAssets($scannedItems, $stagingDir, $manifest, $newCache, $cache, $full);
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
                'totalFiles' => count($manifest->assets),
                'processedFiles' => $processedCount,
                'reusedFiles' => $reusedCount,
                'originalSizeBytes' => $manifest->summary['originalSizeBytes'],
                'optimizedSizeBytes' => $manifest->summary['optimizedSizeBytes'],
                'savingPercent' => $manifest->summary['savingPercent'],
                'durationSeconds' => $duration,
                'combined' => $manifest->hasCombinedAssets() ? [
                    'enabled' => true,
                    'css' => $manifest->getCombinedCss()?->url,
                    'js' => $manifest->getCombinedJs()?->url,
                    'jsHead' => $manifest->getCombinedJsHead()?->url,
                ] : null,
            ];
        } finally {
            $this->removeDirectoryRecursive($stagingDir);
            $locker->release();
        }
    }

    /**
     * Copies any auxiliary static assets (e.g. fonts, images) generated by esbuild from staging.
     *
     * @param string $stagingDir
     * @param Manifest $manifest
     * @return string[]
     */
    private function copyStagingAssets(string $stagingDir, Manifest $manifest): array
    {
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
                    if (!in_array('assets/' . $depFile, $manifest->dependencies, true)) {
                        $manifest->dependencies[] = 'assets/' . $depFile;
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Builds combined CSS and JS bundles from the scanned items and attaches them to the manifest.
     *
     * @param array<int, array{key: string, type: string, bundleClass: string, sourceRelativePath: string, sourceAbsolutePath: string, position?: int}> $scannedItems
     * @param string $stagingDir
     * @param Manifest $manifest
     * @param array<string, array<string, mixed>> $newCache
     * @param array<string, array<string, mixed>> $cache
     * @param bool $full
     */
    protected function buildCombinedAssets(
        array $scannedItems,
        string $stagingDir,
        Manifest $manifest,
        array &$newCache,
        array $cache,
        bool $full
    ): void {
        $cssItems = [];
        $jsHeadItems = [];
        $jsEndItems = [];
        $combinedBundles = [];
        $combinedAssetKeys = [];

        foreach ($scannedItems as $item) {
            $bundleClass = $item['bundleClass'] ?? '';
            if ($bundleClass !== '' && in_array($bundleClass, $this->uncombinedBundles, true)) {
                continue;
            }

            if ($item['type'] === 'css') {
                $cssItems[] = $item;
                $combinedAssetKeys[] = $item['key'];
                if ($bundleClass !== '') {
                    $combinedBundles[$bundleClass] = true;
                }
            } elseif ($item['type'] === 'js') {
                if (($item['position'] ?? null) === \yii\web\View::POS_HEAD) {
                    $jsHeadItems[] = $item;
                } else {
                    $jsEndItems[] = $item;
                }
                $combinedAssetKeys[] = $item['key'];
                if ($bundleClass !== '') {
                    $combinedBundles[$bundleClass] = true;
                }
            }
        }

        $cssRecord = null;
        $jsRecord = null;
        $jsHeadRecord = null;

        // 1. Process Combined CSS
        if (!empty($cssItems)) {
            $cssSources = [];
            $totalCssOriginalSize = 0;
            $cssSourceHashes = [];

            foreach ($cssItems as $item) {
                $src = $item['sourceAbsolutePath'];
                if (!isset($cssSources[$src])) {
                    $cssSources[$src] = $item;
                    $totalCssOriginalSize += (int) @filesize($src);
                    $cssSourceHashes[] = hash('sha256', (string) @file_get_contents($src));
                }
            }

            $compositeCssHash = hash('sha256', implode('|', $cssSourceHashes));
            $cachedCss = $cache['__combined_css__'] ?? null;
            $canReuseCss = false;

            if (
                !$full &&
                is_array($cachedCss) &&
                ($cachedCss['compositeHash'] ?? '') === $compositeCssHash &&
                isset($cachedCss['outputRelativePath'])
            ) {
                $existing = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cachedCss['outputRelativePath']);
                if (file_exists($existing) && filesize($existing) > 0) {
                    $canReuseCss = true;
                }
            }

            if ($canReuseCss) {
                $cssRecord = AssetRecord::fromArray($cachedCss);
                $manifest->addAsset($cssRecord);
                $newCache['__combined_css__'] = $cachedCss;
            } else {
                $entryLines = [];
                foreach (array_keys($cssSources) as $srcPath) {
                    $norm = str_replace('\\', '/', $srcPath);
                    $entryLines[] = "@import \"{$norm}\";";
                }
                $entryFile = $stagingDir . DIRECTORY_SEPARATOR . 'combined_entry.css';
                file_put_contents($entryFile, implode("\n", $entryLines) . "\n");

                $stagingOutCss = $stagingDir . DIRECTORY_SEPARATOR . 'tmp_combined.min.css';
                $res = $this->esbuildRunner->minifyCss($entryFile, $stagingOutCss, $this->optimizedBaseUrl . '/');
                if ($res['exitCode'] !== 0 || !file_exists($stagingOutCss)) {
                    throw new RuntimeException("Combined CSS compilation failed:\n" . ($res['stderr'] ?: $res['stdout']));
                }

                $compiledCss = (string) file_get_contents($stagingOutCss);
                $contentHash = hash('sha256', $compiledCss);
                $shortHash = substr($contentHash, 0, 12);
                $outputRelName = "{$this->combinedBundleName}.{$shortHash}.min.css";
                $finalDest = $this->optimizedBasePath . DIRECTORY_SEPARATOR . $outputRelName;
                AtomicFileSwitch::atomicWrite($finalDest, $compiledCss);
                @unlink($stagingOutCss);
                @unlink($entryFile);

                $dependencies = $this->copyStagingAssets($stagingDir, $manifest);
                $integrity = 'sha384-' . base64_encode(hash('sha384', $compiledCss, true));

                $cssRecord = new AssetRecord();
                $cssRecord->key = 'combined:css';
                $cssRecord->type = 'css';
                $cssRecord->bundleClass = '';
                $cssRecord->sourceRelativePath = 'combined.css';
                $cssRecord->outputRelativePath = $outputRelName;
                $cssRecord->url = rtrim($this->optimizedBaseUrl, '/') . '/' . $outputRelName;
                $cssRecord->contentHash = $contentHash;
                $cssRecord->shortHash = $shortHash;
                $cssRecord->sourceHash = $compositeCssHash;
                $cssRecord->originalSize = $totalCssOriginalSize;
                $cssRecord->optimizedSize = strlen($compiledCss);
                $cssRecord->integrity = $integrity;
                $cssRecord->dependencies = $dependencies;

                $manifest->addAsset($cssRecord);
                $cacheData = $cssRecord->toArray();
                $cacheData['compositeHash'] = $compositeCssHash;
                $newCache['__combined_css__'] = $cacheData;
            }
        }

        // 2. Process Combined JS (End and Head)
        $jsTargets = [
            'js' => [
                'items' => $jsEndItems,
                'cacheKey' => '__combined_js__',
                'key' => 'combined:js',
                'filePrefix' => $this->combinedBundleName,
            ],
            'jsHead' => [
                'items' => $jsHeadItems,
                'cacheKey' => '__combined_js_head__',
                'key' => 'combined:js_head',
                'filePrefix' => "{$this->combinedBundleName}-head",
            ],
        ];

        foreach ($jsTargets as $targetType => $config) {
            $items = $config['items'];
            if (empty($items)) {
                continue;
            }

            $cacheKey = $config['cacheKey'];
            $key = $config['key'];
            $filePrefix = $config['filePrefix'];

            $jsSources = [];
            $totalJsOriginalSize = 0;
            $jsSourceHashes = [];

            foreach ($items as $item) {
                $src = $item['sourceAbsolutePath'];
                if (!isset($jsSources[$src])) {
                    $jsSources[$src] = $item;
                    $totalJsOriginalSize += (int) @filesize($src);
                    $jsSourceHashes[] = hash('sha256', (string) @file_get_contents($src));
                }
            }

            $compositeJsHash = hash('sha256', implode('|', $jsSourceHashes));
            $cachedJs = $cache[$cacheKey] ?? null;
            $canReuseJs = false;

            if (
                !$full &&
                is_array($cachedJs) &&
                ($cachedJs['compositeHash'] ?? '') === $compositeJsHash &&
                isset($cachedJs['outputRelativePath'])
            ) {
                $existing = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cachedJs['outputRelativePath']);
                if (file_exists($existing) && filesize($existing) > 0) {
                    $canReuseJs = true;
                }
            }

            if ($canReuseJs) {
                $record = AssetRecord::fromArray($cachedJs);
                $manifest->addAsset($record);
                $newCache[$cacheKey] = $cachedJs;
                if ($targetType === 'js') {
                    $jsRecord = $record;
                } else {
                    $jsHeadRecord = $record;
                }
            } else {
                $entryFile = $stagingDir . DIRECTORY_SEPARATOR . "entry_{$targetType}.js";
                $fp = fopen($entryFile, 'wb');
                foreach (array_keys($jsSources) as $srcPath) {
                    $content = (string) file_get_contents($srcPath);
                    fwrite($fp, "\n;\n/* --- " . basename($srcPath) . " --- */\n" . trim($content) . "\n;\n");
                }
                fclose($fp);

                $stagingOutJs = $stagingDir . DIRECTORY_SEPARATOR . "tmp_{$targetType}.min.js";
                $res = $this->esbuildRunner->minifyJs($entryFile, $stagingOutJs);
                if ($res['exitCode'] !== 0 || !file_exists($stagingOutJs)) {
                    throw new RuntimeException("Combined JS compilation failed for {$targetType}:\n" . ($res['stderr'] ?: $res['stdout']));
                }

                $compiledJs = (string) file_get_contents($stagingOutJs);
                $contentHash = hash('sha256', $compiledJs);
                $shortHash = substr($contentHash, 0, 12);
                $outputRelName = "{$filePrefix}.{$shortHash}.min.js";
                $finalDest = $this->optimizedBasePath . DIRECTORY_SEPARATOR . $outputRelName;
                AtomicFileSwitch::atomicWrite($finalDest, $compiledJs);
                @unlink($stagingOutJs);
                @unlink($entryFile);

                $integrity = 'sha384-' . base64_encode(hash('sha384', $compiledJs, true));

                $record = new AssetRecord();
                $record->key = $key;
                $record->type = 'js';
                $record->bundleClass = '';
                $record->sourceRelativePath = "combined_{$targetType}.js";
                $record->outputRelativePath = $outputRelName;
                $record->url = rtrim($this->optimizedBaseUrl, '/') . '/' . $outputRelName;
                $record->contentHash = $contentHash;
                $record->shortHash = $shortHash;
                $record->sourceHash = $compositeJsHash;
                $record->originalSize = $totalJsOriginalSize;
                $record->optimizedSize = strlen($compiledJs);
                $record->integrity = $integrity;

                $manifest->addAsset($record);
                $cacheData = $record->toArray();
                $cacheData['compositeHash'] = $compositeJsHash;
                $newCache[$cacheKey] = $cacheData;

                if ($targetType === 'js') {
                    $jsRecord = $record;
                } else {
                    $jsHeadRecord = $record;
                }
            }
        }

        $manifest->combined = [
            'enabled' => true,
            'css' => $cssRecord?->toArray(),
            'js' => $jsRecord?->toArray(),
            'jsHead' => $jsHeadRecord?->toArray(),
            'bundles' => array_keys($combinedBundles),
            'assets' => array_values(array_unique($combinedAssetKeys)),
        ];
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
