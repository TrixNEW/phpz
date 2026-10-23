--TEST--
Typed expectArg/expectArgs outputs, integer ranges, f32 narrowing, nullable/optional states and coercive-mode ZPP conversions
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\typedArgument;
use function MyPHPExt\Test\optionalTypedArgument;
use function MyPHPExt\Test\checkStringArguments;

function parse(string $mode, bool $single, ...$values): mixed {
    if (str_starts_with($mode, 'optional-') || str_starts_with($mode, 'both-')) {
        return optionalTypedArgument($mode, $single, ...$values);
    }
    return typedArgument($mode, $single, ...$values);
}

function same($expected, $actual): void {
    if (is_float($expected) && is_nan($expected) && is_float($actual) && is_nan($actual)) return;
    if ($expected !== $actual) throw new RuntimeException('Unexpected parsed value');
}
function fails(string $class, callable $call): void {
    try { $call(); } catch (Throwable $e) {
        if (get_class($e) !== $class || !str_contains($e->getMessage(), 'Argument #3')) throw $e;
        return;
    }
    throw new RuntimeException("Expected $class");
}
$deprecations = 0;
set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
    if ($errno !== E_DEPRECATED || !str_contains($message, 'parameter #3')) return false;
    $deprecations++;
    return true;
});
function deprecated(int $expected): void {
    global $deprecations;
    if ($deprecations !== $expected) throw new RuntimeException("Expected $expected deprecations, got $deprecations");
    $deprecations = 0;
}
$stringable = new class { public function __toString(): string { return 'stringable'; } };

$ranges = [
    'i8' => [-128, 127], 'i16' => [-32768, 32767],
    'i32' => [-2147483648, 2147483647], 'i64' => [PHP_INT_MIN, PHP_INT_MAX],
    'isize' => [PHP_INT_MIN, PHP_INT_MAX], 'u8' => [0, 255], 'u16' => [0, 65535],
    'u32' => [0, min(PHP_INT_MAX, 4294967295)],
    'u64' => [0, PHP_INT_MAX], 'usize' => [0, PHP_INT_MAX],
];
foreach ([true, false] as $single) {
    try {
        typedArgument('u8', $single);
        throw new RuntimeException('Required argument was accepted as omitted');
    } catch (ArgumentCountError $error) {
        if (!str_contains($error->getMessage(), 'expects exactly 3 arguments, 2 given')) throw $error;
    }
    foreach ($ranges as $type => [$min, $max]) {
        foreach ([$min, 0, 1, $max] as $value) same($value, parse($type, $single, $value));
        if ($min > PHP_INT_MIN) fails(ValueError::class, fn() => parse($type, $single, $min - 1));
        if ($max < PHP_INT_MAX) fails(ValueError::class, fn() => parse($type, $single, $max + 1));
        same(12, parse($type, $single, '12'));
        same(12, parse($type, $single, 12.0));
        same(0, parse($type, $single, false));
        same(1, parse($type, $single, true));
        same(0, parse($type, $single, null));
        deprecated(1);
        foreach (['abc', [], new stdClass, NAN] as $bad) fails(TypeError::class, fn() => parse($type, $single, $bad));
    }
    foreach (['f32', 'f64'] as $type) {
        foreach ([0.0, -0.0, 1.5, 0.1, INF, -INF, NAN, 1.0e-46, 16777217.0] as $value) {
            $expected = $type === 'f32' ? unpack('f', pack('f', $value))[1] : $value;
            same($expected, parse($type, $single, $value));
        }
        same(-INF, fdiv(1.0, parse($type, $single, -0.0)));
        foreach ([[1, 1.0], ['1.5', 1.5], [false, 0.0], ['1e3', 1000.0]] as [$input, $expected]) {
            same($expected, parse($type, $single, $input));
        }
        same(0.0, parse($type, $single, null));
        deprecated(1);
        foreach (['abc', [], new stdClass] as $bad) fails(TypeError::class, fn() => parse($type, $single, $bad));
    }
    $maxFloat = 3.4028234663852886e38;
    same($maxFloat, parse('f32', $single, $maxFloat));
    same(-$maxFloat, parse('f32', $single, -$maxFloat));
    foreach ([$maxFloat * 2, -$maxFloat * 2, PHP_FLOAT_MAX] as $bad) {
        fails(ValueError::class, fn() => parse('f32', $single, $bad));
    }

    foreach (['string', 'cstr', 'str', 'zval'] as $type) {
        foreach (['', 'x', "a\0b", "\xff\xfe", str_repeat('owned', 30)] as $value) {
            same($value, parse($type, $single, $value));
            same(true, checkStringArguments($value));
        }
        foreach ([[1, '1'], [false, ''], [1.5, '1.5'], [$stringable, 'stringable']] as [$input, $expected]) {
            same($expected, parse($type, $single, $input));
        }
        same('', parse($type, $single, null));
        deprecated(1);
        foreach ([[], new stdClass] as $bad) fails(TypeError::class, fn() => parse($type, $single, $bad));
    }

    $resource = fopen('php://memory', 'r+');
    $values = ['u8' => 42, 'f32' => 1.5, 'string' => 'text', 'cstr' => 'text', 'str' => 'text', 'zval' => 'text',
        'int-zval' => -500, 'float-zval' => PHP_FLOAT_MAX, 'bool-zval' => true,
        'array-zval' => ['key' => 42], 'object-zval' => new stdClass, 'resource-zval' => $resource];
    $coercedNull = ['u8' => 0, 'f32' => 0.0, 'string' => '', 'cstr' => '', 'str' => '', 'zval' => '',
        'int-zval' => 0, 'float-zval' => 0.0, 'bool-zval' => false];
    foreach ([[1, true], ['x', true], [0.0, false], ['', false]] as [$input, $expected]) {
        same($expected, parse('bool-zval', $single, $input));
    }
    fails(TypeError::class, fn() => parse('bool-zval', $single, []));
    foreach ($values as $type => $value) {
        foreach (['', 'nullable-', 'optional-', 'both-'] as $prefix) same($value, parse($prefix . $type, $single, $value));
        same(null, parse('nullable-' . $type, $single, null));
        same(null, parse('both-' . $type, $single, null));
        same('omitted', parse('optional-' . $type, $single));
        same('omitted', parse('both-' . $type, $single));
        if (in_array($type, ['array-zval', 'object-zval', 'resource-zval'], true)) {
            fails(TypeError::class, fn() => parse('optional-' . $type, $single, null));
        } else {
            same($coercedNull[$type], parse('optional-' . $type, $single, null));
            deprecated(1);
        }
    }
    fclose($resource);
}
same(42, typedArgument(value: 42, single: true, mode: 'u8'));
echo "typed arguments passed\n";
?>
--EXPECT--
typed arguments passed
