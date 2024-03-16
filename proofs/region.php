<?php

declare(strict_types = 1);

use Innmind\S3\{
    Region,
    Exception\DomainException,
};
use Innmind\BlackBox\{
    Set,
    Tag,
};

return static function() {
    yield test(
        'Throw when empty region',
        static fn($assert) => $assert->throws(
            static fn() => Region::of(''),
            DomainException::class,
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Throw when region contains uppercase letters',
        given(Set\Elements::of(...\range('A', 'Z'))),
        static fn($assert, $letter) => $assert->throws(
            static fn() => Region::of($letter.$letter.$letter),
            DomainException::class,
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Throw when region not matching expected format',
        given(Set\Unicode::strings()->filter(static function($region) {
            return !\preg_match('~^[a-z0-9\-]+$~', $region);
        })),
        static fn($assert, $region) => $assert->throws(
            static fn() => Region::of($region),
            DomainException::class,
            $region,
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Region string cast',
        given(Set\Elements::of('us-east1', 'eu-west', 'fr-par')),
        static fn($assert, $region) => $assert->same(
            $region,
            Region::of($region)->toString(),
        ),
    )->tag(Tag::local, Tag::ci);

    yield proof(
        'Region accepted values',
        given(Set\Sequence::of(
            Set\Strings::madeOf(
                Set\Chars::lowercaseLetter(),
                Set\Chars::number(),
            )->atLeast(1),
        )->atLeast(1)),
        static fn($assert, $parts) => $assert->not()->throws(
            static fn() => Region::of(\implode('-', $parts)),
        ),
    )->tag(Tag::local, Tag::ci);
};
