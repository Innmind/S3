<?php

declare(strict_types = 1);

use Innmind\S3\{
    Bucket,
    Factory,
    Region,
    Exception\LogicException,
};
use Innmind\OperatingSystem\Factory as OSFactory;
use Innmind\Filesystem\File\Content;
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Immutable\SideEffect;
use Innmind\BlackBox\{
    Set,
    Tag,
};
use Fixtures\Innmind\Filesystem\Name;
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

    $names = Name::any()
        ->map(static fn($name) => $name->toString())
        ->map(\rawurlencode(...));
    $paths = Set\Sequence::of($names)
        ->between(1, 4) // above 4*255 chars the S3 server rejects the path
        ->map(static fn($parts) => \implode('/', $parts));

    yield test(
        'Bucket over HTTP interface',
        static fn($assert) => $assert
            ->object($bucket)
            ->instance(Bucket::class),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP cannot get a directory',
        given($paths),
        static fn($assert, $path) => $assert->throws(
            static fn() => $bucket->get(Path::of($path.'/')),
            LogicException::class,
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP cannot list non directory',
        given($paths->map(Path::of(...))),
        static fn($assert, $path) => $assert->throws(
            static fn() => $bucket->list($path),
            LogicException::class,
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP upload',
        given(
            $paths->map(Path::of(...)),
            Set\Unicode::strings()->map(Content::ofString(...)),
        ),
        static function($assert, $path, $content) use ($bucket) {
            $assert
                ->object($bucket->upload($path, $content)->match(
                    static fn($sideEffect) => $sideEffect,
                    static fn() => null,
                ))
                ->instance(SideEffect::class);
            $assert->true(
                $bucket->contains($path),
            );
            $assert->same(
                $content->toString(),
                $bucket
                    ->get($path)
                    ->match(
                        static fn($content) => $content->toString(),
                        static fn() => null,
                    ),
            );

            $assert
                ->object($bucket->delete($path)->match(
                    static fn($sideEffect) => $sideEffect,
                    static fn() => null,
                ))
                ->instance(SideEffect::class);
            $assert->false(
                $bucket->contains($path),
            );
            $assert->null(
                $bucket
                    ->get($path)
                    ->match(
                        static fn($content) => $content->toString(),
                        static fn() => null,
                    ),
            );
        },
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP list files in a directory',
        given(
            $names,
            $names,
            $names,
            Set\Unicode::strings()->map(Content::ofString(...)),
            Set\Unicode::strings()->map(Content::ofString(...)),
        ),
        static function($assert, $directory1, $directory2, $file, $content1, $content2) use ($bucket) {
            $assert
                ->object(
                    $bucket
                        ->upload(Path::of($directory1.'/'.$file), $content1)
                        ->match(
                            static fn($sideEffect) => $sideEffect,
                            static fn() => null,
                        ),
                )
                ->instance(SideEffect::class);
            $assert
                ->object(
                    $bucket
                        ->upload(Path::of($directory1.'/'.$directory2.'/'.$file), $content2)
                        ->match(
                            static fn($sideEffect) => $sideEffect,
                            static fn() => null,
                        ),
                )
                ->instance(SideEffect::class);

            $paths = $bucket
                ->list(Path::of($directory1.'/'))
                ->map(static fn($path) => $path->toString())
                ->toList();
            $assert
                ->expected($file)
                ->in($paths);
            $assert
                ->expected($directory2.'/')
                ->in($paths);
            $assert->same(
                [$file],
                $bucket
                    ->list(Path::of($directory1.'/'.$directory2.'/'))
                    ->map(static fn($path) => $path->toString())
                    ->toList(),
            );

            // cleanup
            $bucket->delete(Path::of($directory1.'/'.$file))->match(
                static fn() => null,
                static fn() => null,
            );
            $bucket->delete(Path::of($directory1.'/'.$directory2.'/'.$file))->match(
                static fn() => null,
                static fn() => null,
            );
        },
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP list files in root directory',
        given(
            Set\Sequence::of($names->map(Path::of(...)))->atMost(5), // max 5 to speed up the proof
            Set\Sequence::of($names)->atMost(5), // max 5 to speed up the proof
            $names,
            Set\Unicode::strings()->map(Content::ofString(...)),
        ),
        static function($assert, $files, $directories, $name, $content) use ($bucket) {
            foreach ($files as $file) {
                $assert->true(
                    $bucket->upload($file, $content)->match(
                        static fn() => true,
                        static fn() => false,
                    ),
                );
            }

            foreach ($directories as $directory) {
                $assert->true(
                    $bucket->upload(Path::of($directory.'/'.$name), $content)->match(
                        static fn() => true,
                        static fn() => false,
                    ),
                );
            }

            $root = $bucket
                ->list(Path::none())
                ->map(static fn($path) => $path->toString())
                ->toList();

            foreach ($files as $file) {
                $assert
                    ->expected($file->toString())
                    ->in($root);
            }

            foreach ($directories as $directory) {
                $assert
                    ->expected($directory.'/')
                    ->in($root);
            }

            // cleanup
            foreach ($files as $file) {
                $assert->true(
                    $bucket->delete($file)->match(
                        static fn() => true,
                        static fn() => false,
                    ),
                );
            }

            foreach ($directories as $directory) {
                $assert->true(
                    $bucket->delete(Path::of($directory.'/'.$name))->match(
                        static fn() => true,
                        static fn() => false,
                    ),
                );
            }
        },
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Bucket over HTTP can check if directory exists',
        given(
            Set\Sequence::of($names)->atMost(3),
            $names,
            Set\Unicode::strings()->map(Content::ofString(...)),
        ),
        static function($assert, $path, $name, $content) use ($bucket) {
            $filePath = Path::of(\implode('/', $path).'/'.$name);

            $assert->true(
                $bucket
                    ->upload($filePath, $content)
                    ->match(
                        static fn() => true,
                        static fn() => false,
                    ),
            );

            $parent = '';
            foreach ($path as $directory) {
                $assert->true(
                    $bucket->contains(Path::of($parent.$directory.'/')),
                );
                $parent .= $directory.'/';
            }

            // cleanup
            $assert->true(
                $bucket
                    ->delete($filePath)
                    ->match(
                        static fn() => true,
                        static fn() => false,
                    ),
            );
        }
    )->tag(Tag::local, Tag::ci);
};
