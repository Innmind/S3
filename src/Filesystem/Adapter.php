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
        $this->upload('', $file);
    }

    /**
     * {@inheritdoc}
     */
    public function get(Name $file): File
    {
        try {
            $content = $this->bucket->get(Path::of("/{$file->toString()}"));
        } catch (UnableToAccessPath $e) {
            throw new FileNotFound($file->toString());
        }

        $parts = Str::of($file->toString())
            ->split('/')
            ->filter(static function(Str $part): bool {
                return !$part->empty();
            })
            ->reverse();

        return $parts->drop(1)->reduce(
            File\File::named($parts->first()->toString(), $content),
            static function(File $file, Str $directory): File {
                return Directory\Directory::named($directory->toString())->add($file);
            }
        );
    }

    public function contains(Name $file): bool
    {
        return $this->bucket->has(Path::of("/{$file->toString()}"));
    }

    /**
     * {@inheritdoc}
     */
    public function remove(Name $file): void
    {
        if (!$this->contains($file)) {
            throw new FileNotFound($file->toString());
        }

        $this->bucket->delete(Path::of("/{$file->toString()}"));
    }

    /**
     * {@inheritdoc}
     * This method always return an empty map as the bucket interface doesn't
     * allow to list files
     */
    public function all(): Set
    {
        return Set::of(File::class);
    }

    private function upload(string $root, File $file): void
    {
        if ($file instanceof Directory) {
            $file->foreach(
                fn(File $subFile) => $this->upload("$root/{$file->name()->toString()}", $subFile)
            );

            return;
        }

        $this->bucket->upload(
            Path::of("$root/{$file->name()->toString()}"),
            $file->content()
        );
    }
}
