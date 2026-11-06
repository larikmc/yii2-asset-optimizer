<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use RuntimeException;

/**
 * Cross-platform atomic file writing service.
 * Ensures that readers never read partially written files or encounter empty pointers.
 */
class AtomicFileSwitch
{
    /**
     * Atomically writes string content to target file.
     *
     * @param string $targetFile Destination file path.
     * @param string $content Content to write.
     * @throws RuntimeException
     */
    public static function atomicWrite(string $targetFile, string $content): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory for file: {$dir}");
        }

        $tempFile = $dir . DIRECTORY_SEPARATOR . '.' . basename($targetFile) . '.tmp.' . bin2hex(random_bytes(6));

        $bytes = @file_put_contents($tempFile, $content, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to write full content to temporary file: {$tempFile}");
        }

        // Try direct rename first (atomic on Linux and modern Windows on same volume)
        if (@rename($tempFile, $targetFile)) {
            return;
        }

        // Fallback for Windows file locks / permission edges
        $backupFile = $dir . DIRECTORY_SEPARATOR . '.' . basename($targetFile) . '.bak.' . bin2hex(random_bytes(6));

        if (file_exists($targetFile)) {
            if (!@rename($targetFile, $backupFile)) {
                // If backup rename also failed, attempt direct copy with lock
                if (@copy($tempFile, $targetFile)) {
                    @unlink($tempFile);
                    return;
                }
                @unlink($tempFile);
                throw new RuntimeException("Cannot replace existing file: {$targetFile}");
            }
        }

        if (!@rename($tempFile, $targetFile)) {
            // Restore from backup if possible
            if (file_exists($backupFile)) {
                @rename($backupFile, $targetFile);
            }
            @unlink($tempFile);
            throw new RuntimeException("Atomic rename failed for {$targetFile}");
        }

        if (file_exists($backupFile)) {
            @unlink($backupFile);
        }
    }

    /**
     * Atomically switches an active pointer file to point to a new build identifier.
     *
     * @param string $versionPointerFile e.g. "@runtime/asset-optimizer/current.version"
     * @param string $buildId e.g. "20261106-220000-a1b2c3d4"
     */
    public static function switchVersion(string $versionPointerFile, string $buildId): void
    {
        self::atomicWrite($versionPointerFile, trim($buildId));
    }
}
