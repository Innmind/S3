# S3

| `develop` |
|-----------|
| [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Innmind/S3/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/S3/?branch=develop) |
| [![Code Coverage](https://scrutinizer-ci.com/g/Innmind/S3/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/S3/?branch=develop) |
| [![Build Status](https://scrutinizer-ci.com/g/Innmind/S3/badges/build.png?b=develop)](https://scrutinizer-ci.com/g/Innmind/S3/build-status/develop) |

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

$bucket = Bucket\OverHttp::locatedAt(
    Url::fromString('https://acces_key:acces_secret@s3.region-name.scw.cloud/bucket-name?region=region-name')
);

$file = $bucket->get(new Path('/some-file.txt'));
// $file is an instance of Innmind\Stream\Readable
$bucket->upload(new Path('/some-other-name.txt'), $file); // essentially this will copy the file
```

To simplify some usage you can use the filesystem adapter on top of the bucket interface. Here's an example to upload a directory to a bucket:

```php
use Innmind\S3\Filesystem;
use Innmind\OperatingSystem\Factory;

$os = Factory::build();
$data = $os->filsystem()->mount(new Path('/var/data'));
$s3 = new Filesystem\Adapter($bucket);
$s3->add($data->get('images'));
```
