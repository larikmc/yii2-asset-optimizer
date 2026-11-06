<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\fixtures;

use yii\web\AssetBundle;

class SampleAssetBundle extends AssetBundle
{
    public $basePath = '@tests/fixtures';
    public $baseUrl = '@web/tests/fixtures';

    public $css = [
        'sample.css',
    ];

    public $js = [
        'sample.js',
    ];
}
