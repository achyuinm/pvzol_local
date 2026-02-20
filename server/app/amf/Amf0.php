<?php
declare(strict_types=1);

require_once __DIR__ . '/AmfByteStream.php';

/**
 * Minimal AMF0 (and embedded AMF3 skip) reader + AMF0 writer used by our gateway.
 *
 * We only implement the subset needed for:
 * - parsing the envelope to get targetURI/responseURI
 * - returning api.vip.rewards with an AMF0 object payload (matches captured traffic)
 */

final class Amf0
{
    /**
     * Decode an AMF0 value into PHP types (best-effort, subset).
     *
     * This is primarily for debugging / understanding captured traffic so we can
     * migrate "replay raw bytes" into configurable JSON over time.
     */
    public static function readValueDecode(AmfByteReader $r): mixed
    {
        $t = $r->readU8();
        switch ($t) {
            case 0x00: // Number (double)
                return self::readDoubleBE($r);
            case 0x01: // Boolean
                return $r->readU8() !== 0;
            case 0x02: // String
                return $r->readUtf8U16BE();
            case 0x03: // Object
                return self::readObject($r);
            case 0x05: // Null
            case 0x06: // Undefined
                return null;
            case 0x07: // Reference
                return ['__amf0_ref' => $r->readU16BE()];
            case 0x08: // ECMA Array (associative)
                $r->readU32BE(); // associative count (ignored)
                return self::readObject($r);
            case 0x0A: // Strict array
                $n = $r->readU32BE();
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = self::readValueDecode($r);
                }
                return $out;
            case 0x0B: // Date
                $ms = self::readDoubleBE($r);
                $tz = $r->readU16BE();
                return ['__amf0_date_ms' => $ms, '__amf0_tz' => $tz];
            case 0x0C: // Long string
                $n = $r->readU32BE();
                return $r->readBytes($n);
            case 0x0F: // XML document (long string)
                $n = $r->readU32BE();
                return $r->readBytes($n);
            case 0x10: // Typed object
                $className = $r->readUtf8U16BE();
                $obj = self::readObject($r);
                if (is_array($obj)) {
                    $obj['__amf0_class'] = $className;
                }
                return $obj;
            case 0x11: // AVM+ (AMF3)
                // We don't decode AMF3 yet; skip and return a marker.
                Amf3::readValueSkip($r);
                return ['__amf3' => true];
            default:
                throw new RuntimeException(sprintf('AMF0 decode: unknown marker 0x%02X at %d', $t, $r->pos() - 1));
        }
    }

    public static function readValueSkip(AmfByteReader $r): void
    {
        $t = $r->readU8();
        switch ($t) {
            case 0x00: // Number (double)
                $r->readBytes(8);
                return;
            case 0x01: // Boolean
                $r->readU8();
                return;
            case 0x02: // String
                $r->readUtf8U16BE();
                return;
            case 0x03: // Object
                self::skipObject($r);
                return;
            case 0x05: // Null
            case 0x06: // Undefined
                return;
            case 0x08: // ECMA Array
                $r->readU32BE(); // associative count (ignored)
                self::skipObject($r);
                return;
            case 0x0A: // Strict array
                $n = $r->readU32BE();
                for ($i = 0; $i < $n; $i++) {
                    self::readValueSkip($r);
                }
                return;
            case 0x0B: // Date
                $r->readBytes(8);
                $r->readBytes(2);
                return;
            case 0x0C: // Long string
                $n = $r->readU32BE();
                $r->readBytes($n);
                return;
            case 0x10: // Typed object
                $r->readUtf8U16BE(); // class name
                self::skipObject($r);
                return;
            case 0x11: // AVM+ (AMF3)
                Amf3::readValueSkip($r);
                return;
            default:
                // Unknown marker; best effort: stop to avoid desync.
                throw new RuntimeException(sprintf('AMF0 unknown marker 0x%02X at %d', $t, $r->pos() - 1));
        }
    }

    private static function skipObject(AmfByteReader $r): void
    {
        while (true) {
            $nameLen = $r->readU16BE();
            if ($nameLen === 0) {
                $end = $r->readU8();
                if ($end === 0x09) {
                    return;
                }
                // malformed; stop
                return;
            }
            $r->readBytes($nameLen); // name
            self::readValueSkip($r); // value
        }
    }

    private static function readObject(AmfByteReader $r): array
    {
        $out = [];
        while (true) {
            $nameLen = $r->readU16BE();
            if ($nameLen === 0) {
                $end = $r->readU8();
                if ($end === 0x09) {
                    return $out;
                }
                // malformed; stop
                return $out;
            }
            $name = $r->readBytes($nameLen);
            $out[$name] = self::readValueDecode($r);
        }
    }

    public static function writeValue(AmfByteWriter $w, mixed $v): void
    {
        if ($v === null) {
            $w->writeU8(0x05);
            return;
        }
        if (is_bool($v)) {
            $w->writeU8(0x01);
            $w->writeU8($v ? 1 : 0);
            return;
        }
        if (is_int($v) || is_float($v)) {
            $w->writeU8(0x00);
            self::writeDoubleBE($w, (float)$v);
            return;
        }
        if (is_string($v)) {
            $w->writeU8(0x02);
            $w->writeUtf8U16BE($v);
            return;
        }
        if (is_array($v)) {
            if (self::isList($v)) {
                $w->writeU8(0x0A);
                $w->writeU32BE(count($v));
                foreach ($v as $item) {
                    self::writeValue($w, $item);
                }
                return;
            }
            // associative => object
            $w->writeU8(0x03);
            foreach ($v as $k => $item) {
                $k = (string)$k;
                $w->writeU16BE(strlen($k));
                $w->writeBytes($k);
                self::writeValue($w, $item);
            }
            // object end
            $w->writeU16BE(0);
            $w->writeU8(0x09);
            return;
        }

        // fallback: stringify
        $w->writeU8(0x02);
        $w->writeUtf8U16BE((string)$v);
    }

    private static function writeDoubleBE(AmfByteWriter $w, float $f): void
    {
        $b = pack('d', $f);
        // pack('d') uses machine endian; AMF uses big-endian.
        if (pack('L', 1) === pack('V', 1)) {
            $b = strrev($b);
        }
        $w->writeBytes($b);
    }

    private static function readDoubleBE(AmfByteReader $r): float
    {
        $b = $r->readBytes(8);
        // AMF uses big-endian doubles; PHP unpack('d') is machine endian.
        if (pack('L', 1) === pack('V', 1)) {
            $b = strrev($b);
        }
        $arr = unpack('dval', $b);
        return (float)$arr['val'];
    }

    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}

final class Amf3
{
    public static function readValueSkip(AmfByteReader $r): void
    {
        $marker = $r->readU8();
        switch ($marker) {
            case 0x00: // undefined
            case 0x01: // null
            case 0x02: // false
            case 0x03: // true
                return;
            case 0x04: // integer (u29)
                self::readU29($r);
                return;
            case 0x05: // double
                $r->readBytes(8);
                return;
            case 0x06: // string
                self::readStringSkip($r);
                return;
            case 0x09: // array
                self::skipArray($r);
                return;
            case 0x0A: // object
                self::skipObject($r);
                return;
            default:
                // Not needed for current requests; fail fast to avoid desync.
                throw new RuntimeException(sprintf('AMF3 unknown marker 0x%02X at %d', $marker, $r->pos() - 1));
        }
    }

    private static function readU29(AmfByteReader $r): int
    {
        $b0 = $r->readU8();
        if ($b0 < 128) return $b0;
        $b1 = $r->readU8();
        if ($b1 < 128) return (($b0 & 0x7F) << 7) | $b1;
        $b2 = $r->readU8();
        if ($b2 < 128) return (($b0 & 0x7F) << 14) | (($b1 & 0x7F) << 7) | $b2;
        $b3 = $r->readU8();
        return (($b0 & 0x7F) << 22) | (($b1 & 0x7F) << 15) | (($b2 & 0x7F) << 8) | $b3;
    }

    private static function readStringSkip(AmfByteReader $r): void
    {
        $ref = self::readU29($r);
        if (($ref & 1) === 0) {
            // reference index, nothing to skip
            return;
        }
        $len = $ref >> 1;
        if ($len > 0) {
            $r->readBytes($len);
        }
    }

    private static function skipArray(AmfByteReader $r): void
    {
        $ref = self::readU29($r);
        if (($ref & 1) === 0) {
            return; // reference
        }
        $denseLen = $ref >> 1;
        // associative part: (name,value)* terminated by empty string
        while (true) {
            // string: u29 header then bytes; we only need to detect empty
            $nameRef = self::readU29($r);
            if (($nameRef & 1) === 0) {
                // ref; can't be empty sentinel reliably, but current traffic uses inline empty.
                // Treat as non-empty and skip value.
                self::readValueSkip($r);
                continue;
            }
            $nameLen = $nameRef >> 1;
            if ($nameLen === 0) {
                break;
            }
            $r->readBytes($nameLen);
            self::readValueSkip($r);
        }
        for ($i = 0; $i < $denseLen; $i++) {
            self::readValueSkip($r);
        }
    }

    private static function skipObject(AmfByteReader $r): void
    {
        $ref = self::readU29($r);
        if (($ref & 1) === 0) {
            return; // object reference
        }
        $traitInfo = $ref >> 1;
        if (($traitInfo & 1) === 0) {
            // trait reference; not needed for current traffic
            return;
        }
        $externalizable = (($traitInfo & 2) !== 0);
        $dynamic = (($traitInfo & 4) !== 0);
        $sealedCount = $traitInfo >> 3;

        // class name string
        self::readStringSkip($r);
        // sealed names
        for ($i = 0; $i < $sealedCount; $i++) {
            self::readStringSkip($r);
        }

        // sealed values
        for ($i = 0; $i < $sealedCount; $i++) {
            self::readValueSkip($r);
        }

        if ($externalizable) {
            // not needed
            return;
        }

        if ($dynamic) {
            while (true) {
                $nameRef = self::readU29($r);
                if (($nameRef & 1) === 0) {
                    self::readValueSkip($r);
                    continue;
                }
                $nameLen = $nameRef >> 1;
                if ($nameLen === 0) {
                    break;
                }
                $r->readBytes($nameLen);
                self::readValueSkip($r);
            }
        }
    }
}
