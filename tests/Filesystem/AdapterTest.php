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
    Name,
    Directory\Directory,
    Exception\FileNotFound,
};
use Innmind\Stream\Readable;
use Innmind\Url\Path;
use Innmind\Immutable\Set;
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
                Path::of('/foo.pdf'),
                $content
            );

        $this->assertNull(
            $filesystem->add(File\File::named('foo.pdf', $content))
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
                Path::of('/dir/sub/foo.pdf'),
                $content1
            );
        $bucket
            ->expects($this->at(1))
            ->method('upload')
            ->with(
                Path::of('/dir/sub/bar.pdf'),
                $content2
            );

        $this->assertNull(
            $filesystem->add(Directory::named('dir')->add(
                Directory::named('sub')
                    ->add(File\File::named('foo.pdf', $content1))
                    ->add(File\File::named('bar.pdf', $content2))
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
            ->with(Path::of('/foo.pdf'))
            ->willReturn($content = $this->createMock(Readable::class));

        $file = $filesystem->get(new Name('foo.pdf'));

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('foo.pdf', $file->name()->toString());
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
            ->with(Path::of('/foo.pdf'))
            ->will($this->throwException(new UnableToAccessPath));

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo.pdf');

        $filesystem->get(new Name('foo.pdf'));
    }

    public function testContains()
    {
        $this
            ->forAll(Generator\elements(true, false))
            ->then(function($exist) {
                $filesystem = new Adapter(
                    $bucket = $this->createMock(Bucket::class)
                );
                $bucket
                    ->expects($this->once())
                    ->method('contains')
                    ->with(Path::of('/foo.pdf'))
                    ->willReturn($exist);

                $this->assertSame($exist, $filesystem->contains(new Name('foo.pdf')));
            });
    }

    public function testRemove()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('/foo.pdf'))
            ->willReturn(true);
        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with(Path::of('/foo.pdf'));

        $this->assertNull($filesystem->remove(new Name('foo.pdf')));
    }

    public function testThrowWhenRemovingUnknownFile()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class)
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('/foo.pdf'))
            ->willReturn(false);

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo.pdf');

        $filesystem->remove(new Name('foo.pdf'));
    }

    public function testFilesListOfFilesystemAlwaysEmpty()
    {
        $filesystem = new Adapter($this->createMock(Bucket::class));

        $files = $filesystem->all();

        $this->assertInstanceOf(Set::class, $files);
        $this->assertSame(File::class, $files->type());
        $this->assertCount(0, $files);
    }
}
