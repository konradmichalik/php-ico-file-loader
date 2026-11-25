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

use Iterator;
use KonradMichalik\PhpIcoFileLoader\IcoFileService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function chr;
use function count;
use function ord;
use function sprintf;
use function strlen;

/**
 * GdRendererPaddingTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
final class GdRendererPaddingTest extends TestCase
{
    private const RED = 0xFF0000;
    private const BLUE = 0x0000FF;

    public static function dimensionProvider(): Iterator
    {
        yield '1 bit 16x16' => [1, 16, 16];
        yield '1 bit 48x48' => [1, 48, 48];
        yield '1 bit 10x3' => [1, 10, 3];
        yield '4 bit 20x20' => [4, 20, 20];
        yield '8 bit 18x18' => [8, 18, 18];
        yield '24 bit 18x18' => [24, 18, 18];
    }

    #[DataProvider('dimensionProvider')]
    public function testRowsAreReadWithPadding(int $bitCount, int $width, int $height): void
    {
        $service = new IcoFileService();
        $icon = $service->fromString(self::buildIcon($bitCount, $width, $height));
        $im = $service->renderImage($icon[0]);

        $wrong = [];
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixel = imagecolorat($im, $x, $y);
                $isMasked = $x === $width - 1;
                $matches = $isMasked
                    ? 127 === (($pixel >> 24) & 0x7F)
                    : (0 === ($x + $y) % 2 ? self::RED : self::BLUE) === ($pixel & 0xFFFFFF);
                if (!$matches) {
                    $wrong[] = sprintf('%d,%d', $x, $y);
                }
            }
        }

        $this->assertSame([], $wrong, sprintf('%d of %d pixels are wrong', count($wrong), $width * $height));
    }

    /**
     * Checkerboard of red and blue, the last column is masked as transparent.
     */
    private static function buildIcon(int $bitCount, int $width, int $height): string
    {
        $paletteSize = $bitCount <= 8 ? 1 << $bitCount : 0;
        $palette = '';
        for ($i = 0; $i < $paletteSize; ++$i) {
            $palette .= match ($i) {
                0 => "\x00\x00\xFF\x00",
                1 => "\xFF\x00\x00\x00",
                default => "\x00\x00\x00\x00",
            };
        }

        $rowBytes = intdiv($width * $bitCount + 31, 32) * 4;
        $maskRowBytes = intdiv($width + 31, 32) * 4;
        $pixels = '';
        $mask = '';
        for ($fileRow = 0; $fileRow < $height; ++$fileRow) {
            $y = $height - 1 - $fileRow;
            $row = '';
            $bits = '';
            $maskRow = str_repeat("\x00", $maskRowBytes);
            for ($x = 0; $x < $width; ++$x) {
                $index = ($x + $y) % 2;
                if (24 === $bitCount) {
                    $row .= 0 === $index ? "\x00\x00\xFF" : "\xFF\x00\x00";
                } else {
                    $bits .= str_pad(decbin($index), $bitCount, '0', \STR_PAD_LEFT);
                }
                if ($x === $width - 1) {
                    $maskRow[$x >> 3] = chr(ord($maskRow[$x >> 3]) | (0x80 >> ($x & 7)));
                }
            }
            foreach (str_split($bits, 8) as $chunk) {
                $row .= chr((int) bindec(str_pad($chunk, 8, '0')));
            }
            $pixels .= str_pad($row, $rowBytes, "\x00");
            $mask .= $maskRow;
        }

        $header = pack('VVVvvVVVVVV', 40, $width, $height * 2, 1, $bitCount, 0, 0, 0, 0, $paletteSize, 0);
        $image = $header.$palette.$pixels.$mask;
        $entry = pack('CCCCvvVV', $width, $height, $paletteSize % 256, 0, 1, $bitCount, strlen($image), 22);

        return pack('vvv', 0, 1, 1).$entry.$image;
    }
}
