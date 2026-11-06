<?php

declare(strict_types=1);

namespace larikmc\assetoptimizer\tests\unit;

use larikmc\assetoptimizer\services\EsbuildRunner;
use PHPUnit\Framework\TestCase;

class EsbuildRunnerTest extends TestCase
{
    public function testResolveBinaryAndGetVersion(): void
    {
        $runner = new EsbuildRunner();
        $this->assertTrue($runner->isAvailable(), 'Esbuild should be available in test environment');
        $version = $runner->getVersion();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    public function testMinifyJs(): void
    {
        $runner = new EsbuildRunner();
        $inputJs = __DIR__ . '/../fixtures/test_input.js';
        $outputJs = __DIR__ . '/../runtime/test_output.min.js';

        file_put_contents($inputJs, "function greet(name) { /* comment */ console.log('Hello, ' + name); } greet('World');");

        $result = $runner->minifyJs($inputJs, $outputJs);
        $this->assertSame(0, $result['exitCode'], 'Minify JS should return exit code 0');
        $this->assertFileExists($outputJs);

        $minified = file_get_contents($outputJs);
        $this->assertStringNotContainsString('/* comment */', $minified);
        $this->assertStringContainsString('greet("World")', $minified);

        @unlink($inputJs);
        @unlink($outputJs);
    }

    public function testMinifyCss(): void
    {
        $runner = new EsbuildRunner();
        $inputCss = __DIR__ . '/../fixtures/test_input.css';
        $outputCss = __DIR__ . '/../runtime/test_output.min.css';

        file_put_contents($inputCss, "body {\n    background-color: #ffffff;\n    margin: 0px;\n}\n");

        $result = $runner->minifyCss($inputCss, $outputCss);
        $this->assertSame(0, $result['exitCode'], 'Minify CSS should return exit code 0');
        $this->assertFileExists($outputCss);

        $minified = trim(file_get_contents($outputCss));
        $this->assertStringNotContainsString("\n    background-color", $minified);
        $this->assertStringContainsString('#fff', $minified);

        @unlink($inputCss);
        @unlink($outputCss);
    }
}
