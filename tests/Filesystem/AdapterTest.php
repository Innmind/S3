<?php
declare(strict_types = 1);

namespace Tests\Innmind\S3\Filesystem;

use Innmind\S3\{
    Filesystem\Adapter,
    Bucket,
};
use Innmind\Filesystem\{
    Adapter as AdapterInterface,
    File,
    Name,
    Directory\Directory,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\Path;
use Innmind\Immutable\{
    Set,
    Sequence,
    Maybe,
    SideEffect,
};
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
            Adapter::of($this->createMock(Bucket::class)),
        );
    }

    public function testAddFile()
    {
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $content = $this->createMock(Content::class);
        $bucket
            ->expects($this->once())
            ->method('upload')
            ->with(
                Path::of('foo.pdf'),
                $content,
            )
            ->willReturn(Maybe::just(new SideEffect));

        $this->assertNull(
            $filesystem->add(File\File::named('foo.pdf', $content)),
        );
    }

    public function testAddDirectory()
    {
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $content1 = $this->createMock(Content::class);
        $content2 = $this->createMock(Content::class);
        $bucket
            ->expects($matcher = $this->exactly(2))
            ->method('upload')

            ->willReturnCallback(function($path, $content) use ($matcher, $content1, $content2) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(Path::of('dir/sub/foo.pdf'), $path),
                    2 => $this->assertEquals(Path::of('dir/sub/bar.pdf'), $path),
                };
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertSame($content1, $content),
                    2 => $this->assertSame($content2, $content),
                };

                return Maybe::just(new SideEffect);
            });

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
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('foo.pdf/'))
            ->willReturn(false);
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo.pdf'))
            ->willReturn(Maybe::just($content = $this->createMock(Content::class)));

        $file = $filesystem->get(new Name('foo.pdf'))->match(
            static fn($file) => $file,
            static fn() => null,
        );

        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('foo.pdf', $file->name()->toString());
        $this->assertSame($content, $file->content());
    }

    public function testReturnNothingWhenFileNotFound()
    {
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('foo.pdf/'))
            ->willReturn(false);
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo.pdf'))
            ->willReturn(Maybe::nothing());

        $this->assertNull($filesystem->get(new Name('foo.pdf'))->match(
            static fn($file) => $file,
            static fn() => null,
        ));
    }

    public function testGetDirectory()
    {
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('contains')
            ->with(Path::of('foo/'))
            ->willReturn(true);
        $bucket
            ->expects($matcher = $this->exactly(2))
            ->method('list')
            ->willReturnCallback(function($path) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(Path::of('foo/'), $path),
                    2 => $this->assertEquals(Path::of('foo/bar/'), $path),
                };

                return match ($matcher->numberOfInvocations()) {
                    1 => Sequence::of(Path::of('bar/')),
                    2 => Sequence::of(Path::of('baz.txt')),
                };
            });
        $bucket
            ->expects($this->once())
            ->method('get')
            ->with(Path::of('foo/bar/baz.txt'))
            ->willReturn(Maybe::just($content = $this->createMock(Content::class)));

        $directory = $filesystem->get(new Name('foo'))->match(
            static fn($directory) => $directory,
            static fn() => null,
        );

        $this->assertInstanceOf(Directory::class, $directory);
        $this->assertSame('foo', $directory->name()->toString());
        $this->assertFalse($directory->contains(new Name('baz.txt')));
        $this->assertTrue($directory->contains(new Name('bar')));
        $bar = $directory->get(new Name('bar'))->match(
            static fn($directory) => $directory,
            static fn() => null,
        );
        $this->assertInstanceOf(Directory::class, $bar);
        $this->assertSame('bar', $bar->name()->toString());
        $this->assertTrue($bar->contains(new Name('baz.txt')));
        $baz = $bar->get(new Name('baz.txt'))->match(
            static fn($file) => $file,
            static fn() => null,
        );
        $this->assertInstanceOf(File::class, $baz);
        $this->assertSame('baz.txt', $baz->name()->toString());
        $this->assertSame($content, $baz->content());
    }

    public function testContains()
    {
        $this
            ->forAll(DataSet\Elements::of(true, false))
            ->then(function($exist) {
                $filesystem = Adapter::of(
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
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with(Path::of('foo.pdf'))
            ->willReturn(Maybe::just(new SideEffect));

        $this->assertNull($filesystem->remove(new Name('foo.pdf')));
    }

    public function testFilesListOfFilesystemAlwaysEmpty()
    {
        $filesystem = Adapter::of(
            $bucket = $this->createMock(Bucket::class),
        );
        $bucket
            ->expects($this->once(0))
            ->method('list')
            ->with(Path::none())
            ->willReturn(Sequence::of(Path::of('foo'), Path::of('bar')));
        $bucket
            ->expects($matcher = $this->exactly(2))
            ->method('get')
            ->willReturnCallback(function($path) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(Path::of('foo'), $path),
                    2 => $this->assertEquals(Path::of('bar'), $path),
                };

                return Maybe::just($this->createMock(Content::class));
            });

        $files = $filesystem->all();

        $this->assertInstanceOf(Set::class, $files);
        $this->assertCount(2, $files);
    }
}
