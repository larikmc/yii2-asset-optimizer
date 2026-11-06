<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use larikmc\assetoptimizer\models\Manifest;
use RuntimeException;
use Throwable;
use Yii;

/**
 * Manages manifest verification, rollback to previous builds, and safe cleanup (prune) of obsolete files.
 */
class ReleaseManager
{
    private ManifestRegistry $manifestRegistry;
    private string $runtimePath;
    private string $optimizedBasePath;

    /**
     * @param ManifestRegistry $manifestRegistry
     * @param string|null $runtimePath Defaults to "@runtime/asset-optimizer".
     * @param string|null $optimizedBasePath Defaults to "@webroot/optimized".
     */
    public function __construct(
        ManifestRegistry $manifestRegistry,
        ?string $runtimePath = null,
        ?string $optimizedBasePath = null
    ) {
        $this->manifestRegistry = $manifestRegistry;
        $this->runtimePath = Yii::getAlias($runtimePath ?? '@runtime/asset-optimizer');
        $this->optimizedBasePath = Yii::getAlias($optimizedBasePath ?? '@webroot/optimized');
    }

    /**
     * Verifies the integrity of the active release: checks that every asset file exists
     * and matches its declared SHA-256 content hash.
     *
     * @return array{
     *     valid: bool,
     *     buildId: ?string,
     *     filesChecked: int,
     *     errors: string[]
     * }
     */
    public function verify(): array
    {
        $manifest = $this->manifestRegistry->getActiveManifest();
        if ($manifest === null) {
            return [
                'valid' => false,
                'buildId' => null,
                'filesChecked' => 0,
                'errors' => ['No active asset manifest found.'],
            ];
        }

        $errors = [];
        $filesChecked = 0;

        foreach ($manifest->assets as $key => $asset) {
            $filesChecked++;
            $filePath = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset->outputRelativePath);

            if (!file_exists($filePath)) {
                $errors[] = "Missing file for [{$key}]: {$filePath}";
                continue;
            }

            if (filesize($filePath) === 0) {
                $errors[] = "Empty file for [{$key}]: {$filePath}";
                continue;
            }

            $content = (string) file_get_contents($filePath);
            $actualHash = hash('sha256', $content);

            if ($actualHash !== $asset->contentHash) {
                $errors[] = "Hash mismatch for [{$key}]: expected {$asset->contentHash}, got {$actualHash}";
            }
        }

        // Verify dependencies
        foreach ($manifest->dependencies as $dep) {
            $filesChecked++;
            $depPath = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dep);
            if (!file_exists($depPath)) {
                $errors[] = "Missing dependency file: {$depPath}";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'buildId' => $manifest->buildId,
            'filesChecked' => $filesChecked,
            'errors' => $errors,
        ];
    }

    /**
     * Rolls back to a specified build ID, or to the immediately preceding build if null.
     *
     * @param string|null $targetBuildId
     * @return array{
     *     success: bool,
     *     previousBuildId: ?string,
     *     activeBuildId: string,
     *     message: string
     * }
     * @throws RuntimeException
     */
    public function rollback(?string $targetBuildId = null): array
    {
        $currentBuildId = $this->manifestRegistry->getActiveBuildId();
        $manifests = $this->getAvailableManifests();

        if (empty($manifests)) {
            throw new RuntimeException("No release manifests found to rollback to.");
        }

        if ($targetBuildId === null || $targetBuildId === '') {
            // Find the previous manifest before current
            $targetBuildId = null;
            $passedCurrent = false;

            foreach ($manifests as $m) {
                if ($currentBuildId === null) {
                    $targetBuildId = $m['buildId'];
                    break;
                }
                if ($passedCurrent) {
                    $targetBuildId = $m['buildId'];
                    break;
                }
                if ($m['buildId'] === $currentBuildId) {
                    $passedCurrent = true;
                }
            }

            if ($targetBuildId === null) {
                throw new RuntimeException("No earlier release build found for rollback.");
            }
        }

        // Verify target manifest exists
        $targetManifest = $this->manifestRegistry->getManifest($targetBuildId);
        if ($targetManifest === null) {
            throw new RuntimeException("Target manifest for build {$targetBuildId} could not be loaded.");
        }

        // Verify that all assets for this target build physically exist on disk
        $missingFiles = [];
        foreach ($targetManifest->assets as $asset) {
            $filePath = $this->optimizedBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset->outputRelativePath);
            if (!file_exists($filePath)) {
                $missingFiles[] = $asset->outputRelativePath;
            }
        }

        if (!empty($missingFiles)) {
            throw new RuntimeException("Cannot rollback to build {$targetBuildId}: " . count($missingFiles) . " asset file(s) are missing from disk.");
        }

        // Atomically switch pointers
        $versionFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.version';
        $currentJsonFile = $this->runtimePath . DIRECTORY_SEPARATOR . 'current.json';

        AtomicFileSwitch::switchVersion($versionFile, $targetBuildId);
        AtomicFileSwitch::atomicWrite($currentJsonFile, $targetManifest->toJson(true));

        $this->manifestRegistry->invalidateCache();

        return [
            'success' => true,
            'previousBuildId' => $currentBuildId,
            'activeBuildId' => $targetBuildId,
            'message' => "Successfully rolled back to build {$targetBuildId}.",
        ];
    }

    /**
     * Safely prunes orphaned files from the optimized directory while protecting
     * active and recent release builds.
     *
     * @param int $keep Number of most recent builds to protect.
     * @param int $days Number of days to protect builds.
     * @param bool $dryRun If true, previews deletions without removing files.
     * @return array{
     *     dryRun: bool,
     *     keptBuilds: string[],
     *     prunedBuilds: string[],
     *     whitelistedFilesCount: int,
     *     deletedFilesCount: int,
     *     deletedFiles: string[],
     *     freedBytes: int
     * }
     */
    public function prune(int $keep = 5, int $days = 30, bool $dryRun = false): array
    {
        $manifests = $this->getAvailableManifests();
        $activeBuildId = $this->manifestRegistry->getActiveBuildId();
        $cutoffTime = time() - ($days * 86400);

        $keptBuilds = [];
        $prunedBuilds = [];
        $whitelistedFiles = [];

        // Determine which builds to keep
        foreach ($manifests as $index => $mInfo) {
            $buildId = $mInfo['buildId'];
            $mtime = $mInfo['mtime'];

            $keepThis = false;
            if ($buildId === $activeBuildId) {
                $keepThis = true;
            } elseif ($index < $keep) {
                $keepThis = true;
            } elseif ($mtime >= $cutoffTime) {
                $keepThis = true;
            }

            if ($keepThis) {
                $keptBuilds[] = $buildId;
                $manifest = $this->manifestRegistry->getManifest($buildId);
                if ($manifest !== null) {
                    foreach ($manifest->assets as $asset) {
                        $whitelistedFiles[$asset->outputRelativePath] = true;
                    }
                    foreach ($manifest->dependencies as $dep) {
                        $whitelistedFiles[$dep] = true;
                    }
                }
            } else {
                $prunedBuilds[] = $buildId;
            }
        }

        // Scan optimized base path for files not in whitelist
        $deletedFiles = [];
        $freedBytes = 0;

        $filesOnDisk = $this->scanDirectoryFiles($this->optimizedBasePath);
        foreach ($filesOnDisk as $relFile => $fullPath) {
            $normRel = str_replace('\\', '/', $relFile);
            if (!isset($whitelistedFiles[$normRel])) {
                $fileSize = (int) @filesize($fullPath);
                $fileMtime = (int) @filemtime($fullPath);

                // Only prune if older than safety window (e.g. 1 hour)
                if ($fileMtime < (time() - 3600)) {
                    $freedBytes += $fileSize;
                    $deletedFiles[] = $normRel;

                    if (!$dryRun) {
                        @unlink($fullPath);
                    }
                }
            }
        }

        // Remove pruned manifest files
        if (!$dryRun) {
            $manifestsDir = $this->runtimePath . DIRECTORY_SEPARATOR . 'manifests';
            foreach ($prunedBuilds as $prunedId) {
                $mFile = $manifestsDir . DIRECTORY_SEPARATOR . "manifest-{$prunedId}.json";
                if (file_exists($mFile)) {
                    @unlink($mFile);
                }
            }
        }

        return [
            'dryRun' => $dryRun,
            'keptBuilds' => $keptBuilds,
            'prunedBuilds' => $prunedBuilds,
            'whitelistedFilesCount' => count($whitelistedFiles),
            'deletedFilesCount' => count($deletedFiles),
            'deletedFiles' => $deletedFiles,
            'freedBytes' => $freedBytes,
        ];
    }

    /**
     * Returns a list of available manifests sorted descending by modification time.
     *
     * @return array<int, array{buildId: string, file: string, mtime: int}>
     */
    public function getAvailableManifests(): array
    {
        $manifestsDir = $this->runtimePath . DIRECTORY_SEPARATOR . 'manifests';
        if (!is_dir($manifestsDir)) {
            return [];
        }

        $list = [];
        $files = (array) scandir($manifestsDir);
        foreach ($files as $file) {
            if (preg_match('/^manifest-(.+)\.json$/', $file, $m)) {
                $fullPath = $manifestsDir . DIRECTORY_SEPARATOR . $file;
                $list[] = [
                    'buildId' => $m[1],
                    'file' => $fullPath,
                    'mtime' => (int) @filemtime($fullPath),
                ];
            }
        }

        usort($list, function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        return $list;
    }

    /**
     * @return array<string, string> relativePath => absolutePath
     */
    private function scanDirectoryFiles(string $dir, string $prefix = ''): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $results = [];
        $items = (array) scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
            $relPath = $prefix !== '' ? $prefix . '/' . $item : $item;

            if (is_dir($fullPath)) {
                $sub = $this->scanDirectoryFiles($fullPath, $relPath);
                foreach ($sub as $k => $v) {
                    $results[$k] = $v;
                }
            } elseif (is_file($fullPath)) {
                $results[$relPath] = $fullPath;
            }
        }

        return $results;
    }
}
