<?php
declare(strict_types = 1);

namespace Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket,
    Region,
    Exception\UnableToAccessPath,
    Exception\FailedToUploadContent,
};
use Innmind\Url\{
    UrlInterface,
    PathInterface,
    Authority\NullUserInformation,
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
    private $client;
    private $bucket;

    public function __construct(S3ClientInterface $client, Name $bucket)
    {
        // @todo : the http client should be injected instead of relying on the
        // aws client to automatically create a http client
        $this->client = $client;
        $this->bucket = (string) $bucket;
    }

    public static function locatedAt(UrlInterface $url): self
    {
        $options = [];
        \parse_str((string) $url->query(), $options);

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
            new Name((string) Str::of((string) $url->path())->leftTrim('/'))
        );
    }

    public function get(PathInterface $path): Readable
    {
        $command = $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => ltrim((string) $path, '/'),
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
                ltrim((string) $path, '/'),
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
                'Key' => ltrim((string) $path, '/'),
            ]
        );

        $this->client->execute($command);
    }

    public function has(PathInterface $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, ltrim((string) $path, '/'));
    }
}
