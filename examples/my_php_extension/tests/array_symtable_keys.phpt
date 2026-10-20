--TEST--
symtable lookups, updates and deletes follow PHP's numeric string key rules; raw lookups do not
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\lookupKey;
use function MyPHPExt\Test\storeKey;
use function MyPHPExt\Test\deleteKey;

$values = [1 => 'one', -5 => 'minus five', 'x' => 'ex', '01' => 'zero one', 'drop' => true];
foreach (['1', '-5', 'x', '01', '1.0', ' 1'] as $key) {
    printf("%-4s symtable=%s raw=%s php=%s\n", var_export($key, true),
        var_export(lookupKey($values, $key, true), true),
        var_export(lookupKey($values, $key, false), true),
        var_export($values[$key] ?? null, true));
}
$stored = storeKey($values, '7', 'seven');
var_dump(array_key_exists(7, $stored), isset($stored['7']), array_key_exists('drop', $stored), count($values));

foreach (['', '-', '0', '-1', '01'] as $key) {
    $stored = storeKey([], $key, 10);
    $stored = storeKey($stored, $key, 20);
    if ($stored !== [$key => 20] || lookupKey($stored, $key, true) !== 20) {
        throw new RuntimeException('symtable update/find mismatch');
    }
    if (deleteKey($stored, $key) !== [] || lookupKey([], $key, true) !== null) {
        throw new RuntimeException('symtable delete/missing lookup mismatch');
    }
}
echo "edge keys passed\n";
?>
--EXPECT--
'1'  symtable='one' raw=NULL php='one'
'-5' symtable='minus five' raw=NULL php='minus five'
'x'  symtable='ex' raw='ex' php='ex'
'01' symtable='zero one' raw='zero one' php='zero one'
'1.0' symtable=NULL raw=NULL php=NULL
' 1' symtable=NULL raw=NULL php=NULL
bool(true)
bool(true)
bool(false)
int(5)
edge keys passed
