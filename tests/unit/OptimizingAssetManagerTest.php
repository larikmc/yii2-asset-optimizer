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

        // Asset requested with query string (?v=12345) should resolve to the clean optimized asset
        $urlWithQuery = $manager->getAssetUrl($bundle, 'sample.css?v=173000000');
        $this->assertSame('/optimized/sample.1234567890ab.min.css', $urlWithQuery);

        // Meaningful query parameters and fragments should be preserved
        $urlWithCustom = $manager->getAssetUrl($bundle, 'sample.css?custom=1#heading');
        $this->assertSame('/optimized/sample.1234567890ab.min.css?custom=1#heading', $urlWithCustom);

        // Technical cache-buster stripped, but custom parameter preserved
        $urlMixed = $manager->getAssetUrl($bundle, 'sample.css?v=173000000&theme=dark');
        $this->assertSame('/optimized/sample.1234567890ab.min.css?theme=dark', $urlMixed);

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

    public function testSplitAssetSpec(): void
    {
        $manager = new OptimizingAssetManager();

        $res1 = $manager->splitAssetSpec('css/site.css?v=123456');
        $this->assertSame('css/site.css', $res1['cleanPath']);
        $this->assertNull($res1['meaningfulQuery']);
        $this->assertNull($res1['fragment']);

        $res2 = $manager->splitAssetSpec('js/app.js?custom=1&lang=ru#hash');
        $this->assertSame('js/app.js', $res2['cleanPath']);
        $this->assertSame('custom=1&lang=ru', $res2['meaningfulQuery']);
        $this->assertSame('hash', $res2['fragment']);

        $res3 = $manager->splitAssetSpec('icons.svg#search');
        $this->assertSame('icons.svg', $res3['cleanPath']);
        $this->assertNull($res3['meaningfulQuery']);
        $this->assertSame('search', $res3['fragment']);
    }

    public function testBypassSourcePathPublishWhenFullyOptimized(): void
    {
        $buildId = 'bypass-build-1';
        $manifest = new Manifest($buildId);

        $asset = new AssetRecord();
        $asset->key = \larikmc\assetoptimizer\tests\fixtures\SourcePathAssetBundle::class . ':sample.css';
        $asset->type = 'css';
        $asset->outputRelativePath = 'sample.1234567890ab.min.css';
        $asset->url = '/optimized/sample.1234567890ab.min.css';
        $manifest->addAsset($asset);

        $assetJs = new AssetRecord();
        $assetJs->key = \larikmc\assetoptimizer\tests\fixtures\SourcePathAssetBundle::class . ':sample.js';
        $assetJs->type = 'js';
        $assetJs->outputRelativePath = 'sample.1234567890ab.min.js';
        $assetJs->url = '/optimized/sample.1234567890ab.min.js';
        $manifest->addAsset($assetJs);

        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$buildId}.json", $manifest->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $buildId);

        $manager = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
            'bypassSourcePathPublish' => true,
            'optimizedBasePath' => '@tests/runtime',
            'optimizedBaseUrl' => '/optimized',
        ]);

        $bundle = $manager->getBundle(\larikmc\assetoptimizer\tests\fixtures\SourcePathAssetBundle::class);
        $this->assertInstanceOf(\larikmc\assetoptimizer\tests\fixtures\SourcePathAssetBundle::class, $bundle);
        $this->assertSame(Yii::getAlias('@tests/runtime'), $bundle->basePath);
        $this->assertSame('/optimized', $bundle->baseUrl);
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
