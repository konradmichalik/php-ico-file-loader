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

namespace KonradMichalik\PhpIcoFileLoader\Renderer;

use GdImage;
use InvalidArgumentException;
use KonradMichalik\PhpIcoFileLoader\Model\IconImage;

use function ord;
use function sprintf;
use function strlen;

/**
 * GdRenderer.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
class GdRenderer implements RendererInterface
{
    private const TRANSPARENT_WHITE_RED = 255;
    private const TRANSPARENT_WHITE_GREEN = 255;
    private const TRANSPARENT_WHITE_BLUE = 255;
    private const TRANSPARENT_ALPHA = 127;
    private const MASK_ROW_ALIGNMENT = 32;
    private const BITS_PER_BYTE = 8;
    private const LOW_NIBBLE_MASK = 0x0F;
    private const HIGH_NIBBLE_MASK = 0xF0;
    private const NIBBLE_SHIFT = 4;
    private const ALPHA_SHIFT = 24;
    private const RED_SHIFT = 16;
    private const GREEN_SHIFT = 8;

    /**
     * @param array<string, mixed>|null $opts
     *
     * @return GdImage
     */
    public function render(IconImage $img, ?array $opts = null): mixed
    {
        $opts = $this->initOptions($img, $opts);

        if ($img->isPng()) {
            $gd = $this->renderPngImage($img, $opts['background']);
        } else {
            $gd = $this->renderBmpImage($img, $opts['background']);
        }

        if ((imagesx($gd) !== $opts['w']) && (imagesy($gd) !== $opts['h'])) {
            $resized = $this->resize($gd, $opts['w'], $opts['h']);
            if (false !== $resized) {
                $gd = $resized;
            }
        }

        return $gd;
    }

    /**
     * @param array<string, mixed>|null $opts
     *
     * @return array<string, mixed>
     */
    protected function initOptions(IconImage $img, ?array $opts): array
    {
        $opts ??= [];
        $opts['w'] ??= $img->width;
        $opts['h'] ??= $img->height;
        $opts['background'] ??= null;

        return $opts;
    }

    /**
     * @return GdImage|false
     */
    protected function resize(GdImage $gd, int $w, int $h): mixed
    {
        $resized = imagescale($gd, $w, $h);
        imagedestroy($gd);

        return $resized;
    }

    protected function renderPngImage(IconImage $img, ?string $hexBackgroundColor): GdImage
    {
        $im = imagecreatefromstring($img->pngData);
        if (false === $im) {
            throw new InvalidArgumentException('Invalid PNG data');
        }
        imagesavealpha($im, true);

        if (null !== $hexBackgroundColor) {
            $gd = $this->createImage($img->width, $img->height);
            $col = $this->parseHexColor($hexBackgroundColor);
            // @phpstan-ignore-next-line argument.type
            $colVal = $this->allocateColor($gd, $col[0], $col[1], $col[2]);
            imagefilledrectangle($gd, 0, 0, $img->width, $img->height, $colVal);
            imagecopy($gd, $im, 0, 0, 0, 0, $img->width, $img->height);
            imagedestroy($im);
            $im = $gd;
        }

        return $im;
    }

    protected function renderBmpImage(IconImage $img, ?string $hexBackgroundColor = null): GdImage
    {
        $gd = $this->createImage($img->width, $img->height);

        if (null === $hexBackgroundColor) {
            imagealphablending($gd, false);
            $colVal = $this->allocateColor(
                $gd,
                self::TRANSPARENT_WHITE_RED,
                self::TRANSPARENT_WHITE_GREEN,
                self::TRANSPARENT_WHITE_BLUE,
                self::TRANSPARENT_ALPHA,
            );
            imagefilledrectangle($gd, 0, 0, $img->width, $img->height, $colVal);
            imagesavealpha($gd, true);
        } else {
            $col = $this->parseHexColor($hexBackgroundColor);
            // @phpstan-ignore-next-line argument.type
            $colVal = $this->allocateColor($gd, $col[0], $col[1], $col[2]);
            imagefilledrectangle($gd, 0, 0, $img->width, $img->height, $colVal);
        }

        // Paint pixels based on bit count
        match ($img->bitCount) {
            32 => $this->render32bit($img, $gd),
            24 => $this->render24bit($img, $gd),
            8 => $this->render8bit($img, $gd),
            4 => $this->render4bit($img, $gd),
            1 => $this->render1bit($img, $gd),
            default => throw new InvalidArgumentException(sprintf('Unsupported bit depth: %d', $img->bitCount)),
        };

        return $gd;
    }

    /**
     * @return array{int, int, int}
     */
    protected function parseHexColor(string $hexCol): array
    {
        if (!preg_match('/^\#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hexCol, $c)) {
            throw new InvalidArgumentException('Invalid hex color format');
        }

        return [(int) hexdec($c[1]), (int) hexdec($c[2]), (int) hexdec($c[3])];
    }

    private function createImage(int $width, int $height): GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(sprintf('Invalid image dimensions: %dx%d', $width, $height));
        }

        $gd = imagecreatetruecolor($width, $height);
        if (false === $gd) {
            throw new InvalidArgumentException('Failed to create GD image');
        }

        return $gd;
    }

    /**
     * Allocate a color on $gd resource. This function prevents
     * from allocating same colors on the same palette. Instead
     * if it finds that the color is already allocated, it only
     * returns the index to that color.
     * It supports alpha channel.
     *
     * @param GdImage    $gd    gd image resource
     * @param int<0,255> $red   Red component
     * @param int<0,255> $green Green component
     * @param int<0,255> $blue  Blue component
     * @param int<0,127> $alpha Alpha channel
     *
     * @return int Color index
     */
    private function allocateColor(GdImage $gd, int $red, int $green, int $blue, int $alpha = 0): int
    {
        // Clamp values to valid ranges
        $red = max(0, min(255, $red));
        $green = max(0, min(255, $green));
        $blue = max(0, min(255, $blue));
        $alpha = max(0, min(127, $alpha));

        $c = imagecolorexactalpha($gd, $red, $green, $blue, $alpha);
        if ($c >= 0) {
            return $c;
        }

        // we don't use this for calculating 32bit color values
        // @codeCoverageIgnoreStart
        $result = imagecolorallocatealpha($gd, $red, $green, $blue, $alpha);
        if (false === $result) {
            throw new InvalidArgumentException('Failed to allocate color');
        }

        return $result;
        // @codeCoverageIgnoreEnd
    }

    private function render32bit(IconImage $img, GdImage $gd): void
    {
        // 32 bits: 4 bytes per pixel [ B | G | R | ALPHA ].
        $dataSize = strlen($img->bmpData);
        $requiredSize = $img->width * $img->height * 4;

        if ($dataSize < $requiredSize) {
            throw new InvalidArgumentException(sprintf('Insufficient bitmap data: need %d bytes, got %d', $requiredSize, $dataSize));
        }

        // Unpack entire binary data once instead of ord() per pixel - much faster
        $bytes = unpack('C*', $img->bmpData);
        if (false === $bytes) {
            throw new InvalidArgumentException('Failed to unpack bitmap data');
        }

        $offset = 1; // unpack is 1-indexed
        for ($i = $img->height - 1; $i >= 0; --$i) {
            for ($j = 0; $j < $img->width; ++$j) {
                // Translate BGRA to aRGB (faster than imagecolorallocatealpha)
                $alpha7 = ((~$bytes[$offset + 3]) & 0xFF) >> 1;
                if ($alpha7 < self::TRANSPARENT_ALPHA) {
                    $col = ($alpha7 << self::ALPHA_SHIFT) |
                        ($bytes[$offset + 2] << self::RED_SHIFT) |
                        ($bytes[$offset + 1] << self::GREEN_SHIFT) |
                        $bytes[$offset];
                    imagesetpixel($gd, $j, $i, $col);
                }
                $offset += 4;
            }
        }
    }

    private function assertSufficientBitmapData(IconImage $img): void
    {
        $maskWidth = $img->width;
        if (($maskWidth % self::MASK_ROW_ALIGNMENT) > 0) {
            $maskWidth += self::MASK_ROW_ALIGNMENT - ($img->width % self::MASK_ROW_ALIGNMENT);
        }

        $required = (int) ($img->width * $img->height * $img->bitCount / self::BITS_PER_BYTE)
            + (int) ($maskWidth * $img->height / self::BITS_PER_BYTE);
        $actual = strlen($img->bmpData);

        if ($actual < $required) {
            throw new InvalidArgumentException(sprintf('Insufficient bitmap data: need %d bytes, got %d', $required, $actual));
        }
    }

    private function render24bit(IconImage $img, GdImage $gd): void
    {
        // 24 bits: 3 bytes per pixel [ B | G | R ].
        $this->assertSufficientBitmapData($img);

        $maskBits = $this->buildMaskBits($img);

        // Unpack for better performance
        $bytes = unpack('C*', $img->bmpData);
        if (false === $bytes) {
            throw new InvalidArgumentException('Failed to unpack bitmap data');
        }

        $offset = 1; // unpack is 1-indexed
        $maskpos = 0;

        for ($i = $img->height - 1; $i >= 0; --$i) {
            for ($j = 0; $j < $img->width; ++$j) {
                if ('0' === $maskBits[$maskpos]) {
                    // Translate BGR to RGB
                    $col = ($bytes[$offset + 2] << self::RED_SHIFT) |
                        ($bytes[$offset + 1] << self::GREEN_SHIFT) |
                        $bytes[$offset];
                    imagesetpixel($gd, $j, $i, $col);
                }
                $offset += 3;
                ++$maskpos;
            }
        }
    }

    private function buildMaskBits(IconImage $img): string
    {
        $width = $img->width;
        if (($width % self::MASK_ROW_ALIGNMENT) > 0) {
            $width += (self::MASK_ROW_ALIGNMENT - ($img->width % self::MASK_ROW_ALIGNMENT));
        }

        $offset = (int) ($img->width * $img->height * $img->bitCount / self::BITS_PER_BYTE);
        $totalBytes = (int) ($width * $img->height / self::BITS_PER_BYTE);
        $maskBits = '';
        $bytes = 0;
        $bytesPerLine = $img->width / self::BITS_PER_BYTE;
        $bytesToRemove = ($width - $img->width) / self::BITS_PER_BYTE;

        for ($i = 0; $i < $totalBytes; ++$i) {
            $maskBits .= str_pad(decbin(ord($img->bmpData[$offset + $i])), 8, '0', \STR_PAD_LEFT);
            ++$bytes;
            if ($bytes === $bytesPerLine) {
                $i += (int) $bytesToRemove;
                $bytes = 0;
            }
        }

        return $maskBits;
    }

    private function render8bit(IconImage $img, GdImage $gd): void
    {
        // 8 bits: 1 byte per pixel [ COLOR INDEX ].
        $this->assertSufficientBitmapData($img);

        $palette = $this->buildPalette($img, $gd);
        $maskBits = $this->buildMaskBits($img);

        // Unpack for better performance
        $bytes = unpack('C*', $img->bmpData);
        if (false === $bytes) {
            throw new InvalidArgumentException('Failed to unpack bitmap data');
        }

        $offset = 0;
        $byteOffset = 1; // unpack is 1-indexed
        for ($i = $img->height - 1; $i >= 0; --$i) {
            for ($j = 0; $j < $img->width; ++$j) {
                if ('0' === $maskBits[$offset]) {
                    $color = $bytes[$byteOffset];
                    imagesetpixel($gd, $j, $i, $palette[$color]);
                }
                ++$offset;
                ++$byteOffset;
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function buildPalette(IconImage $img, GdImage $gd): array
    {
        if (24 === $img->bitCount) {
            return [];
        }

        $palette = [];
        for ($i = 0; $i < $img->colorCount; ++$i) {
            $red = $img->palette[$i]['red'];
            $green = $img->palette[$i]['green'];
            $blue = $img->palette[$i]['blue'];
            $alpha = (int) round($img->palette[$i]['reserved'] / 255 * self::TRANSPARENT_ALPHA);

            // @phpstan-ignore-next-line argument.type
            $palette[$i] = $this->allocateColor($gd, $red, $green, $blue, $alpha);
        }

        return $palette;
    }

    private function render4bit(IconImage $img, GdImage $gd): void
    {
        // 4 bits: half byte/nibble per pixel [ COLOR INDEX ].
        $this->assertSufficientBitmapData($img);

        $palette = $this->buildPalette($img, $gd);
        $maskBits = $this->buildMaskBits($img);

        // Unpack for better performance
        $bytes = unpack('C*', $img->bmpData);
        if (false === $bytes) {
            throw new InvalidArgumentException('Failed to unpack bitmap data');
        }

        $byteOffset = 1; // unpack is 1-indexed
        $maskoffset = 0;
        for ($i = $img->height - 1; $i >= 0; --$i) {
            for ($j = 0; $j < $img->width; $j += 2) {
                $colorByte = $bytes[$byteOffset];
                $lowNibble = $colorByte & self::LOW_NIBBLE_MASK;
                $highNibble = ($colorByte & self::HIGH_NIBBLE_MASK) >> self::NIBBLE_SHIFT;

                if ('0' === $maskBits[$maskoffset++]) {
                    imagesetpixel($gd, $j, $i, $palette[$highNibble]);
                }

                if ('0' === $maskBits[$maskoffset++]) {
                    imagesetpixel($gd, $j + 1, $i, $palette[$lowNibble]);
                }
                ++$byteOffset;
            }
        }
    }

    private function render1bit(IconImage $img, GdImage $gd): void
    {
        // 1 bit: 1 bit per pixel (2 colors, usually black&white) [ COLOR INDEX ].
        $this->assertSufficientBitmapData($img);

        $palette = $this->buildPalette($img, $gd);
        $maskBits = $this->buildMaskBits($img);

        $colorbits = '';
        $total = strlen($img->bmpData);
        for ($i = 0; $i < $total; ++$i) {
            $colorbits .= str_pad(decbin(ord($img->bmpData[$i])), 8, '0', \STR_PAD_LEFT);
        }

        $offset = 0;
        for ($i = $img->height - 1; $i >= 0; --$i) {
            for ($j = 0; $j < $img->width; ++$j) {
                if ('0' === $maskBits[$offset]) {
                    imagesetpixel($gd, $j, $i, $palette[(int) $colorbits[$offset]]);
                }
                ++$offset;
            }
        }
    }
}
