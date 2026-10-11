--TEST--
Zend string allocation, copy-on-write, terminators, hash and interned refcounts
--EXTENSIONS--
my_php_extension
--FILE--
<?php
MyPHPExt\Test\checkZendStrings();
echo "Zend string storage passed\n";
?>
--EXPECT--
Zend string storage passed
