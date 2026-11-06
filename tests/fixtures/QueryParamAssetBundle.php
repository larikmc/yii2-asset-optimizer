<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\fixtures;

use yii\web\AssetBundle;

class QueryParamAssetBundle extends AssetBundle
{
    public $basePath = '@tests/fixtures';
    public $baseUrl = '@web/tests/fixtures';

    public $css = [
        'sample.css?v=12345678',
    ];

    public $js = [
        'sample.js?v=98765432',
    ];
}
