<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\components\OptimizingAssetManager;
use larikmc\assetoptimizer\models\Manifest;
use larikmc\assetoptimizer\services\AssetScanner;
use PHPUnit\Framework\TestCase;
use Yii;

class AutoRebuildTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'auto_rebuild_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/runtime/asset-optimizer', 0777, true);
        mkdir($this->tempDir . '/web/optimized', 0777, true);

        OptimizingAssetManager::resetRequestState();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        OptimizingAssetManager::resetRequestState();
        parent::tearDown();
    }

    public function testIsAutoRebuildActiveInDev(): void
    {
        $am = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->tempDir . '/runtime/asset-optimizer',
            'optimizedBasePath' => $this->tempDir . '/web/optimized',
            'autoRebuild' => true,
        ]);

        $this->assertTrue($am->isAutoRebuildActive());
    }

    public function testIsAutoRebuildExplicitlyDisabled(): void
    {
        $am = new OptimizingAssetManager([
            'optimizerRuntimePath' => $this->tempDir . '/runtime/asset-optimizer',
            'optimizedBasePath' => $this->tempDir . '/web/optimized',
            'autoRebuild' => false,
        ]);

        $this->assertFalse($am->isAutoRebuildActive());
    }

    public function testManifestSourceFilesChangeDetection(): void
    {
        $testFile = $this->tempDir . '/test.css';
        file_put_contents($testFile, 'body { color: blue; }');
        touch($testFile, time() - 100);

        $manifest = new Manifest('test-build');
        $manifest->sourceFiles = [
            $testFile => (int) filemtime($testFile),
        ];

        // Initially no changes
        $this->assertFalse($manifest->hasChangedSourceFiles());

        // Simulate file modification by developer
        touch($testFile, time() + 10);
        clearstatcache(true, $testFile);

        $this->assertTrue($manifest->hasChangedSourceFiles());

        // Simulate file deletion
        unlink($testFile);
        clearstatcache(true, $testFile);

        $this->assertTrue($manifest->hasChangedSourceFiles());
    }

    public function testManifestSourceFilesSerialization(): void
    {
        $manifest = new Manifest('test-serialize');
        $manifest->sourceFiles = [
            '/path/to/site.css' => 1730000000,
            '/path/to/site.js' => 1730000100,
        ];

        $json = $manifest->toJson();
        $restored = Manifest::fromJson($json);

        $this->assertCount(2, $restored->sourceFiles);
        $this->assertSame(1730000000, $restored->sourceFiles['/path/to/site.css']);
        $this->assertSame(1730000100, $restored->sourceFiles['/path/to/site.js']);
    }

    public function testAssetScannerDetectDefaultBundles(): void
    {
        $bundles = AssetScanner::detectDefaultBundles('frontend');
        $this->assertIsArray($bundles);
        $this->assertContains(\yii\web\YiiAsset::class, $bundles);
    }

    public function testCheckAutoRebuildThrottle(): void
    {
        $runtime = $this->tempDir . '/runtime/asset-optimizer';
        $am = new OptimizingAssetManager([
            'optimizerRuntimePath' => $runtime,
            'optimizedBasePath' => $this->tempDir . '/web/optimized',
            'autoRebuild' => true,
            'autoRebuildInterval' => 10,
        ]);

        // First check
        OptimizingAssetManager::resetRequestState();
        $am->checkAutoRebuild();

        $checkFile = $runtime . '/.last_check';
        $this->assertFileExists($checkFile);

        $mtime1 = filemtime($checkFile);

        // Immediate second check should be throttled
        OptimizingAssetManager::resetRequestState();
        $am->checkAutoRebuild();

        $mtime2 = filemtime($checkFile);
        $this->assertSame($mtime1, $mtime2);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
