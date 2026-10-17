<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\Targeting;
use PHPUnit\Framework\TestCase;

/**
 * Targeting-operator parity test (scratch/sdk-operator-parity-spec.md).
 *
 * Asserts every canonical cross-language vector against `Targeting::compare` — the SDK's ONE operator
 * matcher, which must be byte-for-byte equivalent to the backend audience engine `compare()` in
 * `supabase/functions/_shared/edge-serving/pure.ts`. (It used to be the private `Flags::matchesRule`,
 * bolted to the flat rule shape; that shape and that duplicate matcher are gone.)
 */
final class TargetingCompareTest extends TestCase
{
    /**
     * @return array<string,array{string,mixed,mixed,bool}>
     */
    public static function vectors(): array
    {
        return [
            // name => [operator, actual, expected, result]
            'exists present'        => ['exists', 'x', null, true],
            'exists empty'          => ['exists', '', null, false],
            'not_exists null'       => ['not_exists', null, null, true],
            'equals'                => ['equals', '5', '5', true],
            'is'                    => ['is', '5', '5', true],
            'not_equals'            => ['not_equals', '5', '6', true],
            'is_not same'           => ['is_not', 'a', 'a', false],
            'any_of hit'            => ['any_of', 'b', ['a', 'b', 'c'], true],
            'any_of miss'           => ['any_of', 'z', ['a', 'b', 'c'], false],
            'not_any_of'            => ['not_any_of', 'z', ['a', 'b', 'c'], true],
            'contains'              => ['contains', 'hello', 'ell', true],
            'not_contains'          => ['not_contains', 'hello', 'xyz', true],
            'greater_than numeric'  => ['greater_than', '10', '5', true],
            'gt alias numeric'      => ['gt', '3', '5', false],
            'less_than numeric'     => ['less_than', '3', '5', true],
            'gte numeric equal'     => ['gte', '5', '5', true],
            'lte numeric equal'     => ['lte', '5', '5', true],
            'greater_than date'     => ['greater_than', '2026-02-01', '2026-01-01', true],
            'less_than date'        => ['less_than', '2026-01-01', '2026-02-01', true],
            'gte date equal'        => ['gte', '2026-01-01', '2026-01-01', true],
            // Coercion parity with JS: numbers/bools stringify the way `String(...)` does.
            'int actual equals'     => ['equals', 30, '30', true],
            'bool actual equals'    => ['equals', true, 'true', true],
            'numeric gt int value'  => ['greater_than', 30, 18, true],
        ];
    }

    /**
     * @dataProvider vectors
     */
    public function testCanonicalVectors(string $operator, mixed $actual, mixed $expected, bool $result): void
    {
        $this->assertSame(
            $result,
            Targeting::compare($operator, $actual, $expected),
            sprintf('operator=%s actual=%s', $operator, var_export($actual, true))
        );
    }

    public function testUnknownOperatorIsFalse(): void
    {
        $this->assertFalse(Targeting::compare('frobnicate', 'x', 'x'));
    }

    public function testNullActualNonExistenceOperatorIsFalse(): void
    {
        // For non-exists operators, null actual short-circuits to false.
        $this->assertFalse(Targeting::compare('equals', null, '5'));
        $this->assertFalse(Targeting::compare('greater_than', null, '5'));
    }

    public function testNonNumericOrderingIsFalseLikeNaN(): void
    {
        // JS: Number('abc') is NaN, and every relational comparison with NaN is false — including `lte`,
        // where a PHP `(float)` cast would have silently produced 0.0 <= 5 → true.
        $this->assertFalse(Targeting::compare('greater_than', 'abc', 5));
        $this->assertFalse(Targeting::compare('less_than_or_equal', 'abc', 5));
    }
}
