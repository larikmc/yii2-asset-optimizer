<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\models;

/**
 * Represents an individual optimized asset entry in the manifest.
 */
class AssetRecord
{
    /**
     * Unique key in format "BundleClass:relativePath" or normalized path.
     */
    public string $key = '';

    /**
     * Asset type: 'css' or 'js'.
     */
    public string $type = 'css';

    /**
     * Source bundle class name or empty if extraFile.
     */
    public string $bundleClass = '';

    /**
     * Relative path in the source bundle or webroot (e.g. "css/site.css").
     */
    public string $sourceRelativePath = '';

    /**
     * Relative path of the output file in the optimized directory (e.g. "site.7a8b9c0d1e2f.min.css").
     */
    public string $outputRelativePath = '';

    /**
     * Public URL to access the optimized asset (e.g. "/optimized/site.7a8b9c0d1e2f.min.css").
     */
    public string $url = '';

    /**
     * Full SHA-256 hash of the compiled output file.
     */
    public string $contentHash = '';

    /**
     * Short SHA-256 hash (12-16 chars) used in the output file name.
     */
    public string $shortHash = '';

    /**
     * SHA-256 hash of the source file(s) for incremental rebuild detection.
     */
    public string $sourceHash = '';

    /**
     * Original size in bytes before minification.
     */
    public int $originalSize = 0;

    /**
     * Final size in bytes after minification.
     */
    public int $optimizedSize = 0;

    /**
     * Subresource Integrity (SRI) string, e.g. "sha384-...".
     */
    public ?string $integrity = null;

    /**
     * Sub-dependencies (e.g. extracted font or image files referenced by CSS).
     *
     * @var string[]
     */
    public array $dependencies = [];

    /**
     * Converts record to an array for JSON manifest storage.
     * Note: Private local filesystem paths are deliberately NOT included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'bundleClass' => $this->bundleClass,
            'sourceRelativePath' => $this->sourceRelativePath,
            'outputRelativePath' => $this->outputRelativePath,
            'url' => $this->url,
            'contentHash' => $this->contentHash,
            'shortHash' => $this->shortHash,
            'sourceHash' => $this->sourceHash,
            'originalSize' => $this->originalSize,
            'optimizedSize' => $this->optimizedSize,
            'integrity' => $this->integrity,
            'dependencies' => $this->dependencies,
        ];
    }

    /**
     * Creates an AssetRecord instance from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $record = new self();
        $record->key = (string) ($data['key'] ?? '');
        $record->type = (string) ($data['type'] ?? 'css');
        $record->bundleClass = (string) ($data['bundleClass'] ?? '');
        $record->sourceRelativePath = (string) ($data['sourceRelativePath'] ?? '');
        $record->outputRelativePath = (string) ($data['outputRelativePath'] ?? '');
        $record->url = (string) ($data['url'] ?? '');
        $record->contentHash = (string) ($data['contentHash'] ?? '');
        $record->shortHash = (string) ($data['shortHash'] ?? '');
        $record->sourceHash = (string) ($data['sourceHash'] ?? '');
        $record->originalSize = (int) ($data['originalSize'] ?? 0);
        $record->optimizedSize = (int) ($data['optimizedSize'] ?? 0);
        $record->integrity = isset($data['integrity']) ? (string) $data['integrity'] : null;
        $record->dependencies = isset($data['dependencies']) && is_array($data['dependencies'])
            ? array_values(array_map('strval', $data['dependencies']))
            : [];

        return $record;
    }
}
