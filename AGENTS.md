# AGENTS.md

## Project overview

`konradmichalik/php-ico-file-loader` is a PHP library for reading and converting `.ico` files, mainly website favicons. It supports 1, 4, 8, 24 and 32-bit icons, both BMP-based and PNG-embedded. It is a fork and further development of `lordelph/icofileloader`. Composer package type `library`.

- PHP `~8.1` to `~8.5` (Composer platform pinned to 8.1), `ext-gd` is the only runtime dependency
- Namespace `KonradMichalik\PhpIcoFileLoader\` maps to `src/`, tests use `KonradMichalik\PhpIcoFileLoader\Tests\` in `tests/`
- MIT licensed

## Structure

- `src/IcoFileService.php`: main entry point. Takes a `RendererInterface` (default `GdRenderer`) and a `ParserInterface` (default `IcoParser`) via constructor. `extractIcon()` is the one-shot method, `from()`, `fromFile()` and `fromString()` return `Icon` objects, `renderImage()` renders one `IconImage`
- `src/Parser/`: `ParserInterface`, `IcoParser` (binary ICONDIR parsing, BMP or PNG dispatch by signature, standalone PNG handled as a single-image icon)
- `src/Renderer/`: `RendererInterface`, `GdRenderer` (GD rendering, optional hex `background` option, resizing)
- `src/Model/`: `Icon` (collection with `findBest()` and `findBestForSize()`) and `IconImage` (throws `InvalidArgumentException` on unknown properties)
- `tests/`: mirrors `src/` (`Model/`, `Parser/`, `Renderer/`), `assets/` holds `.ico` samples and expected PNG fixtures, `IcoTestCase.php` is the shared base class
- `.github/workflows/`: `cgl.yml`, `tests.yml`, `release.yml`

## Development commands

```bash
composer install

composer lint            # lint:composer, lint:editorconfig, lint:php
composer fix             # fix:composer, fix:editorconfig, fix:php
composer sca             # phpstan analyse --memory-limit=2G
composer test            # phpunit without coverage
composer test:coverage   # phpunit with coverage, HTML report in .build/coverage/html
composer migration       # rector process -c rector.php

./vendor/bin/phpunit --filter testExtract tests/IcoFileServiceTest.php   # single test
```

## Testing

- PHPUnit 10, 11 or 12, config in `phpunit.xml`, bootstrap `tests/bootstrap.php`, deprecations fail the run
- Test classes extend `IcoTestCase`: `parseIcon($asset)` parses a fixture from `tests/assets/`, `assertImageLooksLike($expected, $im)` byte-compares a rendered PNG against a fixture. A missing fixture is generated and the test is skipped, delete a fixture to regenerate it
- CI (`.github/workflows/tests.yml`) calls a reusable workflow from `konradmichalik/reusable-github-actions` on every push, across PHP 8.1 to 8.5 with `highest` and `lowest` dependencies. `cgl.yml` calls the matching reusable CGL workflow, whose steps are defined in that repository

## Code style and linting

- PHP CS Fixer with `konradmichalik/php-cs-fixer-preset` and `php-doc-block-header-fixer` (`.php-cs-fixer.php`)
- PHPStan at level 8 over `src` and `tests`, with `phpstan-phpunit` and `phpstan-symfony`
- Rector (`rector.php`) targets PHP 8.1 via `UP_TO_PHP_81`. Run it on demand via `composer migration`, it is not part of `composer lint`
- `composer normalize` keeps `composer.json` sorted (`ergebnis/composer-normalize`)
- EditorConfig checked with `ec` (`armin/editorconfig-cli`)
- PHP files use `declare(strict_types=1);`
- Parser and renderer are swappable through the `IcoFileService` constructor, keep new behavior behind those interfaces

## Git workflow

- Commit format: `<type>: <description>` with type one of `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`
- Describe the change, not what prompted it
- No co-author trailers
- Pull requests should describe the change and ideally reference an issue
