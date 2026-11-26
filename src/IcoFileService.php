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

namespace KonradMichalik\PhpIcoFileLoader;

use DomainException;
use InvalidArgumentException;
use KonradMichalik\PhpIcoFileLoader\Model\{Icon, IconImage};
use KonradMichalik\PhpIcoFileLoader\Parser\{IcoParser, ParserInterface};
use KonradMichalik\PhpIcoFileLoader\Renderer\{GdRenderer, RendererInterface};

use function sprintf;
use function str_contains;
use function strlen;

/**
 * IcoFileService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license MIT
 */
class IcoFileService
{
    private const MAX_FILE_SIZE = 10485760;
    private const INVALID_ICON_MESSAGE = 'Invalid ICO file format';

    /**
     * IcoFileService constructor.
     *
     * You can inject alternative implementations of the renderer or parser, but for most
     * typical uses, you can accept the defaults.
     */
    public function __construct(protected RendererInterface $renderer = new GdRenderer(), protected ParserInterface $parser = new IcoParser()) {}

    /**
     * This is a useful one-stop function for obtaining the best possible icon of a particular size from an .ico file.
     *
     * As icons are often hand-crafted to look good at particular sizes, this will try to use the best quality image
     * in the icon at the required size. If it can't be found, then it will resize the largest icon it can find.
     *
     * This will either return a valid image, or will throw an \InvalidArgumentException in the event of the file
     * being unreadable.
     *
     * @param string                    $dataOrFile Either a filename to a .ico file, or binary data from an .ico file in a string
     * @param int                       $w          Desired width. The class tries to locate the best quality image at this size, but
     *                                              if not found, the largest available icon will be used and resized to fit.
     * @param int                       $h          desired height - as icons are usually square, this should be same as $w
     * @param array<string, mixed>|null $opts       Array of renderer options. The built in renderer supports an optional 'background'
     *                                              element in this array. Normally, the result will use alpha transparency, but you can
     *                                              pass a hex colour to choose the colour of the transparent area instead, e.g.
     *                                              ['background=>'#ffffff'] for a white background.
     *
     * @return mixed The built in renderer will return a gd image resource, which you could save with
     *               the gd function imagepng(), for example. If you swap in an alternative renderer,
     *               the result is whatever that renderer returns.
     *
     * @throws DomainException          if icon does not contain any images
     * @throws InvalidArgumentException if file is not found or is invalid
     */
    public function extractIcon(string $dataOrFile, int $w, int $h, ?array $opts = null): mixed
    {
        $icon = $this->from($dataOrFile);
        $image = $icon->findBestForSize($w, $h);
        if (null === $image) {
            // nothing at our required size, so we'll find the highest quality icon
            $image = $icon->findBest();
            if (null === $image) {
                throw new DomainException('Icon does not contain any images.');
            }
        }

        return $this->renderImage($image, $w, $h, $opts);
    }

    /**
     * Renders an IconImage at a desired width and height.
     *
     * @param IconImage                 $image image obtained from an Icon object
     * @param int|null                  $w     desired width - if null, width of IconImage is used
     * @param int|null                  $h     desired height - if null, height of IconImage is used
     * @param array<string, mixed>|null $opts  Array of renderer options. The built in renderer supports an optional 'background'
     *                                         element in this array. Normally, the result will use alpha transparency, but you can
     *                                         pass a hex colour to choose the colour of the transparent area instead, e.g.
     *                                         ['background=>'#ffffff'] for a white background.
     *
     * @return mixed The built in renderer will return a gd image resource, which you could save with
     *               the gd function imagepng(), for example. If you swap in an alternative renderer,
     *               the result is whatever that renderer returns.
     *
     * @throws InvalidArgumentException if IconImage or options are invalid
     */
    public function renderImage(IconImage $image, ?int $w = null, ?int $h = null, ?array $opts = null): mixed
    {
        $opts ??= [];
        $opts['w'] = $w;
        $opts['h'] = $h;

        return $this->renderer->render($image, $opts);
    }

    /**
     * Parses a .ico file from a pathname or binary data string and return an Icon object.
     *
     * This is a useful lower level member which can be used to inspect an icon before
     * rendering a particular image within it with renderImage.
     *
     * Data that is not an ICO or PNG is only treated as a path if it can be one. Pass untrusted
     * bytes to fromString() so that they are never interpreted as a path.
     *
     * @param string $dataOrFile either filename or binary data
     *
     * @throws InvalidArgumentException if file is not found or invalid
     */
    public function from(string $dataOrFile): Icon
    {
        if ($this->parser->isSupportedBinaryString($dataOrFile)) {
            return $this->parser->parse($dataOrFile);
        }

        if (str_contains($dataOrFile, "\0") || strlen($dataOrFile) > \PHP_MAXPATHLEN) {
            throw new InvalidArgumentException(self::INVALID_ICON_MESSAGE);
        }

        $this->assertNoStreamWrapper($dataOrFile);

        if (!is_file($dataOrFile)) {
            throw new InvalidArgumentException(self::INVALID_ICON_MESSAGE);
        }

        return $this->fromFile($dataOrFile);
    }

    /**
     * Loads icon from a local file path.
     *
     * Stream wrappers (e.g. phar://, php://, http://) are rejected to avoid SSRF,
     * local file disclosure and phar deserialization. To load a remote icon, fetch
     * the bytes yourself and pass them to fromString().
     *
     * @param string $file local filesystem path to a .ico file
     *
     * @throws InvalidArgumentException if the path uses a stream wrapper, or the file is not found, too large or invalid
     */
    public function fromFile(string $file): Icon
    {
        $this->assertNoStreamWrapper($file);

        if (!is_file($file)) {
            throw new InvalidArgumentException('File could not be loaded.');
        }

        $data = @file_get_contents($file, false, null, 0, self::MAX_FILE_SIZE + 1);
        if (false === $data) {
            throw new InvalidArgumentException('File could not be loaded.');
        }
        if (strlen($data) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(sprintf('File is too large, the maximum is %d bytes.', self::MAX_FILE_SIZE));
        }

        return $this->parser->parse($data);
    }

    /**
     * Loads icon from string.
     *
     * @param string $data binary data string containing a .ico file
     *
     * @throws InvalidArgumentException if file is not found or invalid
     */
    public function fromString(string $data): Icon
    {
        return $this->parser->parse($data);
    }

    private function assertNoStreamWrapper(string $path): void
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
            throw new InvalidArgumentException('Stream wrappers are not allowed; pass a local file path or the binary data directly.');
        }
    }
}
