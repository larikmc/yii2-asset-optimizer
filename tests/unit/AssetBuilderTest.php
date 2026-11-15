<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\services\AssetBuilder;
use larikmc\assetoptimizer\services\AssetScanner;
use larikmc\assetoptimizer\services\EsbuildRunner;
use larikmc\assetoptimizer\services\ManifestRegistry;
use larikmc\assetoptimizer\tests\fixtures\SampleAssetBundle;
use PHPUnit\Framework\TestCase;

class AssetBuilderTest extends TestCase
{
    private string $runtimePath;
    private string $optimizedBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimePath = __DIR__ . '/../runtime/builder_runtime';
        $this->optimizedBasePath = __DIR__ . '/../runtime/builder_web/optimized';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->runtimePath);
        $this->removeDir(dirname($this->optimizedBasePath));
        parent::tearDown();
    }

    public function testIncrementalBuildAndCacheReuse(): void
    {
        $scanner = new AssetScanner([SampleAssetBundle::class]);
        $esbuildRunner = new EsbuildRunner();
        $registry = new ManifestRegistry($this->runtimePath, false);

        $builder = new AssetBuilder(
            $esbuildRunner,
            $scanner,
            $registry,
            $this->runtimePath,
            $this->optimizedBasePath,
            '/optimized'
        );
        $builder->combineAssets = false;

        // First build: initial compilation
        $res1 = $builder->build(false);
        $this->assertSame(2, $res1['totalFiles']);
        $this->assertSame(2, $res1['processedFiles']);
        $this->assertSame(0, $res1['reusedFiles']);
        $this->assertNotEmpty($res1['buildId']);

        // Verify output files exist
        $manifest = $res1['manifest'];
        $this->assertCount(2, $manifest->assets);

        foreach ($manifest->assets as $asset) {
            $destFile = $this->optimizedBasePath . '/' . $asset->outputRelativePath;
            $this->assertFileExists($destFile);
            $this->assertGreaterThan(0, filesize($destFile));
            $this->assertStringContainsString($asset->shortHash, $asset->outputRelativePath);
        }

        // Verify version file was written
        $versionFile = $this->runtimePath . '/current.version';
        $this->assertFileExists($versionFile);
        $this->assertSame($res1['buildId'], trim(file_get_contents($versionFile)));

        // Second build: incremental reuse
        $res2 = $builder->build(false);
        $this->assertSame(2, $res2['totalFiles']);
        $this->assertSame(0, $res2['processedFiles'], 'Unchanged files should not be recompiled');
        $this->assertSame(2, $res2['reusedFiles'], 'Both files should be reused from cache');
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
