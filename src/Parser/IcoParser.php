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

namespace KonradMichalik\PhpIcoFileLoader\Parser;

use InvalidArgumentException;
use KonradMichalik\PhpIcoFileLoader\Model\{Icon, IconImage};

use function count;
use function sprintf;
use function strlen;

/**
 * IcoParser.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
class IcoParser implements ParserInterface
{
    private const PNG_SIGNATURE = 0x474E5089;
    private const ICO_HEADER_SIZE = 6;
    private const ICO_DIR_ENTRY_SIZE = 16;
    private const BMP_INFO_HEADER_SIZE = 40;
    private const ICO_TYPE_ICON = 1;
    private const ICO_RESERVED = 0;
    private const DEFAULT_COLOR_COUNT = 256;
    private const DEFAULT_DIMENSION = 256;
    private const MAX_PNG_PIXELS = 16777216; // 4096 x 4096

    public function isSupportedBinaryString(string $data): bool
    {
        return null !== $this->parseIconDir($data) || $this->isPNG($data);
    }

    public function parse(string $data): Icon
    {
        if ($this->isPNG($data)) {
            return $this->parsePNGAsIco($data);
        }

        return $this->parseICO($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseIconDir(string $data): ?array
    {
        if (strlen($data) < self::ICO_HEADER_SIZE) {
            return null;
        }

        $icondir = unpack('SReserved/SType/SCount', $data);
        if (false === $icondir) {
            return null;
        }

        if (self::ICO_RESERVED === $icondir['Reserved'] && self::ICO_TYPE_ICON === $icondir['Type']) {
            return $icondir;
        }

        return null;
    }

    private function isPNG(string $data): bool
    {
        if (strlen($data) < 4) {
            return false;
        }

        $signature = unpack('LFourCC', $data);
        if (false === $signature) {
            return false;
        }

        return self::PNG_SIGNATURE === $signature['FourCC'];
    }

    private function parseICO(string $data): Icon
    {
        $icondir = $this->parseIconDir($data);
        if (!$icondir) {
            throw new InvalidArgumentException('Invalid ICO file format');
        }

        $data = substr($data, self::ICO_HEADER_SIZE);

        $icon = new Icon();
        $data = $this->parseIconDirEntries($icon, $data, $icondir['Count']);

        foreach ($icon as $iconImage) {
            if ($iconImage->fileOffset < 0) {
                throw new InvalidArgumentException('Invalid image offset in icon directory entry');
            }

            if ($this->isPNG(substr($data, $iconImage->fileOffset, 4))) {
                $this->parsePng($iconImage, $data);
            } else {
                $this->parseBmp($iconImage, $data);
            }
        }

        return $icon;
    }

    private function parsePNGAsIco(string $data): Icon
    {
        $info = getimagesizefromstring($data);
        if (false !== $info && ($info[0] * $info[1]) > self::MAX_PNG_PIXELS) {
            throw new InvalidArgumentException(sprintf('PNG dimensions %dx%d exceed the maximum allowed size of %d pixels', $info[0], $info[1], self::MAX_PNG_PIXELS));
        }

        $png = imagecreatefromstring($data);
        if (false === $png) {
            throw new InvalidArgumentException('Invalid PNG data');
        }
        $w = imagesx($png);
        $h = imagesy($png);
        $bits = imageistruecolor($png) ? 32 : 8;
        imagedestroy($png);

        $icoDirEntry = [
            'width' => $w,
            'height' => $h,
            'bitCount' => $bits,
        ];

        $image = new IconImage($icoDirEntry);
        $image->setPngFile($data);

        $icon = new Icon();
        $icon[] = $image;

        return $icon;
    }

    private function parseIconDirEntries(Icon $icon, string $data, int $count): string
    {
        for ($i = 0; $i < $count; ++$i) {
            if (strlen($data) < self::ICO_DIR_ENTRY_SIZE) {
                break;
            }

            $icoDirEntry = unpack(
                'Cwidth/Cheight/CcolorCount/Creserved/Splanes/SbitCount/LsizeInBytes/LfileOffset',
                $data,
            );
            if (false === $icoDirEntry) {
                break;
            }

            // Adjust file offset to be relative to image data (after all headers)
            $icoDirEntry['fileOffset'] -= ($count * self::ICO_DIR_ENTRY_SIZE) + self::ICO_HEADER_SIZE;

            // ICO format uses 0 to represent 256 for dimensions and color count
            if (0 === $icoDirEntry['colorCount']) {
                $icoDirEntry['colorCount'] = self::DEFAULT_COLOR_COUNT;
            }
            if (0 === $icoDirEntry['width']) {
                $icoDirEntry['width'] = self::DEFAULT_DIMENSION;
            }
            if (0 === $icoDirEntry['height']) {
                $icoDirEntry['height'] = self::DEFAULT_DIMENSION;
            }

            $entry = new IconImage($icoDirEntry);
            $icon[] = $entry;

            $data = substr($data, self::ICO_DIR_ENTRY_SIZE);
        }

        return $data;
    }

    private function parsePng(IconImage $entry, string $data): void
    {
        $dataLength = strlen($data);
        $requiredLength = $entry->fileOffset + $entry->sizeInBytes;

        if ($requiredLength > $dataLength) {
            throw new InvalidArgumentException(sprintf('Invalid PNG data: required %d bytes, but only %d available', $requiredLength, $dataLength));
        }

        $png = substr($data, $entry->fileOffset, $entry->sizeInBytes);
        $entry->setPngFile($png);
    }

    private function parseBmp(IconImage $entry, string $data): void
    {
        if (strlen($data) < $entry->fileOffset + self::BMP_INFO_HEADER_SIZE) {
            throw new InvalidArgumentException('Invalid BMP data: insufficient data for header');
        }

        $bitmapInfoHeader = unpack(
            'LSize/LWidth/LHeight/SPlanes/SBitCount/LCompression/LImageSize/'.
            'LXpixelsPerM/LYpixelsPerM/LColorsUsed/LColorsImportant',
            substr($data, $entry->fileOffset, self::BMP_INFO_HEADER_SIZE),
        );

        if (false === $bitmapInfoHeader) {
            throw new InvalidArgumentException('Failed to parse BMP header');
        }

        $entry->setBitmapInfoHeader($bitmapInfoHeader);

        match ($entry->bitCount) {
            32, 24 => $this->parseTrueColorImageData($entry, $data),
            8, 4, 1 => $this->parsePaletteImageData($entry, $data),
            default => null,
        };
    }

    private function parseTrueColorImageData(IconImage $entry, string $data): void
    {
        $length = $entry->bmpHeaderWidth * $entry->bmpHeaderHeight * ($entry->bitCount / 8);
        $offset = $entry->fileOffset + $entry->bmpHeaderSize;

        // Note: ICO files include mask bits after the color data, so we don't validate total size here
        // The mask data will be part of the remaining data in the buffer
        $bmpData = substr($data, $offset, (int) $length);
        $entry->setBitmapData($bmpData);
    }

    private function parsePaletteImageData(IconImage $entry, string $data): void
    {
        $paletteSize = $entry->colorCount * 4;
        $paletteOffset = $entry->fileOffset + $entry->bmpHeaderSize;

        // Extract and parse palette data efficiently using unpack
        $paletteData = substr($data, $paletteOffset, $paletteSize);

        if (strlen($paletteData) < $paletteSize) {
            throw new InvalidArgumentException(sprintf('Invalid palette data: required %d bytes, but only %d available', $paletteSize, strlen($paletteData)));
        }

        $paletteBytes = unpack('C*', $paletteData);

        if (false === $paletteBytes) {
            throw new InvalidArgumentException('Failed to parse palette data');
        }

        // Parse palette entries (BGRA format)
        for ($j = 0; $j < $entry->colorCount; ++$j) {
            $offset = $j * 4 + 1; // unpack is 1-indexed
            $entry->addToBmpPalette(
                $paletteBytes[$offset + 2],  // red
                $paletteBytes[$offset + 1],  // green
                $paletteBytes[$offset],      // blue
                $paletteBytes[$offset + 3],   // alpha
            );
        }

        // Parse bitmap data
        // Note: ICO files include mask bits after the color data, which is included in this calculation
        $length = $entry->bmpHeaderWidth * $entry->bmpHeaderHeight * (1 + $entry->bitCount) / $entry->bitCount;
        $bmpDataOffset = $paletteOffset + $paletteSize;

        $bmpData = substr($data, $bmpDataOffset, (int) $length);
        $entry->setBitmapData($bmpData);
    }
}
