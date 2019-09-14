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
    Exception\FileNotFound,
};
use Innmind\Url\Path;
use Innmind\Immutable\{
    MapInterface,
    Map,
    Str,
};

final class Adapter implements AdapterInterface
{
    private $bucket;

    public function __construct(Bucket $bucket)
    {
        $this->bucket = $bucket;
    }

    public function add(File $file): AdapterInterface
    {
        $this->upload('', $file);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $file): File
    {
        try {
            $content = $this->bucket->get(new Path("/$file"));
        } catch (UnableToAccessPath $e) {
            throw new FileNotFound($file);
        }

        $parts = Str::of($file)
            ->split('/')
            ->filter(static function(Str $part): bool {
                return !$part->empty();
            })
            ->reverse();

        return $parts->drop(1)->reduce(
            new File\File((string) $parts->first(), $content),
            static function(File $file, Str $directory): File {
                return (new Directory\Directory((string) $directory))->add($file);
            }
        );
    }

    public function has(string $file): bool
    {
        return $this->bucket->has(new Path("/$file"));
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $file): AdapterInterface
    {
        if (!$this->has($file)) {
            throw new FileNotFound($file);
        }

        $this->bucket->delete(new Path("/$file"));

        return $this;
    }

    /**
     * {@inheritdoc}
     * This method always return an empty map as the bucket interface doesn't
     * allow to list files
     */
    public function all(): MapInterface
    {
        return Map::of('string', File::class);
    }

    private function upload(string $root, File $file): void
    {
        if ($file instanceof Directory) {
            foreach ($file as $subFile) {
                $this->upload("$root/{$file->name()}", $subFile);
            }

            return;
        }

        $this->bucket->upload(
            new Path("$root/{$file->name()}"),
            $file->content()
        );
    }
}
