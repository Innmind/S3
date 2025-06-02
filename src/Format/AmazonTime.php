<?php
declare(strict_types = 1);

namespace Innmind\S3\Format;

use Innmind\TimeContinuum\Format;

final class AmazonTime
{
    /**
     * @psalm-pure
     */
    public static function new(): Format
    {
        return Format::of('Ymd\THis\Z');
    }
}
