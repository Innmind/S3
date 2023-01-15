<?php
declare(strict_types = 1);

namespace Innmind\S3\Bucket;

use Innmind\S3\{
    Bucket,
    Region,
    Format\AmazonDate,
    Format\AmazonTime,
    Exception\LogicException,
};
use Innmind\Url\{
    Url,
    Path,
    Query,
};
use Innmind\HttpTransport\Transport;
use Innmind\Filesystem\File\Content;
use Innmind\TimeContinuum\{
    Clock,
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
use Innmind\Xml\{
    Reader,
    Element,
    Node\Document,
};
use Innmind\Immutable\{
    Str,
    Set,
    Maybe,
    SideEffect,
    Predicate\Instance,
};

final class OverHttp implements Bucket
{
    private Transport $fulfill;
    private Clock $clock;
    private Reader $read;
    private Url $bucket;
    private Region $region;

    private function __construct(
        Transport $fulfill,
        Clock $clock,
        Reader $reader,
        Url $bucket,
        Region $region,
    ) {
        $this->fulfill = $fulfill;
        $this->clock = $clock;
        $this->read = $reader;
        $this->bucket = $bucket;
        $this->region = $region;
    }

    public static function of(
        Transport $fulfill,
        Clock $clock,
        Reader $reader,
        Url $bucket,
        Region $region,
    ): self {
        return new self($fulfill, $clock, $reader, $bucket, $region);
    }

    /**
     * Path must be relative
     *
     * @return Maybe<Content>
     */
    public function get(Path $path): Maybe
    {
        if ($path->directory()) {
            throw new LogicException("A directory can't be retrieved, got '{$path->toString()}'");
        }

        return ($this->fulfill)($this->request(Method::get, $path))
            ->maybe()
            ->map(static fn($success) => $success->response()->body());
    }

    /**
     * Path must be relative
     */
    public function upload(Path $path, Content $content): Maybe
    {
        return ($this->fulfill)($this->request(Method::put, $path, $content))
            ->maybe()
            ->map(static fn() => new SideEffect);
    }

    /**
     * Path must be relative
     */
    public function delete(Path $path): Maybe
    {
        return ($this->fulfill)($this->request(Method::delete, $path))
            ->maybe()
            ->map(static fn() => new SideEffect);
    }

    /**
     * Path must be relative
     */
    public function contains(Path $path): bool
    {
        if ($path->directory()) {
            return !$this->list($path)->empty();
        }

        return $this->get($path)->match(
            static fn() => true,
            static fn() => false,
        );
    }

    /**
     * Path must be relative or Path::none() to list the root of the bucket
     *
     * @return Set<Path> Paths are relative to $path
     */
    public function list(Path $path): Set
    {
        if (!$path->directory()) {
            throw new LogicException("Only a directory can be listed, got '{$path->toString()}'");
        }

        if ($path->equals(Path::none())) {
            $query = Query::of('list-type=2&delimiter=/');
            $prefixLength = 0;
        } else {
            $query = Query::of('list-type=2&delimiter=/&prefix='.$path->toString());
            $prefixLength = Str::of($path->toString())->length();
        }

        return ($this->fulfill)($this->request(
            Method::get,
            $this->bucket->path(),
            null,
            $query,
        ))
            ->maybe()
            ->map(static fn($success) => $success->response()->body())
            ->flatMap($this->read)
            ->keep(Instance::of(Document::class))
            ->flatMap(static fn($document) => $document->children()->first())
            ->map(static fn($list) => $list->children()->keep(Instance::of(Element::class)))
            ->map(
                static fn($elements) => $elements
                    ->filter(static fn($element) => $element->name() === 'Contents')
                    ->flatMap(
                        static fn($content) => $content
                            ->children()
                            ->keep(Instance::of(Element::class))
                            ->filter(static fn($attribute) => $attribute->name() === 'Key')
                            ->map(static fn($attribute) => $attribute->content()),
                    )
                    ->append(
                        $elements
                            ->filter(static fn($element) => $element->name() === 'CommonPrefixes')
                            ->flatMap(static fn($prefixes) => $prefixes->children())
                            ->keep(Instance::of(Element::class))
                            ->filter(static fn($element) => $element->name() === 'Prefix')
                            ->map(static fn($prefix) => $prefix->content()),
                    ),
            )
            ->map(
                static fn($paths) => $paths
                    ->map(Str::of(...))
                    ->map(
                        static fn($found) => $found
                            ->trim()
                            ->drop($prefixLength)
                            ->toString(),
                    )
                    ->map(Path::of(...)),
            )
            ->match(
                static fn($paths) => Set::of(...$paths->toList()),
                static fn() => Set::of(),
            );
    }

    /**
     * This method has been adapted from mnapoli/simple-s3
     *
     * @see https://github.com/mnapoli/simple-s3/blob/1.1.0/src/SimpleS3.php#L185-L237
     */
    private function request(
        Method $method,
        Path $path,
        Content $content = null,
        Query $query = null,
    ): Request {
        $now = $this->clock->now()->changeTimezone(new UTC);
        $url = $this
            ->bucket
            ->withAuthority($this->bucket->authority()->withoutUserInformation())
            ->withPath($this->bucket->path()->resolve($path));

        if ($query instanceof Query) {
            $url = $url->withQuery($query);
        }

        $amazonDate = $now->format(new AmazonDate);
        $amazonTime = $now->format(new AmazonTime);
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
