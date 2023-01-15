<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3;

use Innmind\S3\{
    Region,
    Exception\DomainException,
};
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};

class RegionTest extends TestCase
{
    use BlackBox;

    public function testThrowWenEmptyRegion()
    {
        $this->expectException(DomainException::class);

        Region::of('');
    }

    public function testThrowWhenContainsUpperCaseLetters()
    {
        $this
            ->forAll(Set\Elements::of(...\range('A', 'Z')))
            ->then(function($letter) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($letter.$letter.$letter);

                Region::of($letter.$letter.$letter);
            });
    }

    public function testThrowWhenNotMatchingExpectedFormat()
    {
        $this
            ->forAll(Set\Unicode::strings()->filter(static function($region) {
                return !\preg_match('~^[a-z0-9\-]+$~', $region);
            }))
            ->then(function($region) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($region);

                Region::of($region);
            });
    }

    public function testStringCast()
    {
        $this
            ->forAll(Set\Elements::of('us-east1', 'eu-west', 'fr-par'))
            ->then(function($string) {
                $region = Region::of($string);

                $this->assertSame($string, $region->toString());
            });
    }
}
