--TEST--
Typed arguments follow strict_types like ZPP, including int to float widening
--EXTENSIONS--
my_php_extension
--FILE--
<?php
declare(strict_types=1);

use function MyPHPExt\Test\typedArgument as parse;

function same($expected, $actual): void {
    if ($expected !== $actual) throw new RuntimeException('Unexpected parsed value ' . var_export($actual, true));
}
function fails(callable $call, string $type): void {
    try { $call(); } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), "Argument #3 (\$value) must be of type $type")) throw $e;
        return;
    }
    throw new RuntimeException('Expected TypeError');
}
$stringable = new class { public function __toString(): string { return 'stringable'; } };

foreach ([true, false] as $single) {
    foreach (['u8', 'i64'] as $type) {
        foreach (['12', 12.0, false, [], null] as $bad) fails(fn() => parse($type, $single, $bad), 'int');
        fails(fn() => parse('nullable-' . $type, $single, '12'), '?int');
    }
    foreach (['f32', 'f64'] as $type) {
        same(1.0, parse($type, $single, 1));
        foreach (['1.5', false, [], null] as $bad) fails(fn() => parse($type, $single, $bad), 'float');
    }
    same(-500.0, parse('float-zval', $single, -500));
    foreach (['string', 'cstr', 'str', 'zval'] as $type) {
        foreach ([1, false, 1.5, [], new stdClass, $stringable, null] as $bad) fails(fn() => parse($type, $single, $bad), 'string');
    }
    foreach ([1, 'x', 0.0, null] as $bad) fails(fn() => parse('bool-zval', $single, $bad), 'bool');
    fails(fn() => parse('optional-u8', $single, null), 'int');
    fails(fn() => parse('array-zval', $single, 'x'), 'array');
}
echo "strict typed arguments passed\n";
?>
--EXPECT--
strict typed arguments passed
