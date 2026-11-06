<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use larikmc\assetoptimizer\services\AtomicFileSwitch;
use larikmc\assetoptimizer\services\ManifestRegistry;
use larikmc\assetoptimizer\services\ReleaseManager;
use PHPUnit\Framework\TestCase;

class ReleaseManagerTest extends TestCase
{
    private string $runtimePath;
    private string $optimizedBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimePath = __DIR__ . '/../runtime/release_runtime';
        $this->optimizedBasePath = __DIR__ . '/../runtime/release_web/optimized';

        if (!is_dir($this->runtimePath . '/manifests')) {
            mkdir($this->runtimePath . '/manifests', 0777, true);
        }
        if (!is_dir($this->optimizedBasePath)) {
            mkdir($this->optimizedBasePath, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimePath);
        $this->removeDir(dirname($this->optimizedBasePath));
        parent::tearDown();
    }

    public function testVerifyDetectsValidAndInvalidReleases(): void
    {
        $buildId = 'verify-build-1';
        $manifest = new Manifest($buildId);

        $content = 'body { color: red; }';
        $contentHash = hash('sha256', $content);
        $fileName = 'style.1234.min.css';

        file_put_contents($this->optimizedBasePath . '/' . $fileName, $content);

        $asset = new AssetRecord();
        $asset->key = 'bundle:style.css';
        $asset->type = 'css';
        $asset->outputRelativePath = $fileName;
        $asset->url = '/optimized/' . $fileName;
        $asset->contentHash = $contentHash;
        $manifest->addAsset($asset);

        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$buildId}.json", $manifest->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $buildId);

        $registry = new ManifestRegistry($this->runtimePath, false);
        $manager = new ReleaseManager($registry, $this->runtimePath, $this->optimizedBasePath);

        // Should be valid
        $res = $manager->verify();
        $this->assertTrue($res['valid']);
        $this->assertEmpty($res['errors']);

        // Corrupt file content -> should fail verification
        file_put_contents($this->optimizedBasePath . '/' . $fileName, 'corrupted');
        $registry->invalidateCache();
        $failRes = $manager->verify();
        $this->assertFalse($failRes['valid']);
        $this->assertNotEmpty($failRes['errors']);
    }

    public function testRollbackSwitchesBuild(): void
    {
        $registry = new ManifestRegistry($this->runtimePath, false);
        $manager = new ReleaseManager($registry, $this->runtimePath, $this->optimizedBasePath);

        // Create Build 1
        $build1 = 'build-001';
        $m1 = new Manifest($build1);
        $file1 = 'b1.min.js';
        file_put_contents($this->optimizedBasePath . '/' . $file1, 'console.log(1);');
        $a1 = new AssetRecord();
        $a1->key = 'b:1';
        $a1->outputRelativePath = $file1;
        $a1->contentHash = hash('sha256', 'console.log(1);');
        $m1->addAsset($a1);
        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$build1}.json", $m1->toJson());

        // Create Build 2
        $build2 = 'build-002';
        $m2 = new Manifest($build2);
        $file2 = 'b2.min.js';
        file_put_contents($this->optimizedBasePath . '/' . $file2, 'console.log(2);');
        $a2 = new AssetRecord();
        $a2->key = 'b:2';
        $a2->outputRelativePath = $file2;
        $a2->contentHash = hash('sha256', 'console.log(2);');
        $m2->addAsset($a2);
        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$build2}.json", $m2->toJson());

        // Set Build 2 as current
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $build2);
        $this->assertSame($build2, $registry->getActiveBuildId());

        // Rollback to Build 1
        $rollbackRes = $manager->rollback($build1);
        $this->assertTrue($rollbackRes['success']);
        $this->assertSame($build1, $rollbackRes['activeBuildId']);
        $this->assertSame($build1, $registry->getActiveBuildId());
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
