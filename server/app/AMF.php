<?php
declare(strict_types=1);

/**
 * AMF gateway core (planned).
 *
 * This file is intentionally a placeholder skeleton:
 * - Decode AMF request body (AMF0/AMF3 envelope)
 * - Extract targetURI / method name (e.g. api.apiskill.getAllSkills)
 * - Dispatch to handler and return typed response
 * - Encode response back to AMF
 *
 * Current entrypoint is server/public/amf.php (transport + headers).
 */

final class AMF
{
    public static function notImplemented(): never
    {
        throw new \RuntimeException('AMF core not implemented yet.');
    }
}

