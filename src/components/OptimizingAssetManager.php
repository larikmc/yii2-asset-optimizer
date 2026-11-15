<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\components;

use larikmc\assetoptimizer\bundles\CombinedAssetBundle;
use larikmc\assetoptimizer\services\AssetBuilder;
use larikmc\assetoptimizer\services\AssetScanner;
use larikmc\assetoptimizer\services\EsbuildRunner;
use larikmc\assetoptimizer\services\ManifestRegistry;
use Yii;
use yii\helpers\Url;
use yii\web\AssetBundle;
use yii\web\AssetManager;

/**
 * OptimizingAssetManager extends standard yii\web\AssetManager to resolve
 * CSS/JS assets from content-hashed pre-compiled manifests with ZERO runtime overhead.
 */
class OptimizingAssetManager extends AssetManager
{
    /**
     * @var bool Whether asset optimization URL resolution is active. Defaults to true.
     */
    public bool $enabled = true;

    /**
     * @var string Base runtime directory storing current release manifests.
     */
    public string $optimizerRuntimePath = '@runtime/asset-optimizer';

    /**
     * @var string Base public URL storing optimized hashed assets.
     */
    public string $optimizedBaseUrl = '@web/optimized';

    /**
     * @var string Base filesystem directory storing optimized hashed assets.
     */
    public string $optimizedBasePath = '@webroot/optimized';

    /**
     * @var bool Whether to enable APCu caching for manifest lookups.
     */
    public bool $enableApcu = true;

    /**
     * @var string[] List of AssetBundle classes that should NOT be optimized.
     */
    public array $excludedBundles = [];

    /**
     * @var string[] List of file patterns that should NOT be optimized.
     */
    public array $excludedFiles = [];

    /**
     * @var bool If true, prevents unnecessary file copying from sourcePath for fully optimized bundles.
     */
    public bool $bypassSourcePathPublish = false;

    /**
     * @var bool Whether to combine all CSS and JS into single common bundles. Defaults to true.
     * When enabled and the active manifest contains combined assets,
     * individual bundled CSS and JS are served via CombinedAssetBundle.
     */
    public bool $combineAssets = true;

    /**
     * @var int Interval in seconds to cache active build ID in APCu (0 = check current.version on every request for instant sync).
     */
    public int $versionCheckInterval = 0;

    /**
     * @var bool Preserved setting for unoptimized assets fallback.
     */
    public bool $appendTimestampForUnoptimized = false;

    /**
     * @var string Base bundle name for combined assets when combineAssets is enabled. Defaults to "all".
     */
    public string $combinedBundleName = 'all';

    /**
     * @var string[] List of AssetBundle classes that should remain uncombined even when combineAssets is enabled.
     */
    public array $uncombinedBundles = [];

    /**
     * @var bool|null Whether to automatically check source file changes and rebuild assets on HTTP requests.
     * When null, auto-enabled ONLY in development environments (YII_ENV_DEV === true or YII_DEBUG && !YII_ENV_PROD).
     * STRICTLY disabled on production.
     */
    public ?bool $autoRebuild = null;

    /**
     * @var int Minimum seconds between change detection checks in autoRebuild mode. Defaults to 1 second.
     */
    public int $autoRebuildInterval = 1;

    /**
     * @var string[] Extra bundles to scan for auto-rebuild if not explicitly configured.
     */
    public array $scanBundles = [];

    /**
     * @var string[] Extra files to scan for auto-rebuild.
     */
    public array $scanExtraFiles = [];

    /**
     * @var string|null Optional custom path to esbuild executable.
     */
    public ?string $esbuildBin = null;

    /**
     * @var string|null Optional custom path to Node.js directory.
     */
    public ?string $nodePath = null;

    /**
     * @var bool Tracks whether auto-rebuild check was already performed in the current HTTP request.
     */
    private static bool $_autoRebuildCheckedInRequest = false;

    private ?ManifestRegistry $_manifestRegistry = null;

    public function init(): void
    {
        parent::init();

        if ($this->enabled) {
            // Preserve configured appendTimestamp for unoptimized fallback assets
            $this->appendTimestampForUnoptimized = (bool) $this->appendTimestamp;
            // Disable global appendTimestamp so yii\web\View does NOT issue filemtime() disk checks on HTTP requests
            $this->appendTimestamp = false;

            if ($this->isAutoRebuildActive()) {
                $this->checkAutoRebuild();
            }
        }
    }

    /**
     * Overrides loadBundle to safely bypass sourcePath copying for fully optimized bundles,
     * or to combine bundles into CombinedAssetBundle when combineAssets is enabled.
     *
     * @param string $name
     * @param array $config
     * @param bool $publish
     * @return AssetBundle
     */
    protected function loadBundle($name, $config = [], $publish = true)
    {
        if (!$this->enabled || $this->isBundleExcluded($name)) {
            return parent::loadBundle($name, $config, $publish);
        }

        if ($this->isAutoRebuildActive()) {
            $this->checkAutoRebuild();
        }

        $manifest = $this->getManifestRegistry()->getActiveManifest();

        // 1. Resolve synthetic CombinedAssetBundle
        if ($name === CombinedAssetBundle::class) {
            /** @var CombinedAssetBundle $bundle */
            $bundle = Yii::createObject(CombinedAssetBundle::class);
            $bundle->basePath = $this->optimizedBasePath !== null ? Yii::getAlias($this->optimizedBasePath) : null;
            $bundle->baseUrl = $this->optimizedBaseUrl !== null ? Yii::getAlias($this->optimizedBaseUrl) : null;
            $bundle->sourcePath = null;
            $bundle->depends = [];

            if ($manifest !== null && $manifest->hasCombinedAssets()) {
                $cssRec = $manifest->getCombinedCss();
                if ($cssRec !== null) {
                    $bundle->css = [$cssRec->outputRelativePath];
                }
                $jsItems = [];
                $jsHeadRec = $manifest->getCombinedJsHead();
                if ($jsHeadRec !== null) {
                    $jsItems[] = [$jsHeadRec->outputRelativePath, 'position' => \yii\web\View::POS_HEAD];
                }
                $jsRec = $manifest->getCombinedJs();
                if ($jsRec !== null) {
                    $jsItems[] = $jsRec->outputRelativePath;
                }
                $bundle->js = $jsItems;
            }
            return $bundle;
        }

        // 2. Handle combined bundles transformation when combineAssets is enabled
        if ($this->combineAssets && $manifest !== null && $manifest->hasCombinedAssets() && $manifest->isBundleCombined($name)) {
            if (!isset($config['class'])) {
                $config['class'] = $name;
            }
            /** @var AssetBundle $bundle */
            $bundle = Yii::createObject($config);

            // Filter out combined CSS files
            $remainingCss = [];
            foreach ($bundle->css as $item) {
                $file = is_array($item) ? ($item[0] ?? null) : $item;
                if ($file === null) {
                    continue;
                }
                $spec = $this->splitAssetSpec($file);
                $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
                if (!$manifest->isAssetCombined($key)) {
                    $remainingCss[] = $item;
                }
            }
            $bundle->css = $remainingCss;

            // Filter out combined JS files
            $remainingJs = [];
            foreach ($bundle->js as $item) {
                $file = is_array($item) ? ($item[0] ?? null) : $item;
                if ($file === null) {
                    continue;
                }
                $spec = $this->splitAssetSpec($file);
                $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
                if (!$manifest->isAssetCombined($key)) {
                    $remainingJs[] = $item;
                }
            }
            $bundle->js = $remainingJs;

            // Ensure dependency on CombinedAssetBundle
            if (!in_array(CombinedAssetBundle::class, $bundle->depends, true)) {
                $bundle->depends[] = CombinedAssetBundle::class;
            }

            if ($bundle->sourcePath !== null) {
                if (empty($bundle->css) && empty($bundle->js)) {
                    $bundle->basePath = $this->optimizedBasePath !== null ? Yii::getAlias($this->optimizedBasePath) : null;
                    $bundle->baseUrl = $this->optimizedBaseUrl !== null ? Yii::getAlias($this->optimizedBaseUrl) : null;
                    return $bundle;
                }
                if ($this->bypassSourcePathPublish && $this->isBundleFullyOptimized($bundle)) {
                    $bundle->basePath = $this->optimizedBasePath !== null ? Yii::getAlias($this->optimizedBasePath) : null;
                    $bundle->baseUrl = $this->optimizedBaseUrl !== null ? Yii::getAlias($this->optimizedBaseUrl) : null;
                    return $bundle;
                }
            }

            if ($publish && $bundle->sourcePath !== null) {
                $bundle->publish($this);
            }

            return $bundle;
        }

        // 3. Fallback to standard bypassSourcePathPublish logic
        if (!$this->bypassSourcePathPublish) {
            return parent::loadBundle($name, $config, $publish);
        }

        if (!isset($config['class'])) {
            $config['class'] = $name;
        }

        /** @var AssetBundle $bundle */
        $bundle = Yii::createObject($config);

        if ($publish && $bundle->sourcePath !== null && $this->isBundleFullyOptimized($bundle)) {
            $bundle->basePath = $this->optimizedBasePath !== null ? Yii::getAlias($this->optimizedBasePath) : null;
            $bundle->baseUrl = $this->optimizedBaseUrl !== null ? Yii::getAlias($this->optimizedBaseUrl) : null;
            return $bundle;
        }

        if ($publish) {
            $bundle->publish($this);
        }

        return $bundle;
    }

    /**
     * Checks if all CSS and JS files in a bundle are present in the active manifest.
     *
     * @param AssetBundle $bundle
     * @return bool
     */
    public function isBundleFullyOptimized(AssetBundle $bundle): bool
    {
        if (empty($bundle->css) && empty($bundle->js)) {
            return false;
        }

        $manifest = $this->getManifestRegistry()->getActiveManifest();
        if ($manifest === null) {
            return false;
        }

        foreach ($bundle->css as $item) {
            $file = is_array($item) ? ($item[0] ?? null) : $item;
            if ($file === null || !Url::isRelative($file) || str_starts_with($file, '//')) {
                continue;
            }
            if ($this->isFileExcluded($file)) {
                return false;
            }
            $spec = $this->splitAssetSpec($file);
            $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
            if ($manifest->getAsset($key) === null) {
                return false;
            }
        }

        foreach ($bundle->js as $item) {
            $file = is_array($item) ? ($item[0] ?? null) : $item;
            if ($file === null || !Url::isRelative($file) || str_starts_with($file, '//')) {
                continue;
            }
            if ($this->isFileExcluded($file)) {
                return false;
            }
            $spec = $this->splitAssetSpec($file);
            $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
            if ($manifest->getAsset($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Splits an asset specification into clean path, meaningful query parameters, and fragment.
     * Purely technical cache-busting timestamps (v=..., t=..., ver=...) are filtered out for optimized assets.
     *
     * @param string $asset
     * @return array{cleanPath: string, meaningfulQuery: ?string, fragment: ?string}
     */
    public function splitAssetSpec(string $asset): array
    {
        $posFragment = strpos($asset, '#');
        $fragment = $posFragment !== false ? substr($asset, $posFragment + 1) : null;
        $beforeFragment = $posFragment !== false ? substr($asset, 0, $posFragment) : $asset;

        $posQuery = strpos($beforeFragment, '?');
        $queryString = $posQuery !== false ? substr($beforeFragment, $posQuery + 1) : null;
        $cleanPath = $posQuery !== false ? substr($beforeFragment, 0, $posQuery) : $beforeFragment;

        $meaningfulQuery = null;
        if ($queryString !== null && $queryString !== '') {
            parse_str($queryString, $params);
            $filtered = [];
            foreach ($params as $k => $v) {
                $lowerKey = strtolower((string) $k);
                if (in_array($lowerKey, ['v', 't', 'ver', 'version', '_'], true) && (is_numeric($v) || preg_match('/^[a-f0-9]{8,}$/i', (string) $v))) {
                    continue;
                }
                $filtered[$k] = $v;
            }
            if (!empty($filtered)) {
                $meaningfulQuery = http_build_query($filtered);
            }
        }

        return [
            'cleanPath' => $cleanPath,
            'meaningfulQuery' => $meaningfulQuery,
            'fragment' => $fragment,
        ];
    }

    /**
     * Returns the actual URL for the specified asset.
     * When optimization is enabled and the asset is found in the manifest,
     * returns the pre-compiled content-hashed URL directly without disk calls.
     *
     * @param AssetBundle $bundle
     * @param string $asset
     * @param bool|null $appendTimestamp
     * @return string
     */
    public function getAssetUrl($bundle, $asset, $appendTimestamp = null): string
    {
        if (!$this->enabled || $this->isBundleExcluded($bundle) || $this->isFileExcluded($asset)) {
            $ts = $appendTimestamp ?? $this->appendTimestampForUnoptimized;
            return parent::getAssetUrl($bundle, $asset, $ts);
        }

        $spec = $this->splitAssetSpec($asset);
        $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
        $optimizedUrl = $this->getManifestRegistry()->resolveUrl($key);

        if ($optimizedUrl !== null) {
            $resultUrl = $optimizedUrl;
            if ($spec['meaningfulQuery'] !== null && $spec['meaningfulQuery'] !== '') {
                $resultUrl .= (str_contains($resultUrl, '?') ? '&' : '?') . $spec['meaningfulQuery'];
            }
            if ($spec['fragment'] !== null && $spec['fragment'] !== '') {
                $resultUrl .= '#' . $spec['fragment'];
            }
            return $resultUrl;
        }

        $ts = $appendTimestamp ?? $this->appendTimestampForUnoptimized;
        return parent::getAssetUrl($bundle, $asset, $ts);
    }

    /**
     * Returns actual URL without parameters.
     *
     * @param AssetBundle $bundle
     * @param string $asset
     * @return string
     */
    public function getActualAssetUrl($bundle, $asset): string
    {
        if (!$this->enabled || $this->isBundleExcluded($bundle) || $this->isFileExcluded($asset)) {
            return parent::getActualAssetUrl($bundle, $asset);
        }

        $spec = $this->splitAssetSpec($asset);
        $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
        $optimizedUrl = $this->getManifestRegistry()->resolveUrl($key);

        if ($optimizedUrl !== null) {
            return $optimizedUrl;
        }

        return parent::getActualAssetUrl($bundle, $asset);
    }

    /**
     * Resolves the Subresource Integrity (SRI) string for the given asset if available.
     *
     * @param AssetBundle $bundle
     * @param string $asset
     * @return string|null
     */
    public function getAssetIntegrity($bundle, $asset): ?string
    {
        if (!$this->enabled || $this->isBundleExcluded($bundle) || $this->isFileExcluded($asset)) {
            return null;
        }

        $spec = $this->splitAssetSpec($asset);
        $key = $this->buildAssetKey($bundle, $spec['cleanPath']);
        return $this->getManifestRegistry()->resolveIntegrity($key);
    }

    /**
     * Builds an unambiguous composite key for asset lookup.
     * Format: "BundleClass:relativePath" (e.g. "frontend\assets\AppAsset:css/site.css").
     *
     * @param AssetBundle $bundle
     * @param string $asset
     * @return string
     */
    public function buildAssetKey($bundle, string $asset): string
    {
        $bundleClass = is_object($bundle) ? get_class($bundle) : (string) $bundle;
        $cleanPath = strtok($asset, '?#');
        $cleanAsset = ($cleanPath !== false && $cleanPath !== '') ? $cleanPath : $asset;
        $cleanAsset = ltrim(str_replace('\\', '/', $cleanAsset), '/');

        return "{$bundleClass}:{$cleanAsset}";
    }

    public function isBundleExcluded($bundle): bool
    {
        if (empty($this->excludedBundles)) {
            return false;
        }

        $class = is_object($bundle) ? get_class($bundle) : (string) $bundle;
        return in_array($class, $this->excludedBundles, true);
    }

    public function isFileExcluded(string $file): bool
    {
        if (empty($this->excludedFiles)) {
            return false;
        }

        $cleanPath = strtok($file, '?#');
        $cleanFile = ($cleanPath !== false && $cleanPath !== '') ? $cleanPath : $file;

        $norm = str_replace('\\', '/', $cleanFile);
        $normRaw = str_replace('\\', '/', $file);
        foreach ($this->excludedFiles as $pattern) {
            $normPattern = str_replace('\\', '/', $pattern);
            if (fnmatch($normPattern, $norm) || str_contains($norm, $normPattern)
                || fnmatch($normPattern, $normRaw) || str_contains($normRaw, $normPattern)) {
                return true;
            }
        }

        return false;
    }

    public function getManifestRegistry(): ManifestRegistry
    {
        if ($this->_manifestRegistry === null) {
            $this->_manifestRegistry = new ManifestRegistry(
                $this->optimizerRuntimePath,
                $this->enableApcu,
                'asset_optimizer:',
                $this->versionCheckInterval
            );
        }

        return $this->_manifestRegistry;
    }

    public function setManifestRegistry(ManifestRegistry $registry): void
    {
        $this->_manifestRegistry = $registry;
    }

    /**
     * Resets request-level state for unit tests.
     */
    public static function resetRequestState(): void
    {
        self::$_autoRebuildCheckedInRequest = false;
    }

    /**
     * Determines whether auto-rebuild is active.
     * Guaranteed to return false on production!
     */
    public function isAutoRebuildActive(): bool
    {
        // 1. Hard production guard: NEVER allow auto-rebuild in production!
        $isProd = (defined('YII_ENV_PROD') && YII_ENV_PROD) || (defined('YII_ENV') && YII_ENV === 'prod');
        $isDebug = defined('YII_DEBUG') && YII_DEBUG;

        if ($isProd && !$isDebug) {
            return false;
        }

        // 2. Explicit setting
        if ($this->autoRebuild !== null) {
            return (bool) $this->autoRebuild;
        }

        // 3. Default: active only in dev or debug environments
        $isDev = (defined('YII_ENV_DEV') && YII_ENV_DEV) || (defined('YII_ENV') && YII_ENV === 'dev');
        return $isDev || $isDebug;
    }

    /**
     * Checks if source files have changed and rebuilds assets if needed (Dev mode only).
     */
    public function checkAutoRebuild(): void
    {
        if (self::$_autoRebuildCheckedInRequest) {
            return;
        }
        self::$_autoRebuildCheckedInRequest = true;

        if (!$this->isAutoRebuildActive() || !$this->enabled) {
            return;
        }

        // Cross-request throttle via .last_check file
        $runtimePath = Yii::getAlias($this->optimizerRuntimePath, false);
        if ($runtimePath !== false && $runtimePath !== '') {
            $checkFile = $runtimePath . DIRECTORY_SEPARATOR . '.last_check';
            $now = time();
            if (file_exists($checkFile)) {
                $lastCheck = (int) @filemtime($checkFile);
                if (($now - $lastCheck) < $this->autoRebuildInterval) {
                    return;
                }
            }
            if (!is_dir($runtimePath)) {
                @mkdir($runtimePath, 0777, true);
            }
            @touch($checkFile);
        }

        $manifest = $this->getManifestRegistry()->getActiveManifest();
        $needsRebuild = false;

        if ($manifest === null) {
            $needsRebuild = true;
        } elseif ($manifest->hasChangedSourceFiles()) {
            $needsRebuild = true;
        } elseif (empty($manifest->sourceFiles)) {
            // Legacy manifest: compare source file mtimes with manifest creation timestamp
            $manifestTime = strtotime($manifest->createdAt);
            if ($manifestTime > 0) {
                try {
                    $scanner = $this->createAssetScanner();
                    $items = $scanner->scan();
                    foreach ($items as $item) {
                        if (file_exists($item['sourceAbsolutePath']) && (int) @filemtime($item['sourceAbsolutePath']) > $manifestTime) {
                            $needsRebuild = true;
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore scanner check failure
                }
            }
        }

        if ($needsRebuild) {
            $this->rebuildAssets();
        }
    }

    /**
     * Rebuilds assets using AssetBuilder and invalidates runtime manifest caches.
     */
    public function rebuildAssets(): bool
    {
        try {
            $builder = $this->createAssetBuilder();
            $builder->build(false);
            $this->getManifestRegistry()->invalidateActiveManifest();
            return true;
        } catch (\Throwable $e) {
            Yii::warning('Asset Optimizer auto-rebuild failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Creates an AssetScanner configured for the current application.
     */
    public function createAssetScanner(): AssetScanner
    {
        $bundles = $this->scanBundles;
        if (empty($bundles)) {
            $bundles = AssetScanner::detectDefaultBundles();
        }

        if (!empty($this->bundles) && is_array($this->bundles)) {
            foreach ($this->bundles as $bClass => $bVal) {
                if ($bVal !== false && is_string($bClass) && !in_array($bClass, $bundles, true)) {
                    $bundles[] = $bClass;
                }
            }
        }

        return new AssetScanner(
            $bundles,
            $this->scanExtraFiles,
            $this->excludedBundles,
            $this->excludedFiles
        );
    }

    /**
     * Creates an AssetBuilder instance.
     */
    public function createAssetBuilder(): AssetBuilder
    {
        $scanner = $this->createAssetScanner();
        $runner = new EsbuildRunner($this->esbuildBin, $this->nodePath);

        $builder = new AssetBuilder(
            $runner,
            $scanner,
            $this->getManifestRegistry(),
            $this->optimizerRuntimePath,
            $this->optimizedBasePath,
            $this->optimizedBaseUrl
        );

        $builder->combineAssets = $this->combineAssets;
        $builder->combinedBundleName = $this->combinedBundleName;
        $builder->uncombinedBundles = $this->uncombinedBundles;

        return $builder;
    }
}
