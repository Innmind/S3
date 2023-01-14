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
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set as DataSet,
};

class AdapterTest extends TestCase
{
    use BlackBox;

    public function testInterface()
    {
        $this->assertInstanceOf(
            AdapterInterface::class,
            new Adapter($this->createMock(Bucket::class)),
        );
    }

    public function testAddFile()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $content = $this->createMock(Readable::class);
        $bucket
            ->expects($this->once())
            ->method('upload')
            ->with(
                Path::of('foo.pdf'),
                $content,
            );

        $this->assertNull(
            $filesystem->add(File\File::named('foo.pdf', $content)),
        );
    }

    public function testAddDirectory()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $content1 = $this->createMock(Readable::class);
        $content2 = $this->createMock(Readable::class);
        $bucket
            ->expects($this->exactly(2))
            ->method('upload')
            ->withConsecutive(
                [Path::of('dir/sub/foo.pdf'), $content1],
                [Path::of('dir/sub/bar.pdf'), $content2],
            );

        $this->assertNull(
            $filesystem->add(Directory::named('dir')->add(
                Directory::named('sub')
                    ->add(File\File::named('foo.pdf', $content1))
                    ->add(File\File::named('bar.pdf', $content2)),
            )),
        );
    }

    public function testGet()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('foo.pdf'))
            ->willReturn(true);
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo.pdf'))
            ->willReturn($content = $this->createMock(Readable::class));

        $file = $filesystem->get(new Name('foo.pdf'));

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('foo.pdf', $file->name()->toString());
        $this->assertSame($content, $file->content());
    }

    public function testThrowWhenFileNotFound()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('foo.pdf'))
            ->willReturn(true);
        // true then throw simulate the file disappearing between the 2 calls
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo.pdf'))
            ->will($this->throwException(new UnableToAccessPath));

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo.pdf');

        $filesystem->get(new Name('foo.pdf'));
    }

    public function testThrowWhenGettingUnknownDirectory()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->exactly(2))
            ->method('contains')
            ->withConsecutive([Path::of('foo')], [Path::of('foo/')])
            ->willReturn(false);

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('foo');

        $filesystem->get(new Name('foo'));
    }

    public function testGetDirectory()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->exactly(2))
            ->method('contains')
            ->withConsecutive(
                [Path::of('foo')],
                [Path::of('foo/')],
            )
            ->will($this->onConsecutiveCalls(false, true));
        $bucket
            ->expects($this->exactly(2))
            ->method('list')
            ->withConsecutive(
                [Path::of('foo/')],
                [Path::of('foo/bar/')],
            )
            ->will($this->onConsecutiveCalls(
                Set::of(Path::class, Path::of('bar/')),
                Set::of(Path::class, Path::of('baz.txt')),
            ));
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo/bar/baz.txt'))
            ->willReturn($content = $this->createMock(Readable::class));

        $directory = $filesystem->get(new Name('foo'));

        $this->assertInstanceOf(Directory::class, $directory);
        $this->assertSame('foo', $directory->name()->toString());
        $this->assertFalse($directory->contains(new Name('baz.txt')));
        $this->assertTrue($directory->contains(new Name('bar')));
        $bar = $directory->get(new Name('bar'));
        $this->assertInstanceOf(Directory::class, $bar);
        $this->assertSame('bar', $bar->name()->toString());
        $this->assertTrue($bar->contains(new Name('baz.txt')));
        $baz = $bar->get(new Name('baz.txt'));
        $this->assertInstanceOf(File::class, $baz);
        $this->assertSame('baz.txt', $baz->name()->toString());
        $this->assertSame($content, $baz->content());
    }

    public function testContains()
    {
        $this
            ->forAll(DataSet\Elements::of(true, false))
            ->then(function($exist) {
                $filesystem = new Adapter(
                    $bucket = $this->createMock(Bucket::class),
                );
                $bucket
                    ->expects($this->once())
                    ->method('contains')
                    ->with(Path::of('foo.pdf'))
                    ->willReturn($exist);

                $this->assertSame($exist, $filesystem->contains(new Name('foo.pdf')));
            });
    }

    public function testRemove()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with(Path::of('foo.pdf'));

        $this->assertNull($filesystem->remove(new Name('foo.pdf')));
    }

    public function testFilesListOfFilesystemAlwaysEmpty()
    {
        $filesystem = new Adapter(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once(0))
            ->method('list')
            ->with(Path::none())
            ->willReturn(Set::of(Path::class, Path::of('foo'), Path::of('bar')));
        $bucket
            ->expects($this->exactly(2))
            ->method('get')
            ->withConsecutive([Path::of('foo')], [Path::of('bar')]);

        $files = $filesystem->all();

        $this->assertInstanceOf(Set::class, $files);
        $this->assertSame(File::class, $files->type());
        $this->assertCount(2, $files);
    }
}
