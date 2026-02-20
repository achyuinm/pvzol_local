<?php
declare(strict_types=1);

final class HttpResponse
{
    public int $status;
    /** @var array<string,string> */
    public array $headers;
    public string $body;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }

    public static function xml(string $xml, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/xml; charset=utf-8'], $xml);
    }
}

