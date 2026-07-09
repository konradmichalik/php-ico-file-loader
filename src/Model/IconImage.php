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

namespace KonradMichalik\PhpIcoFileLoader\Model;

use InvalidArgumentException;

use function sprintf;

/**
 * IconImage.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
class IconImage
{
    private const ALLOWED_PROPERTIES = [
        'width' => true, 'height' => true, 'colorCount' => true, 'reserved' => true,
        'planes' => true, 'bitCount' => true, 'sizeInBytes' => true, 'fileOffset' => true,
        'bmpHeaderSize' => true, 'bmpHeaderWidth' => true, 'bmpHeaderHeight' => true,
        'pngData' => true, 'bmpData' => true, 'palette' => true,
    ];
    public int $width = 0;
    public int $height = 0;
    public int $colorCount = 0;
    public int $reserved = 0;
    public int $planes = 0;
    public int $bitCount = 0;
    public int $sizeInBytes = 0;
    public int $fileOffset = 0;
    public int $bmpHeaderSize = 0;
    public int $bmpHeaderWidth = 0;
    public int $bmpHeaderHeight = 0;
    public string $pngData = '';
    public string $bmpData = '';

    /**
     * @var array<int, array{red: int, green: int, blue: int, reserved: int}>
     */
    public array $palette = [];

    private bool $isPng = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        foreach ($data as $name => $value) {
            if (!isset(self::ALLOWED_PROPERTIES[$name])) {
                throw new InvalidArgumentException(sprintf('Unknown property: %s', $name));
            }
            $this->$name = $value;
        }

        if (!empty($this->pngData)) {
            $this->isPng = true;
        }
    }

    public function getDescription(): string
    {
        return sprintf(
            '%dx%d pixel %s @ %d bits/pixel',
            $this->width,
            $this->height,
            $this->isPng() ? 'PNG' : 'BMP',
            $this->bitCount,
        );
    }

    public function setPngFile(string $pngData): void
    {
        $this->pngData = $pngData;
        $this->isPng = !empty($pngData);
    }

    public function isPng(): bool
    {
        return $this->isPng;
    }

    public function isBmp(): bool
    {
        return !$this->isPng;
    }

    /**
     * @param array<string, mixed> $bmpInfo
     */
    public function setBitmapInfoHeader(array $bmpInfo): void
    {
        $this->bitCount = $bmpInfo['BitCount'];

        $this->bmpHeaderWidth = $bmpInfo['Width'];
        $this->bmpHeaderHeight = $bmpInfo['Height'];
        $this->bmpHeaderSize = $bmpInfo['Size'];
    }

    public function setBitmapData(string $bmpData): void
    {
        $this->bmpData = $bmpData;
    }

    public function addToBmpPalette(int $r, int $g, int $b, int $reserved): void
    {
        $this->palette[] = ['red' => $r, 'green' => $g, 'blue' => $b, 'reserved' => $reserved];
    }
}
