# S3

[![codecov](https://codecov.io/gh/Innmind/S3/branch/develop/graph/badge.svg)](https://codecov.io/gh/Innmind/S3)
[![Build Status](https://github.com/Innmind/S3/workflows/CI/badge.svg?branch=master)](https://github.com/Innmind/S3/actions?query=workflow%3ACI)
[![Type Coverage](https://shepherd.dev/github/Innmind/S3/coverage.svg)](https://shepherd.dev/github/Innmind/S3)

Very simple abstraction on top of the [`aws-sdk`](https://packagist.org/packages/aws/aws-sdk-php) to work with any S3 bucket.

## Installation

```sh
composer require innmind/s3
```

## Usage

```php
use Innmind\S3\Bucket;
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\OperatingSystem\Factory;

$os = Factory::build();

$bucket = Bucket\OverHttp::locatedAt(
    $os->remote()->http(),
    Url::of('https://acces_key:acces_secret@s3.region-name.scw.cloud/bucket-name/?region=region-name'),
);

$file = $bucket->get(Path::of('some-file.txt'));
// $file is an instance of Innmind\Stream\Readable
$bucket->upload(Path::of('some-other-name.txt'), $file); // essentially this will copy the file
```

To simplify some usage you can use the filesystem adapter on top of the bucket interface. Here's an example to upload a directory to a bucket:

```php
use Innmind\S3\Filesystem;

$data = $os->filsystem()->mount(Path::of('/var/data'));
$s3 = new Filesystem\Adapter($bucket);
$s3->add($data->get('images'));
```
