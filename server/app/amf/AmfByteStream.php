<?php
declare(strict_types=1);

/**
 * Minimal big-endian byte reader/writer for AMF packets.
 *
 * Keep this tiny and self-contained so AMF transport does not depend on any framework.
 */

final class AmfByteReader
{
    /** @var string */
    private string $b;
    private int $i = 0;

    public function __construct(string $bytes)
    {
        $this->b = $bytes;
    }

    public function pos(): int { return $this->i; }
    public function len(): int { return strlen($this->b); }

    public function remaining(): int
    {
        return $this->len() - $this->i;
    }

    public function readU8(): int
    {
        if ($this->i + 1 > $this->len()) {
            throw new RuntimeException('AMF readU8: EOF');
        }
        return ord($this->b[$this->i++]);
    }

    public function readU16BE(): int
    {
        if ($this->i + 2 > $this->len()) {
            throw new RuntimeException('AMF readU16BE: EOF');
        }
        $v = unpack('n', substr($this->b, $this->i, 2))[1];
        $this->i += 2;
        return (int)$v;
    }

    public function readU32BE(): int
    {
        if ($this->i + 4 > $this->len()) {
            throw new RuntimeException('AMF readU32BE: EOF');
        }
        $v = unpack('N', substr($this->b, $this->i, 4))[1];
        $this->i += 4;
        // PHP int is signed; treat as unsigned where it matters (length fields).
        return (int)$v;
    }

    public function readBytes(int $n): string
    {
        if ($n < 0) {
            throw new RuntimeException('AMF readBytes: negative length');
        }
        if ($this->i + $n > $this->len()) {
            throw new RuntimeException('AMF readBytes: EOF');
        }
        $s = substr($this->b, $this->i, $n);
        $this->i += $n;
        return $s;
    }

    public function readUtf8U16BE(): string
    {
        $n = $this->readU16BE();
        return $this->readBytes($n);
    }
}

final class AmfByteWriter
{
    private string $b = '';

    public function bytes(): string { return $this->b; }
    public function len(): int { return strlen($this->b); }

    public function writeU8(int $v): void
    {
        $this->b .= chr($v & 0xFF);
    }

    public function writeU16BE(int $v): void
    {
        $this->b .= pack('n', $v & 0xFFFF);
    }

    public function writeU32BE(int $v): void
    {
        // length fields are unsigned 32-bit
        $this->b .= pack('N', $v & 0xFFFFFFFF);
    }

    public function writeBytes(string $s): void
    {
        $this->b .= $s;
    }

    public function writeUtf8U16BE(string $s): void
    {
        $this->writeU16BE(strlen($s));
        $this->writeBytes($s);
    }
}

