<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\services\AtomicFileSwitch;
use PHPUnit\Framework\TestCase;

class AtomicFileSwitchTest extends TestCase
{
    private string $targetFile;
    private string $versionFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->targetFile = __DIR__ . '/../runtime/atomic_test.txt';
        $this->versionFile = __DIR__ . '/../runtime/current_version_test.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->targetFile);
        @unlink($this->versionFile);
        parent::tearDown();
    }

    public function testAtomicWriteNewFile(): void
    {
        AtomicFileSwitch::atomicWrite($this->targetFile, 'Hello World');
        $this->assertFileExists($this->targetFile);
        $this->assertSame('Hello World', file_get_contents($this->targetFile));
    }

    public function testAtomicWriteOverwriteExisting(): void
    {
        AtomicFileSwitch::atomicWrite($this->targetFile, 'Initial Version');
        $this->assertSame('Initial Version', file_get_contents($this->targetFile));

        AtomicFileSwitch::atomicWrite($this->targetFile, 'Updated Version');
        $this->assertSame('Updated Version', file_get_contents($this->targetFile));
    }

    public function testSwitchVersion(): void
    {
        AtomicFileSwitch::switchVersion($this->versionFile, '20261106-220000-abcd1234');
        $this->assertFileExists($this->versionFile);
        $this->assertSame('20261106-220000-abcd1234', file_get_contents($this->versionFile));
    }
}
