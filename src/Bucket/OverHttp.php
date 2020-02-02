<?php
declare(strict_types = 1);

namespace Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket,
    Region,
    Exception\UnableToAccessPath,
    Exception\FailedToUploadContent,
    Exception\LogicException,
};
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Stream\Readable;
use Innmind\Http\{
    Bridge\Psr7\Stream as PsrToStream,
    Adapter\Psr7\Stream as StreamToPsr,
};
use Innmind\Immutable\{
    Str,
    Set,
};
use function Innmind\Immutable\join;
use Aws\S3\{
    S3ClientInterface,
    S3Client,
    Exception\S3Exception,
    Exception\S3MultipartUploadException,
};
use Aws\ResultPaginator;

final class OverHttp implements Bucket
{
    private S3ClientInterface $client;
    private string $bucket;
    private Path $rootDirectory;

    public function __construct(
        S3ClientInterface $client,
        Name $bucket,
        Path $rootDirectory = null
    ) {
        $rootDirectory ??= Path::none();

        if (!$rootDirectory->directory()) {
            throw new LogicException("Root directory '{$rootDirectory->toString()}' must represent a directory");
        }

        // @todo : the http client should be injected instead of relying on the
        // aws client to automatically create a http client
        $this->client = $client;
        $this->bucket = $bucket->toString();
        $this->rootDirectory = $rootDirectory;
    }

    public static function locatedAt(Url $url): self
    {
        $options = [];
        \parse_str($url->query()->toString(), $options);
        $path = Str::of($url->path()->toString());
        $parts = $path
            ->split('/')
            ->filter(static function(Str $part): bool {
                return !$part->empty();
            });

        if ($parts->empty()) {
            throw new LogicException('Missing bucket name in the url path');
        }

        $rootDirectory = $path->substring($parts->first()->length() + 1); // the 1 is for the leading /

        return new self(
            new S3Client([
                'credentials' => [
                    'key' => $url->authority()->userInformation()->user()->toString(),
                    'secret' => $url->authority()->userInformation()->password()->toString(),
                ],
                'version' => $options['version'] ?? 'latest',
                'region' => (new Region($options['region']))->toString(),
                'endpoint' => $url
                    ->withAuthority(
                        $url->authority()->withoutUserInformation(),
                    )
                    ->withoutPath()
                    ->withoutQuery()
                    ->toString(),
            ]),
            new Name($parts->first()->toString()),
            $rootDirectory->empty() ? Path::none() : Path::of($rootDirectory->toString()),
        );
    }

    public function get(Path $path): Readable
    {
        if ($path->directory()) {
            throw new LogicException("A directory can't be retrieved, got '{$path->toString()}'");
        }

        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->keyFor($path),
        ]);

        try {
            $response = $this->client->execute($command);
        } catch (S3Exception $e) {
            throw new UnableToAccessPath($path->toString(), 0, $e);
        }

        return new PsrToStream($response['Body']);
    }

    public function upload(Path $path, Readable $content): void
    {
        try {
            $this->client->upload(
                $this->bucket,
                $this->keyFor($path),
                new StreamToPsr($content),
            );
        } catch (S3MultipartUploadException $e) {
            throw new FailedToUploadContent($path->toString(), 0, $e);
        }
    }

    public function delete(Path $path): void
    {
        $command = $this->client->getCommand(
            'DeleteObject',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->keyFor($path),
            ],
        );

        $this->client->execute($command);
    }

    public function contains(Path $path): bool
    {
        if ($path->directory()) {
            return $this->containsDirectory($path);
        }

        return $this->client->doesObjectExist(
            $this->bucket,
            $this->keyFor($path),
        );
    }

    public function list(Path $path): Set
    {
        if (!$path->directory()) {
            throw new LogicException("Only a directory can be listed, got '{$path->toString()}'");
        }

        $paginator = $this->client->getPaginator(
            'ListObjects',
            [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix = $this->keyFor($path),
                'Delimiter' => '/',
            ],
        );

        return Set::defer(
            Path::class,
            (function(string $prefix, ResultPaginator $results): \Generator {
                foreach ($results as $result) {
                    foreach ($result['Contents'] ?? [] as $file) {
                        if ($file['Key'] === $prefix) {
                            // when the folder exist it is listed as the first element
                            // of the files and we dont wan't the folder itself in the
                            // list of it's children
                            continue;
                        }

                        yield $this->removePrefix($prefix, $file['Key']);
                    }

                    foreach ($result['CommonPrefixes'] ?? [] as $directory) {
                        yield $this->removePrefix($prefix, $directory['Prefix']);
                    }
                }
            })($prefix, $paginator),
        );
    }

    private function keyFor(Path $path): string
    {
        if ($path->equals(Path::none())) {
            return Str::of($this->rootDirectory->toString())->leftTrim('/')->toString();
        }

        if ($path->absolute()) {
            throw new LogicException("Path to a file must be relative, got '{$path->toString()}'");
        }

        $path = $this
            ->rootDirectory
            ->resolve($path)
            ->toString();

        return Str::of($path)->leftTrim('/')->toString();
    }

    private function containsDirectory(Path $directory): bool
    {
        $command = $this->client->getCommand(
            'ListObjects',
            [
                'Bucket' => $this->bucket,
                'Prefix' => $this->keyFor($directory),
                'MaxKeys' => 1, // no need to list all objects to know if directory exist
            ],
        );

        $result = $this->client->execute($command);

        return \count($result['Contents'] ?? []) > 0;
    }

    private function removePrefix(string $prefix, string $path): Path
    {
        return Path::of(
            Str::of($path)
                ->substring(Str::of($prefix)->length())
                ->toString(),
        );
    }
}
