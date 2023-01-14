<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket\Http,
    Bucket,
    Region,
};
use Innmind\HttpTransport\Curl;
use Innmind\TimeContinuum\Earth\Clock;
use Innmind\Filesystem\File\Content;
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

class HttpTest extends TestCase
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
        $this->bucket = new Http(
            Curl::of($clock = new Clock),
            $clock,
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
                $this->assertNull($this->bucket->upload(
                    Path::of($name),
                    Content\Lines::ofContent($content),
                ));
                $this->assertSame(
                    $content,
                    $this
                        ->bucket
                        ->get(Path::of($name))
                        ->match(
                            static fn($content) => $content->toString(),
                            static fn() => null,
                        ),
                );
            });
    }
}
