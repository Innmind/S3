<?php
declare(strict_types = 1);

namespace Innmind\S3\Format;

use Innmind\TimeContinuum\Format;

/**
 * @psalm-immutable
 */
final class AmazonDate implements Format
{
    public function toString(): string
    {
        return 'Ymd';
    }
}
