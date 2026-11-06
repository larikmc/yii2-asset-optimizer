<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\services\FileLocker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileLockerTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockFile = __DIR__ . '/../runtime/test.lock';
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
        parent::tearDown();
    }

    public function testAcquireAndRelease(): void
    {
        $locker = new FileLocker($this->lockFile);
        $this->assertTrue($locker->acquire());
        $this->assertTrue($locker->isLocked());
        $this->assertFileExists($this->lockFile);

        $locker->release();
        $this->assertFalse($locker->isLocked());
    }

    public function testCollisionFails(): void
    {
        $locker1 = new FileLocker($this->lockFile);
        $this->assertTrue($locker1->acquire());

        $locker2 = new FileLocker($this->lockFile);
        $this->assertFalse($locker2->acquire(false));

        $this->expectException(RuntimeException::class);
        $locker2->acquire(true);
    }
}
