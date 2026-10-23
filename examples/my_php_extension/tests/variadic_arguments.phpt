--TEST--
Variadic arguments preserve names and use expect specs with explicit acceptance modes
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\makeFnClosure;

function check($actual, $expected): void {
    if ($actual !== $expected) throw new Exception(var_export([$actual, $expected], true));
}
function fails(callable $call, string $class, string $text): void {
    try { $call(); } catch (Throwable $e) {
        check($e instanceof $class, true);
        check(str_contains($e->getMessage(), $text), true);
        return;
    }
    throw new Exception('expected ' . $class);
}

$parse = makeFnClosure('variadic');
$count = makeFnClosure('variadic_mode');
foreach (['positional_only', 'named_only', 'both'] as $mode) {
    check($parse($mode, 'mixed'), []);
    check($count($mode), 0);
}
check($parse('positional_only', 'int', 1, '2'), [1, 2]);
check($parse('named_only', 'int', first: '3', second: 4), ['first' => 3, 'second' => 4]);
check($parse('both', 'int', 1, extra: '2'), [1, 'extra' => 2]);
check($count('both', 1, extra: 2), 2);
check($count('named_only', extra: 2), 1);
check($count('positional_only', 1), 1);
fails(fn () => $parse('positional_only', 'mixed', extra: 1), ArgumentCountError::class, 'named');
fails(fn () => $parse('named_only', 'mixed', 1), ArgumentCountError::class, 'positionally');
fails(fn () => $count('positional_only', extra: 1), ArgumentCountError::class, 'named');
fails(fn () => $count('named_only', 1, extra: 2), ArgumentCountError::class, 'positionally');
fails(fn () => $parse('both'), ArgumentCountError::class, 'at least');
// Fixed parameters are parsed before the explicit mode check.
fails(fn () => $parse('positional_only', [], extra: 1), TypeError::class, 'Argument #2');
// The mode is checked before any element is parsed.
fails(fn () => $parse('positional_only', 'int', [], extra: 1), ArgumentCountError::class, 'named');
echo "modes and argument counts: passed\n";

$object = new stdClass();
check($parse('both', 'mixed', null, ['a'], obj: $object, args: null),
    [null, ['a'], 'obj' => $object, 'args' => null]);
check($parse('both', 'nullable-int', null, a: null, b: '2'), [null, 'a' => null, 'b' => 2]);
check($parse('both', 'union', 1, number: '2'), [1, 'number' => '2']);
check($parse('both', 'string', "a\0b", named: 42), ["a\0b", 'named' => '42']);
check($parse('both', 'float', 1, named: '2.5'), [1.0, 'named' => 2.5]);
check($parse('both', 'bool', 1, named: ''), [true, 'named' => false]);
check($parse('both', 'object', $object, named: $object), [$object, 'named' => $object]);
$stream = fopen('php://memory', 'r+');
check($parse('both', 'stream', $stream, named: $stream), [$stream, 'named' => $stream]);
fclose($stream);
check($parse('both', 'callable', fn () => 1, named: fn () => 2), [1, 'named' => 2]);
// Parsing remains lazy; the unvisited named value is not checked.
check($parse('both', 'first-int', 1, unchecked: []), [1]);
fails(fn () => $parse('both', 'int', 1, bad: []), TypeError::class, 'Argument ($bad) must be of type int, array given');
fails(fn () => $parse('both', 'nullable-int', bad: []), TypeError::class, 'Argument ($bad) must be of type ?int, array given');
fails(fn () => $parse('both', 'int', []), TypeError::class, 'Argument #3');
fails(fn () => $parse('both', 'u8', bad: 256), ValueError::class, '($bad)');
fails(fn () => $parse('both', 'union', bad: []), TypeError::class, '($bad)');
fails(fn () => $parse('both', 'object', bad: []), TypeError::class, '($bad)');
fails(fn () => $parse('both', 'callable', bad: 'no_such_variadic_callback'), TypeError::class, '($bad)');
fails(fn () => $parse('both', 'string', bad: new class {
    public function __toString(): string { throw new RuntimeException('original conversion exception'); }
}), RuntimeException::class, 'original conversion exception');
echo "element specs, runtime data and diagnostics: passed\n";

$messages = [];
set_error_handler(function ($severity, $message) use (&$messages) {
    check($severity, E_DEPRECATED);
    $messages[] = $message;
    return true;
});
foreach (['int' => 0, 'float' => 0.0, 'bool' => false, 'string' => ''] as $kind => $expected) {
    check($parse('both', $kind, named: null), ['named' => $expected]);
}
restore_error_handler();
check(count($messages), 4);
// Zend uses the declared variadic parameter's position without a name.
foreach ($messages as $message) check(str_contains($message, 'Passing null to parameter #1 of type '), true);
$messages = [];
set_error_handler(function ($severity, $message) use (&$messages) {
    check($severity, E_DEPRECATED);
    $messages[] = $message;
    return true;
});
check($parse('both', 'int', 1.5, named: '2.5'), [1, 'named' => 2]);
restore_error_handler();
check(count($messages), 2);
foreach ($messages as $message) check(str_contains($message, 'loses precision'), true);
set_error_handler(fn ($severity, $message) => throw new ErrorException($message));
foreach (['int', 'float', 'bool', 'string'] as $kind) {
    fails(fn () => $parse('both', $kind, named: null), ErrorException::class, 'Passing null to parameter #1 of type ');
}
fails(fn () => $parse('both', 'int', named: 1.5), ErrorException::class, 'loses precision');
restore_error_handler();
echo "named null coercion and pending exceptions: passed\n";
?>
--EXPECT--
modes and argument counts: passed
element specs, runtime data and diagnostics: passed
named null coercion and pending exceptions: passed
