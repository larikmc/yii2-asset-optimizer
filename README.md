# larikmc/yii2-asset-optimizer

[![Latest Stable Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/larikmc/yii2-asset-optimizer)
[![Yii2](https://img.shields.io/badge/Yii2-2.0.x-green.svg)](https://www.yiiframework.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-informational.svg)](LICENSE.md)

Высокопроизводительный Composer-пакет для автоматической оптимизации, минификации и версионирования CSS и JavaScript в проектах на Yii2 с **нулевыми накладными расходами на HTTP-запросах**.

---

## ⚡ Главные преимущества

1. **Zero-Overhead Runtime**: Никакой компиляции, обхода директорий, вычисления хешей или вызова `filemtime` на запросах посетителей. Разрешение оптимизированного URL выполняется за $O(1)$ через in-memory хэш-таблицу или APCu.
2. **Изоляция и безопасность JS**: JavaScript минифицируется без искусственного объединения в один монолитный бандл, что сохраняет глобальные переменные, плагины jQuery, валидаторы ActiveForm и логику PJAX.
3. **Умный CSS-пайплайн**: esbuild автоматически разрешает локальные `@import`, пересчитывает пути в `url()` и публикует связанные шрифты и SVG с контентными хешами.
4. **Атомарная активация (Zero-Downtime)**: Сборка ведется в staging-директории. Указатель активной версии переключается атомарно только после полной проверки целостности всех файлов.
5. **Иммунитет к OPcache**: Манифесты хранятся в JSON. Обновление ассетов **не требует `opcache_reset()`**, в отличие от устаревшего `yii asset`.
6. **Мгновенный откат (Rollback)**: Предыдущие версии файлов не удаляются при деплое. Команда отката переключает версию за миллисекунды без пересборки.
7. **Watch-режим**: Автоматическое отслеживание изменений файлов и инкрементальная пересборка для комфортной локальной разработки.

---

## 📦 Установка

```bash
composer require larikmc/yii2-asset-optimizer
```

Убедитесь, что в системе установлен Node.js (v18+) и esbuild (либо npx):
```bash
npm install -g esbuild
# или локально в проекте:
npm install --save-dev esbuild
```

---

## ⚙️ Конфигурация

### 1. Подключение компонента AssetManager

В конфигурационном файле приложения (`config/web.php` в Yii2 Basic или `frontend/config/main.php` в Yii2 Advanced):

```php
'components' => [
    'assetManager' => [
        'class' => \larikmc\assetoptimizer\components\OptimizingAssetManager::class,
        'enabled' => YII_ENV_PROD, // или true для постоянной оптимизации
        'enableApcu' => true,
        // Исключения при необходимости:
        'excludedBundles' => [
            // 'debug\DebugAsset',
        ],
        'excludedFiles' => [
            // '*.debug.js',
        ],
    ],
],
```

### 2. Подключение консольных команд

В `config/console.php` (или `console/config/main.php`):

```php
'controllerMap' => [
    'asset-optimizer' => [
        'class' => \larikmc\assetoptimizer\commands\AssetOptimizerController::class,
        'bundles' => [
            \frontend\assets\AppAsset::class,
            \yii\web\YiiAsset::class,
            \yii\bootstrap5\BootstrapAsset::class,
        ],
        'extraFiles' => [
            // '@webroot/js/custom-standalone.js',
        ],
    ],
],
```

---

## 🚀 Консольные команды

| Команда | Описание |
|---|---|
| `php yii asset-optimizer/build` | Инкрементальная сборка (пересобирает только измененные файлы) |
| `php yii asset-optimizer/build --full=1` | Полная принудительная пересборка всех ассетов |
| `php yii asset-optimizer/watch` | Фоновое отслеживание файлов и пересборка в реальном времени |
| `php yii asset-optimizer/verify` | Проверка целостности активного релиза и сверка SHA-256 хешей |
| `php yii asset-optimizer/stats` | Просмотр статистики сжатия и экономии трафика |
| `php yii asset-optimizer/stats --json=1` | Статистика в машиночитаемом формате JSON |
| `php yii asset-optimizer/rollback` | Мгновенный откат на предыдущую сборку |
| `php yii asset-optimizer/rollback [buildId]` | Откат на конкретный исторический манифест |
| `php yii asset-optimizer/prune --keep=5 --days=30` | Безопасная очистка старых версий файлов |
| `php yii asset-optimizer/prune --dry-run=1` | Предварительный просмотр файлов подлежащих удалению |
| `php yii asset-optimizer/doctor` | Проверка окружения, Node.js, esbuild, APCu, OPcache и прав |

---

## 🌐 Настройка веб-сервера (Nginx)

Поскольку все оптимизированные файлы содержат уникальный SHA-256 хеш содержимого в имени, настройте для них долговечное кэширование:

```nginx
location /optimized/ {
    expires 365d;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
    try_files $uri =404;
}
```

---

## 🧪 Тестирование

```bash
vendor/bin/phpunit
```

---

## 📄 Лицензия

Пакет распространяется под лицензией MIT. Подробнее см. в [LICENSE.md](LICENSE.md).
