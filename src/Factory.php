<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Xml\Reader;
use Innmind\Url\Url;

final class Factory
{
    private OperatingSystem $os;
    private Reader $reader;

    private function __construct(OperatingSystem $os)
    {
        $this->os = $os;
        $this->reader = Reader::of();
    }

    public static function of(OperatingSystem $os): self
    {
        return new self($os);
    }

    public function build(Url $bucket, Region $region): Bucket
    {
        return Bucket\OverHttp::of(
            $this->os->remote()->http(),
            $this->os->clock(),
            $this->reader,
            $bucket,
            $region,
        );
    }
}
