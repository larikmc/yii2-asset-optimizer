# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-06

### Added
- Core `OptimizingAssetManager` component extending `yii\web\AssetManager` with O(1) URL resolution.
- `EsbuildRunner` cross-platform service for executing esbuild commands on Windows and Linux.
- CSS compilation pipeline with automatic `@import` bundling, `url()` rebasing, and font/SVG static asset copying.
- Zero-bundling JavaScript isolation pipeline preserving global variables, jQuery plugins, and ActiveForm events.
- Atomic pointer switching (`current.version` and `current.json`) with Windows and Linux filesystem safety.
- Multi-tier manifest caching: per-request in-memory static cache, APCu shared memory cache, and filesystem JSON fallback.
- `FileLocker` cross-platform process concurrency lock (`.build.lock`).
- `ReleaseManager` supporting `verify`, `rollback`, and safe `prune` with active and recent build protection.
- `StatsService` providing machine-readable JSON and human-readable CLI tables.
- `DoctorService` environment diagnostics inspecting PHP, APCu, OPcache, esbuild, and directory permissions.
- `AssetOptimizerController` CLI controller (`build`, `watch`, `verify`, `stats`, `rollback`, `prune`, `doctor`).
- Comprehensive PHPUnit 12 automated test suite (20 tests, 80 assertions).
- Comprehensive architectural documentation in `docs/ARCHITECTURE.md`.
