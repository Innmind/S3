<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket\Name,
    Exception\DomainException,
};
use PHPUnit\Framework\TestCase;
use Eris\{
    Generator,
    TestTrait,
};

class NameTest extends TestCase
{
    use TestTrait;

    public function testThrowWenBucketNameIsLess3Characters()
    {
        $this
            ->forAll(Generator\elements('a', 'aa'))
            ->then(function($name) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($name);

                new Name($name);
            });
    }

    public function testThrowWhenContainsUpperCaseLetters()
    {
        $this
            ->forAll(Generator\elements(...range('A', 'Z')))
            ->then(function($letter) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($letter.$letter.$letter);

                new Name($letter.$letter.$letter);
            });
    }

    public function testThrowWhenNotMatchingExpectedFormat()
    {
        $this
            ->forAll(Generator\string())
            ->when(static function($name) {
                return !preg_match('~^[a-z0-9\.\-]{3,}$~', $name);
            })
            ->then(function($name) {
                $this->expectException(DomainException::class);
                $this->expectExceptionMessage($name);

                new Name($name);
            });
    }

    public function testStringCast()
    {
        $this
            ->forAll(Generator\elements('wat-ev', 'wat.ev', '4watev2', '4all.in-one2'))
            ->then(function($string) {
                $name = new Name($string);

                $this->assertSame($string, (string) $name);
            });
    }
}
