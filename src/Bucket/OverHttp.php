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
    UrlInterface,
    PathInterface,
    Authority\NullUserInformation,
    Path,
    NullPath,
    NullQuery,
};
use Innmind\Stream\Readable;
use Innmind\Http\{
    Bridge\Psr7\Stream as PsrToStream,
    Adapter\Psr7\Stream as StreamToPsr,
};
use Innmind\Immutable\Str;
use Aws\S3\{
    S3ClientInterface,
    S3Client,
    Exception\S3Exception,
    Exception\S3MultipartUploadException,
};

final class OverHttp implements Bucket
{
    private S3ClientInterface $client;
    private string $bucket;
    private Str $rootDirectory;

    public function __construct(
        S3ClientInterface $client,
        Name $bucket,
        PathInterface $rootDirectory = null
    ) {
        // @todo : the http client should be injected instead of relying on the
        // aws client to automatically create a http client
        $this->client = $client;
        $this->bucket = (string) $bucket;
        $this->rootDirectory = Str::of((string) ($rootDirectory ?? new NullPath))->trim('/');
    }

    public static function locatedAt(UrlInterface $url): self
    {
        $options = [];
        \parse_str((string) $url->query(), $options);
        $parts = Str::of((string) $url->path())
            ->split('/')
            ->filter(static function(Str $part): bool {
                return !$part->empty();
            });

        if ($parts->empty()) {
            throw new LogicException('Missing bucket name in the url path');
        }

        return new self(
            new S3Client([
                'credentials' => [
                    'key' => (string) $url->authority()->userInformation()->user(),
                    'secret' => (string) $url->authority()->userInformation()->password(),
                ],
                'version' => $options['version'] ?? 'latest',
                'region' => (string) new Region($options['region']),
                'endpoint' => (string) $url
                    ->withAuthority(
                        $url->authority()->withUserInformation(new NullUserInformation)
                    )
                    ->withPath(new NullPath)
                    ->withQuery(new NullQuery),
            ]),
            new Name((string) $parts->first()),
            new Path((string) $parts->drop(1)->join('/')->prepend('/'))
        );
    }

    public function get(PathInterface $path): Readable
    {
        $command = $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->keyFor($path),
        ]);

        try {
            $response = $this->client->execute($command);
        } catch (S3Exception $e) {
            throw new UnableToAccessPath((string) $path, 0, $e);
        }

        return new PsrToStream($response['Body']);
    }

    public function upload(PathInterface $path, Readable $content): void
    {
        try {
            $this->client->upload(
                $this->bucket,
                $this->keyFor($path),
                new StreamToPsr($content)
            );
        } catch (S3MultipartUploadException $e) {
            throw new FailedToUploadContent((string) $path, 0, $e);
        }
    }

    public function delete(PathInterface $path): void
    {
        $command = $this->client->getCommand(
            'deleteObject',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->keyFor($path),
            ]
        );

        $this->client->execute($command);
    }

    public function has(PathInterface $path): bool
    {
        return $this->client->doesObjectExist(
            $this->bucket,
            $this->keyFor($path)
        );
    }

    private function keyFor(PathInterface $path): string
    {
        $path = Str::of((string) $path)->leftTrim('/');

        return (string) $this
            ->rootDirectory
            ->append("/$path")
            ->leftTrim('/');
    }
}
