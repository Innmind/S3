<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\Path;
use Innmind\Filesystem\File\Content;
use Innmind\Immutable\{
    Sequence,
    Maybe,
    Attempt,
    SideEffect,
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
     * @return Attempt<SideEffect>
     */
    public function upload(Path $path, Content $content): Attempt;

    /**
     * Path must be relative
     *
     * @return Attempt<SideEffect>
     */
    public function delete(Path $path): Attempt;

    /**
     * Path must be relative
     */
    public function contains(Path $path): bool;

    /**
     * Path must be relative or Path::none() to list the root of the bucket
     *
     * @return Sequence<Path> Paths are relative to $path
     */
    public function list(Path $path): Sequence;
}
