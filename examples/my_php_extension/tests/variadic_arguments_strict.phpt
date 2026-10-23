--TEST--
Variadic element parsing honors strict_types for positional and named arguments
--EXTENSIONS--
my_php_extension
--FILE--
<?php
declare(strict_types=1);
$parse = MyPHPExt\Test\makeFnClosure('variadic');
foreach (['int', 'float', 'bool', 'string'] as $kind) {
    $invalid = $kind === 'string' ? 42 : '2';
    foreach ([[$invalid], ['named' => $invalid], ['named' => null]] as $args) {
        try { $parse('both', $kind, ...$args); throw new Exception('accepted invalid argument'); }
        catch (TypeError $e) {
            $label = array_key_exists('named', $args) ? '($named)' : 'Argument #3';
            if (!str_contains($e->getMessage(), $label)) throw $e;
        }
    }
}
if ($parse('both', 'float', 1, named: 2) !== [1.0, 'named' => 2.0]) throw new Exception('int to float');
if ($parse('both', 'nullable-int', named: null) !== ['named' => null]) throw new Exception('nullable');
echo "strict variadic arguments: passed\n";
?>
--EXPECT--
strict variadic arguments: passed
