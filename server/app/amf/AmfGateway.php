<?php
declare(strict_types=1);

require_once __DIR__ . '/Amf0.php';

final class AmfGatewayRequest
{
    public int $version;
    public string $targetUri;
    public string $responseUri;

    public function __construct(int $version, string $targetUri, string $responseUri)
    {
        $this->version = $version;
        $this->targetUri = $targetUri;
        $this->responseUri = $responseUri;
    }
}

/**
 * Wrapper for pre-encoded AMF0 bytes (including the AMF0 type marker).
 *
 * Use this to replay captured payloads while still generating a fresh envelope
 * (so responseUri/onResult stays consistent with the current request).
 */
final class AmfRawValue
{
    public string $bytes;

    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }
}

final class AmfGateway
{
    /**
     * Decode AMF envelope enough to dispatch a method.
     *
     * We intentionally do not decode parameters yet; we only skip them to reach end-of-packet.
     */
    public static function parseRequest(string $raw): AmfGatewayRequest
    {
        $r = new AmfByteReader($raw);

        $version = $r->readU16BE();
        $headerCount = $r->readU16BE();
        for ($h = 0; $h < $headerCount; $h++) {
            $r->readUtf8U16BE(); // header name
            $r->readU8();        // mustUnderstand
            $r->readU32BE();     // header length (ignored)
            Amf0::readValueSkip($r);
        }

        $messageCount = $r->readU16BE();
        if ($messageCount < 1) {
            throw new RuntimeException('AMF: empty message list');
        }

        $targetUri = $r->readUtf8U16BE();
        $responseUri = $r->readUtf8U16BE();
        $r->readU32BE(); // body length (ignored)
        Amf0::readValueSkip($r); // params (skip)

        return new AmfGatewayRequest($version, $targetUri, $responseUri);
    }

    /**
     * Extract the raw AMF0 body bytes (including marker) from the first message.
     *
     * Captured traffic sometimes stores a bogus body length (e.g. 1), so we do not rely
     * on the length field and instead return the remaining bytes after the length field.
     */
    public static function extractFirstMessageBodyRaw(string $raw): string
    {
        $r = new AmfByteReader($raw);

        $r->readU16BE(); // version
        $headerCount = $r->readU16BE();
        for ($h = 0; $h < $headerCount; $h++) {
            $r->readUtf8U16BE(); // header name
            $r->readU8();        // mustUnderstand
            $r->readU32BE();     // header length (ignored)
            Amf0::readValueSkip($r);
        }

        $messageCount = $r->readU16BE();
        if ($messageCount < 1) {
            throw new RuntimeException('AMF: empty message list');
        }

        $r->readUtf8U16BE(); // targetUri
        $r->readUtf8U16BE(); // responseUri
        $r->readU32BE();     // body length (ignored)

        return substr($raw, $r->pos());
    }

    /**
     * Build an AMF response packet.
     *
     * Notes (matching captured traffic):
     * - Response version is 0 (even if request version is 3).
     * - Response body targetURI is `${request.responseUri}/onResult`.
     * - Response body responseURI is "null".
     * - We include "AppendToGatewayUrl" header carrying "?PHPSESSID=...".
     */
    public static function buildResponse(
        string $requestResponseUri,
        mixed $resultValue,
        string $phpSessionId
    ): string {
        $bodyTarget = rtrim($requestResponseUri, '/');
        if (!str_ends_with($bodyTarget, '/onResult')) {
            $bodyTarget .= '/onResult';
        }

        $w = new AmfByteWriter();
        $w->writeU16BE(0); // version (matches captured responses)

        // headers: 1 (AppendToGatewayUrl)
        $w->writeU16BE(1);
        $w->writeUtf8U16BE('AppendToGatewayUrl');
        $w->writeU8(0); // mustUnderstand = false
        $w->writeU32BE(1); // length (captured traffic uses 1)
        // value: string "?PHPSESSID=..."
        Amf0::writeValue($w, '?PHPSESSID=' . $phpSessionId);

        // messages: 1
        $w->writeU16BE(1);
        $w->writeUtf8U16BE($bodyTarget);
        $w->writeUtf8U16BE('null');

        // body: AMF0 value (either encoded from PHP types, or replayed raw)
        if ($resultValue instanceof AmfRawValue) {
            $bodyBytes = $resultValue->bytes;
        } else {
            $bodyW = new AmfByteWriter();
            Amf0::writeValue($bodyW, $resultValue);
            $bodyBytes = $bodyW->bytes();
        }

        $w->writeU32BE(strlen($bodyBytes));
        $w->writeBytes($bodyBytes);

        return $w->bytes();
    }
}
