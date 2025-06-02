<?php
declare(strict_types = 1);

use Innmind\S3\{
    Factory,
    Filesystem,
    Region,
};
use Innmind\OperatingSystem\{
    Factory as OSFactory,
    Config\Resilient,
};
use Innmind\Url\Url;
use Properties\Innmind\Filesystem\Adapter;
use Innmind\BlackBox\{
    Set,
    Tag,
};
use Symfony\Component\Dotenv\Dotenv;

return static function() {
    $file = __DIR__.'/../.env';

    if (\file_exists($file)) {
        $dotenv = new Dotenv;
        $dotenv->usePutenv();
        $dotenv->load($file);
    }

    $os = OSFactory::build()->map(Resilient::new());
    $bucket = Factory::of($os)->build(
        Url::of(\getenv('S3_PROPERTIES_URL') ?? throw new Exception('Env var missing')),
        Region::of(\getenv('S3_PROPERTIES_REGION') ?? throw new Exception('Env var missing')),
    );

    yield properties(
        'S3',
        Adapter::properties(),
        Set::call(static fn() => Filesystem\Adapter::of($bucket)),
    )->tag(Tag::local, Tag::ci);

    foreach (Adapter::alwaysApplicable() as $property) {
        yield property(
            $property,
            Set::call(static fn() => Filesystem\Adapter::of($bucket)),
        )
            ->named('S3')
            ->tag(Tag::local, Tag::ci);
    }
};
