<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\models\AssetRecord;
use larikmc\assetoptimizer\models\Manifest;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    public function testManifestLifecycleAndSerialization(): void
    {
        $manifest = new Manifest('test-build-1');
        $this->assertSame('test-build-1', $manifest->buildId);
        $this->assertSame(Manifest::SCHEMA_VERSION, $manifest->schemaVersion);

        $asset = new AssetRecord();
        $asset->key = 'app\assets\AppAsset:css/site.css';
        $asset->type = 'css';
        $asset->bundleClass = 'app\assets\AppAsset';
        $asset->sourceRelativePath = 'css/site.css';
        $asset->outputRelativePath = 'site.1234567890ab.min.css';
        $asset->url = '/optimized/site.1234567890ab.min.css';
        $asset->contentHash = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
        $asset->shortHash = '1234567890ab';
        $asset->originalSize = 1000;
        $asset->optimizedSize = 400;
        $asset->integrity = 'sha384-xyz';

        $manifest->addAsset($asset);

        $this->assertSame(1, $manifest->summary['totalFiles']);
        $this->assertSame(1000, $manifest->summary['originalSizeBytes']);
        $this->assertSame(400, $manifest->summary['optimizedSizeBytes']);
        $this->assertSame(60.0, $manifest->summary['savingPercent']);

        $json = $manifest->toJson();
        $this->assertJson($json);

        $restored = Manifest::fromJson($json);
        $this->assertSame($manifest->buildId, $restored->buildId);
        $this->assertCount(1, $restored->assets);

        $restoredAsset = $restored->getAsset('app\assets\AppAsset:css/site.css');
        $this->assertNotNull($restoredAsset);
        $this->assertSame('/optimized/site.1234567890ab.min.css', $restoredAsset->url);
        $this->assertSame('sha384-xyz', $restoredAsset->integrity);
    }
}
