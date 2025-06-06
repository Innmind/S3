# S3

[![codecov](https://codecov.io/gh/Innmind/S3/branch/develop/graph/badge.svg)](https://codecov.io/gh/Innmind/S3)
[![Build Status](https://github.com/Innmind/S3/workflows/CI/badge.svg?branch=master)](https://github.com/Innmind/S3/actions?query=workflow%3ACI)
[![Type Coverage](https://shepherd.dev/github/Innmind/S3/coverage.svg)](https://shepherd.dev/github/Innmind/S3)

Minimalist abstraction to work with any S3 bucket.

## Installation

```sh
composer require innmind/s3
```

## Usage

```php
use Innmind\S3\{
    Factory,
    Region,
};
use Innmind\OperatingSystem\Factory as OSFactory;
use Innmind\Filesystem\File\Content;
use Innmind\Url\{
    Url,
    Path,
};

$os = OSFactory::build();

$bucket = Factory::of($os)->build(
    Url::of('https://acces_key:acces_secret@bucket-name.s3.region-name.scw.cloud/'),
    Region::of('region-name'),
);

$file = $bucket->get(Path::of('some-file.txt'))->match(
    static fn(Content $file) => $file,
    static fn() => throw new \RuntimeException('File does not exist'),
);
$bucket->upload(Path::of('some-other-name.txt'), $file)->match( // essentially this will copy the file
    static fn() => null, // everything ok
    static fn(\Throwable $e) => throw $e,
);
```

To simplify some usage you can use the filesystem adapter on top of the bucket interface. Here's an example to upload a directory to a bucket:

```php
use Innmind\S3\Filesystem;
use Innmind\Filesystem\Name;

$data = $os->filsystem()->mount(Path::of('/var/data'));
$s3 = Filesystem::of($bucket);
$data
    ->get(Name::of('images'))
    ->match(
        static fn($file) => $s3->add($file)->unwrap(),
        static fn() => null, // do something if there is no images
    );
```

> [!WARNING]
> A bucket can contain a file and a directory with the same name. This is not supported by `innmind/filesystem` adapters. This means that if you use `Innmind\S3\Filesystem\Adapter` on a bucket where there is a file and a directory with the same name it will result in unexpected behaviour or an exception.
