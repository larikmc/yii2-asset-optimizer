# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-12

### Added
- **Global CSS/JS Combining (`combineAssets`)**:
  - Automatically bundle and concatenate all registered CSS stylesheets into a single common file (`all.<hash>.min.css`).
  - Automatically bundle and concatenate all registered JavaScript files into a single common file (`all.<hash>.min.js`).
  - Dedicated Head Script bundling (`all-head.<hash>.min.js`) for scripts registered with `View::POS_HEAD` (e.g., color-mode/theme toggles), preserving critical inline head execution while bundling the rest at the end of the body.
  - Semicolon-safe concatenation delimiters (`\n;\n`) to prevent ASI (Automatic Semicolon Insertion) collisions between merged libraries.
  - Automatic CSS asset rebasing and extraction of fonts/images into `/optimized/assets/` during single-file bundling.
  - Zero-overhead runtime transformation via synthetic `CombinedAssetBundle` and Yii2 dependency injection: clearing individual files from registered bundles and linking `CombinedAssetBundle` dynamically to guarantee a single HTTP request per type without changing view templates or bundle classes.
  - Configuration options in `OptimizingAssetManager`: `$combineAssets`, `$combinedBundleName`, `$uncombinedBundles`.
  - CLI option `--combine` / `-c` for `asset-optimizer/build` with automatic detection from web application config.
  - Expanded test suite: 27 unit tests with 142 assertions (100% pass rate).

## [1.0.0] - 2026-11-07

### Added
- **OptimizingAssetManager**: Core component extending `yii\web\AssetManager` with O(1) URL resolution from content-hashed manifests.
- **Zero-Mtime HTTP Runtime**: Complete elimination of runtime Node.js, esbuild, minification, hashing, and `filemtime` calls on HTTP requests.
- **Instant Cross-Process Version Sync**: Configurable `$versionCheckInterval` (default 0) reading 25-byte `current.version` per request to eliminate stale APCu caches across isolated CLI and PHP-FPM processes without restarting web services.
- **SourcePath Publication Bypassing**: `bypassSourcePathPublish` skips copying entire bundle directories into `web/assets/<hash>/` when all CSS/JS assets are present in the active manifest, setting `$bundle->basePath` and `$bundle->baseUrl` directly to optimized paths.
- **Intelligent Query Parameter Parsing**: `splitAssetSpec()` automatically strips technical cache-busting timestamps (`v`, `t`, `ver`, `version`, `_`), but preserves meaningful parameters and SVG fragments (`#id`) on optimized URLs, and preserves original parameters on unoptimized fallback assets.
- **EsbuildRunner**: Cross-platform service executing `esbuild` synchronously with dynamic binary discovery (`resolveNodePath()`, `NODE_PATH`, `params['assetOptimizer']['nodePath']`, system PATH, and local `node_modules`), completely free of hardcoded paths.
- **CSS Optimization Pipeline**: Dependency bundling with local `@import` inlining, relative `url()` resolution, and automatic extraction & copying of auxiliary fonts (`.woff2`, `.woff`, `.ttf`, `.eot`) and images (`.svg`, `.png`, `.webp`, `.jpg`).
- **JavaScript Isolation Pipeline**: Independent minification without bundling to preserve global scopes, jQuery plugins, PJAX, and ActiveForm events.
- **Subresource Integrity (SRI)**: Generation of `sha384-...` hashes in manifests and resolution via `getAssetIntegrity()`.
- **Recursive Bundle Dependency Resolution**: Scanner automatically traverses `$bundle->depends` recursively in topological order with circular reference prevention.
- **Automatic Configuration Sync**: Console build automatically detects bundles disabled in web configuration (`'bundle\Class' => false`) and appends them to exclusions.
- **Default Yii2 Bundle Discovery**: Out-of-the-box support for `YiiAsset`, `JqueryAsset`, `ActiveFormAsset`, `ValidationAsset`, `GridViewAsset`, `PjaxAsset`, `CaptchaAsset`, and backend admin bundles.
- **Atomic Release Activation**: Staging directories and atomic pointer switching (`current.version` / `current.json`) ensuring zero downtime during builds with retry-based Windows concurrency handling.
- **Multi-Tier Caching**: Static in-memory cache, APCu shared memory cache by immutable build ID, and JSON filesystem fallback.
- **FileLocker**: Cross-platform non-blocking concurrency locking on `.build.lock`.
- **ReleaseManager**: Full management of release lifecycles including `verify`, `rollback`, and safe `prune` with active and recent release protection.
- **Comprehensive CLI**: `build`, `watch`, `verify`, `stats`, `rollback`, `prune`, and `doctor`.
- **Diagnostics**: `doctor` command inspecting PHP version, JSON, APCu, OPcache, esbuild, directory permissions, and providing recommended Nginx configuration without conflicting `expires` directives.
- **Test Suite**: 24 unit tests with 104 assertions covering all services, models, and components with 100% pass rate.
