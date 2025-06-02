<?php
declare(strict_types = 1);

namespace Innmind\S3\Format;

use Innmind\TimeContinuum\Format;

/**
 * @psalm-immutable
 */
enum Amazon implements Format\Custom
{
    case date;
    case time;

    #[\Override]
    public function normalize(): Format
    {
        return Format::of(match ($this) {
            self::date => 'Ymd',
            self::time => 'Ymd\THis\Z',
        });
    }
}
