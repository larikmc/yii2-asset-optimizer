<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\services\AssetScanner;
use larikmc\assetoptimizer\tests\fixtures\QueryParamAssetBundle;
use larikmc\assetoptimizer\tests\fixtures\SampleAssetBundle;
use PHPUnit\Framework\TestCase;

class AssetScannerTest extends TestCase
{
    public function testScanBundleExtractsCssAndJs(): void
    {
        $scanner = new AssetScanner([
            SampleAssetBundle::class,
        ]);

        $items = $scanner->scan();
        $this->assertCount(2, $items);

        $keys = array_column($items, 'key');
        $this->assertContains(SampleAssetBundle::class . ':sample.css', $keys);
        $this->assertContains(SampleAssetBundle::class . ':sample.js', $keys);
    }

    public function testScanBundleStripsQueryParams(): void
    {
        $scanner = new AssetScanner([
            QueryParamAssetBundle::class,
        ]);

        $items = $scanner->scan();
        $this->assertCount(2, $items);

        $keys = array_column($items, 'key');
        $this->assertContains(QueryParamAssetBundle::class . ':sample.css', $keys);
        $this->assertContains(QueryParamAssetBundle::class . ':sample.js', $keys);

        // Verify sourceRelativePath has stripped query strings
        $this->assertSame('sample.css', $items[0]['sourceRelativePath']);
        $this->assertSame('sample.js', $items[1]['sourceRelativePath']);
    }

    public function testExcludedBundleIsSkipped(): void
    {
        $scanner = new AssetScanner(
            [SampleAssetBundle::class],
            [],
            [SampleAssetBundle::class] // excluded
        );

        $items = $scanner->scan();
        $this->assertEmpty($items);
    }

    public function testExcludedFilePatternIsSkipped(): void
    {
        $scanner = new AssetScanner(
            [SampleAssetBundle::class],
            [],
            [],
            ['*.js'] // exclude all JS files
        );

        $items = $scanner->scan();
        $this->assertCount(1, $items);
        $this->assertSame('css', $items[0]['type']);
    }
}
