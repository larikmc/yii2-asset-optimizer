<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use larikmc\assetoptimizer\services\AtomicFileSwitch;
use larikmc\assetoptimizer\services\ManifestRegistry;
use PHPUnit\Framework\TestCase;

class ManifestRegistryTest extends TestCase
{
    private string $runtimePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimePath = __DIR__ . '/../runtime/registry_test';
        if (!is_dir($this->runtimePath . '/manifests')) {
            mkdir($this->runtimePath . '/manifests', 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimePath);
        parent::tearDown();
    }

    public function testResolveUrlAndIntegrity(): void
    {
        $buildId = 'build-123';
        $manifest = new Manifest($buildId);

        $asset = new AssetRecord();
        $asset->key = 'testBundle:style.css';
        $asset->type = 'css';
        $asset->outputRelativePath = 'style.abcdef123456.min.css';
        $asset->url = '/optimized/style.abcdef123456.min.css';
        $asset->integrity = 'sha384-mockintegrity';
        $manifest->addAsset($asset);

        // Write manifest and version pointer
        $manifestFile = $this->runtimePath . "/manifests/manifest-{$buildId}.json";
        AtomicFileSwitch::atomicWrite($manifestFile, $manifest->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $buildId);

        $registry = new ManifestRegistry($this->runtimePath, false);

        $this->assertSame($buildId, $registry->getActiveBuildId());
        $this->assertSame('/optimized/style.abcdef123456.min.css', $registry->resolveUrl('testBundle:style.css'));
        $this->assertSame('sha384-mockintegrity', $registry->resolveIntegrity('testBundle:style.css'));
        $this->assertNull($registry->resolveUrl('nonexistent:key'));
    }

    public function testMissingManifestReturnsNull(): void
    {
        $registry = new ManifestRegistry($this->runtimePath . '/nonexistent', false);
        $this->assertNull($registry->getActiveBuildId());
        $this->assertNull($registry->resolveUrl('any:key'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = (array) scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
