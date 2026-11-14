<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use larikmc\assetoptimizer\models\Manifest;
use Yii;

/**
 * High-performance runtime registry for active release manifests.
 * Implements tiered caching: in-memory static -> APCu shared memory -> filesystem JSON.
 */
class ManifestRegistry
{
    private string $runtimePath;
    private bool $enableApcu;
    private string $apcuPrefix;
    private int $versionCheckInterval;

    /**
     * In-memory cache for current request lifecycle.
     *
     * @var array<string, Manifest>
     */
    private array $manifestMemoryCache = [];

    private ?string $activeBuildIdMemory = null;

    /**
     * @param string|null $runtimePath Base directory for asset-optimizer runtime files. Defaults to "@runtime/asset-optimizer".
     * @param bool|null $enableApcu Whether to use APCu caching. Defaults to true if APCu is available.
     * @param string $apcuPrefix Prefix for APCu cache keys.
     * @param int $versionCheckInterval Interval in seconds to cache active build ID in APCu (0 = check on every request for instant sync).
     */
    public function __construct(
        ?string $runtimePath = null,
        ?bool $enableApcu = null,
        string $apcuPrefix = 'asset_optimizer:',
        int $versionCheckInterval = 0
    ) {
        $this->runtimePath = Yii::getAlias($runtimePath ?? '@runtime/asset-optimizer');
        $this->enableApcu = $enableApcu ?? (extension_loaded('apcu') && (bool) ini_get('apc.enabled'));
        $this->apcuPrefix = $apcuPrefix;
        $this->versionCheckInterval = max(0, $versionCheckInterval);
    }

    /**
     * Resolves an asset's public URL by its unique key.
     *
     * @param string $key e.g. "frontend\assets\AppAsset:css/site.css"
     * @return string|null Optimized URL (e.g. "/optimized/site.7a8b9c0d1e2f.min.css") or null if not optimized.
     */
    public function resolveUrl(string $key): ?string
    {
        $manifest = $this->getActiveManifest();
        if ($manifest === null) {
            return null;
        }

        $asset = $manifest->getAsset($key);
        return $asset !== null ? $asset->url : null;
    }

    /**
     * Resolves the Subresource Integrity (SRI) string for an asset by its key.
     */
    public function resolveIntegrity(string $key): ?string
    {
        $manifest = $this->getActiveManifest();
        if ($manifest === null) {
            return null;
        }

        $asset = $manifest->getAsset($key);
        return $asset !== null ? $asset->integrity : null;
    }

    /**
     * Returns the active release Manifest instance.
     */
    public function getActiveManifest(): ?Manifest
    {
        $buildId = $this->getActiveBuildId();
        if ($buildId === null || $buildId === '') {
            return null;
        }

        return $this->getManifest($buildId);
    }

    /**
     * Returns the active build identifier.
     */
    public function getActiveBuildId(): ?string
    {
        if ($this->activeBuildIdMemory !== null) {
            return $this->activeBuildIdMemory;
        }

        $apcuKey = $this->apcuPrefix . 'active_build';

        // Check APCu only if an explicit interval > 0 was configured
        if ($this->enableApcu && $this->versionCheckInterval > 0) {
            $success = false;
            $cachedId = apcu_fetch($apcuKey, $success);
            if ($success && is_string($cachedId) && $cachedId !== '') {
                return $this->activeBuildIdMemory = $cachedId;
            }
        }

        // Read pointer file (lightweight 25-byte read, cached in $activeBuildIdMemory for the request)
        $versionFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.version';
        if (!file_exists($versionFile)) {
            // Check fallback current.json
            $currentJsonFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.json';
            if (file_exists($currentJsonFile)) {
                $content = @file_get_contents($currentJsonFile);
                if ($content !== false && $content !== '') {
                    try {
                        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                        if (isset($data['buildId']) && is_string($data['buildId'])) {
                            $this->activeBuildIdMemory = $data['buildId'];
                            if ($this->enableApcu && $this->versionCheckInterval > 0) {
                                apcu_store($apcuKey, $this->activeBuildIdMemory, $this->versionCheckInterval);
                            }
                            return $this->activeBuildIdMemory;
                        }
                    } catch (\Throwable $e) {
                        // ignore and return null
                    }
                }
            }
            return null;
        }

        $buildId = trim((string) @file_get_contents($versionFile));
        if ($buildId === '') {
            return null;
        }

        $this->activeBuildIdMemory = $buildId;
        if ($this->enableApcu && $this->versionCheckInterval > 0) {
            apcu_store($apcuKey, $buildId, $this->versionCheckInterval);
        }

        return $this->activeBuildIdMemory;
    }

    /**
     * Invalidates in-memory and APCu cache of the active build.
     * Called after auto-rebuild or version switch.
     */
    public function invalidateActiveManifest(): void
    {
        $this->activeBuildIdMemory = null;
        if ($this->enableApcu) {
            apcu_delete($this->apcuPrefix . 'active_build');
        }
    }

    /**
     * Retrieves a Manifest by its build ID.
     */
    public function getManifest(string $buildId): ?Manifest
    {
        if (isset($this->manifestMemoryCache[$buildId])) {
            return $this->manifestMemoryCache[$buildId];
        }

        $apcuKey = $this->apcuPrefix . 'manifest:' . $buildId;

        // Try APCu serialized array
        if ($this->enableApcu) {
            $success = false;
            $cachedArray = apcu_fetch($apcuKey, $success);
            if ($success && is_array($cachedArray)) {
                $manifest = Manifest::fromArray($cachedArray);
                return $this->manifestMemoryCache[$buildId] = $manifest;
            }
        }

        // Read immutable JSON file from disk
        $manifestFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . "manifest-{$buildId}.json";
        if (!file_exists($manifestFile)) {
            return null;
        }

        $json = @file_get_contents($manifestFile);
        if ($json === false || $json === '') {
            return null;
        }

        try {
            $manifest = Manifest::fromJson($json);
            $this->manifestMemoryCache[$buildId] = $manifest;

            if ($this->enableApcu) {
                // Store raw array in APCu to avoid serialization overhead
                apcu_store($apcuKey, $manifest->toArray(), 86400);
            }

            return $manifest;
        } catch (\Throwable $e) {
            Yii::error("Failed to parse asset manifest {$manifestFile}: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Invalidate caches (e.g. after activating a new release).
     */
    public function invalidateCache(): void
    {
        $this->activeBuildIdMemory = null;
        $this->manifestMemoryCache = [];

        if ($this->enableApcu) {
            apcu_delete($this->apcuPrefix . 'active_build');
        }
    }

    public function getRuntimePath(): string
    {
        return $this->runtimePath;
    }
}
