--TEST--
Native Zig threads isolate TSRM caches and module globals across thread resource recreation
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip requires ZTS PHP');
?>
--FILE--
<?php
// Each invocation joins every worker; repeat to also exercise new OS threads.
var_dump(MyPHPExt\Test\checkNativeTsrmThreads());
var_dump(MyPHPExt\Test\checkNativeTsrmThreads());
// The calling thread must remain usable after worker teardown.
echo greet('main'), "\n";
?>
--EXPECT--
bool(true)
bool(true)
Hello, main!
