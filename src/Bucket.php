<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\Path;
use Innmind\Stream\Readable;

interface Bucket
{
    /**
     * @throws Exception\UnableToAccessPath
     */
    public function get(Path $path): Readable;

    /**
     * @throws Exception\FailedToUploadContent
     */
    public function upload(Path $path, Readable $content): void;
    public function delete(Path $path): void;
    public function contains(Path $path): bool;
}
