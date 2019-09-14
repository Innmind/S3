<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Filesystem;

use Innmind\S3\{
    Filesystem\Adapter,
    Bucket,
    Exception\UnableToAccessPath,
};
use Innmind\Filesystem\{
    Adapter as AdapterInterface,
    File,
    Directory\Directory,
    Exception\FileNotFound,
};
use Innmind\Stream\Readable;
use Innmind\Url\Path;
use Innmind\Immutable\MapInterface;
use PHPUnit\Framework\TestCase;
use Eris\{
    Generator,
    TestTrait,
};

class AdapterTest extends TestCase
{
    use TestTrait;

    public function testInterface()
    {
        $this->assertInstanceOf(
            AdapterInterface::class,
            new Adapter($this->createMock(Bucket::class))
        );
    }

    public function testAddFile()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $content = $this->createMock(Readable::class);
        $bucket
            ->expects($this->once())
            ->method('upload')
            ->with(
                new Path('/foo.pdf'),
                $content
            );

        $this->assertSame(
            $filesystem,
            $filesystem->add(new File\File('foo.pdf', $content))
        );
    }

    public function testAddDirectory()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $content1 = $this->createMock(Readable::class);
        $content2 = $this->createMock(Readable::class);
        $bucket
            ->expects($this->at(0))
            ->method('upload')
            ->with(
                new Path('/dir/sub/foo.pdf'),
                $content1
            );
        $bucket
            ->expects($this->at(1))
            ->method('upload')
            ->with(
                new Path('/dir/sub/bar.pdf'),
                $content2
            );

        $this->assertSame(
            $filesystem,
            $filesystem->add((new Directory('dir'))->add(
                (new Directory('sub'))
                    ->add(new File\File('foo.pdf', $content1))
                    ->add(new File\File('bar.pdf', $content2))
            ))
        );
    }

    public function testGet()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(new Path('/foo.pdf'))
            ->willReturn($content = $this->createMock(Readable::class));

        $file = $filesystem->get('foo.pdf');

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('foo.pdf', (string) $file->name());
        $this->assertSame($content, $file->content());
    }

    public function testGetFileLocatedInSubDirectory()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(new Path('/sub/dir/foo.pdf'))
            ->willReturn($content = $this->createMock(Readable::class));

        $directory = $filesystem->get('sub/dir/foo.pdf');

        $this->assertInstanceOf(Directory::class, $directory);
        $this->assertSame('sub', (string) $directory->name());
        $directory = $directory->get('dir');
        $this->assertInstanceOf(Directory::class, $directory);
        $this->assertSame('dir', (string) $directory->name());
        $file = $directory->get('foo.pdf');
        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('foo.pdf', (string) $file->name());
        $this->assertSame($content, $file->content());
    }

    public function testThrowWhenFileNotFound()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(new Path('/foo.pdf'))
            ->will($this->throwException(new UnableToAccessPath));

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo.pdf');

        $filesystem->get('foo.pdf');
    }

    public function testHas()
    {
        $this
            ->forAll(Generator\elements(true, false))
            ->then(function($exist) {
                $filesystem = new Adapter(
                    $bucket = $this->createMock(Bucket::class)
                );
                $bucket
                    ->expects($this->once())
                    ->method('has')
                    ->with(new Path('/foo.pdf'))
                    ->willReturn($exist);

                $this->assertSame($exist, $filesystem->has('foo.pdf'));
            });
    }

    public function testRemove()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('has')
            ->with(new Path('/foo.pdf'))
            ->willReturn(true);
        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with(new Path('/foo.pdf'));

        $this->assertSame($filesystem, $filesystem->remove('foo.pdf'));
    }

    public function testThrowWhenRemovingUnknownFile()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('has')
            ->with(new Path('/foo.pdf'))
            ->willReturn(false);

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo.pdf');

        $filesystem->remove('foo.pdf');
    }

    public function testFilesListOfFilesystemAlwaysEmpty()
    {
        $filesystem = new Adapter($this->createMock(Bucket::class));

        $files = $filesystem->all();

        $this->assertInstanceOf(MapInterface::class, $files);
        $this->assertSame('string', (string) $files->keyType());
        $this->assertSame(File::class, (string) $files->valueType());
        $this->assertCount(0, $files);
    }
}
