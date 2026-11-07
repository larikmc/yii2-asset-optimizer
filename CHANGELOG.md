# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-07

### Added
- **OptimizingAssetManager**: Core component extending `yii\web\AssetManager` with O(1) URL resolution from content-hashed manifests.
- **Zero-Mtime HTTP Runtime**: Complete elimination of runtime Node.js, esbuild, minification, hashing, and `filemtime` calls on HTTP requests.
- **EsbuildRunner**: Cross-platform service executing `esbuild` synchronously via robust process execution forwarding all environment variables.
- **CSS Optimization Pipeline**: Dependency bundling with local `@import` inlining, relative `url()` resolution, and automatic extraction & copying of auxiliary fonts (`.woff2`, `.woff`, `.ttf`, `.eot`) and images (`.svg`, `.png`, `.webp`, `.jpg`).
- **JavaScript Isolation Pipeline**: Independent minification without bundling to preserve global scopes, jQuery plugins, PJAX, and ActiveForm events.
- **Subresource Integrity (SRI)**: Generation of `sha384-...` hashes in manifests and resolution via `getAssetIntegrity()`.
- **Recursive Bundle Dependency Resolution**: Scanner automatically traverses `$bundle->depends` recursively in topological order with circular reference prevention.
- **Query Parameter Normalization**: Strips dynamic query parameters (`?v=...`) and hash fragments (`#...`) from bundle declarations so legacy cache-busting strings don't prevent asset resolution.
- **Automatic Configuration Sync**: Console build automatically detects bundles disabled in web configuration (`'bundle\Class' => false`) and appends them to exclusions.
- **Default Yii2 Bundle Discovery**: Out-of-the-box support for `YiiAsset`, `JqueryAsset`, `ActiveFormAsset`, `ValidationAsset`, `GridViewAsset`, `PjaxAsset`, `CaptchaAsset`, and backend admin bundles.
- **Atomic Release Activation**: Staging directories and atomic pointer switching (`current.version` / `current.json`) ensuring zero downtime during builds.
- **Multi-Tier Caching**: Static in-memory cache, APCu shared memory cache, and JSON filesystem fallback.
- **FileLocker**: Cross-platform non-blocking concurrency locking on `.build.lock`.
- **ReleaseManager**: Full management of release lifecycles including `verify`, `rollback`, and safe `prune` with active and recent release protection.
- **Comprehensive CLI**: `build`, `watch`, `verify`, `stats`, `rollback`, `prune`, and `doctor`.
- **Diagnostics**: `doctor` command inspecting PHP version, JSON, APCu, OPcache, esbuild, directory permissions, and providing recommended Nginx configuration.
- **Test Suite**: 21 unit tests covering all services, models, and components with 100% pass rate.
