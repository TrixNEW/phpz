--TEST--
HashTable reference counts, string keys, pointer values, dereferencing, capacity and reverse callbacks
--EXTENSIONS--
my_php_extension
--FILE--
<?php
$value = 7;
MyPHPExt\Test\checkArrayHashTable([&$value, 'reference' => &$value, 'plain' => 9, 'empty' => []]);
var_dump($value);
echo "HashTable APIs passed\n";
?>
--EXPECT--
int(7)
HashTable APIs passed
