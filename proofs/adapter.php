<?php
declare(strict_types = 1);

use Innmind\S3\{
    Factory as S3Factory,
    Filesystem,
    Region,
};
use Innmind\OperatingSystem\Factory;
use Innmind\Url\Url;
use Innmind\Immutable\Sequence;
use Properties\Innmind\Filesystem\Adapter;
use Innmind\BlackBox\Set;
use Symfony\Component\Filesystem\Filesystem as FS;
use Symfony\Component\Process\Process;

return static function() {
    (new FS)->remove(__DIR__.'/../../fixtures/my-bucket');

    $command = [
        'npm',
        'run',
        's3-server',
        '--',
        '--directory',
        __DIR__.'/../../fixtures',
        '--configure-bucket',
        'my-bucket',
    ];
    $s3ServerProcess = new Process($command, __DIR__.'/..');
    $s3ServerProcess->start();
    $s3ServerProcess->waitUntil(
        static fn($type, $output) => \str_contains($output, 'S3rver listening'),
    );

    yield properties(
        'S3',
        Adapter::properties()
            ->filter(
                static fn($all) => !Sequence::of(...$all->properties())
                    ->map(static fn($property) => $property::class)
                    ->any(static fn($property) => $property === Adapter\AddEmptyDirectory::class),
            )
            ->filter(
                static fn($all) => Sequence::of(...$all->properties())
                    ->filter(static fn($property) => $property instanceof Adapter\AddDirectory)
                    ->filter(static fn($property) => $property->directory()->all()->empty())
                    ->empty(),
            ),
        Set\Call::of(fn() => Filesystem\Adapter::of(
            S3Factory::of(Factory::build())->build(
                Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket/'),
                Region::of('doesnt-matter-here'),
            ),
        )),
    );

    foreach (Adapter::alwaysApplicable() as $property) {
        yield property(
            $property,
            Set\Call::of(fn() => Filesystem\Adapter::of(
                S3Factory::of(Factory::build())->build(
                    Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket/'),
                    Region::of('doesnt-matter-here'),
                ),
            )),
        )->named('S3');
    }

    $s3ServerProcess->stop();
};
