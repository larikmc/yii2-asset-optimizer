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

    public function testInstantVersionSwitchBetweenRequests(): void
    {
        // Build 1
        $build1 = 'build-1';
        $manifest1 = new Manifest($build1);
        $asset1 = new AssetRecord();
        $asset1->key = 'bundle:app.js';
        $asset1->url = '/optimized/app.111111111111.min.js';
        $manifest1->addAsset($asset1);
        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$build1}.json", $manifest1->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $build1);

        $registry = new ManifestRegistry($this->runtimePath, false, 'test:', 0);
        $this->assertSame($build1, $registry->getActiveBuildId());
        $this->assertSame('/optimized/app.111111111111.min.js', $registry->resolveUrl('bundle:app.js'));

        // Switch to Build 2
        $build2 = 'build-2';
        $manifest2 = new Manifest($build2);
        $asset2 = new AssetRecord();
        $asset2->key = 'bundle:app.js';
        $asset2->url = '/optimized/app.222222222222.min.js';
        $manifest2->addAsset($asset2);
        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$build2}.json", $manifest2->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $build2);

        // New request (new registry instance or invalidated request)
        $newRequestRegistry = new ManifestRegistry($this->runtimePath, false, 'test:', 0);
        $this->assertSame($build2, $newRequestRegistry->getActiveBuildId());
        $this->assertSame('/optimized/app.222222222222.min.js', $newRequestRegistry->resolveUrl('bundle:app.js'));
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
