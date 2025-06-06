<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Filesystem\{
    Adapter,
    File,
    File\Content,
    Directory,
    Name,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Sequence,
    Set,
    Str,
    Attempt,
    Maybe,
    SideEffect,
};

final class Filesystem implements Adapter
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

    #[\Override]
    public function add(File|Directory $file): Attempt
    {
        return $this->upload(Path::none(), $file);
    }

    #[\Override]
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

    #[\Override]
    public function contains(Name $file): bool
    {
        return $this->bucket->contains(Path::of($file->toString())) ||
            $this->bucket->contains(Path::of($file->toString().'/'));
    }

    #[\Override]
    public function remove(Name $file): Attempt
    {
        return $this
            ->doRemove(Path::of($file->toString()))
            ->sink(SideEffect::identity())
            ->attempt(static fn($_, $call) => $call);
    }

    #[\Override]
    public function root(): Directory
    {
        return Directory::of(
            Name::of('root'),
            $this->children(Path::none()),
        );
    }

    /**
     * @return Attempt<SideEffect>
     */
    private function upload(Path $root, File|Directory $file): Attempt
    {
        $path = $this->resolve($root, $file);

        if ($this->loaded->offsetExists($file)) {
            /** @psalm-suppress PossiblyNullReference */
            if ($file instanceof File && $this->loaded[$file]->equals($path)) {
                return Attempt::result(SideEffect::identity());
            }

            /** @psalm-suppress PossiblyNullReference */
            if ($file instanceof Directory && $this->loaded[$file]->equals(Path::of($path->toString().'/'))) {
                return Attempt::result(SideEffect::identity());
            }
        }

        if ($file instanceof Directory) {
            return $this
                ->removeEventualFileWithSameName($root, $file)
                ->flatMap(fn() => $this->uploadFiles($path, $file))
                ->flatMap(fn($uploaded) => $this->removeFiles($uploaded, $path, $file));
        }

        return $this
            ->doRemove($path)
            ->sink(SideEffect::identity())
            ->attempt(static fn($_, $call) => $call)
            ->flatMap(fn() => $this->bucket->upload(
                $path,
                $file->content(),
            ));
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
        return $this
            ->bucket
            ->list($folder)
            ->map(
                function(Path $child) use ($folder) {
                    $path = $folder->equals(Path::none()) ? $child : $folder->resolve($child);

                    if ($child->directory()) {
                        /**
                         * We use a lazy sequence here to avoid loading the whole
                         * bucket
                         * @psalm-suppress ArgumentTypeCoercion
                         */
                        $directory = Directory::of(
                            Name::of(Str::of($child->toString())->dropEnd(1)->toString()), // drop trailing '/'
                            Sequence::lazy(function() use ($path) {
                                yield $this->children($path);
                            })->flatMap(static fn($files) => $files),
                        );
                        $this->loaded[$directory] = $path;

                        return $directory;
                    }

                    /**
                     * We use a lazy sequence to load the file content to
                     * prevent fetching the content even when not used.
                     * The drawback of this approach is that if the file is
                     * deleted between the moment it has been listed and the
                     * moment the content is used then the content will be empty.
                     * @psalm-suppress ArgumentTypeCoercion
                     */
                    $file = File::named(
                        $child->toString(),
                        Content::ofChunks(Sequence::lazy(function() use ($path) {
                            yield $this
                                ->bucket
                                ->get($path)
                                ->toSequence()
                                ->flatMap(static fn($content) => $content->chunks());
                        })->flatMap(static fn($chunks) => $chunks)),
                    );
                    $this->loaded[$file] = $path;

                    return $file;
                },
            )
            ->filter(static fn($file) => $file->name()->toString() !== self::VOID_FILE);
    }

    /**
     * @return Sequence<Attempt<SideEffect>>
     */
    private function doRemove(Path $path): Sequence
    {
        $directory = Path::of(\rtrim($path->toString(), '/').'/');

        return $this
            ->bucket
            ->list($directory)
            ->flatMap(fn($path) => $this->doRemove($directory->resolve($path)))
            ->append(Sequence::lazy(function() use ($path) {
                // We use a lazy sequence here to make sure this delete call is
                // made after the recursive call to doRemove() above
                yield $this->bucket->delete($path);
            }));
    }

    /**
     * @return Attempt<SideEffect>
     */
    private function removeEventualFileWithSameName(
        Path $root,
        Directory $directory,
    ): Attempt {
        // Delete any file that may exist with the same name as the directory
        $possibleFilePath = $root->resolve(
            Path::of($directory->name()->toString()),
        );

        return $this
            ->bucket
            ->get($possibleFilePath)
            ->attempt(static fn() => new \Exception('Discarded error'))
            ->eitherWay(
                fn() => $this->bucket->delete($possibleFilePath),
                static fn() => Attempt::result(SideEffect::identity()),
            );
    }

    /**
     * @return Attempt<Set<non-empty-string>>
     */
    private function uploadFiles(
        Path $path,
        Directory $directory,
    ): Attempt {
        $all = match ($this->keepEmptyDirectories) {
            true => $directory->all()->add(File::named(self::VOID_FILE, Content::none())),
            false => $directory->all(),
        };

        /** @var Set<non-empty-string> */
        $uploaded = Set::of();

        return $all
            ->sink($uploaded)
            ->attempt(
                fn($uploaded, $file) => $this
                    ->upload($path, $file)
                    ->map(static fn() => $file->name()->toString())
                    ->map($uploaded),
            )
            ->memoize();
    }

    /**
     * @param Set<non-empty-string> $uploaded
     *
     * @return Attempt<SideEffect>
     */
    private function removeFiles(
        Set $uploaded,
        Path $path,
        Directory $directory,
    ): Attempt {
        return Sequence::of(...$directory->removed()->toList())
            ->filter(static fn($file) => !$uploaded->contains($file->toString()))
            ->map(fn($file) => $this->resolve(
                $path,
                File::of($file, Content::none()), // wrap name as a file because we can't know if the name represent a file or directory
            ))
            ->flatMap($this->doRemove(...))
            ->sink(SideEffect::identity())
            ->attempt(static fn($_, $call) => $call);
    }
}
