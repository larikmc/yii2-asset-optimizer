<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\models;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable build release manifest model.
 */
class Manifest
{
    public const SCHEMA_VERSION = '1.0';

    public string $schemaVersion = self::SCHEMA_VERSION;
    public string $buildId;
    public string $createdAt;

    /**
     * @var array{php?: string, esbuild?: string, optimizer?: string}
     */
    public array $toolchain = [];

    /**
     * @var array{totalFiles: int, originalSizeBytes: int, optimizedSizeBytes: int, savingPercent: float}
     */
    public array $summary = [
        'totalFiles' => 0,
        'originalSizeBytes' => 0,
        'optimizedSizeBytes' => 0,
        'savingPercent' => 0.0,
    ];

    /**
     * Map of asset key => AssetRecord
     *
     * @var array<string, AssetRecord>
     */
    public array $assets = [];

    /**
     * List of external static dependencies (fonts, images) published alongside assets.
     *
     * @var string[]
     */
    public array $dependencies = [];

    public function __construct(string $buildId = '')
    {
        $this->buildId = $buildId !== '' ? $buildId : self::generateBuildId();
        $this->createdAt = date('c');
    }

    public static function generateBuildId(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Adds an AssetRecord and recalculates summary statistics.
     */
    public function addAsset(AssetRecord $asset): void
    {
        $this->assets[$asset->key] = $asset;
        $this->recalculateSummary();
    }

    /**
     * Returns an asset by its key or null if not found.
     */
    public function getAsset(string $key): ?AssetRecord
    {
        return $this->assets[$key] ?? null;
    }

    /**
     * Recalculates summary statistics based on registered assets.
     */
    public function recalculateSummary(): void
    {
        $totalFiles = count($this->assets);
        $originalTotal = 0;
        $optimizedTotal = 0;

        foreach ($this->assets as $asset) {
            $originalTotal += $asset->originalSize;
            $optimizedTotal += $asset->optimizedSize;
        }

        $savingPercent = $originalTotal > 0
            ? round((($originalTotal - $optimizedTotal) / $originalTotal) * 100, 2)
            : 0.0;

        $this->summary = [
            'totalFiles' => $totalFiles,
            'originalSizeBytes' => $originalTotal,
            'optimizedSizeBytes' => $optimizedTotal,
            'savingPercent' => $savingPercent,
        ];
    }

    /**
     * Converts manifest to an array for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $assetsArray = [];
        foreach ($this->assets as $key => $asset) {
            $assetsArray[$key] = $asset->toArray();
        }

        return [
            'schemaVersion' => $this->schemaVersion,
            'buildId' => $this->buildId,
            'createdAt' => $this->createdAt,
            'toolchain' => $this->toolchain,
            'summary' => $this->summary,
            'assets' => $assetsArray,
            'dependencies' => array_values(array_unique($this->dependencies)),
        ];
    }

    /**
     * Serializes manifest to JSON string.
     *
     * @throws JsonException
     */
    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->toArray(), $flags);
    }

    /**
     * Parses manifest from a JSON string.
     *
     * @throws JsonException|InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Decoded JSON manifest must be an array.');
        }

        return self::fromArray($data);
    }

    /**
     * Instantiates a Manifest from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $manifest = new self((string) ($data['buildId'] ?? ''));
        $manifest->schemaVersion = (string) ($data['schemaVersion'] ?? self::SCHEMA_VERSION);
        $manifest->createdAt = (string) ($data['createdAt'] ?? date('c'));

        if (isset($data['toolchain']) && is_array($data['toolchain'])) {
            $manifest->toolchain = $data['toolchain'];
        }

        if (isset($data['summary']) && is_array($data['summary'])) {
            $manifest->summary = [
                'totalFiles' => (int) ($data['summary']['totalFiles'] ?? 0),
                'originalSizeBytes' => (int) ($data['summary']['originalSizeBytes'] ?? 0),
                'optimizedSizeBytes' => (int) ($data['summary']['optimizedSizeBytes'] ?? 0),
                'savingPercent' => (float) ($data['summary']['savingPercent'] ?? 0.0),
            ];
        }

        if (isset($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $key => $assetData) {
                if (is_array($assetData)) {
                    $manifest->assets[(string) $key] = AssetRecord::fromArray($assetData);
                }
            }
        }

        if (isset($data['dependencies']) && is_array($data['dependencies'])) {
            $manifest->dependencies = array_values(array_map('strval', $data['dependencies']));
        }

        return $manifest;
    }
}
