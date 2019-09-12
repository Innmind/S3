<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket\OverHttp,
    Bucket\Name,
    Bucket,
    Exception\UnableToAccessPath,
    Exception\FailedToUploadContent,
};
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Stream\Readable;
use Aws\{
    S3\S3ClientInterface,
    S3\Exception\S3Exception,
    S3\Exception\S3MultipartUploadException,
    CommandInterface,
};
use Psr\Http\Message\StreamInterface;
use function GuzzleHttp\Psr7\stream_for;
use PHPUnit\Framework\TestCase;
use Eris\{
    Generator,
    TestTrait,
};

class OverHttpTest extends TestCase
{
    use TestTrait;

    public function testInterface()
    {
        $this->assertInstanceOf(
            Bucket::class,
            new OverHttp(
                $this->createMock(S3ClientInterface::class),
                new Name('test-php-lib')
            )
        );
    }

    public function testLoacatedAt()
    {
        $this->assertInstanceOf(
            OverHttp::class,
            OverHttp::locatedAt(Url::fromString('https://key:secret@s3.region-name.scw.cloud/bucket-name?region=region-name'))
        );
    }

    public function testGet()
    {
        $this
            ->forAll(Generator\string())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
                );
                $client
                    ->expects($this->once())
                    ->method('getCommand')
                    ->with(
                        'getObject',
                        ['Bucket' => 'bucket-name', 'Key' => 'File-1423132640.pdf']
                    )
                    ->willReturn($command = $this->createMock(CommandInterface::class));
                $client
                    ->expects($this->once())
                    ->method('execute')
                    ->with($command)
                    ->willReturn(['Body' => stream_for($fileContent)]);

                $content = $bucket->get(new Path('/File-1423132640.pdf'));

                $this->assertInstanceOf(Readable::class, $content);
                $this->assertSame($fileContent, (string) $content);
            });
    }

    public function testGetFileLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(Generator\string())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    new Path('/root')
                );
                $client
                    ->expects($this->once())
                    ->method('getCommand')
                    ->with(
                        'getObject',
                        ['Bucket' => 'bucket-name', 'Key' => 'root/File-1423132640.pdf']
                    )
                    ->willReturn($command = $this->createMock(CommandInterface::class));
                $client
                    ->expects($this->once())
                    ->method('execute')
                    ->with($command)
                    ->willReturn(['Body' => stream_for($fileContent)]);

                $content = $bucket->get(new Path('/File-1423132640.pdf'));

                $this->assertInstanceOf(Readable::class, $content);
                $this->assertSame($fileContent, (string) $content);
            });
    }

    public function testThrowWhenUnableToAccessFile()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->once())
            ->method('getCommand')
            ->with(
                'getObject',
                ['Bucket' => 'bucket-name', 'Key' => 'File-1423132640.pdf']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command)
            ->will($this->throwException(new S3Exception('', $command)));

        $this->expectException(UnableToAccessPath::class);
        $this->expectExceptionMessage('/File-1423132640.pdf');

        $bucket->get(new Path('/File-1423132640.pdf'));
    }

    public function testUpload()
    {
        $this
            ->forAll(Generator\string())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
                );
                $client
                    ->expects($this->once())
                    ->method('upload')
                    ->with(
                        'bucket-name',
                        'sub/composer.json',
                        $this->callback(static function(StreamInterface $content) use ($fileContent) {
                            return (string) $content === $fileContent;
                        })
                    );

                $resource = fopen('php://temp', 'r+');
                fwrite($resource, $fileContent);

                $this->assertNull($bucket->upload(
                    new Path('/sub/composer.json'),
                    new Readable\Stream($resource)
                ));
            });
    }

    public function testUploadLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(Generator\string())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    new Path('/root')
                );
                $client
                    ->expects($this->once())
                    ->method('upload')
                    ->with(
                        'bucket-name',
                        'root/sub/composer.json',
                        $this->callback(static function(StreamInterface $content) use ($fileContent) {
                            return (string) $content === $fileContent;
                        })
                    );

                $resource = fopen('php://temp', 'r+');
                fwrite($resource, $fileContent);

                $this->assertNull($bucket->upload(
                    new Path('/sub/composer.json'),
                    new Readable\Stream($resource)
                ));
            });
    }

    public function testThrowWhenUploadFailed()
    {
        $this
            ->forAll(Generator\string())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
                );
                $client
                    ->expects($this->once())
                    ->method('upload')
                    ->will($this->throwException($this->createMock(S3MultipartUploadException::class)));

                $resource = fopen('php://temp', 'r+');
                fwrite($resource, $fileContent);

                $this->expectException(FailedToUploadContent::class);
                $this->expectExceptionMessage('/sub/composer.json');

                $bucket->upload(
                    new Path('/sub/composer.json'),
                    new Readable\Stream($resource)
                );
            });
    }

    public function testDelete()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->once())
            ->method('getCommand')
            ->with(
                'deleteObject',
                ['Bucket' => 'bucket-name', 'Key' => 'sub/composer.json']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command);

        $this->assertNull($bucket->delete(new Path('/sub/composer.json')));
    }

    public function testDeleteLocatedInSpecificRootDirectory()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            new Path('/root')
        );
        $client
            ->expects($this->once())
            ->method('getCommand')
            ->with(
                'deleteObject',
                ['Bucket' => 'bucket-name', 'Key' => 'root/sub/composer.json']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command);

        $this->assertNull($bucket->delete(new Path('/sub/composer.json')));
    }

    public function testFileExists()
    {
        $this
            ->forAll(Generator\elements(true, false))
            ->then(function($exist) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
                );
                $client
                    ->expects($this->once())
                    ->method('doesObjectExist')
                    ->with(
                        'bucket-name',
                        'sub/composer.json'
                    )
                    ->willReturn($exist);

                $this->assertSame($exist, $bucket->has(new Path('/sub/composer.json')));
            });
    }

    public function testFileExistsLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(Generator\elements(true, false))
            ->then(function($exist) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    new Path('/root')
                );
                $client
                    ->expects($this->once())
                    ->method('doesObjectExist')
                    ->with(
                        'bucket-name',
                        'root/sub/composer.json'
                    )
                    ->willReturn($exist);

                $this->assertSame($exist, $bucket->has(new Path('/sub/composer.json')));
            });
    }
}
