<?php
declare(strict_types = 1);

namespace Innmind\S3\Filesystem;

use Innmind\S3\Bucket;
use Innmind\Filesystem\{
    Adapter as AdapterInterface,
    File,
    Directory,
    Name,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Set,
    Sequence,
    Str,
    Maybe,
};

final class Adapter implements AdapterInterface
{
    private Bucket $bucket;

    private function __construct(Bucket $bucket)
    {
        $this->bucket = $bucket;
    }

    public static function of(Bucket $bucket): self
    {
        return new self($bucket);
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
            ));
        }

        /** @var Maybe<File> */
        return $this
            ->bucket
            ->get(Path::of($file->toString()))
            ->map(static fn($content) => File::of(
                $file,
                $content,
            ));
    }

    public function contains(Name $file): bool
    {
        return $this->bucket->contains(Path::of($file->toString()));
    }

    public function remove(Name $file): void
    {
        // the ->match() is here to force unwrap the monad to make sure the
        // underlying operation is executed
        $_ = $this
            ->bucket
            ->delete(Path::of($file->toString()))
            ->match(
                static fn() => null,
                static fn() => null,
            );
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
        if ($file instanceof Directory) {
            $_ = $file->foreach(
                fn($subFile) => $this->upload($this->resolve($root, $file), $subFile),
            );

            return;
        }

        // the ->match() is here to force unwrap the monad to make sure the
        // underlying operation is executed
        $_ = $this
            ->bucket
            ->upload(
                $this->resolve($root, $file),
                $file->content(),
            )->match(
                static fn() => null,
                static fn() => null,
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
                                yield Directory::of(
                                    Name::of(Str::of($child->toString())->dropEnd(1)->toString()), // drop trailing '/'
                                    $this->children($path),
                                );
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
                        ->toSequence();
                },
            );
    }
}
