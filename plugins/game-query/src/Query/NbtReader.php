<?php

namespace Marschux\GameQuery\Query;

use RuntimeException;

/**
 * Reads one value out of a Minecraft NBT file, gzip compressed or not.
 *
 * Only walks as far as the requested path and skips everything else, so it never builds the
 * whole tree. Kept dependency-free so it can be checked without a running panel.
 */
class NbtReader
{
    private const END = 0;

    private const COMPOUND = 10;

    /** Fixed payload sizes in bytes, by tag type. */
    private const FIXED = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8];

    private string $data;

    private int $offset = 0;

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * The value at $path - compound names below the root, e.g. ['Data', 'WorldGenSettings', 'seed'].
     * Integer tags come back as int, strings as string; null when the path does not exist, points
     * at a non-scalar tag, or the content is not valid NBT at all.
     *
     * @param  string[]  $path
     */
    public static function find(string $content, array $path): int|float|string|null
    {
        if (str_starts_with($content, "\x1f\x8b")) {
            $content = @gzdecode($content);

            if ($content === false) {
                return null;
            }
        }

        try {
            $reader = new self($content);

            if ($reader->byte() !== self::COMPOUND) {
                return null;
            }

            $reader->skip($reader->u16()); // root name

            return $reader->walk($path);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @param  string[]  $path */
    private function walk(array $path): int|float|string|null
    {
        $wanted = array_shift($path);

        while (($type = $this->byte()) !== self::END) {
            $name = $this->read($this->u16());

            if ($name !== $wanted) {
                $this->skipPayload($type);

                continue;
            }

            if ($path === []) {
                return $this->scalar($type);
            }

            return $type === self::COMPOUND ? $this->walk($path) : null;
        }

        return null;
    }

    private function scalar(int $type): int|float|string|null
    {
        return match ($type) {
            1 => unpack('c', $this->read(1))[1],
            2 => unpack('n', $this->read(2))[1] << 48 >> 48,
            3 => unpack('N', $this->read(4))[1] << 32 >> 32,
            // "J" is documented unsigned, but a 64 bit php int keeps the bit pattern, which is
            // exactly the signed long minecraft stores
            4 => unpack('J', $this->read(8))[1],
            5 => unpack('G', $this->read(4))[1],
            6 => unpack('E', $this->read(8))[1],
            8 => $this->read($this->u16()),
            default => null,
        };
    }

    private function skipPayload(int $type): void
    {
        if (isset(self::FIXED[$type])) {
            $this->skip(self::FIXED[$type]);

            return;
        }

        match ($type) {
            7 => $this->skip($this->i32()),
            8 => $this->skip($this->u16()),
            9 => $this->skipList(),
            10 => $this->skipCompound(),
            11 => $this->skip($this->i32() * 4),
            12 => $this->skip($this->i32() * 8),
            default => throw new RuntimeException("unknown nbt tag $type"),
        };
    }

    private function skipList(): void
    {
        $type = $this->byte();
        $count = $this->i32();

        if (isset(self::FIXED[$type])) {
            $this->skip(self::FIXED[$type] * $count);

            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->skipPayload($type);
        }
    }

    private function skipCompound(): void
    {
        while (($type = $this->byte()) !== self::END) {
            $this->skip($this->u16());
            $this->skipPayload($type);
        }
    }

    private function byte(): int
    {
        return ord($this->read(1));
    }

    private function u16(): int
    {
        return unpack('n', $this->read(2))[1];
    }

    private function i32(): int
    {
        $value = unpack('N', $this->read(4))[1] << 32 >> 32;

        if ($value < 0) {
            throw new RuntimeException('negative nbt length');
        }

        return $value;
    }

    private function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new RuntimeException('nbt data ends early');
        }

        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    private function skip(int $length): void
    {
        $this->read($length);
    }
}
