<?php

declare(strict_types=1);

// Find vendor autoload
$vendorAutoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/autoload.php',
    'D:/OSPanel6.5.1/home/playlog.loc/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$loaded = false;
foreach ($vendorAutoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    throw new RuntimeException('Could not locate vendor/autoload.php. Run composer install first.');
}

// Locate Yii.php
$yiiCandidates = [
    __DIR__ . '/../vendor/yiisoft/yii2/Yii.php',
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    'D:/OSPanel6.5.1/home/playlog.loc/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php',
];

$yiiLoaded = false;
foreach ($yiiCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $yiiLoaded = true;
        break;
    }
}

if (!$yiiLoaded) {
    throw new RuntimeException('Could not locate yiisoft/yii2/Yii.php.');
}

// Register PSR-4 autoloader for package classes
spl_autoload_register(function (string $class) {
    $prefixes = [
        'larikmc\\assetoptimizer\\tests\\' => __DIR__ . '/',
        'larikmc\\assetoptimizer\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// Configure base Yii aliases
Yii::setAlias('@larikmc/assetoptimizer', dirname(__DIR__) . '/src');
Yii::setAlias('@tests', __DIR__);
Yii::setAlias('@runtime', __DIR__ . '/runtime');
Yii::setAlias('@webroot', __DIR__ . '/runtime/web');
Yii::setAlias('@web', '');

if (!is_dir(__DIR__ . '/runtime/web')) {
    mkdir(__DIR__ . '/runtime/web', 0777, true);
}

// Initialize minimal console application for test context
new \yii\console\Application([
    'id' => 'asset-optimizer-test',
    'basePath' => __DIR__,
    'runtimePath' => __DIR__ . '/runtime',
    'params' => [
        'assetOptimizer' => [
            'nodePath' => getenv('NODE_PATH') ?: (is_dir('D:/DevTools/nodejs/node-v24.19.0-win-x64') ? 'D:/DevTools/nodejs/node-v24.19.0-win-x64' : null),
        ],
    ],
]);
