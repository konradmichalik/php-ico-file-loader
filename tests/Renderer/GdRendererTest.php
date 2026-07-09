<?php

declare(strict_types=1);

/*
 * This file is part of the "php-ico-file-loader" Composer package.
 *
 * (c) Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\PhpIcoFileLoader\Tests\Renderer;

use InvalidArgumentException;
use Iterator;
use KonradMichalik\PhpIcoFileLoader\Model\IconImage;
use KonradMichalik\PhpIcoFileLoader\Renderer\GdRenderer;
use KonradMichalik\PhpIcoFileLoader\Tests\IcoTestCase;

/**
 * GdRendererTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
final class GdRendererTest extends IcoTestCase
{
    public static function greenBackgroundProvider(): Iterator
    {
        yield ['32bit-16px-32px-sample.ico', 1, '32x32 pixel BMP @ 32 bits/pixel', '32bit-32px-expected.png'];
        yield ['24bit-32px-sample.ico', 0, '32x32 pixel BMP @ 24 bits/pixel', '24bit-32px-expected.png'];
        yield ['8bit-48px-32px-16px-sample.ico', 2, '32x32 pixel BMP @ 8 bits/pixel', '8bit-32px-expected.png'];
        yield ['8bit-48px-32px-16px-sample.ico', 4, '48x48 pixel BMP @ 8 bits/pixel', '8bit-48px-expected.png'];
        yield ['4bit-32px-16px-sample.ico', 0, '32x32 pixel BMP @ 4 bits/pixel', '4bit-32px-expected.png'];
        yield ['1bit-32px-sample.ico', 0, '32x32 pixel BMP @ 1 bits/pixel', '1bit-32px-expected.png'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('greenBackgroundProvider')]
    public function testWithGreenBackground($srcIconFile, $imageIndex, $expectedFormat, $expectedPngFile): void
    {
        $renderer = new GdRenderer();
        $icon = $this->parseIcon($srcIconFile);

        $this->assertEquals($expectedFormat, $icon[$imageIndex]->getDescription());

        // render on green background to better show where transparency should be
        $im = $renderer->render($icon[$imageIndex], ['background' => '#00ff00']);
        $this->assertImageLooksLike($expectedPngFile, $im);
    }

    public function testPng(): void
    {
        $renderer = new GdRenderer();
        $icon = $this->parseIcon('32bit-png-sample.ico');

        $this->assertSame('256x256 pixel PNG @ 32 bits/pixel', $icon[11]->getDescription());

        // as well as testing png, we test a transparent render too
        $im = $renderer->render($icon[11]);
        $this->assertImageLooksLike('32bit-png-transparent-expected.png', $im);

        // and let's try it on a green background
        $im = $renderer->render($icon[11], ['background' => '#00ff00']);
        $this->assertImageLooksLike('32bit-png-green-expected.png', $im);
    }

    public function testInvalidBackground(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $renderer = new GdRenderer();
        $icon = $this->parseIcon('32bit-png-sample.ico');
        $renderer->render($icon[11], ['background' => 'this is garbage']);
    }

    public function testTruncated8bitBitmapDataIsRejected(): void
    {
        $image = new IconImage(['width' => 16, 'height' => 16, 'bitCount' => 8, 'colorCount' => 2]);
        $image->addToBmpPalette(0, 0, 0, 255);
        $image->addToBmpPalette(255, 255, 255, 255);
        $image->setBitmapData("\x00\x00");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient bitmap data');

        (new GdRenderer())->render($image);
    }

    public function testTruncated24bitBitmapDataIsRejected(): void
    {
        $image = new IconImage(['width' => 16, 'height' => 16, 'bitCount' => 24]);
        $image->setBitmapData("\x00\x00\x00");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient bitmap data');

        (new GdRenderer())->render($image);
    }

    public function testResizeWhenOnlyOneDimensionDiffers(): void
    {
        $renderer = new GdRenderer();
        $icon = $this->parseIcon('24bit-32px-sample.ico');
        $image = $icon[0];
        $this->assertSame(32, $image->width);
        $this->assertSame(32, $image->height);

        // width already matches the source (32), only the height differs
        $im = $renderer->render($image, ['w' => 32, 'h' => 64]);

        $this->assertSame(32, imagesx($im));
        $this->assertSame(64, imagesy($im));
    }

    public function testOversizedPngIsRejected(): void
    {
        $ihdr = pack('NN', 100000, 100000)."\x08\x06\x00\x00\x00";
        $bombPng = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        $image = new IconImage(['width' => 16, 'height' => 16, 'bitCount' => 32]);
        $image->setPngFile($bombPng);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceed the maximum allowed size');

        (new GdRenderer())->render($image);
    }
}
