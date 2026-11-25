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
use function intdiv;
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
    private const MAX_ENTRIES = 256;
    private const MAX_BMP_DIMENSION = 65535;
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

        $icondir = unpack('vReserved/vType/vCount', $data);
        // @codeCoverageIgnoreStart
        if (false === $icondir) {
            return null;
        }
        // @codeCoverageIgnoreEnd

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

        $signature = unpack('VFourCC', $data);
        // @codeCoverageIgnoreStart
        if (false === $signature) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return self::PNG_SIGNATURE === $signature['FourCC'];
    }

    private function parseICO(string $data): Icon
    {
        $icondir = $this->parseIconDir($data);
        if (!$icondir) {
            throw new InvalidArgumentException('Invalid ICO file format');
        }

        $count = $icondir['Count'];
        $directoryEnd = self::ICO_HEADER_SIZE + $count * self::ICO_DIR_ENTRY_SIZE;
        if ($count > self::MAX_ENTRIES || $directoryEnd > strlen($data)) {
            throw new InvalidArgumentException(sprintf('Invalid icon directory: %d entries do not fit into %d bytes (maximum %d entries)', $count, strlen($data), self::MAX_ENTRIES));
        }

        $icon = new Icon();
        $this->parseIconDirEntries($icon, $data, $count);

        $declaredBytes = 0;
        foreach ($icon as $iconImage) {
            if ($iconImage->fileOffset < $directoryEnd) {
                throw new InvalidArgumentException('Invalid image offset in icon directory entry');
            }

            // Entries of a valid icon do not overlap, so overlapping ones would only multiply memory use.
            // Entries that exceed the file are rejected with a specific message when they are parsed.
            if ($iconImage->fileOffset + $iconImage->sizeInBytes <= strlen($data)) {
                $declaredBytes += $iconImage->sizeInBytes;
            }
            if ($declaredBytes > strlen($data)) {
                throw new InvalidArgumentException('Invalid icon directory: entries declare more data than the file contains');
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

    private function parseIconDirEntries(Icon $icon, string $data, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $icoDirEntry = unpack(
                'Cwidth/Cheight/CcolorCount/Creserved/vplanes/vbitCount/VsizeInBytes/VfileOffset',
                $data,
                self::ICO_HEADER_SIZE + $i * self::ICO_DIR_ENTRY_SIZE,
            );
            // @codeCoverageIgnoreStart
            if (false === $icoDirEntry) {
                break;
            }
            // @codeCoverageIgnoreEnd

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

            $icon[] = new IconImage($icoDirEntry);
        }
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
        $dataLength = strlen($data);
        $requiredLength = $entry->fileOffset + $entry->sizeInBytes;

        if ($requiredLength > $dataLength) {
            throw new InvalidArgumentException(sprintf('Invalid BMP data: required %d bytes, but only %d available', $requiredLength, $dataLength));
        }

        $entryData = substr($data, $entry->fileOffset, $entry->sizeInBytes);

        if (strlen($entryData) < self::BMP_INFO_HEADER_SIZE) {
            throw new InvalidArgumentException('Invalid BMP data: insufficient data for header');
        }

        $bitmapInfoHeader = unpack(
            'VSize/VWidth/VHeight/vPlanes/vBitCount/VCompression/VImageSize/'.
            'VXpixelsPerM/VYpixelsPerM/VColorsUsed/VColorsImportant',
            $entryData,
        );

        // @codeCoverageIgnoreStart
        if (false === $bitmapInfoHeader) {
            throw new InvalidArgumentException('Failed to parse BMP header');
        }
        // @codeCoverageIgnoreEnd

        $entry->setBitmapInfoHeader($bitmapInfoHeader);

        if ($entry->bmpHeaderSize < self::BMP_INFO_HEADER_SIZE || $entry->bmpHeaderSize > strlen($entryData)) {
            throw new InvalidArgumentException(sprintf('Invalid BMP data: header size %d', $entry->bmpHeaderSize));
        }
        if ($entry->bmpHeaderWidth > self::MAX_BMP_DIMENSION || $entry->bmpHeaderHeight > self::MAX_BMP_DIMENSION) {
            throw new InvalidArgumentException(sprintf('Invalid BMP data: dimensions %dx%d', $entry->bmpHeaderWidth, $entry->bmpHeaderHeight));
        }

        match ($entry->bitCount) {
            32, 24 => $entry->setBitmapData(substr($entryData, $entry->bmpHeaderSize, $this->bitmapDataLength($entry))),
            8, 4, 1 => $this->parsePaletteImageData($entry, $entryData),
            default => null,
        };
    }

    private function parsePaletteImageData(IconImage $entry, string $entryData): void
    {
        $paletteSize = $entry->colorCount * 4;
        $paletteOffset = $entry->bmpHeaderSize;

        $paletteData = substr($entryData, $paletteOffset, $paletteSize);

        if (strlen($paletteData) < $paletteSize) {
            throw new InvalidArgumentException(sprintf('Invalid palette data: required %d bytes, but only %d available', $paletteSize, strlen($paletteData)));
        }

        $paletteBytes = unpack('C*', $paletteData);

        // @codeCoverageIgnoreStart
        if (false === $paletteBytes) {
            throw new InvalidArgumentException('Failed to parse palette data');
        }
        // @codeCoverageIgnoreEnd

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

        $entry->setBitmapData(substr($entryData, $paletteOffset + $paletteSize, $this->bitmapDataLength($entry)));
    }

    /**
     * Pixel data followed by the AND mask. Rows are padded to 4 bytes, the header height covers both parts.
     */
    private function bitmapDataLength(IconImage $entry): int
    {
        $rowBytes = intdiv($entry->bmpHeaderWidth * $entry->bitCount + 31, 32) * 4;
        $maskRowBytes = intdiv($entry->bmpHeaderWidth + 31, 32) * 4;

        return intdiv($entry->bmpHeaderHeight, 2) * ($rowBytes + $maskRowBytes);
    }
}
