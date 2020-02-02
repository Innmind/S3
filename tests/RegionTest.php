<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3;

use Innmind\S3\{
    Region,
    Exception\DomainException,
};
use PHPUnit\Framework\TestCase;
use Eris\{
    Generator,
    TestTrait,
};

class RegionTest extends TestCase
{
    use TestTrait;

    public function testThrowWenEmptyRegion()
    {
        $this->expectException(DomainException::class);

        new Region('');
    }

    public function testThrowWhenContainsUpperCaseLetters()
    {
        $this
            ->forAll(Generator\elements(...range('A', 'Z')))
            ->then(function($letter) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($letter.$letter.$letter);

                new Region($letter.$letter.$letter);
            });
    }

    public function testThrowWhenNotMatchingExpectedFormat()
    {
        $this
            ->forAll(Generator\string())
            ->when(static function($region) {
                return !preg_match('~^[a-z0-9\-]+$~', $region);
            })
            ->then(function($region) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($region);

                new Region($region);
            });
    }

    public function testStringCast()
    {
        $this
            ->forAll(Generator\elements('us-east1', 'eu-west', 'fr-par'))
            ->then(function($string) {
                $region = new Region($string);

                $this->assertSame($string, $region->toString());
            });
    }
}
