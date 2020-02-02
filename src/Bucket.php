<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\Path;
use Innmind\Stream\Readable;

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
}
