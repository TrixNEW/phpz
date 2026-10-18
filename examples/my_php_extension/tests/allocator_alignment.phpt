--TEST--
php_allocator honours alignments above the Zend MM alignment and resizes in place
--EXTENSIONS--
my_php_extension
--FILE--
<?php
var_dump(MyPHPExt\Test\allocatorAlignment());
?>
--EXPECT--
bool(true)
