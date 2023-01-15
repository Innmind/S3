<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket\OverHttp,
    Bucket,
    Region,
    Exception\LogicException,
};
use Innmind\HttpTransport\Curl;
use Innmind\TimeContinuum\Earth\Clock;
use Innmind\Filesystem\File\Content;
use Innmind\Xml\Reader\Reader;
use Innmind\Url\{
    Url,
    Path,
};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};

class OverHttpTest extends TestCase
{
    use BlackBox;

    private static $s3ServerProcess;
    private $bucket;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        (new Filesystem)->remove(__DIR__.'/../../fixtures/my-bucket');

        $command = [
            'npm',
            'run',
            's3-server',
            '--',
            '--directory',
            __DIR__.'/../../fixtures',
            '--configure-bucket',
            'my-bucket',
        ];
        self::$s3ServerProcess = new Process($command, __DIR__.'/..');
        self::$s3ServerProcess->start();
        self::$s3ServerProcess->waitUntil(
            static fn($type, $output) => \str_contains($output, 'S3rver listening'),
        );
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$s3ServerProcess) {
            self::$s3ServerProcess->stop();
        }
    }

    public function setUp(): void
    {
        $this->bucket = OverHttp::of(
            Curl::of($clock = new Clock),
            $clock,
            Reader::of(),
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket/'),
            new Region('doesnt-matter-here'),
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Bucket::class,
            $this->bucket,
        );
    }

    public function testUpload()
    {
        $this
            ->forAll(
                Set\Elements::of('composer.json', 'sub/composer.json'),
                Set\Unicode::strings(),
            )
            ->then(function($name, $content) {
                $path = Path::of($name);

                $this->assertFalse($this->bucket->contains($path));
                $this->assertNull($this->bucket->upload(
                    $path,
                    Content\Lines::ofContent($content),
                ));
                $this->assertTrue($this->bucket->contains($path));
                $this->assertSame(
                    $content,
                    $this
                        ->bucket
                        ->get($path)
                        ->match(
                            static fn($content) => $content->toString(),
                            static fn() => null,
                        ),
                );
                $this->assertNull($this->bucket->delete($path));
                $this->assertFalse($this->bucket->contains($path));
                $this->assertNull(
                    $this
                        ->bucket
                        ->get($path)
                        ->match(
                            static fn($content) => $content->toString(),
                            static fn() => null,
                        ),
                );
            });
    }

    public function testPreventFromGettingADirectory()
    {
        $this->expectException(LogicException::class);

        $this->bucket->get(Path::of('foo/'));
    }

    public function testListFilesInADirectory()
    {
        $this
            ->forAll(
                Set\Unicode::strings(),
                Set\Unicode::strings(),
            )
            ->take(1)
            ->disableShrinking()
            ->then(function($content1, $content2) {
                $this->bucket->upload(
                    Path::of('l1/l2/file1.txt'),
                    Content\Lines::ofContent($content1),
                );
                $this->bucket->upload(
                    Path::of('l1/file2.txt'),
                    Content\Lines::ofContent($content2),
                );

                $this->assertSame(
                    ['file2.txt', 'l2/'],
                    $this
                        ->bucket
                        ->list(Path::of('l1/'))
                        ->map(static fn($path) => $path->toString())
                        ->toList(),
                );
                $this->assertNull($this->bucket->delete(Path::of('l1/')));
            });
    }

    public function testListFilesInRootDirectory()
    {
        $this
            ->forAll(
                Set\Unicode::strings(),
                Set\Unicode::strings(),
            )
            ->then(function($content1, $content2) {
                $this->bucket->upload(
                    Path::of('l1/l2/file1.txt'),
                    Content\Lines::ofContent($content1),
                );
                $this->bucket->upload(
                    Path::of('l1/file2.txt'),
                    Content\Lines::ofContent($content2),
                );

                $this->assertSame(
                    ['l1/'],
                    $this
                        ->bucket
                        ->list(Path::none())
                        ->map(static fn($path) => $path->toString())
                        ->toList(),
                );
                $this->assertNull($this->bucket->delete(Path::of('l1/')));
            });
    }

    public function testPreventFromListingNonDirectory()
    {
        $this->expectException(LogicException::class);

        $this->bucket->list(Path::of('foo'));
    }

    public function testContainsDirectory()
    {
        $this
            ->forAll(Set\Unicode::strings())
            ->then(function($content) {
                $this->bucket->upload(
                    Path::of('l1/l2/file1.txt'),
                    Content\Lines::ofContent($content),
                );

                $this->assertTrue($this->bucket->contains(Path::of('l1/')));
                $this->assertTrue($this->bucket->contains(Path::of('l1/l2/')));
                $this->assertNull($this->bucket->delete(Path::of('l1/')));
            });
    }
}
