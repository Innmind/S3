<?php

declare(strict_types = 1);

use Innmind\S3\{
    Bucket,
    Factory,
    Region,
};
use Innmind\OperatingSystem\Factory as OSFactory;
use Innmind\Url\Url;
use Innmind\BlackBox\Tag;
use Symfony\Component\Dotenv\Dotenv;

return static function() {
    $file = __DIR__.'/../.env';

    if (\file_exists($file)) {
        $dotenv = new Dotenv;
        $dotenv->usePutenv();
        $dotenv->load($file);
    }

    $os = OSFactory::build();
    $bucket = Factory::of($os)->build(
        Url::of(\getenv('S3_URL') ?? throw new Exception('Env var missing')),
        Region::of(\getenv('S3_REGION') ?? throw new Exception('Env var missing')),
    );

    yield test(
        'Bucket over HTTP interface',
        static fn($assert) => $assert
            ->object($bucket)
            ->instance(Bucket::class),
    )->tag(Tag::local, Tag::ci);
};
