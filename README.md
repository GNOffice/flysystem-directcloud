# Flysystem adapter for the DirectCloud API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gn-office/flysystem-directcloud.svg?style=flat-square)](https://packagist.org/packages/gn-office/flysystem-directcloud)
[![Total Downloads](https://img.shields.io/packagist/dt/gn-office/flysystem-directcloud.svg?style=flat-square)](https://packagist.org/packages/gn-office/flysystem-directcloud)

This package contains a [Flysystem](https://flysystem.thephpleague.com/) adapter for DirectCloud. Under the hood, the [DirectCloud API](https://directcloud.jp/api_reference/) is used.

## Installation

You can install the package via composer:

``` bash
composer require gn-office/flysystem-directcloud
```

## Usage

The first thing you need to do is to get an authorization information at DirectCloud. You'll find more info at [DirectCloud API Documentation](https://directcloud.jp/api_reference/detail/%E3%83%A6%E3%83%BC%E3%82%B6%E3%83%BC/Auth).

```php
use League\Flysystem\Filesystem;
use GNOffice\DirectCloud\Client;
use GNOffice\FlysystemDirectCloud\DirectCloudAdapter;

$client = new Client([$service, $service_key, $code, $id, $password]);
// or
$client = new Client([$service, $service_key, $access_key]);

$adapter = new DirectCloudAdapter($client);

$filesystem = new Filesystem($adapter);
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
