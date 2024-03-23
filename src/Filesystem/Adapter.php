<?php
declare(strict_types = 1);

namespace Innmind\S3\Filesystem;

use Innmind\S3\{
    Bucket,
    Exception\RuntimeException,
};
use Innmind\Filesystem\{
    Adapter as AdapterInterface,
    File,
    File\Content,
    Directory,
    Name,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Sequence,
    Str,
    Maybe,
};

final class Adapter implements AdapterInterface
{
    private const VOID_FILE = '.keep-empty-directory';

    private Bucket $bucket;
    /** @var \WeakMap<File|Directory, Path> */
    private \WeakMap $loaded;
    private bool $keepEmptyDirectories;

    private function __construct(Bucket $bucket, bool $keepEmptyDirectories)
    {
        $this->bucket = $bucket;
        $this->keepEmptyDirectories = $keepEmptyDirectories;
        /** @var \WeakMap<File|Directory, Path> */
        $this->loaded = new \WeakMap;
    }

    public static function of(Bucket $bucket): self
    {
        return new self($bucket, true);
    }

    /**
     * By default this adapter creates an empty dot file in each directory in
     * order to be fully compatible with other adapters from the
     * innmind/filesystem package.
     *
     * Call this method if you want to keep the default S3 behaviour of not
     * listing empty directories.
     *
     * @psalm-mutation-free
     */
    public function dontKeepEmptyDirectories(): self
    {
        return new self($this->bucket, false);
    }

    public function add(File|Directory $file): void
    {
        $this->upload(Path::none(), $file);
    }

    public function get(Name $file): Maybe
    {
        if ($this->bucket->contains(Path::of($file->toString().'/'))) {
            /** @var Maybe<File> */
            return Maybe::just(Directory::of(
                $file,
                $this->children(Path::of($file->toString().'/')),
            ))->map(function($directory) {
                $this->loaded[$directory] = Path::of($directory->name()->toString().'/');

                return $directory;
            });
        }

        /** @var Maybe<File> */
        return $this
            ->bucket
            ->get(Path::of($file->toString()))
            ->map(static fn($content) => File::of(
                $file,
                $content,
            ))
            ->map(function($file) {
                $this->loaded[$file] = Path::of($file->name()->toString());

                return $file;
            });
    }

    public function contains(Name $file): bool
    {
        return $this->bucket->contains(Path::of($file->toString())) ||
            $this->bucket->contains(Path::of($file->toString().'/'));
    }

    public function remove(Name $file): void
    {
        $this->doRemove(Path::of($file->toString()));
    }

    public function root(): Directory
    {
        return Directory::of(
            Name::of('root'),
            $this->children(Path::none()),
        );
    }

    private function upload(Path $root, File|Directory $file): void
    {
        $path = $this->resolve($root, $file);

        if ($this->loaded->offsetExists($file)) {
            if ($file instanceof File && $this->loaded[$file]->equals($path)) {
                return;
            }

            if ($file instanceof Directory && $this->loaded[$file]->equals(Path::of($path->toString().'/'))) {
                return;
            }
        }

        if ($file instanceof Directory) {
            $all = match ($this->keepEmptyDirectories) {
                true => $file->all()->add(File::named(self::VOID_FILE, Content::none())),
                false => $file->all(),
            };
            $persisted = $all
                ->map(function($file) use ($path) {
                    $this->upload($path, $file);

                    return $file;
                })
                ->map(static fn($file) => $file->name()->toString())
                ->memoize()
                ->toSet();
            $_ = $file
                ->removed()
                ->filter(static fn($file) => !$persisted->contains($file->toString()))
                ->foreach(
                    fn($file) => $this->doRemove(
                        $this->resolve($path, File::of($file, Content::none())), // wrap name as a file because we can't know if the name represent a file or name
                    ),
                );

            return;
        }

        $this->doRemove($path);
        // the ->match() is here to force unwrap the monad to make sure the
        // underlying operation is executed
        $_ = $this
            ->bucket
            ->upload(
                $path,
                $file->content(),
            )
            ->match(
                static fn() => null,
                static fn() => throw new RuntimeException("Failed to upload '{$path->toString()}'"),
            );
    }

    private function resolve(Path $root, File|Directory $file): Path
    {
        $name = $file->name()->toString();

        if ($file instanceof Directory) {
            $name .= '/';
        }

        if ($root->equals(Path::none())) {
            return Path::of($name);
        }

        return $root->resolve(
            Path::of($name),
        );
    }

    /**
     * @return Sequence<File|Directory>
     */
    private function children(Path $folder): Sequence
    {
        /**
         * @psalm-suppress InvalidArgument Due to empty Set
         * @var Sequence<File>
         */
        return $this
            ->bucket
            ->list($folder)
            ->flatMap(
                function(Path $child) use ($folder): Sequence {
                    $path = $folder->equals(Path::none()) ? $child : $folder->resolve($child);

                    if ($child->directory()) {
                        /**
                         * We use a lazy sequence here to avoid loading the whole
                         * bucket
                         * @psalm-suppress ArgumentTypeCoercion
                         */
                        return Sequence::lazy(
                            function() use ($child, $path) {
                                $directory = Directory::of(
                                    Name::of(Str::of($child->toString())->dropEnd(1)->toString()), // drop trailing '/'
                                    $this->children($path),
                                );
                                $this->loaded[$directory] = $path;

                                yield $directory;
                            },
                        );
                    }

                    /** @psalm-suppress ArgumentTypeCoercion */
                    return $this
                        ->bucket
                        ->get($path)
                        ->map(static fn($content) => File::named(
                            $child->toString(),
                            $content,
                        ))
                        ->map(function($file) use ($path) {
                            $this->loaded[$file] = $path;

                            return $file;
                        })
                        ->toSequence();
                },
            )
            ->filter(static fn($file) => $file->name()->toString() !== self::VOID_FILE);
    }

    private function doRemove(Path $path): void
    {
        $directory = Path::of(\rtrim($path->toString(), '/').'/');

        if ($this->bucket->contains($directory)) {
            $_ = $this
                ->bucket
                ->list($directory)
                ->foreach(fn($path) => $this->doRemove($directory->resolve($path)));

            // no return to also call the delete below in case there is both a
            // directory and a file with the same name
        }

        // the ->match() is here to force unwrap the monad to make sure the
        // underlying operation is executed
        $_ = $this
            ->bucket
            ->delete($path)
            ->match(
                static fn() => null,
                static fn() => throw new RuntimeException("Failed to remove '{$path->toString()}'"),
            );
    }
}
