# Yii2 Asset Optimizer

[![Latest Stable Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/larikmc/yii2-asset-optimizer/releases)
[![Yii2](https://img.shields.io/badge/Yii2-2.0.x-green.svg)](https://www.yiiframework.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-informational.svg)](LICENSE.md)
[![Tests](https://img.shields.io/badge/PHPUnit-21%20passed-brightgreen.svg)](tests/)
[![esbuild](https://img.shields.io/badge/powered%20by-esbuild-orange.svg)](https://esbuild.github.io/)

Универсальный высокопроизводительный Composer-пакет для автоматической оптимизации, минификации и версионирования CSS и JavaScript в проектах на **Yii2 Framework** на базе ультрабыстрого компилятора **esbuild**.

**Главный принцип: абсолютный ноль накладных расходов на HTTP-запросах (Zero-Overhead Runtime), надёжность и безопасность.**

---

## Содержание

- [Архитектурный принцип](#архитектурный-принцип)
- [Ключевые возможности](#ключевые-возможности)
- [Требования](#требования)
- [Установка](#установка)
- [Конфигурация](#конфигурация)
  - [1. Yii2 Basic](#1-yii2-basic)
  - [2. Yii2 Advanced (Frontend + Backend)](#2-yii2-advanced-frontend--backend)
  - [3. Настройка Nginx](#3-настройка-nginx)
- [Консольные команды (CLI)](#консольные-команды-cli)
  - [Сборка ресурсов (`build`)](#1-сборка-ресурсов-build)
  - [Режим отслеживания (`watch`)](#2-режим-отслеживания-watch)
  - [Проверка целостности (`verify`)](#3-проверка-целостности-verify)
  - [Статистика сжатия (`stats`)](#4-статистика-сжатия-stats)
  - [Откат релиза (`rollback`)](#5-откат-релиза-rollback)
  - [Очистка устаревших релизов (`prune`)](#6-очистка-устаревших-релизов-prune)
  - [Диагностика окружения (`doctor`)](#7-диагностика-окружения-doctor)
- [Пайплайн развёртывания (Deploy / CI/CD)](#пайплайн-развёртывания-deploy--cicd)
- [Особенности и решение краевых задач](#особенности-и-решение-краевых-задач)
- [Тестирование](#тестирование)
- [Лицензия](#лицензия)

---

## Архитектурный принцип

Система строго разделена на два независимых слоя:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        BUILD-TIME (Только CLI)                        │
│                                                                        │
│   AssetScanner ──► EsbuildRunner ──► Content Hash ──► Staging Release  │
│   (обход $depends, (esbuild CSS/JS,  (SHA-256 / SRI,  (атомарный       │
│    очистка ?v=...)  копирование WOFF) манифест JSON)   свитч указателя)│
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ current.version / current.json
┌───────────────────────────────────▼────────────────────────────────────┐
│                        RUNTIME (HTTP-запросы)                          │
│                                                                        │
│   OptimizingAssetManager ──► ManifestRegistry ──► $O(1)$ URL           │
│   (без Node.js, без esbuild, без filemtime, без вычисления хешей)      │
│   (кэш: In-Memory Static Array ──► APCu Shared Memory ──► JSON)        │
└────────────────────────────────────────────────────────────────────────┘
```

### На HTTP-запросах пользователей:
- Резолв URL выполняется за **$O(1)$** через многоуровневый кэш (статический массив в памяти PHP $\to$ разделяемая память APCu $\to$ чтение готового JSON-манифеста).
- **Категорически отсутствуют:** запуск Node.js/esbuild, минификация на лету, обход исходных каталогов, вызовы `filemtime()` на диске, вычисление хешей, фоновые сборки и блокировки.

---

## Ключевые возможности

- ⚡ **Ультрабыстрая компиляция через esbuild**: Сборка CSS и JS занимает доли секунды благодаря нативному движку на Go.
- 🔒 **Иммутабельное кэширование**: Каждый выходной файл получает уникальное имя с 12-символьным SHA-256 хешем содержимого (например, `site.a1b2c3d4e5f6.min.css`). Браузеры и CDN могут кэшировать файлы навечно (`Cache-Control: immutable`).
- 🛡️ **Безопасная изоляция JavaScript**: Традиционные скрипты минифицируются **без склейки в ES-модули**, гарантируя сохранение глобального контекста `window`, плагинов jQuery, событий ActiveForm и работы PJAX.
- 🎨 **Умный CSS-пайплайн**:
  - Локальный инлайнинг `@import`.
  - Автоматический резолв относительных путей в `url(...)`.
  - Извлечение, хеширование и копирование связанных шрифтов (`.woff2`, `.woff`, `.ttf`, `.eot`) и изображений (`.svg`, `.png`, `.webp`, `.jpg`) в публичный каталог `/optimized/assets/`.
- 🔄 **Рекурсивный обход зависимостей (`$depends`)**: Сканнер автоматически анализирует свойства `$depends` каждого зарегистрированного `AssetBundle` в правильном топологическом порядке.
- 🧹 **Нормализация путей и легаси query-параметров**: Если бандлы содержат параметры версионирования вроде `css/site.css?v=173000000` или фрагменты `#...`, они автоматически очищаются при сканировании и резолвинге.
- 🚀 **Zero-Downtime атомарные релизы**: Сборка идёт в изолированный staging-каталог. Переключение на новую версию происходит атомарной записью указателя (`current.version` / `current.json`). Посетители сайта никогда не увидят наполовину собранный ассет.
- 📦 **Независимость от OPcache**: Манифесты хранятся в JSON, поэтому обновление ассетов **не требует сброса `opcache_reset()`** (в отличие от PHP-массивов).
- 🔙 **Мгновенный откат (Rollback)**: Возможность в одну команду переключить активную сборку на предыдущую без перекомпиляции.
- 🧹 **Безопасная очистка (Prune)**: Удаление старых файлов с гарантированной защитой активного релиза и последних $N$ версий.
- 🔍 **Subresource Integrity (SRI)**: Автоматическая генерация `sha384-...` хешей для безопасности ресурсов.
- 🩺 **Встроенный Doctor**: Команда самодиагностики проверяет PHP, расширения JSON/APCu, OPcache, доступность esbuild, права на каталоги и генерирует готовую конфигурацию для Nginx.

---

## Требования

- **PHP**: `>= 8.0.0`
- **Yii2**: `~2.0.0`
- **Расширения PHP**: `ext-json`
- **ext-apcu**: *рекомендуется* для максимальной скорости разрешения URL без дискового ввода-вывода.
- **Node.js**: `>= 18.0.0` и **esbuild** (только на машине сборки / в CLI, на веб-сервере во время обслуживания HTTP-запросов Node.js не нужен).

---

## Установка

1. Установите пакет через Composer:

```bash
composer require larikmc/yii2-asset-optimizer
```

2. Убедитесь, что `esbuild` установлен (глобально или локально в проект):

```bash
# Глобально:
npm install -g esbuild

# Или локально в проект (рекомендуется):
npm install --save-dev esbuild
```

> **Совет:** Пакет автоматически ищет бинарник `esbuild` в `./node_modules/.bin/`, в системном `$PATH` или запускает его через `npx`.

---

## Конфигурация

### 1. Yii2 Basic

В файле конфигурации веб-приложения `config/web.php`:

```php
'components' => [
    'assetManager' => [
        'class' => \larikmc\assetoptimizer\components\OptimizingAssetManager::class,
        'enabled' => YII_ENV_PROD, // Включайте в production или true для постоянной оптимизации
        'enableApcu' => true,      // Кэширование манифеста в APCu (O(1))
    ],
    // ...
],
```

### 2. Yii2 Advanced (Frontend + Backend)

В Yii2 Advanced фронтенд и бэкенд обычно разделяют общий публичный корень `webroot` (например, в каталоге `frontend/web/optimized`).

**В `frontend/config/main.php`:**
```php
'components' => [
    'assetManager' => [
        'class' => \larikmc\assetoptimizer\components\OptimizingAssetManager::class,
        'enabled' => true,
        'enableApcu' => true,
    ],
],
```

**В `backend/config/main.php`:**
```php
'components' => [
    'assetManager' => [
        'class' => \larikmc\assetoptimizer\components\OptimizingAssetManager::class,
        'optimizerRuntimePath' => '@backend/runtime/asset-optimizer',
        'enabled' => true,
        'enableApcu' => true,
    ],
],
```

#### Дополнительные параметры `OptimizingAssetManager`:

| Параметр | По умолчанию | Описание |
|---|---|---|
| `enabled` | `true` | Включение/отключение резолва оптимизированных ресурсов. При `false` работает стандартный AssetManager Yii2. |
| `optimizerRuntimePath` | `'@runtime/asset-optimizer'` | Каталог для манифестов и кэша сборки. |
| `optimizedBaseUrl` | `'@web/optimized'` | Публичный базовый URL оптимизированных ассетов. |
| `optimizedBasePath` | `'@webroot/optimized'` | Файловая директория, куда складываются готовые ассеты. |
| `enableApcu` | `true` | Использовать ли память APCu для кэширования активного манифеста. |
| `excludedBundles` | `[]` | Массив классов AssetBundle, которые нужно исключить из оптимизации. |
| `excludedFiles` | `[]` | Массив масок файлов (например, `['*chart.js']`), исключаемых из оптимизации. |

---

### 3. Настройка Nginx

Поскольку скомпилированные ассеты содержат SHA-256 хеш содержимого в имени файла, настройте для них максимальное кэширование на стороне веб-сервера.

Добавьте в секцию `server` конфигурации Nginx (до основных regex-локаций):

```nginx
# Оптимизированные ассеты: долгосрочное иммутабельное кэширование
location ^~ /optimized/ {
    expires 365d;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    access_log off;
    try_files $uri =404;
}
```

Модификатор `^~` останавливает проверку регулярных выражений, обеспечивая мгновенную отдачу статики напрямую через Nginx.

---

## Консольные команды (CLI)

Консольный контроллер `asset-optimizer` регистрируется автоматически благодаря механизму Bootstrap расширений Yii2.

### 1. Сборка ресурсов (`build`)

Выполняет инкрементальную компиляцию всех обнаруженных ресурсов:

```bash
# Для приложения по умолчанию (frontend):
php yii asset-optimizer/build

# Для бэкенда в Yii2 Advanced:
php yii asset-optimizer/build --app=backend

# Полная чистая пересборка без использования кэша (-f):
php yii asset-optimizer/build --full
```

**Особенности сборки:**
- Вычисляется хеш исходников; не изменившиеся файлы повторно **не компилируются**, а берутся из локального кэша (`Reused cached`).
- Процесс защищён файловым неблокирующим мьютексом `.build.lock`.

---

### 2. Режим отслеживания (`watch`)

Режим для локальной разработки: непрерывно следит за изменениями файлов исходников и зависимостей, мгновенно пересобирая только затронутые ассеты:

```bash
php yii asset-optimizer/watch

# Настройка интервала опроса в миллисекундах:
php yii asset-optimizer/watch 200
```

---

### 3. Проверка целостности (`verify`)

Проверяет корректность активного релиза: валидирует структуру активного JSON-манифеста, физическое наличие всех скомпилированных файлов и совпадение их фактических SHA-256 хешей:

```bash
php yii asset-optimizer/verify
php yii asset-optimizer/verify --app=backend
```

---

### 4. Статистика сжатия (`stats`)

Выводит подробную сводку по всем ассетам активного билда (исходный размер, оптимизированный размер, процент экономии и расчётный размер с Gzip):

```bash
php yii asset-optimizer/stats

# Вывод в формате JSON для интеграции с пайплайнами мониторинга:
php yii asset-optimizer/stats --json
```

**Пример вывода:**
```text
--- [Asset Optimizer] Asset Statistics ---
Active Build:    20261106-224559-cc36a3bb
Created:         2026-11-06 22:45:59
Total Files:     10
Original Size:   714.39 KB
Optimized Size:  353.16 KB
Estimated Gzip:  123.21 KB
Savings:         50.56%

Assets Breakdown:
  yii\web\JqueryAsset:jquery.js                      278.63 KB   85.83 KB   (-69.2%)
  yii\web\YiiAsset:yii.js                            20.49 KB    5.61 KB    (-72.6%)
  frontend\assets\AppAsset:css/bootstrap-icons.min.css 83.86 KB 81.9 KB     (-2.3%)
  frontend\assets\AppAsset:css/playlog.css           86.54 KB    60.66 KB   (-29.9%)
  frontend\assets\AppAsset:js/playlog.js             151.2 KB    89.07 KB   (-41.1%)
  yii\widgets\ActiveFormAsset:yii.activeForm.js      36.29 KB    9.77 KB    (-73.1%)
  yii\validators\ValidationAsset:yii.validation.js   17.04 KB    6.33 KB    (-62.8%)
  yii\captcha\CaptchaAsset:yii.captcha.js            2.01 KB     1.12 KB    (-44.3%)
```

---

### 5. Откат релиза (`rollback`)

Позволяет мгновенно переключить активную версию на предыдущий билд либо на конкретный `buildId`:

```bash
# Откат на предыдущий успешный билд:
php yii asset-optimizer/rollback

# Откат на конкретный идентификатор сборки:
php yii asset-optimizer/rollback 20261106-223917-fb4be4d9
```

Откат происходит атомарным переключением указателя версии без изменения физических файлов на диске.

---

### 6. Очистка устаревших релизов (`prune`)

Безопасно удаляет файлы старых релизов, которые больше не используются. 
**Защищены от удаления:**
- Активный релиз.
- Последние $N$ релизов (параметр `--keep`, по умолчанию 5).
- Релизы, созданные менее $X$ дней назад (параметр `--days`, по умолчанию 30).
- Все вспомогательные зависимости (шрифты и изображения), на которые ссылаются защищённые релизы.

```bash
# Просмотр файлов перед удалением (dry-run):
php yii asset-optimizer/prune --dryRun=1

# Очистить, оставив 3 последних релиза:
php yii asset-optimizer/prune --keep=3
```

---

### 7. Диагностика окружения (`doctor`)

Запускает глубокую диагностику сервера и программного окружения:

```bash
php yii asset-optimizer/doctor
```

Проверяет:
- Версию PHP (>= 8.0.0).
- Наличие `ext-json`.
- Работу и активность `ext-apcu`.
- Настройки Zend OPcache (`opcache.validate_timestamps`).
- Доступность и версию исполняемого файла `esbuild`.
- Права на запись в runtime-каталог и директорию публикации статики.
- Выводит рекомендуемую конфигурацию Nginx.

---

## Пайплайн развёртывания (Deploy / CI/CD)

Встройте команду сборки ассетов в ваш деплой-скрипт (Deployer, GitHub Actions, GitLab CI, bash-скрипт) **после** установки Composer-зависимостей:

```bash
# 1. Установка зависимостей
composer install --no-dev --optimize-autoloader

# 2. Сборка оптимизированных ассетов
php yii asset-optimizer/build --app=frontend
php yii asset-optimizer/build --app=backend

# 3. Валидация сборки
php yii asset-optimizer/verify --app=frontend
php yii asset-optimizer/verify --app=backend

# 4. Очистка старых версий (сохраняя последние 5)
php yii asset-optimizer/prune --keep=5
```

> **Важно:** Благодаря тому, что манифесты хранятся в формате JSON, вызов `opcache_reset()` при деплое ассетов **не требуется**.

---

## Особенности и решение краевых задач

1. **Совместимость с `appendTimestamp`**:
   Стандартный `appendTimestamp = true` в Yii2 на каждом HTTP-запросе вызывает тяжелый системный вызов `@filemtime()`. В `OptimizingAssetManager` для оптимизированных файлов `filemtime` полностью устранён, так как хеш уже зашит в имя файла. Для сторонних неоптимизированных файлов версионирование по времени сохраняется как fallback.
2. **Динамические параметры в объявлении бандлов**:
   Если в `AppAsset` прописано `'css/style.css?v=' . filemtime(...)`, модуль автоматически удаляет query-параметры при сборке и резолвинге, обеспечивая точное совпадение ключей.
3. **Изоляция виджетов и сторонних бандлов**:
   Виджеты с динамическими JS-скриптами (ActiveForm, Pjax, GridView, Captcha, DatePicker) работают штатно без перенастройки кода представлений.

---

## Тестирование

Пакет покрыт модульными тестами PHPUnit:

```bash
vendor/bin/phpunit
```

Тестовый набор проверяет:
- Корректность сборки и вычисление хешей.
- Работу `EsbuildRunner` и аргументов компилятора.
- Работу сканнера с рекурсивными зависимостями и query-параметрами.
- Атомарное переключение файлов и версий на Windows и Linux.
- Неблокирующий локер `FileLocker`.
- Механизм кэширования `ManifestRegistry` (Static array + APCu + JSON).
- Логику отката (`rollback`) и очистки (`prune`).
- Интеграцию с `OptimizingAssetManager`.

---

## Лицензия

Пакет распространяется под свободной лицензией [MIT](LICENSE.md).  
Автор: **LarikMC** ([https://64web.ru](https://64web.ru), larikmc@mail.ru).
