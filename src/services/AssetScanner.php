<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use InvalidArgumentException;
use Yii;
use yii\web\AssetBundle;
use yii\helpers\Url;

/**
 * Discovers and resolves AssetBundles and individual CSS/JS asset files across Yii2 applications.
 */
class AssetScanner
{
    /**
     * @var string[] List of AssetBundle class names to scan.
     */
    private array $bundles;

    /**
     * @var string[] List of individual extra files (e.g. ["@webroot/js/standalone.js"]).
     */
    private array $extraFiles;

    /**
     * @var string[] List of AssetBundle classes to exclude.
     */
    private array $excludedBundles;

    /**
     * @var string[] List of file patterns to exclude.
     */
    private array $excludedFiles;

    /**
     * @param string[] $bundles AssetBundle class names.
     * @param string[] $extraFiles Additional files outside of bundles.
     * @param string[] $excludedBundles Bundles to skip.
     * @param string[] $excludedFiles File patterns to skip.
     */
    public function __construct(
        array $bundles = [],
        array $extraFiles = [],
        array $excludedBundles = [],
        array $excludedFiles = []
    ) {
        $this->bundles = $bundles;
        $this->extraFiles = $extraFiles;
        $this->excludedBundles = $excludedBundles;
        $this->excludedFiles = $excludedFiles;
    }

    /**
     * Scans and returns all discoverable asset definitions.
     *
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     bundleClass: string,
     *     sourceRelativePath: string,
     *     sourceAbsolutePath: string
     * }>
     */
    public function scan(): array
    {
        $discovered = [];

        // 1. Scan configured AssetBundles
        foreach ($this->bundles as $bundleClass) {
            if ($this->isBundleExcluded($bundleClass)) {
                continue;
            }

            $items = $this->scanBundle($bundleClass);
            foreach ($items as $item) {
                $discovered[] = $item;
            }
        }

        // 2. Scan extra standalone files
        foreach ($this->extraFiles as $file) {
            $cleanFile = strtok($file, '?#');
            $cleanFile = ($cleanFile !== false && $cleanFile !== '') ? $cleanFile : $file;

            $resolved = Yii::getAlias($cleanFile);
            if (!file_exists($resolved)) {
                continue;
            }

            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            if ($ext !== 'css' && $ext !== 'js') {
                continue;
            }

            if ($this->isFileExcluded($file) || $this->isFileExcluded($cleanFile) || $this->isFileExcluded($resolved)) {
                continue;
            }

            $webroot = Yii::getAlias('@webroot');
            $relPath = str_starts_with($resolved, $webroot)
                ? ltrim(str_replace(['\\', '/'], '/', substr($resolved, strlen($webroot))), '/')
                : basename($resolved);

            $discovered[] = [
                'key' => 'extra:' . $relPath,
                'type' => $ext,
                'bundleClass' => '',
                'sourceRelativePath' => $relPath,
                'sourceAbsolutePath' => $resolved,
            ];
        }

        return $discovered;
    }

    /**
     * Scans a single AssetBundle class and extracts its CSS and JS entries.
     *
     * @param string $bundleClass
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     bundleClass: string,
     *     sourceRelativePath: string,
     *     sourceAbsolutePath: string
     * }>
     */
    public function scanBundle(string $bundleClass): array
    {
        if (!class_exists($bundleClass)) {
            throw new InvalidArgumentException("AssetBundle class {$bundleClass} does not exist.");
        }

        /** @var AssetBundle $bundle */
        $bundle = Yii::createObject($bundleClass);

        $results = [];
        $baseDir = null;

        if ($bundle->sourcePath !== null) {
            $baseDir = rtrim(Yii::getAlias($bundle->sourcePath), '/\\');
        } elseif ($bundle->basePath !== null) {
            $baseDir = rtrim(Yii::getAlias($bundle->basePath), '/\\');
        } else {
            $baseDir = rtrim(Yii::getAlias('@webroot'), '/\\');
        }

        // Process CSS
        foreach ($bundle->css as $cssItem) {
            $file = is_array($cssItem) ? array_shift($cssItem) : $cssItem;
            if ($file === null || !Url::isRelative($file) || str_starts_with($file, '//')) {
                continue;
            }

            $cleanPath = strtok($file, '?#');
            $cleanFile = ($cleanPath !== false && $cleanPath !== '') ? $cleanPath : $file;

            if ($this->isFileExcluded($cleanFile) || $this->isFileExcluded($file)) {
                continue;
            }

            $absPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanFile);
            $normalizedKey = $bundleClass . ':' . ltrim(str_replace('\\', '/', $cleanFile), '/');

            $results[] = [
                'key' => $normalizedKey,
                'type' => 'css',
                'bundleClass' => $bundleClass,
                'sourceRelativePath' => $cleanFile,
                'sourceAbsolutePath' => $absPath,
            ];
        }

        // Process JS
        foreach ($bundle->js as $jsItem) {
            $file = is_array($jsItem) ? array_shift($jsItem) : $jsItem;
            if ($file === null || !Url::isRelative($file) || str_starts_with($file, '//')) {
                continue;
            }

            $cleanPath = strtok($file, '?#');
            $cleanFile = ($cleanPath !== false && $cleanPath !== '') ? $cleanPath : $file;

            if ($this->isFileExcluded($cleanFile) || $this->isFileExcluded($file)) {
                continue;
            }

            $absPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanFile);
            $normalizedKey = $bundleClass . ':' . ltrim(str_replace('\\', '/', $cleanFile), '/');

            $results[] = [
                'key' => $normalizedKey,
                'type' => 'js',
                'bundleClass' => $bundleClass,
                'sourceRelativePath' => $cleanFile,
                'sourceAbsolutePath' => $absPath,
            ];
        }

        return $results;
    }

    public function isBundleExcluded(string $bundleClass): bool
    {
        return in_array($bundleClass, $this->excludedBundles, true);
    }

    public function isFileExcluded(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        foreach ($this->excludedFiles as $pattern) {
            $normPattern = str_replace('\\', '/', $pattern);
            if (fnmatch($normPattern, $normalized) || str_contains($normalized, $normPattern)) {
                return true;
            }
        }
        return false;
    }
}
