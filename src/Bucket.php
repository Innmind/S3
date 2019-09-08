<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Url\PathInterface;
use Innmind\Stream\Readable;

interface Bucket
{
    public function get(PathInterface $path): Readable;
    public function upload(PathInterface $path, Readable $content): void;
    public function delete(PathInterface $path): void;
    public function has(PathInterface $path): bool;
}
