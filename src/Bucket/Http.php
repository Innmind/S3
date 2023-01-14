<?php
declare(strict_types = 1);

namespace Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket,
    Region,
    Exception\FailedToUploadContent,
};
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\HttpTransport\Transport;
use Innmind\Filesystem\File\Content;
use Innmind\TimeContinuum\{
    Clock,
    Format,
    Earth\Timezone\UTC,
};
use Innmind\Http\{
    Message\Request\Request,
    Message\Method,
    ProtocolVersion,
    Headers,
    Header\Header,
    Header\Value\Value,
};
use Innmind\Hash\Hash;
use Innmind\Immutable\{
    Set,
    Maybe,
};

final class Http implements Bucket
{
    private Transport $fulfill;
    private Clock $clock;
    private Url $bucket;
    private Region $region;

    public function __construct(
        Transport $fulfill,
        Clock $clock,
        Url $bucket,
        Region $region,
    ) {
        $this->fulfill = $fulfill;
        $this->clock = $clock;
        $this->bucket = $bucket;
        $this->region = $region;
    }

    /**
     * Path must be relative
     *
     * @return Maybe<Content>
     */
    public function get(Path $path): Maybe
    {
        return ($this->fulfill)($this->request(Method::get, $path))
            ->maybe()
            ->map(static fn($success) => $success->response()->body());
    }

    /**
     * Path must be relative
     */
    public function upload(Path $path, Content $content): void
    {
        ($this->fulfill)($this->request(Method::put, $path, $content))->match(
            static fn() => null,
            static fn() => throw new FailedToUploadContent,
        );
    }

    /**
     * Path must be relative
     */
    public function delete(Path $path): void
    {
    }

    /**
     * Path must be relative
     */
    public function contains(Path $path): bool
    {
    }

    /**
     * Path must be relative or Path::none() to list the root of the bucket
     *
     * @return Set<Path> Paths are relative to $path
     */
    public function list(Path $path): Set
    {
        return Set::of();
    }

    private function request(
        Method $method,
        Path $path,
        Content $content = null,
    ): Request {
        $now = $this->clock->now()->changeTimezone(new UTC);
        $url = $this
            ->bucket
            ->withAuthority($this->bucket->authority()->withoutUserInformation())
            ->withPath($this->bucket->path()->resolve($path));
        $amazonDate = $now->format(new class implements Format {
            public function toString(): string
            {
                return 'Ymd';
            }
        });
        $amazonTime = $now->format(new class implements Format {
            public function toString(): string
            {
                return 'Ymd\THis\Z';
            }
        });
        $contentHash = Hash::sha256
            ->ofContent($content ?? Content\None::of())
            ->hex();
        $headerNames = 'x-amz-content-sha256;x-amz-date';
        $headers = <<<HEADERS
        x-amz-content-sha256:$contentHash
        x-amz-date:$amazonTime

        HEADERS;
        $request = <<<REQUEST
        {$method->toString()}
        {$url->path()->toString()}

        $headers
        $headerNames
        $contentHash
        REQUEST;
        $scope = \sprintf(
            '%s/%s/s3/aws4_request',
            $amazonDate,
            $this->region->toString(),
        );
        $toSign = \sprintf(
            "AWS4-HMAC-SHA256\n%s\n%s\n%s",
            $amazonTime,
            $scope,
            \hash('sha256', $request),
        );
        $signingKey = \hash_hmac(
            'sha256',
            'aws4_request',
            \hash_hmac(
                'sha256',
                's3',
                \hash_hmac(
                    'sha256',
                    $this->region->toString(),
                    \hash_hmac(
                        'sha256',
                        $amazonDate,
                        'AWS4'.$this
                            ->bucket
                            ->authority()
                            ->userInformation()
                            ->password()
                            ->toString(),
                        true,
                    ),
                    true,
                ),
                true,
            ),
            true,
        );
        $signature = \hash_hmac('sha256', $toSign, $signingKey);
        $user = $this
            ->bucket
            ->authority()
            ->userInformation()
            ->user()
            ->toString();

        return new Request(
            $url,
            $method,
            ProtocolVersion::v11,
            Headers::of(
                new Header('x-amz-date', new Value($amazonTime)),
                new Header('x-amz-content-sha256', new Value($contentHash)),
                new Header('Authorization', new Value(
                    "AWS4-HMAC-SHA256 Credential=$user/$scope,SignedHeaders=$headerNames,Signature=$signature",
                )),
            ),
            $content,
        );
    }
}
