<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\components;

use larikmc\assetoptimizer\services\ManifestRegistry;
use Yii;
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
     * @var bool Preserved setting for unoptimized assets fallback.
     */
    public bool $appendTimestampForUnoptimized = false;

    private ?ManifestRegistry $_manifestRegistry = null;

    public function init(): void
    {
        parent::init();

        if ($this->enabled) {
            // Preserve configured appendTimestamp for unoptimized fallback assets
            $this->appendTimestampForUnoptimized = (bool) $this->appendTimestamp;
            // Disable global appendTimestamp so yii\web\View does NOT issue filemtime() disk checks on HTTP requests
            $this->appendTimestamp = false;
        }
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

        $key = $this->buildAssetKey($bundle, $asset);
        $optimizedUrl = $this->getManifestRegistry()->resolveUrl($key);

        if ($optimizedUrl !== null) {
            // Optimized URL already contains content hash (e.g. /optimized/site.a1b2c3d4e5f6.min.css).
            // filemtime() query parameter is intentionally omitted for zero HTTP overhead and immutable caching.
            return $optimizedUrl;
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

        $key = $this->buildAssetKey($bundle, $asset);
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

        $key = $this->buildAssetKey($bundle, $asset);
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
                $this->enableApcu
            );
        }

        return $this->_manifestRegistry;
    }

    public function setManifestRegistry(ManifestRegistry $registry): void
    {
        $this->_manifestRegistry = $registry;
    }
}
