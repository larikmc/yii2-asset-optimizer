<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\commands;

use larikmc\assetoptimizer\components\OptimizingAssetManager;
use larikmc\assetoptimizer\services\AssetBuilder;
use larikmc\assetoptimizer\services\AssetScanner;
use larikmc\assetoptimizer\services\DoctorService;
use larikmc\assetoptimizer\services\EsbuildRunner;
use larikmc\assetoptimizer\services\ManifestRegistry;
use larikmc\assetoptimizer\services\ReleaseManager;
use larikmc\assetoptimizer\services\StatsService;
use Throwable;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * CLI controller for larikmc/yii2-asset-optimizer.
 *
 * Provides commands for building, watching, verifying, rolling back, and pruning assets.
 */
class AssetOptimizerController extends Controller
{
    /**
     * @var string|null Custom configuration file or app identifier (e.g. "frontend" or "backend").
     */
    public $app = null;

    /**
     * @var int Force full clean rebuild (1 or 0).
     */
    public $full = 0;

    /**
     * @var int|null Combine all CSS and JS into single common bundles (1 or 0). Defaults to web configuration.
     */
    public $combine = null;

    /**
     * @var int Output statistics in JSON format (1 or 0).
     */
    public $json = 0;

    /**
     * @var int Number of recent builds to protect during prune.
     */
    public $keep = 5;

    /**
     * @var int Number of days to protect builds during prune.
     */
    public $days = 30;

    /**
     * @var int Dry run mode without deleting files (1 or 0).
     */
    public $dryRun = 0;

    /**
     * @var string[] Custom list of bundles to process.
     */
    public array $bundles = [];

    /**
     * @var string[] Custom list of standalone extra files to process.
     */
    public array $extraFiles = [];

    /**
     * @var string[] List of bundle classes to exclude.
     */
    public array $excludedBundles = [];

    /**
     * @var string[] List of file patterns to exclude.
     */
    public array $excludedFiles = [];

    /**
     * @var string|null Custom path to esbuild executable.
     */
    public ?string $esbuildBin = null;

    /**
     * @var string Base runtime path.
     */
    public string $runtimePath = '@runtime/asset-optimizer';

    /**
     * @var string Destination web directory for optimized assets.
     */
    public string $optimizedBasePath = '@webroot/optimized';

    /**
     * @var string Base public URL for optimized assets.
     */
    public string $optimizedBaseUrl = '@web/optimized';

    public function init(): void
    {
        parent::init();

        if (Yii::getAlias('@webroot', false) === false) {
            if (Yii::getAlias('@frontend/web', false) !== false) {
                Yii::setAlias('@webroot', Yii::getAlias('@frontend/web'));
            } elseif (Yii::getAlias('@app/web', false) !== false) {
                Yii::setAlias('@webroot', Yii::getAlias('@app/web'));
            }
        }
        if (Yii::getAlias('@web', false) === false) {
            Yii::setAlias('@web', '');
        }
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($this->app === null) {
            if (Yii::getAlias('@frontend', false) !== false) {
                $this->app = 'frontend';
            } elseif (Yii::getAlias('@app', false) !== false) {
                $this->app = 'app';
            }
        }

        if ($this->app === 'backend') {
            if (Yii::getAlias('@backend', false) !== false) {
                $this->runtimePath = '@backend/runtime/asset-optimizer';
                $this->optimizedBasePath = Yii::getAlias('@frontend/web', false) !== false
                    ? '@frontend/web/optimized'
                    : '@backend/web/optimized';
                $this->optimizedBaseUrl = '/optimized';
            }
        } else {
            // default / frontend in Yii2 Advanced
            if (Yii::getAlias('@frontend', false) !== false) {
                $this->runtimePath = '@frontend/runtime/asset-optimizer';
                $this->optimizedBasePath = '@frontend/web/optimized';
                $this->optimizedBaseUrl = '/optimized';
            }
        }

        return true;
    }

    public function options($actionID): array
    {
        $common = ['app'];
        return match ($actionID) {
            'build' => array_merge($common, ['full', 'combine']),
            'stats' => array_merge($common, ['json']),
            'prune' => array_merge($common, ['keep', 'days', 'dryRun']),
            default => $common,
        };
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'full',
            'c' => 'combine',
            'j' => 'json',
            'k' => 'keep',
            'd' => 'days',
        ];
    }

    /**
     * Performs an incremental or full build of configured CSS and JavaScript assets.
     */
    public function actionBuild(): int
    {
        $this->stdout("--- [Asset Optimizer] Starting Build ---\n", Console::FG_CYAN, Console::BOLD);

        try {
            $builder = $this->createAssetBuilder();
            $result = $builder->build((bool) $this->full);

            $this->stdout("✓ Build completed successfully!\n", Console::FG_GREEN, Console::BOLD);
            $this->stdout("  Build ID:       {$result['buildId']}\n");
            if (!empty($result['combined']['enabled'])) {
                $this->stdout("  Combined Mode:  ACTIVE (all CSS & JS merged)\n", Console::FG_CYAN, Console::BOLD);
                if (!empty($result['combined']['css'])) {
                    $this->stdout("  Combined CSS:   {$result['combined']['css']}\n");
                }
                if (!empty($result['combined']['js'])) {
                    $this->stdout("  Combined JS:    {$result['combined']['js']}\n");
                }
                if (!empty($result['combined']['jsHead'])) {
                    $this->stdout("  Combined Head:  {$result['combined']['jsHead']}\n");
                }
            }
            $this->stdout("  Total files:    {$result['totalFiles']}\n");
            $this->stdout("  Processed:      {$result['processedFiles']}\n");
            $this->stdout("  Reused cached:  {$result['reusedFiles']}\n");
            $this->stdout("  Original size:  " . StatsService::formatBytes($result['originalSizeBytes']) . "\n");
            $this->stdout("  Optimized size: " . StatsService::formatBytes($result['optimizedSizeBytes']) . "\n");
            $this->stdout("  Saved:          {$result['savingPercent']}%\n", Console::FG_YELLOW, Console::BOLD);
            $this->stdout("  Duration:       {$result['durationSeconds']}s\n");

            return ExitCode::OK;
        } catch (Throwable $e) {
            $this->stderr("✗ Build failed: " . $e->getMessage() . "\n", Console::FG_RED, Console::BOLD);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Continuously watches for changes in source files and automatically rebuilds assets.
     */
    public function actionWatch(int $pollInterval = 300): int
    {
        $this->stdout("--- [Asset Optimizer] Watch Mode Started ---\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout("Watching for source changes (interval: {$pollInterval}ms). Press Ctrl+C to stop.\n\n");

        $builder = $this->createAssetBuilder();
        $scanner = $this->createAssetScanner();

        // Perform initial build
        try {
            $builder->build(false);
            $this->stdout("[" . date('H:i:s') . "] Initial build complete.\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr("[" . date('H:i:s') . "] Initial build error: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        // Track file modification timestamps
        $fileMtimes = [];
        $scanned = $scanner->scan();
        foreach ($scanned as $item) {
            $src = $item['sourceAbsolutePath'];
            $fileMtimes[$src] = file_exists($src) ? (int) @filemtime($src) : 0;
        }

        while (true) {
            usleep($pollInterval * 1000);

            $hasChanges = false;
            $currentScanned = $scanner->scan();

            foreach ($currentScanned as $item) {
                $src = $item['sourceAbsolutePath'];
                $currentMtime = file_exists($src) ? (int) @filemtime($src) : 0;
                $lastMtime = $fileMtimes[$src] ?? 0;

                if ($currentMtime !== $lastMtime) {
                    $hasChanges = true;
                    $fileMtimes[$src] = $currentMtime;
                    $this->stdout("[" . date('H:i:s') . "] File changed: " . basename($src) . "\n", Console::FG_YELLOW);
                }
            }

            if ($hasChanges) {
                try {
                    $res = $builder->build(false);
                    $this->stdout("[" . date('H:i:s') . "] ✓ Rebuilt successfully ({$res['durationSeconds']}s, saved {$res['savingPercent']}%)\n", Console::FG_GREEN);
                } catch (Throwable $e) {
                    $this->stderr("[" . date('H:i:s') . "] ✗ Rebuild error: " . $e->getMessage() . "\n", Console::FG_RED);
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Verifies the integrity of the active release files and content hashes.
     */
    public function actionVerify(): int
    {
        $this->stdout("--- [Asset Optimizer] Verifying Active Release ---\n", Console::FG_CYAN);

        $manager = $this->createReleaseManager();
        $res = $manager->verify();

        if ($res['valid']) {
            $this->stdout("✓ Active release [{$res['buildId']}] is valid. {$res['filesChecked']} file(s) verified.\n", Console::FG_GREEN, Console::BOLD);
            return ExitCode::OK;
        }

        $this->stderr("✗ Verification failed for release [{$res['buildId']}]:\n", Console::FG_RED, Console::BOLD);
        foreach ($res['errors'] as $err) {
            $this->stderr("  - {$err}\n", Console::FG_RED);
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Displays detailed compression statistics and savings.
     */
    public function actionStats(): int
    {
        $registry = $this->createManifestRegistry();
        $statsService = new StatsService($registry);
        $stats = $statsService->getStats();

        if ($this->json) {
            $this->stdout(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            return ExitCode::OK;
        }

        $this->stdout("--- [Asset Optimizer] Asset Statistics ---\n", Console::FG_CYAN, Console::BOLD);

        if (!$stats['hasActiveBuild']) {
            $this->stdout("No active build found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Active Build:    {$stats['buildId']}\n");
        $this->stdout("Created:         {$stats['createdAt']}\n");
        $this->stdout("Total Files:     {$stats['totalFiles']}\n");
        $this->stdout("Original Size:   " . StatsService::formatBytes($stats['originalSizeBytes']) . "\n");
        $this->stdout("Optimized Size:  " . StatsService::formatBytes($stats['optimizedSizeBytes']) . "\n");
        $this->stdout("Estimated Gzip:  " . StatsService::formatBytes($stats['estimatedGzipBytes']) . "\n");
        $this->stdout("Savings:         {$stats['savingPercent']}%\n", Console::FG_GREEN, Console::BOLD);

        if (!empty($stats['assets'])) {
            $this->stdout("\nAssets Breakdown:\n", Console::BOLD);
            foreach ($stats['assets'] as $a) {
                $orig = StatsService::formatBytes($a['originalSize']);
                $opt = StatsService::formatBytes($a['optimizedSize']);
                $this->stdout(sprintf("  %-40s %-8s %-8s (-%s%%)\n", $a['key'], $orig, $opt, $a['savingPercent']));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Rolls back to a specified build ID or to the previous build.
     */
    public function actionRollback(?string $buildId = null): int
    {
        $this->stdout("--- [Asset Optimizer] Rollback ---\n", Console::FG_CYAN);

        try {
            $manager = $this->createReleaseManager();
            $res = $manager->rollback($buildId);

            $this->stdout("✓ {$res['message']}\n", Console::FG_GREEN, Console::BOLD);
            return ExitCode::OK;
        } catch (Throwable $e) {
            $this->stderr("✗ Rollback failed: " . $e->getMessage() . "\n", Console::FG_RED, Console::BOLD);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Safely deletes orphaned assets while protecting active and recent releases.
     */
    public function actionPrune(): int
    {
        $this->stdout("--- [Asset Optimizer] Prune Obsolete Releases ---\n", Console::FG_CYAN);

        $manager = $this->createReleaseManager();
        $res = $manager->prune((int) $this->keep, (int) $this->days, (bool) $this->dryRun);

        $mode = $res['dryRun'] ? 'Dry-run preview' : 'Cleanup completed';
        $this->stdout("{$mode}:\n", Console::BOLD);
        $this->stdout("  Kept releases:        " . count($res['keptBuilds']) . "\n");
        $this->stdout("  Pruned releases:      " . count($res['prunedBuilds']) . "\n");
        $this->stdout("  Whitelisted files:    {$res['whitelistedFilesCount']}\n");
        $this->stdout("  Files deleted:        {$res['deletedFilesCount']}\n");
        $this->stdout("  Disk space freed:     " . StatsService::formatBytes($res['freedBytes']) . "\n");

        if (!empty($res['deletedFiles'])) {
            $this->stdout("\nDeleted files:\n");
            foreach ($res['deletedFiles'] as $f) {
                $this->stdout("  - {$f}\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Performs comprehensive host and toolchain diagnostics.
     */
    public function actionDoctor(): int
    {
        $this->stdout("--- [Asset Optimizer] System Diagnostics ---\n", Console::FG_CYAN, Console::BOLD);

        $doctor = new DoctorService(
            $this->createEsbuildRunner(),
            $this->runtimePath,
            $this->optimizedBasePath
        );

        $results = $doctor->diagnose();
        $hasErrors = false;

        foreach ($results as $check => $info) {
            $color = match ($info['status']) {
                'ok' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                'error' => Console::FG_RED,
            };

            $mark = match ($info['status']) {
                'ok' => '✓',
                'warning' => '!',
                'error' => '✗',
            };

            $this->stdout("[{$mark}] {$info['message']}\n", $color, Console::BOLD);
            if (!empty($info['detail'])) {
                $this->stdout("    Tip: {$info['detail']}\n", Console::FG_GREY);
            }

            if ($info['status'] === 'error') {
                $hasErrors = true;
            }
        }

        $this->stdout("\nRecommended Nginx configuration for /optimized/:\n", Console::FG_CYAN);
        $this->stdout("location ^~ /optimized/ {\n    add_header Cache-Control \"public, max-age=31536000, immutable\" always;\n    access_log off;\n    try_files \$uri =404;\n}\n", Console::FG_GREY);

        return $hasErrors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function createAssetScanner(): AssetScanner
    {
        $bundles = $this->bundles;
        $extraFiles = $this->extraFiles;
        $excludedBundles = $this->excludedBundles;
        $excludedFiles = $this->excludedFiles;

        $targetApp = $this->app ?: (Yii::getAlias('@frontend', false) !== false ? 'frontend' : 'app');

        // Auto-detect excluded bundles and files from application configuration if available
        $appConfigDir = Yii::getAlias("@{$targetApp}/config", false);
        if ($appConfigDir !== false) {
            $webConfigFile = $appConfigDir . '/main.php';
            if (file_exists($webConfigFile)) {
                try {
                    $webConfig = @require $webConfigFile;
                    $am = $webConfig['components']['assetManager'] ?? [];
                    if (is_array($am)) {
                        if (!empty($am['excludedBundles']) && is_array($am['excludedBundles'])) {
                            $excludedBundles = array_unique(array_merge($excludedBundles, $am['excludedBundles']));
                        }
                        if (!empty($am['excludedFiles']) && is_array($am['excludedFiles'])) {
                            $excludedFiles = array_unique(array_merge($excludedFiles, $am['excludedFiles']));
                        }
                        if (!empty($am['bundles']) && is_array($am['bundles'])) {
                            foreach ($am['bundles'] as $bClass => $bVal) {
                                if ($bVal === false && !in_array($bClass, $excludedBundles, true)) {
                                    $excludedBundles[] = $bClass;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore if web config relies on web-only context
                }
            }
        }

        // Auto-detect bundles if none configured
        if (empty($bundles)) {
            $bundles = $this->detectDefaultBundles();
        }

        return new AssetScanner(
            $bundles,
            $extraFiles,
            $excludedBundles,
            $excludedFiles
        );
    }

    private function createAssetBuilder(): AssetBuilder
    {
        $builder = new AssetBuilder(
            $this->createEsbuildRunner(),
            $this->createAssetScanner(),
            $this->createManifestRegistry(),
            $this->runtimePath,
            $this->optimizedBasePath,
            $this->optimizedBaseUrl
        );

        $combine = $this->combine !== null ? (bool) $this->combine : $this->detectCombineSetting();
        $builder->combineAssets = $combine;

        return $builder;
    }

    private function detectCombineSetting(): bool
    {
        $targetApp = $this->app ?: (Yii::getAlias('@frontend', false) !== false ? 'frontend' : 'app');
        $appConfigDir = Yii::getAlias("@{$targetApp}/config", false);
        if ($appConfigDir !== false) {
            $webConfigFile = $appConfigDir . '/main.php';
            if (file_exists($webConfigFile)) {
                try {
                    $webConfig = @require $webConfigFile;
                    $am = $webConfig['components']['assetManager'] ?? [];
                    if (is_array($am) && array_key_exists('combineAssets', $am)) {
                        return (bool) $am['combineAssets'];
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        return true;
    }

    private function createReleaseManager(): ReleaseManager
    {
        return new ReleaseManager(
            $this->createManifestRegistry(),
            $this->runtimePath,
            $this->optimizedBasePath
        );
    }

    private function createManifestRegistry(): ManifestRegistry
    {
        return new ManifestRegistry($this->runtimePath);
    }

    private function createEsbuildRunner(): EsbuildRunner
    {
        return new EsbuildRunner($this->esbuildBin);
    }

    /**
     * @return string[]
     */
    private function detectDefaultBundles(): array
    {
        $targetApp = $this->app ?: (Yii::getAlias('@frontend', false) !== false ? 'frontend' : 'app');
        return AssetScanner::detectDefaultBundles($targetApp);
    }
}
