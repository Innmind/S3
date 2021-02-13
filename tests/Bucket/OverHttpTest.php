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
    S3\Exception\S3Exception,
    S3\Exception\S3MultipartUploadException,
    CommandInterface,
    ResultPaginator,
    Result,
};
use Psr\Http\Message\StreamInterface;
use function GuzzleHttp\Psr7\stream_for;
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set as DataSet,
};

class OverHttpTest extends TestCase
{
    use BlackBox;

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

    public function testGet()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
                );
                $client
                    ->expects($this->once())
                    ->method('getCommand')
                    ->with(
                        'GetObject',
                        ['Bucket' => 'bucket-name', 'Key' => 'File-1423132640.pdf']
                    )
                    ->willReturn($command = $this->createMock(CommandInterface::class));
                $client
                    ->expects($this->once())
                    ->method('execute')
                    ->with($command)
                    ->willReturn(new Result(['Body' => stream_for($fileContent)]));

                $content = $bucket->get(Path::of('File-1423132640.pdf'));

                $this->assertInstanceOf(Readable::class, $content);
                $this->assertSame($fileContent, $content->toString());
            });
    }

    public function testGetFileLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    Path::of('/root/')
                );
                $client
                    ->expects($this->once())
                    ->method('getCommand')
                    ->with(
                        'GetObject',
                        ['Bucket' => 'bucket-name', 'Key' => 'root/File-1423132640.pdf']
                    )
                    ->willReturn($command = $this->createMock(CommandInterface::class));
                $client
                    ->expects($this->once())
                    ->method('execute')
                    ->with($command)
                    ->willReturn(new Result(['Body' => stream_for($fileContent)]));

                $content = $bucket->get(Path::of('File-1423132640.pdf'));

                $this->assertInstanceOf(Readable::class, $content);
                $this->assertSame($fileContent, $content->toString());
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
                'GetObject',
                ['Bucket' => 'bucket-name', 'Key' => 'File-1423132640.pdf']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command)
            ->will($this->throwException(new S3Exception('', $command)));

        $this->expectException(UnableToAccessPath::class);
        $this->expectExceptionMessage('File-1423132640.pdf');

        $bucket->get(Path::of('File-1423132640.pdf'));
    }

    public function testUpload()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
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

                $this->assertNull($bucket->upload(
                    Path::of('sub/composer.json'),
                    Readable\Stream::ofContent($fileContent),
                ));
            });
    }

    public function testUploadLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    Path::of('/root/')
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

                $this->assertNull($bucket->upload(
                    Path::of('sub/composer.json'),
                    Readable\Stream::ofContent($fileContent)
                ));
            });
    }

    public function testThrowWhenUploadFailed()
    {
        $this
            ->forAll(DataSet\Unicode::strings())
            ->then(function($fileContent) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name')
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
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->once())
            ->method('getCommand')
            ->with(
                'DeleteObject',
                ['Bucket' => 'bucket-name', 'Key' => 'sub/composer.json']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command);

        $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
    }

    public function testDeleteLocatedInSpecificRootDirectory()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            Path::of('/root/')
        );
        $client
            ->expects($this->once())
            ->method('getCommand')
            ->with(
                'DeleteObject',
                ['Bucket' => 'bucket-name', 'Key' => 'root/sub/composer.json']
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->once())
            ->method('execute')
            ->with($command);

        $this->assertNull($bucket->delete(Path::of('sub/composer.json')));
    }

    public function testFileExists()
    {
        $this
            ->forAll(DataSet\Elements::of(true, false))
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

                $this->assertSame($exist, $bucket->contains(Path::of('sub/composer.json')));
            });
    }

    public function testDirectoryExistsWhenTheresAtLeastOneElementFound()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->at(0))
            ->method('getCommand')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'sub/folder/',
                    'MaxKeys' => 1,
                ],
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->at(1))
            ->method('execute')
            ->with($command)
            ->willReturn(new Result(['Contents' => [['Key' => 'some-file']]]));

        $this->assertTrue($bucket->contains(Path::of('sub/folder/')));
    }

    public function testDirectoryDoesntExistsWhenNoElementFound()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->at(0))
            ->method('getCommand')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'sub/folder/',
                    'MaxKeys' => 1,
                ],
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->at(1))
            ->method('execute')
            ->with($command)
            ->willReturn(new Result(['Contents' => []]));

        $this->assertFalse($bucket->contains(Path::of('sub/folder/')));
    }

    public function testDirectoryDoesntExistsWhenNoContentReturned()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name')
        );
        $client
            ->expects($this->at(0))
            ->method('getCommand')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'sub/folder/',
                    'MaxKeys' => 1,
                ],
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->at(1))
            ->method('execute')
            ->with($command)
            ->willReturn(new Result([]));

        $this->assertFalse($bucket->contains(Path::of('sub/folder/')));
    }

    public function testFileExistsLocatedInSpecificRootDirectory()
    {
        $this
            ->forAll(DataSet\Elements::of(true, false))
            ->then(function($exist) {
                $bucket = new OverHttp(
                    $client = $this->createMock(S3ClientInterface::class),
                    new Name('bucket-name'),
                    Path::of('/root/')
                );
                $client
                    ->expects($this->once())
                    ->method('doesObjectExist')
                    ->with(
                        'bucket-name',
                        'root/sub/composer.json'
                    )
                    ->willReturn($exist);

                $this->assertSame($exist, $bucket->contains(Path::of('sub/composer.json')));
            });
    }

    public function testThrowWhenUsingAnAbsolutePathToAccessAFile()
    {
        $bucket = new OverHttp(
            $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            Path::of('/root/')
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Path to a file must be relative, got '/sub/composer.json'");

        $bucket->get(Path::of('/sub/composer.json'));
    }

    public function testThrowWhenTryingToLostOnANonDirectory()
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
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            Path::of('/root/'),
        );
        $client
            ->expects($this->at(0))
            ->method('getPaginator')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/foo/',
                    'Delimiter' => '/',
                ],
            )
            ->willReturn(new ResultPaginator(
                $client,
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/foo/',
                    'Delimiter' => '/',
                ],
                [
                    // defaults coming from the sdk implementation
                    'input_token' => null,
                    'output_token' => null,
                    'limit_key' => null,
                    'result_key' => null,
                    'more_results' => null,
                ],
            ));
        $client
            ->expects($this->at(1))
            ->method('getCommand')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/foo/',
                    'Delimiter' => '/',
                ],
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->at(2))
            ->method('execute')
            ->with($command)
            ->willReturn(new Result([
                'Contents' => [
                    ['Key' => 'root/foo/'],
                    ['Key' => 'root/foo/some-file'],
                ],
                'CommonPrefixes' => [['Prefix' => 'root/foo/sub-dir/']],
            ]));

        $paths = $bucket->list(Path::of('foo/'));

        $this->assertInstanceOf(Set::class, $paths);
        $this->assertSame(Path::class, $paths->type());
        $this->assertEquals(
            [Path::of('some-file'), Path::of('sub-dir/')],
            unwrap($paths),
        );
    }

    public function testListFilesAtBucketRoot()
    {
        $bucket = new OverHttp(
            $client = $this->createMock(S3ClientInterface::class),
            new Name('bucket-name'),
            Path::of('/root/'),
        );
        $client
            ->expects($this->at(0))
            ->method('getPaginator')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/',
                    'Delimiter' => '/',
                ],
            )
            ->willReturn(new ResultPaginator(
                $client,
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/',
                    'Delimiter' => '/',
                ],
                [
                    // defaults coming from the sdk implementation
                    'input_token' => null,
                    'output_token' => null,
                    'limit_key' => null,
                    'result_key' => null,
                    'more_results' => null,
                ],
            ));
        $client
            ->expects($this->at(1))
            ->method('getCommand')
            ->with(
                'ListObjects',
                [
                    'Bucket' => 'bucket-name',
                    'Prefix' => 'root/',
                    'Delimiter' => '/',
                ],
            )
            ->willReturn($command = $this->createMock(CommandInterface::class));
        $client
            ->expects($this->at(2))
            ->method('execute')
            ->with($command)
            ->willReturn(new Result([
                'Contents' => [
                    ['Key' => 'root/'],
                    ['Key' => 'root/some-file'],
                ],
                'CommonPrefixes' => [['Prefix' => 'root/sub-dir/']],
            ]));

        $paths = $bucket->list(Path::none());

        $this->assertInstanceOf(Set::class, $paths);
        $this->assertSame(Path::class, $paths->type());
        $this->assertEquals(
            [Path::of('some-file'), Path::of('sub-dir/')],
            unwrap($paths),
        );
    }
}
