<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * murmurhash3 (32-bit, x86, seed-able) — ported verbatim from the canonical
 * web reference `packages/ab-testing/src/ABTest.ts`.
 *
 * This is the cross-platform bucketing primitive for local feature-flag
 * evaluation. The exact same algorithm runs on web / iOS / Android / every
 * server SDK so that `murmurhash3("{key}.{distinctId}") % 100` produces an
 * identical bucket everywhere — a user is deterministically in or out of a
 * rollout regardless of which platform evaluated the flag.
 *
 * Implementation notes (must not change without re-pinning the vectors):
 * - UTF-8 bytes
 * - 32-bit multiply via imul (emulated with explicit 32-bit masking)
 * - unsigned 32-bit result
 *
 * PHP integers are (typically) 64-bit, so every arithmetic step is masked back
 * to 32 bits to reproduce JavaScript's `Math.imul` / `>>> 0` semantics exactly.
 */
final class Murmur
{
    private const U32 = 0xFFFFFFFF;

    /** 32-bit unsigned multiply (mirrors JS `Math.imul`, returned unsigned). */
    private static function imul(int $a, int $b): int
    {
        $a &= self::U32;
        $b &= self::U32;

        // Split into 16-bit halves to avoid 64-bit overflow during multiply.
        $aHi = ($a >> 16) & 0xFFFF;
        $aLo = $a & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;

        // (aLo*bLo) + ((aHi*bLo + aLo*bHi) << 16), low 32 bits.
        $lo = $aLo * $bLo;
        $mid = (($aHi * $bLo) + ($aLo * $bHi)) & 0xFFFF;
        return ($lo + ($mid << 16)) & self::U32;
    }

    /** Logical left rotate within 32 bits. */
    private static function rotl(int $x, int $r): int
    {
        $x &= self::U32;
        return (($x << $r) | ($x >> (32 - $r))) & self::U32;
    }

    /**
     * 32-bit murmurhash3 of $key (UTF-8 bytes) with the given $seed.
     * Returns an unsigned 32-bit integer.
     */
    public static function hash(string $key, int $seed = 0): int
    {
        $bytes = array_values(unpack('C*', "\0" . $key)); // index from 0 reliably
        // unpack('C*', $key) is 1-indexed; prefixing a NUL then shifting avoids
        // off-by-one surprises. Drop the leading NUL byte we added.
        array_shift($bytes);

        $h1 = $seed & self::U32;
        $c1 = 0xcc9e2d51;
        $c2 = 0x1b873593;
        $len = count($bytes);
        $blocks = intdiv($len, 4);

        for ($i = 0; $i < $blocks; $i++) {
            $k1 = ($bytes[$i * 4])
                | ($bytes[$i * 4 + 1] << 8)
                | ($bytes[$i * 4 + 2] << 16)
                | ($bytes[$i * 4 + 3] << 24);
            $k1 &= self::U32;

            $k1 = self::imul($k1, $c1);
            $k1 = self::rotl($k1, 15);
            $k1 = self::imul($k1, $c2);

            $h1 ^= $k1;
            $h1 = self::rotl($h1, 13);
            $h1 = (self::imul($h1, 5) + 0xe6546b64) & self::U32;
        }

        $k1 = 0;
        $remainder = $len % 4;
        $offset = $blocks * 4;
        switch ($remainder) {
            case 3:
                $k1 ^= $bytes[$offset + 2] << 16;
                // fall through
            case 2:
                $k1 ^= $bytes[$offset + 1] << 8;
                // fall through
            case 1:
                $k1 ^= $bytes[$offset];
                $k1 &= self::U32;
                $k1 = self::imul($k1, $c1);
                $k1 = self::rotl($k1, 15);
                $k1 = self::imul($k1, $c2);
                $h1 ^= $k1;
        }

        $h1 ^= $len;
        $h1 &= self::U32;
        $h1 ^= ($h1 >> 16);
        $h1 = self::imul($h1, 0x85ebca6b);
        $h1 ^= ($h1 >> 13);
        $h1 = self::imul($h1, 0xc2b2ae35);
        $h1 ^= ($h1 >> 16);

        return $h1 & self::U32;
    }

    /**
     * Deterministic 0..99 bucket for a `"{key}.{distinctId}"` style seed.
     */
    public static function bucketOf(string $seed): int
    {
        return self::hash($seed) % 100;
    }
}
