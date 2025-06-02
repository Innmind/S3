<?php
declare(strict_types = 1);

namespace Innmind\S3;

use Innmind\S3\{
    Format\Amazon,
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
    Offset,
};
use Innmind\Http\{
    Request,
    Method,
    ProtocolVersion,
    Headers,
    Header,
    Header\Value,
    Header\ContentLength,
};
use Innmind\Hash\Hash;
use Innmind\Xml\{
    Reader,
    Element,
    Document,
    Node,
};
use Innmind\Immutable\{
    Str,
    Sequence,
    Attempt,
    Maybe,
    SideEffect,
    Predicate\Instance,
};

final class Bucket
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
     *
     * @return Attempt<SideEffect>
     */
    public function upload(Path $path, Content $content): Attempt
    {
        return ($this->fulfill)($this->request(Method::put, $path, $content))
            ->attempt(static fn($error) => new \RuntimeException(\sprintf(
                'Failed to upload %s (error %s)',
                $path->toString(),
                $error::class,
            )))
            ->map(static fn() => new SideEffect);
    }

    /**
     * Path must be relative
     *
     * @return Attempt<SideEffect>
     */
    public function delete(Path $path): Attempt
    {
        return ($this->fulfill)($this->request(Method::delete, $path))
            ->attempt(static fn($error) => new \RuntimeException(\sprintf(
                'Failed to delete %s (error %s)',
                $path->toString(),
                $error::class,
            )))
            ->map(static fn() => new SideEffect);
    }

    /**
     * Path must be relative
     */
    public function contains(Path $path): bool
    {
        if ($path->directory()) {
            return !$this->enumerate($path)->empty();
        }

        return $this->get($path)->match(
            static fn() => true,
            static fn() => false,
        );
    }

    /**
     * Path must be relative or Path::none() to list the root of the bucket
     *
     * @return Sequence<Path> Paths are relative to $path
     */
    public function list(Path $path): Sequence
    {
        return $this
            ->enumerate($path)
            ->exclude(static fn($found) => $found === '') // in case a folder is created without any file inside
            ->map(Path::of(...));
    }

    /**
     * @return Sequence<string>
     */
    private function enumerate(Path $path): Sequence
    {
        if (!$path->directory()) {
            throw new LogicException("Only a directory can be listed, got '{$path->toString()}'");
        }

        $query = [
            'delimiter' => '/',
            'list-type' => 2,
        ];
        $prefixLength = 0;

        if (!$path->equals(Path::none())) {
            $query['prefix'] = $path->toString();
            $prefixLength = Str::of($path->toString(), Str\Encoding::ascii)->length();
        }

        return $this
            ->paginate($path, $query)
            ->map(Str::of(...))
            ->map(
                static fn($found) => $found
                    ->toEncoding(Str\Encoding::ascii)
                    ->drop($prefixLength)
                    ->toString(),
            );
    }

    /**
     * @return Sequence<string>
     */
    private function paginate(
        Path $path,
        array $query,
        ?string $next = null,
    ): Sequence {
        if (\is_string($next)) {
            $next = ['continuation-token' => $next];
        } else {
            $next = [];
        }

        return Sequence::lazy(function() use ($path, $query, $next) {
            yield ($this->fulfill)($this->request(
                Method::get,
                $this->bucket->path(),
                null,
                Query::of(\http_build_query(
                    [
                        ...$next,
                        ...$query,
                    ],
                    '',
                    '&',
                    \PHP_QUERY_RFC3986,
                )),
            ))
                ->attempt(static fn() => new \RuntimeException('Failed to retrieve path list'))
                ->map(static fn($success) => $success->response()->body())
                ->flatMap($this->read)
                ->maybe()
                ->keep(Instance::of(Document::class))
                ->flatMap(static fn($document) => $document->children()->first())
                ->keep(Instance::of(Element::class))
                ->map(static fn($list) => $list->children()->keep(Instance::of(Element::class)))
                ->map(
                    fn($elements) => $elements
                        ->filter(static fn($element) => $element->name()->toString() === 'Contents')
                        ->flatMap(
                            static fn($content) => $content
                                ->children()
                                ->keep(Instance::of(Element::class))
                                ->filter(static fn($attribute) => $attribute->name()->toString() === 'Key')
                                ->flatMap(static fn($attribute) => $attribute->children())
                                ->keep(Instance::of(Node::class))
                                ->map(static fn($attribute) => $attribute->content()),
                        )
                        ->append(
                            $elements
                                ->filter(static fn($element) => $element->name()->toString() === 'CommonPrefixes')
                                ->flatMap(static fn($prefixes) => $prefixes->children())
                                ->keep(Instance::of(Element::class))
                                ->filter(static fn($element) => $element->name()->toString() === 'Prefix')
                                ->flatMap(static fn($prefix) => $prefix->children())
                                ->keep(Instance::of(Node::class))
                                ->map(static fn($prefix) => $prefix->content()),
                        )
                        ->append(
                            $elements
                                ->find(static fn($element) => $element->name()->toString() === 'NextContinuationToken')
                                ->keep(Instance::of(Element::class))
                                ->flatMap(static fn($token) => $token->children()->first())
                                ->keep(Instance::of(Node::class))
                                ->map(static fn($token) => $token->content())
                                ->match(
                                    fn($token) => $this->paginate(
                                        $path,
                                        $query,
                                        $token,
                                    ),
                                    static fn() => Sequence::of(),
                                ),
                        ),
                )
                // Use monad type juggling instead of matching to allow to schedule
                // mutliple http calls at once
                ->toSequence()
                ->flatMap(static fn($paths) => $paths);
        })->flatMap(static fn($elements) => $elements);
    }

    /**
     * This method has been adapted from mnapoli/simple-s3
     *
     * @see https://github.com/mnapoli/simple-s3/blob/1.1.0/src/SimpleS3.php#L185-L237
     */
    private function request(
        Method $method,
        Path $path,
        ?Content $content = null,
        ?Query $query = null,
    ): Request {
        $content ??= Content::none();
        $now = $this->clock->now()->changeOffset(Offset::utc());
        $url = $this
            ->bucket
            ->withAuthority($this->bucket->authority()->withoutUserInformation())
            ->withPath($this->bucket->path()->resolve(self::sanitize($path)));

        if ($query instanceof Query) {
            $url = $url->withQuery($query);
        }

        $amazonDate = $now->format(Amazon::date);
        $amazonTime = $now->format(Amazon::time);
        $contentHash = Hash::sha256
            ->ofContent($content)
            ->hex();
        $headerNames = 'x-amz-content-sha256;x-amz-date';
        $headers = <<<HEADERS
        x-amz-content-sha256:$contentHash
        x-amz-date:$amazonTime

        HEADERS;
        $request = <<<REQUEST
        {$method->toString()}
        {$url->path()->toString()}
        {$url->query()->toString()}
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
        $headers = Headers::of(
            Header::of('x-amz-date', Value::of($amazonTime)),
            Header::of('x-amz-content-sha256', Value::of($contentHash)),
            Header::of('Authorization', Value::of(
                "AWS4-HMAC-SHA256 Credential=$user/$scope,SignedHeaders=$headerNames,Signature=$signature",
            )),
        );

        $headers = $content->size()->match(
            static fn($size) => ($headers)(ContentLength::of($size->toInt())),
            static fn() => $headers,
        );

        return Request::of(
            $url,
            $method,
            ProtocolVersion::v11,
            $headers,
            $content,
        );
    }

    private static function sanitize(Path $path): Path
    {
        return Path::of(
            Str::of('/')
                ->join(
                    Str::of($path->toString())
                        ->split('/')
                        ->map(static fn($part) => $part->toString())
                        ->map(\rawurlencode(...)),
                )
                ->toString(),
        );
    }
}
