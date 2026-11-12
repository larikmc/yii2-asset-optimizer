<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\fixtures;

use yii\web\AssetBundle;

class SourcePathAssetBundle extends AssetBundle
{
    public $sourcePath = '@tests/fixtures';

    public $css = [
        'sample.css',
    ];

    public $js = [
        'sample.js',
    ];
}
