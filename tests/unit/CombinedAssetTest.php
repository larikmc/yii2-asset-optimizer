<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\bundles\CombinedAssetBundle;
use larikmc\assetoptimizer\components\OptimizingAssetManager;
use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use larikmc\assetoptimizer\services\AssetBuilder;
use larikmc\assetoptimizer\services\AssetScanner;
use larikmc\assetoptimizer\services\AtomicFileSwitch;
use larikmc\assetoptimizer\services\EsbuildRunner;
use larikmc\assetoptimizer\services\ManifestRegistry;
use larikmc\assetoptimizer\tests\fixtures\SampleAssetBundle;
use PHPUnit\Framework\TestCase;
use Yii;

class CombinedAssetTest extends TestCase
{
    private string $runtimePath;
    private string $webroot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimePath = Yii::getAlias('@tests/runtime/combined_test_' . bin2hex(random_bytes(4)));
        $this->webroot = $this->runtimePath . '/web';
        mkdir($this->runtimePath, 0777, true);
        mkdir($this->webroot . '/optimized', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimePath);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function testManifestCombinedProperties(): void
    {
        $manifest = new Manifest('test-build-1');

        $this->assertFalse($manifest->hasCombinedAssets());
        $this->assertNull($manifest->getCombinedCss());
        $this->assertNull($manifest->getCombinedJs());

        $css = new AssetRecord();
        $css->key = 'combined:css';
        $css->type = 'css';
        $css->outputRelativePath = 'all.1234567890ab.min.css';
        $css->url = '/optimized/all.1234567890ab.min.css';

        $js = new AssetRecord();
        $js->key = 'combined:js';
        $js->type = 'js';
        $js->outputRelativePath = 'all.9876543210fe.min.js';
        $js->url = '/optimized/all.9876543210fe.min.js';

        $manifest->combined = [
            'enabled' => true,
            'css' => $css->toArray(),
            'js' => $js->toArray(),
            'bundles' => [SampleAssetBundle::class],
            'assets' => [SampleAssetBundle::class . ':sample.css', SampleAssetBundle::class . ':sample.js'],
        ];

        $this->assertTrue($manifest->hasCombinedAssets());
        $this->assertSame('all.1234567890ab.min.css', $manifest->getCombinedCss()?->outputRelativePath);
        $this->assertSame('all.9876543210fe.min.js', $manifest->getCombinedJs()?->outputRelativePath);
        $this->assertTrue($manifest->isBundleCombined(SampleAssetBundle::class));
        $this->assertTrue($manifest->isAssetCombined(SampleAssetBundle::class . ':sample.css'));
        $this->assertFalse($manifest->isAssetCombined('OtherBundle:file.css'));

        // Test serialization round-trip
        $json = $manifest->toJson();
        $restored = Manifest::fromJson($json);

        $this->assertTrue($restored->hasCombinedAssets());
        $this->assertSame($manifest->getCombinedCss()?->url, $restored->getCombinedCss()?->url);
        $this->assertSame($manifest->getCombinedJs()?->url, $restored->getCombinedJs()?->url);
        $this->assertTrue($restored->isBundleCombined(SampleAssetBundle::class));
    }

    public function testBuildCombinedAssets(): void
    {
        $nodePath = Yii::$app->params['assetOptimizer']['nodePath'] ?? null;
        $esbuild = new EsbuildRunner(null, $nodePath);
        if (!$esbuild->isAvailable()) {
            $this->markTestSkipped('esbuild is not available');
        }

        $scanner = new AssetScanner([SampleAssetBundle::class]);
        $registry = new ManifestRegistry($this->runtimePath);
        $builder = new AssetBuilder(
            $esbuild,
            $scanner,
            $registry,
            $this->runtimePath,
            $this->webroot . '/optimized',
            '/optimized'
        );

        $builder->combineAssets = true;
        $result = $builder->build(true);

        $this->assertNotNull($result['combined']);
        $this->assertTrue($result['combined']['enabled']);
        $this->assertNotEmpty($result['combined']['css']);
        $this->assertNotEmpty($result['combined']['js']);

        $manifest = $result['manifest'];
        $this->assertTrue($manifest->hasCombinedAssets());

        $combinedCss = $manifest->getCombinedCss();
        $this->assertNotNull($combinedCss);
        $cssFile = $this->webroot . '/optimized/' . $combinedCss->outputRelativePath;
        $this->assertFileExists($cssFile);
        $this->assertGreaterThan(0, filesize($cssFile));

        $combinedJs = $manifest->getCombinedJs();
        $this->assertNotNull($combinedJs);
        $jsFile = $this->webroot . '/optimized/' . $combinedJs->outputRelativePath;
        $this->assertFileExists($jsFile);
        $this->assertGreaterThan(0, filesize($jsFile));

        // Test incremental build cache reuse
        $res2 = $builder->build(false);
        $this->assertNotNull($res2['combined']);
        $this->assertSame($result['combined']['css'], $res2['combined']['css']);
        $this->assertSame($result['combined']['js'], $res2['combined']['js']);
    }

    public function testOptimizingAssetManagerWithCombinedAssets(): void
    {
        $buildId = 'test-combine-build';
        $manifest = new Manifest($buildId);

        $css = new AssetRecord();
        $css->key = 'combined:css';
        $css->type = 'css';
        $css->outputRelativePath = 'all.csshash1234.min.css';
        $css->url = '/optimized/all.csshash1234.min.css';

        $js = new AssetRecord();
        $js->key = 'combined:js';
        $js->type = 'js';
        $js->outputRelativePath = 'all.jshash5678.min.js';
        $js->url = '/optimized/all.jshash5678.min.js';

        $manifest->combined = [
            'enabled' => true,
            'css' => $css->toArray(),
            'js' => $js->toArray(),
            'bundles' => [SampleAssetBundle::class],
            'assets' => [SampleAssetBundle::class . ':sample.css', SampleAssetBundle::class . ':sample.js'],
        ];

        mkdir($this->runtimePath . '/manifests', 0777, true);
        AtomicFileSwitch::atomicWrite($this->runtimePath . "/manifests/manifest-{$buildId}.json", $manifest->toJson());
        AtomicFileSwitch::switchVersion($this->runtimePath . '/current.version', $buildId);

        $manager = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
            'combineAssets' => true,
            'optimizedBasePath' => $this->webroot . '/optimized',
            'optimizedBaseUrl' => '/optimized',
        ]);

        // 1. Loading SampleAssetBundle should have its files emptied and depend on CombinedAssetBundle
        $bundle = $manager->getBundle(SampleAssetBundle::class);
        $this->assertInstanceOf(SampleAssetBundle::class, $bundle);
        $this->assertEmpty($bundle->css, 'Combined CSS files should be filtered out from original bundle');
        $this->assertEmpty($bundle->js, 'Combined JS files should be filtered out from original bundle');
        $this->assertContains(CombinedAssetBundle::class, $bundle->depends);

        // 2. Loading CombinedAssetBundle directly should return the combined assets
        $combinedBundle = $manager->getBundle(CombinedAssetBundle::class);
        $this->assertInstanceOf(CombinedAssetBundle::class, $combinedBundle);
        $this->assertSame(['all.csshash1234.min.css'], $combinedBundle->css);
        $this->assertSame(['all.jshash5678.min.js'], $combinedBundle->js);
        $this->assertSame('/optimized', $combinedBundle->baseUrl);

        // 3. When combineAssets is false, SampleAssetBundle retains its files
        $managerDisabled = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->runtimePath,
            'enableApcu' => false,
            'combineAssets' => false,
            'optimizedBasePath' => $this->webroot . '/optimized',
            'optimizedBaseUrl' => '/optimized',
        ]);
        $origBundle = $managerDisabled->getBundle(SampleAssetBundle::class);
        $this->assertNotEmpty($origBundle->css);
        $this->assertNotEmpty($origBundle->js);
        $this->assertNotContains(CombinedAssetBundle::class, $origBundle->depends);
    }
}
