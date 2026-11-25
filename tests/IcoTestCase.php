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
        $this->assertIsObject($im);

        $expectedFile = './tests/assets/'.$expected;
        // can regenerate expected results by deleting and re-running test
        if (!file_exists($expectedFile)) {
            imagepng($im, $expectedFile, 0);
            $this->markTestSkipped('Regenerated $expected  - skipping test');
        }

        // save icon as PNG with no compression
        ob_start();
        imagepng($im, null, 0);
        $imageData = ob_get_contents();
        ob_end_clean();

        // it's possible this might break if the gd results change anything in their png encoding
        // but that should be rare - the aim here to catch everyday problems in library maintenance
        $expectedData = file_get_contents('./tests/assets/'.$expected);
        if ($expectedData !== $imageData) {
            $observedFile = str_replace('.png', '-OBSERVED.png', $expectedFile);
            file_put_contents($observedFile, $imageData);
        }
        $this->assertSame($imageData, $expectedData, 'generated image did not match expected '.$expected);
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
