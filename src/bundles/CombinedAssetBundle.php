<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\bundles;

use yii\web\AssetBundle;

/**
 * Synthetic AssetBundle that registers the combined/concatenated CSS and JS assets
 * produced when combineAssets is enabled.
 */
class CombinedAssetBundle extends AssetBundle
{
}
