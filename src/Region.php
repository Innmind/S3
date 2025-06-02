<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\Immutable\{
    Str,
    Maybe,
};

final class Region
{
    private function __construct(private string $value)
    {
    }

    /**
     * @param literal-string $value
     *
     * @throws \DomainException
     */
    public static function of(string $value): self
    {
        return self::maybe($value)->match(
            static fn($self) => $self,
            static fn() => throw new \DomainException($value),
        );
    }

    /**
     * @return Maybe<self>
     */
    public static function maybe(string $value): Maybe
    {
        return Str::of($value)
            ->maybe(static fn($value) => $value->matches('~^[a-z0-9\-]+$~'))
            ->map(static fn($value) => new self($value->toString()));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
