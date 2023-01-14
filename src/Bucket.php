<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\Path;
use Innmind\Filesystem\File\Content;
use Innmind\Immutable\{
    Set,
    Maybe,
};

interface Bucket
{
    /**
     * Path must be relative
     *
     * @return Maybe<Content>
     */
    public function get(Path $path): Maybe;

    /**
     * Path must be relative
     *
     * @throws Exception\FailedToUploadContent
     */
    public function upload(Path $path, Content $content): void;

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
