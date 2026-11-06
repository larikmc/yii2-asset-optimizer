<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer;

use larikmc\assetoptimizer\commands\AssetOptimizerController;
use yii\base\Application;
use yii\base\BootstrapInterface;

/**
 * Extension bootstrap class for larikmc/yii2-asset-optimizer.
 * Automatically registers the 'asset-optimizer' console command when running in console mode.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @param Application $app
     */
    public function bootstrap($app): void
    {
        if ($app instanceof \yii\console\Application) {
            if (!isset($app->controllerMap['asset-optimizer'])) {
                $app->controllerMap['asset-optimizer'] = AssetOptimizerController::class;
            }
        }
    }
}
