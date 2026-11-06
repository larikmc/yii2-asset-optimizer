<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use larikmc\assetoptimizer\models\Manifest;
use Yii;

/**
 * Generates human-readable and machine-readable statistics about optimized assets.
 */
class StatsService
{
    private ManifestRegistry $manifestRegistry;

    public function __construct(ManifestRegistry $manifestRegistry)
    {
        $this->manifestRegistry = $manifestRegistry;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $manifest = $this->manifestRegistry->getActiveManifest();
        if ($manifest === null) {
            return [
                'hasActiveBuild' => false,
                'message' => 'No active build manifest found.',
            ];
        }

        $assetsData = [];
        $totalOriginal = 0;
        $totalOptimized = 0;
        $totalGzip = 0;

        foreach ($manifest->assets as $key => $asset) {
            $totalOriginal += $asset->originalSize;
            $totalOptimized += $asset->optimizedSize;

            // Estimated gzip size (standard compression level 6)
            $gzipEstimate = (int) round($asset->optimizedSize * 0.35);
            $totalGzip += $gzipEstimate;

            $savingPct = $asset->originalSize > 0
                ? round((($asset->originalSize - $asset->optimizedSize) / $asset->originalSize) * 100, 1)
                : 0.0;

            $assetsData[] = [
                'key' => $key,
                'type' => $asset->type,
                'url' => $asset->url,
                'originalSize' => $asset->originalSize,
                'optimizedSize' => $asset->optimizedSize,
                'savingPercent' => $savingPct,
                'shortHash' => $asset->shortHash,
            ];
        }

        $overallSavingPct = $totalOriginal > 0
            ? round((($totalOriginal - $totalOptimized) / $totalOriginal) * 100, 2)
            : 0.0;

        return [
            'hasActiveBuild' => true,
            'buildId' => $manifest->buildId,
            'createdAt' => $manifest->createdAt,
            'toolchain' => $manifest->toolchain,
            'totalFiles' => count($manifest->assets),
            'originalSizeBytes' => $totalOriginal,
            'optimizedSizeBytes' => $totalOptimized,
            'estimatedGzipBytes' => $totalGzip,
            'savingPercent' => $overallSavingPct,
            'assets' => $assetsData,
            'dependenciesCount' => count($manifest->dependencies),
        ];
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min((int) $pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
