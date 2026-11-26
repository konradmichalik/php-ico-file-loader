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

namespace KonradMichalik\PhpIcoFileLoader\Tests;

use GdImage;
use KonradMichalik\PhpIcoFileLoader\Model\Icon;
use KonradMichalik\PhpIcoFileLoader\Parser\IcoParser;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * IcoTestCase.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
abstract class IcoTestCase extends TestCase
{
    /**
     * A PNG that consists of the signature and an IHDR chunk only, enough for getimagesizefromstring().
     */
    protected static function bombPng(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    /**
     * @param string  $expected leafname of asset file we expect image to look like
     * @param GdImage $im       generated image you want to check
     */
    protected function assertImageLooksLike(string $expected, GdImage $im): void
    {
        $expectedFile = './tests/assets/'.$expected;
        // can regenerate expected results by deleting and re-running test
        if (!file_exists($expectedFile)) {
            imagepng($im, $expectedFile, 0);
            $this->markTestSkipped('Regenerated $expected  - skipping test');
        }

        // pixels are compared instead of the encoded file, whose bytes depend on the libgd, libpng and zlib versions
        $expectedIm = imagecreatefrompng($expectedFile);
        $this->assertInstanceOf(GdImage::class, $expectedIm);
        imagepalettetotruecolor($expectedIm);
        imagepalettetotruecolor($im);

        $this->assertSame(
            [imagesx($expectedIm), imagesy($expectedIm)],
            [imagesx($im), imagesy($im)],
            'generated image size did not match expected '.$expected,
        );

        $differing = 0;
        for ($y = 0; $y < imagesy($im); ++$y) {
            for ($x = 0; $x < imagesx($im); ++$x) {
                if (imagecolorat($im, $x, $y) !== imagecolorat($expectedIm, $x, $y)) {
                    ++$differing;
                }
            }
        }

        if ($differing > 0) {
            $observedFile = str_replace('.png', '-OBSERVED.png', $expectedFile);
            imagepng($im, $observedFile, 0);
        }
        $this->assertSame(0, $differing, sprintf('%d pixels of the generated image did not match expected %s', $differing, $expected));
    }

    /**
     * @param string $asset leafname of asset file
     */
    protected function parseIcon(string $asset): Icon
    {
        $parser = new IcoParser();

        return $parser->parse(file_get_contents('./tests/assets/'.$asset));
    }
}
