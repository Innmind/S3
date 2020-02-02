<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\Path;
use Innmind\Stream\Readable;
use Innmind\Immutable\Set;

interface Bucket
{
    /**
     * Path must be relative
     *
     * @throws Exception\UnableToAccessPath
     */
    public function get(Path $path): Readable;

    /**
     * Path must be relative
     *
     * @throws Exception\FailedToUploadContent
     */
    public function upload(Path $path, Readable $content): void;

    /**
     * Path must be relative
     */
    public function delete(Path $path): void;

    /**
     * Path must be relative
     */
    public function contains(Path $path): bool;

    /**
     * Path must be relative or Path::none() to list the root of the bucket
     *
     * @return Set<Path> Paths are relative to $path
     */
    public function list(Path $path): Set;
}
