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

use DomainException;
use GdImage;
use InvalidArgumentException;
use KonradMichalik\PhpIcoFileLoader\IcoFileService;

use function count;

/**
 * IcoFileServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
final class IcoFileServiceTest extends IcoTestCase
{
    public function testExtract(): void
    {
        $service = new IcoFileService();
        $im = $service->extractIcon('./tests/assets/32bit-16px-32px-sample.ico', 64, 64);
        $this->assertImageLooksLike('32bit-64px-resize-expected.png', $im);
    }

    public function testResizedImageKeepsTransparencyWhenSaved(): void
    {
        $service = new IcoFileService();
        $im = $service->extractIcon('./tests/assets/32bit-16px-32px-sample.ico', 64, 64);

        ob_start();
        imagepng($im);
        $reloaded = imagecreatefromstring((string) ob_get_clean());

        $this->assertInstanceOf(GdImage::class, $reloaded);
        $this->assertSame(127, (imagecolorat($reloaded, 0, 0) >> 24) & 0x7F, 'transparent corner became opaque');
    }

    public function testExtractEmpty(): void
    {
        $this->expectException(DomainException::class);

        $service = new IcoFileService();
        $im = $service->extractIcon('./tests/assets/empty.ico', 64, 64);
    }

    public function testFromWithData(): void
    {
        $service = new IcoFileService();
        $data = file_get_contents('./tests/assets/32bit-16px-32px-sample.ico');
        $icon = $service->from($data);
        $this->assertNotNull($icon);

        $icon = $service->fromString($data);
        $this->assertNotNull($icon);
    }

    public function testFromWithFile(): void
    {
        $service = new IcoFileService();
        $icon = $service->from('./tests/assets/32bit-16px-32px-sample.ico');
        $this->assertNotNull($icon);
    }

    public function testInvalidFrom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $service = new IcoFileService();
        $service->from('not an icon');
    }

    public function testInvalidFromString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $service = new IcoFileService();
        $service->fromString('not an icon');
    }

    public function testInvalidFromFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $service = new IcoFileService();
        $service->fromFile('not a file');
    }

    public function testFromFileRejectsPharWrapper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream wrappers are not allowed');

        $service = new IcoFileService();
        $service->fromFile('phar://./tests/assets/32bit-16px-32px-sample.ico');
    }

    public function testFromFileRejectsRemoteUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream wrappers are not allowed');

        $service = new IcoFileService();
        $service->fromFile('https://example.com/favicon.ico');
    }

    public function testFromRejectsStreamWrapperInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream wrappers are not allowed');

        $service = new IcoFileService();
        $service->from('php://filter/read=convert.base64-encode/resource=/etc/passwd');
    }

    public function testFromRejectsBinaryDataWithNullBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IcoFileService())->from("GIF89a\x01\x00\x01\x00\x00\x00\x00;");
    }

    public function testFromRejectsOverlongPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IcoFileService())->from(str_repeat('a', \PHP_MAXPATHLEN + 1));
    }

    public function testFromRejectsDataUri(): void
    {
        $data = base64_encode(file_get_contents('./tests/assets/32bit-16px-32px-sample.ico'));

        $this->expectException(InvalidArgumentException::class);

        (new IcoFileService())->from('data:application/octet-stream;base64,'.$data);
    }

    public function testFromUsesOneMessageForMissingFileAndUnknownData(): void
    {
        $service = new IcoFileService();
        $messages = [];

        foreach (['./tests/assets/does-not-exist.ico', 'not an icon'] as $input) {
            try {
                $service->from($input);
            } catch (InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
    }

    public function testFromFileRejectsDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IcoFileService())->fromFile('./tests/assets');
    }

    public function testFromFileRejectsOversizedFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ico');
        file_put_contents($file, str_repeat("\x00", 10 * 1024 * 1024 + 1));

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('too large');

            (new IcoFileService())->fromFile($file);
        } finally {
            unlink($file);
        }
    }

    public function testFromFileMissingFileMessageIsNotDuplicated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^File could not be loaded\.$/');

        (new IcoFileService())->fromFile('./tests/assets/does-not-exist.ico');
    }

    public function testExtractIconRejectsInvalidSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new IcoFileService())->extractIcon('./tests/assets/32bit-16px-32px-sample.ico', 0, 0);
    }

    public function testIterateExample(): void
    {
        $service = new IcoFileService();
        $icon = $service->fromFile('./tests/assets/32bit-16px-32px-sample.ico');

        $count = 0;
        foreach ($icon as $image) {
            $im = $service->renderImage($image);
            $this->assertIsObject($im);
            ++$count;
        }
        $this->assertSame(count($icon), $count);
    }
}
