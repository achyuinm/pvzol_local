<?php
declare(strict_types=1);

final class RequestContext
{
    /** @var array<string,mixed> */
    private static array $ctx = [];

    /**
     * @param array<string,mixed> $ctx
     */
    public static function set(array $ctx): void
    {
        self::$ctx = $ctx;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get(): array
    {
        return self::$ctx;
    }
}

