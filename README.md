<div align="center">

# Php Ico File Loader

[![Coverage](https://img.shields.io/coverallsCoverage/github/konradmichalik/php-ico-file-loader?logo=coveralls)](https://coveralls.io/github/konradmichalik/php-ico-file-loader)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/php-ico-file-loader/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/php-ico-file-loader/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/php-ico-file-loader/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/php-ico-file-loader/actions/workflows/tests.yml)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/php-ico-file-loader/php?logo=php)](https://packagist.org/packages/konradmichalik/php-ico-file-loader)

</div>

This package enables loading and converting `.ico` files within PHP applications.
It requires no dependencies except for [gd](http://php.net/manual/en/book.image.php) for image rendering.


## 🔥 Installation

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/php-ico-file-loader?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/php-ico-file-loader)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/php-ico-file-loader?color=brightgreen)](https://packagist.org/packages/konradmichalik/php-ico-file-loader)

```bash
composer require konradmichalik/php-ico-file-loader
```

## ⚡ Usage

```php
$loader = new KonradMichalik\PhpIcoFileLoeader\Parser\IcoFileService;
$im = $loader->extractIcon('/path/to/icon.ico', 32, 32);

imagepng($im, '/path/to/output.png');
```

## 💛 Acknowledgements

This project is a fork and further development of [`lordelph/icofileloader`](https://github.com/lordelph/icofileloader).

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [MIT](LICENSE).
