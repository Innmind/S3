<?php
declare(strict_types = 1);

namespace Innmind\S3\Filesystem;

use Innmind\S3\{
    Bucket,
    Exception\UnableToAccessPath,
};
use Innmind\Filesystem\{
    Adapter as AdapterInterface,
    File,
    Directory,
    Name,
    Exception\FileNotFound,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    Set,
    Str,
};

final class Adapter implements AdapterInterface
{
    private Bucket $bucket;

    public function __construct(Bucket $bucket)
    {
        $this->bucket = $bucket;
    }

    public function add(File $file): void
    {
        $this->upload(Path::none(), $file);
    }

    public function get(Name $file): File
    {
        if ($this->bucket->contains(Path::of($file->toString()))) {
            try {
                return new File\File(
                    $file,
                    $this->bucket->get(Path::of($file->toString())),
                );
            } catch (UnableToAccessPath $e) {
                throw new FileNotFound($file->toString());
            }
        }

        if (!$this->bucket->contains(Path::of($file->toString().'/'))) {
            throw new FileNotFound($file->toString());
        }

        return new Directory\Directory(
            $file,
            $this->children(Path::of($file->toString().'/')),
        );
    }

    public function contains(Name $file): bool
    {
        return $this->bucket->contains(Path::of($file->toString()));
    }

    public function remove(Name $file): void
    {
        $this->bucket->delete(Path::of($file->toString()));
    }

    public function all(): Set
    {
        return $this->children(Path::none());
    }

    private function upload(Path $root, File $file): void
    {
        if ($file instanceof Directory) {
            $file->foreach(
                fn(File $subFile) => $this->upload($this->resolve($root, $file), $subFile),
            );

            return;
        }

        $this->bucket->upload(
            $this->resolve($root, $file),
            $file->content(),
        );
    }

    private function resolve(Path $root, File $file): Path
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
     * @return Set<File>
     */
    private function children(Path $folder): Set
    {
        /** @var Set<File> */
        return $this
            ->bucket
            ->list($folder)
            ->mapTo(
                File::class,
                function(Path $child) use ($folder): File {
                    $path = $folder->equals(Path::none()) ? $child : $folder->resolve($child);

                    if ($child->directory()) {
                        return new Directory\Directory(
                            new Name(Str::of($child->toString())->dropEnd(1)->toString()), // drop trailing '/'
                            $this->children($path),
                        );
                    }

                    return File\File::named(
                        $child->toString(),
                        $this->bucket->get($path),
                    );
                },
            );
    }
}
