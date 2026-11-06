<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\services;

use RuntimeException;

/**
 * Cross-platform process locking using filesystem flock.
 * Prevents concurrent builds from corrupting staging and cache state.
 */
class FileLocker
{
    private string $lockFile;

    /**
     * @var resource|null
     */
    private $handle = null;

    private bool $isLocked = false;

    public function __construct(string $lockFile)
    {
        $this->lockFile = $lockFile;
    }

    /**
     * Attempts to acquire an exclusive non-blocking lock.
     *
     * @param bool $throwOnFailure If true, throws RuntimeException when lock is held by another process.
     * @return bool True if lock acquired, false otherwise.
     * @throws RuntimeException
     */
    public function acquire(bool $throwOnFailure = true): bool
    {
        if ($this->isLocked) {
            return true;
        }

        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory for lock file: {$dir}");
        }

        $this->handle = @fopen($this->lockFile, 'c+');
        if ($this->handle === false) {
            throw new RuntimeException("Cannot open or create lock file: {$this->lockFile}");
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;

            if ($throwOnFailure) {
                throw new RuntimeException("Another asset build process is currently running (lock held on {$this->lockFile}).");
            }
            return false;
        }

        // Record PID and timestamp into lock file for diagnostics
        ftruncate($this->handle, 0);
        fwrite($this->handle, (string) getmypid() . ' | ' . date('c'));
        fflush($this->handle);

        $this->isLocked = true;
        return true;
    }

    /**
     * Releases the acquired lock.
     */
    public function release(): void
    {
        if ($this->handle !== null) {
            if ($this->isLocked) {
                flock($this->handle, LOCK_UN);
                $this->isLocked = false;
            }
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function getLockFile(): string
    {
        return $this->lockFile;
    }

    public function __destruct()
    {
        $this->release();
    }
}
