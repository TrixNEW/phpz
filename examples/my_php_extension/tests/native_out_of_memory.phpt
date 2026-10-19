--TEST--
An OutOfMemory error from a native allocator becomes a PHP fatal error, not a silent exit
--EXTENSIONS--
my_php_extension
--FILE--
<?php
register_shutdown_function(fn () => print("shutdown ran\n"));
echo "before\n";
MyPHPExt\Test\nativeOutOfMemory();
echo "unreachable\n";
?>
--EXPECTF--
before

Fatal error: Out of memory in %s on line %d
shutdown ran
