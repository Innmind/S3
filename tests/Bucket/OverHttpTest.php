<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket\OverHttp,
    Bucket\Name,
    Bucket,
    Exception\UnableToAccessPath,
    Exception\FailedToUploadContent,
    Exception\LogicException,
};
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Stream\Readable;
use Innmind\HttpTransport\Transport;
use Innmind\Immutable\Set;
use function Innmind\Immutable\unwrap;
use Aws\{
    S3\S3ClientInterface,
    S3\Exception\S3MultipartUploadException,
};
use function Innmind\HttpTransport\bootstrap as http;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set as DataSet,
};

class OverHttpTest extends TestCase
{
    use BlackBox;

    private static $s3ServerProcess;
    private $http;

    public function setUp(): void
    {
        parent::setUp();
        $this->http = http()['default']();
    }

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
            fn($type, $output) => \str_contains($output, 'S3rver listening'),
        );
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$s3ServerProcess) {
            self::$s3ServerProcess->stop();
        }
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Bucket::class,
            new OverHttp(
                $this->createMock(S3ClientInterface::class),
                new Name('test-php-lib'),
            ),
        );
    }

    public function testLocatedAt()
    {
        $this->assertInstanceOf(
            OverHttp::class,
            OverHttp::locatedAt(
                $this->createMock(Transport::class),
                Url::of('https://key:secret@s3.region-name.scw.cloud/bucket-name?region=region-name'),
            ),
        );
        $this->assertInstanceOf(
            OverHttp::class,
            OverHttp::locatedAt(
                $this->createMock(Transport::class),
                Url::of('https://key:secret@s3.region-name.scw.cloud/bucket-name/root-dir/?region=region-name'),
            ),
        );
    }

    public function testThrowWhenNoBucketNameInPath()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Missing bucket name in the url path');

        OverHttp::locatedAt(
            $this->createMock(Transport::class),
            Url::of('https://key:secret@s3.region-name.scw.cloud/?region=region-name'),
        );
    }

    public function testThrowWhenRootDirectoryDoesntRepresentADirectory()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Root directory '/root-dir' must represent a directory");

        OverHttp::locatedAt(
            $this->createMock(Transport::class),
            Url::of('https://key:secret@s3.region-name.scw.cloud/bucket-name/root-dir?region=region-name'),
        );
    }

    public function testPreventFromGettingADirectory()
    {
        $bucket = new OverHttp(
            $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("A directory can't be retrieved, got 'foo/'");

        $bucket->get(Path::of('foo/'));
    }

    public function testThrowWhenUnableToAccessFile()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );

        $this->expectException(UnableToAccessPath::class);
        $this->expectExceptionMessage('File-1423132640.pdf');

        $bucket->get(Path::of('File-1423132640.pdf'));
    }

    public function testUpload()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->take(1)
            ->disableShrinking()
            ->then(function($fileContent) {
                $bucket = OverHttp::locatedAt(
                    $this->http,
                    Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
                );

                $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
                $this->assertFalse($bucket->contains(Path::of('sub/composer.json')));
                $this->assertNull($bucket->upload(
                    Path::of('sub/composer.json'),
                    Readable\Stream::ofContent($fileContent),
                ));
                $this->assertTrue($bucket->contains(Path::of('sub/composer.json')));
                $this->assertSame(
                    $fileContent,
                    $bucket->get(Path::of('sub/composer.json'))->toString(),
                );
            });
    }

    public function testUploadLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = OverHttp::locatedAt(
                    $this->http,
                    Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket/root/?region=us-east-1'),
                );

                $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
                $this->assertFalse($bucket->contains(Path::of('sub/composer.json')));
                $this->assertNull($bucket->upload(
                    Path::of('sub/composer.json'),
                    Readable\Stream::ofContent($fileContent),
                ));
                $this->assertTrue($bucket->contains(Path::of('sub/composer.json')));
                $this->assertSame(
                    $fileContent,
                    $bucket->get(Path::of('sub/composer.json'))->toString(),
                );
            });
    }

    public function testThrowWhenUploadFailed()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                );
                $client
                    ->expects($this->once())
                    ->method('upload')
                    ->will($this->throwException($this->createMock(S3MultipartUploadException::class)));

                $this->expectException(FailedToUploadContent::class);
                $this->expectExceptionMessage('sub/composer.json');

                $bucket->upload(
                    Path::of('sub/composer.json'),
                    Readable\Stream::ofContent($fileContent),
                );
            });
    }

    public function testDelete()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );
        $bucket->upload(
            Path::of('sub/composer.json'),
            Readable\Stream::ofContent('test'),
        );

        $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
        $this->assertFalse($bucket->contains(Path::of('sub/composer.json')));
    }

    public function testDeleteLocatedInSpecificRootDirectory()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket/root/?region=us-east-1'),
        );
        $bucket->upload(
            Path::of('sub/composer.json'),
            Readable\Stream::ofContent('test'),
        );

        $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
        $this->assertFalse($bucket->contains(Path::of('sub/composer.json')));
    }

    public function testFileExists()
    {
        $this
            ->forAll(DataSet\Elements::of(true, false))
            ->then(function($exist) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                );
                $client
                    ->expects($this->once())
                    ->method('doesObjectExist')
                    ->with(
                        'bucket-name',
                        'sub/composer.json',
                    )
                    ->willReturn($exist);

                $this->assertSame($exist, $bucket->contains(Path::of('sub/composer.json')));
            });
    }

    public function testDirectoryExistsWhenTheresAtLeastOneElementFound()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );
        $bucket->upload(
            Path::of('sub/composer.json'),
            Readable\Stream::ofContent('test'),
        );

        $this->assertTrue($bucket->contains(Path::of('sub/')));
    }

    public function testDirectoryDoesntExistsWhenNoElementFound()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );

        $this->assertFalse($bucket->contains(Path::of('unknown/')));
    }

    public function testThrowWhenUsingAnAbsolutePathToAccessAFile()
    {
        $bucket = new OverHttp(
            $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            Path::of('/root/'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Path to a file must be relative, got '/sub/composer.json'");

        $bucket->get(Path::of('/sub/composer.json'));
    }

    public function testThrowWhenTryingToListOnANonDirectory()
    {
        $bucket = new OverHttp(
            $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Only a directory can be listed, got 'foo'");

        $bucket->list(Path::of('foo'));
    }

    public function testListFilesInADirectory()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );
        $bucket->upload(
            Path::of('sub/composer.json'),
            Readable\Stream::ofContent('test'),
        );
        $bucket->upload(
            Path::of('sub/deep/composer2.json'),
            Readable\Stream::ofContent('test'),
        );

        $paths = $bucket->list(Path::of('sub/'));

        $this->assertInstanceOf(Set::class, $paths);
        $this->assertSame(Path::class, $paths->type());
        $this->assertEquals(
            [Path::of('composer.json'), Path::of('deep/')],
            unwrap($paths),
        );
    }

    public function testListFilesAtBucketRoot()
    {
        $bucket = OverHttp::locatedAt(
            $this->http,
            Url::of('http://S3RVER:S3RVER@localhost:4568/my-bucket?region=us-east-1'),
        );
        $bucket->upload(
            Path::of('sub/composer.json'),
            Readable\Stream::ofContent('test'),
        );
        $bucket->upload(
            Path::of('some-file'),
            Readable\Stream::ofContent('test'),
        );

        $paths = $bucket->list(Path::none());

        $this->assertInstanceOf(Set::class, $paths);
        $this->assertSame(Path::class, $paths->type());
        $this->assertEquals(
            [Path::of('some-file'), Path::of('sub/')],
            unwrap($paths),
        );
    }
}
