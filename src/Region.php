<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\S3\Exception\DomainException;
use Innmind\Immutable\Str;

final class Region
{
    private $value;

    public function __construct(string $value)
    {
        if (!Str::of($value)->matches('~^[a-z0-9\-]+$~')) {
            throw new DomainException($value);
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
