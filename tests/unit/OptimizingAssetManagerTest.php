<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\components\OptimizingAssetManager;
use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use larikmc\assetoptimizer\services\AtomicFileSwitch;
use larikmc\assetoptimizer\services\ManifestRegistry;
use larikmc\assetoptimizer\tests\fixtures\SampleAssetBundle;
use PHPUnit\Framework\TestCase;
use Yii;

class OptimizingAssetManagerTest extends TestCase
{
    private string $runtimePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimePath = __DIR__ . '/../runtime/manager_runtime';
        if (!is_dir($this->runtimePath . '/manifests')) {
            mkdir($this->runtimePath . '/manifests', 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimePath);
        parent::tearDown();
    }

    public function testGetAssetUrlResolvesFromManifest(): void
    {
        $buildId = 'manager-build-1';
        $manifest = new Manifest($buildId);

        $asset = new AssetRecord();
        $asset->key = SampleAssetBundle::class . ':sample.css';
        $asset->type = 'css';
        $asset->outputRelativePath = 'sample.1234567890ab.min.css';
        $asset->url = '/optimized/sample.1234567890ab.min.css';
        $asset->integrity = 'sha384-csshash';
        $manifest->addAsset($asset);

        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$buildId}.json", $manifest->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $buildId);

        $bundle = Yii::createObject(SampleAssetBundle::class);
        $manager = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
        ]);

        $url = $manager->getAssetUrl($bundle, 'sample.css');
        $this->assertSame('/optimized/sample.1234567890ab.min.css', $url);
        $this->assertSame('sha384-csshash', $manager->getAssetIntegrity($bundle, 'sample.css'));

        // Unoptimized asset should fallback to standard URL
        $unoptimizedUrl = $manager->getAssetUrl($bundle, 'sample.js');
        $this->assertStringContainsString('sample.js', $unoptimizedUrl);
        $this->assertStringNotContainsString('/optimized/', $unoptimizedUrl);
    }

    public function testFallbackWhenDisabled(): void
    {
        $bundle = Yii::createObject(SampleAssetBundle::class);
        $manager = new OptimizingAssetManager([
            'enabled' => false,
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
        ]);

        $url = $manager->getAssetUrl($bundle, 'sample.css');
        $this->assertStringContainsString('sample.css', $url);
        $this->assertStringNotContainsString('/optimized/', $url);
    }

    public function testFallbackWhenBundleExcluded(): void
    {
        $bundle = Yii::createObject(SampleAssetBundle::class);
        $manager = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
            'excludedBundles' => [SampleAssetBundle::class],
        ]);

        $url = $manager->getAssetUrl($bundle, 'sample.css');
        $this->assertStringContainsString('sample.css', $url);
        $this->assertStringNotContainsString('/optimized/', $url);
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
