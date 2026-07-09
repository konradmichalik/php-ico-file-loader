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

namespace KonradMichalik\PhpIcoFileLoader\Tests\Model;

use InvalidArgumentException;
use KonradMichalik\PhpIcoFileLoader\Model\IconImage;
use KonradMichalik\PhpIcoFileLoader\Tests\IcoTestCase;

/**
 * IconImageTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
final class IconImageTest extends IcoTestCase
{
    public function testAssignsKnownProperties(): void
    {
        $image = new IconImage(['width' => 32, 'height' => 16, 'bitCount' => 8]);

        $this->assertSame(32, $image->width);
        $this->assertSame(16, $image->height);
        $this->assertSame(8, $image->bitCount);
    }

    public function testRejectsUnknownProperty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown property: bogus');

        new IconImage(['bogus' => 1]);
    }

    public function testConstructorFlagsPngWhenPngDataProvided(): void
    {
        $image = new IconImage(['pngData' => 'not-empty']);

        $this->assertTrue($image->isPng());
        $this->assertFalse($image->isBmp());
    }
}
