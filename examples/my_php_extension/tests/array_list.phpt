--TEST--
Array list detection and conversion preserve value ownership and reference semantics
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\isArrayList;
use function MyPHPExt\Test\arrayToList;

function check($condition) {
    if (!$condition) throw new RuntimeException('check failed');
}
$withHole = [1, 2, 3];
unset($withHole[1]);
$mixedList = ['temporary' => 0, 0 => 1, 1 => 2];
unset($mixedList['temporary']);
foreach ([[], [1, 2], $withHole, $mixedList, [1 => 1, 0 => 2], [-1 => 1], ['01' => 1]] as $source) {
    check(isArrayList($source) === array_is_list($source));
    $result = arrayToList($source);
    check(isArrayList($result));
    check($result === array_values($source));
}

// The new list owns its values independently of the source.
$source = ['object' => new stdClass];
$weak = WeakReference::create($source['object']);
$result = arrayToList($source);
unset($source);
check($weak->get() === $result[0]);
unset($result);
check($weak->get() === null);

$value = 1;
$source = ['ref' => &$value];
$result = arrayToList($source);
$result[0] = 2;
check($value === 2 && $source['ref'] === 2);
unset($result, $source, $value);

// Zend unwraps references that have only one owner when copying.
$value = 1;
$source = ['ref' => &$value];
unset($value);
$result = arrayToList($source);
check(ReflectionReference::fromArrayElement($result, 0) === null);
$result[0] = 2;
check($source['ref'] === 1);
echo "Array list APIs passed\n";
?>
--EXPECT--
Array list APIs passed
