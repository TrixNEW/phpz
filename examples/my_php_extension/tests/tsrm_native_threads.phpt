--TEST--
Native Zig threads isolate TSRM caches, concurrent PHP requests, exceptions and observers
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip requires ZTS PHP');
if (PHP_SAPI !== 'cli') die('skip requires CLI SAPI');
// Windows CLI must refresh its private TSRMLS cache when a worker starts a request.
// https://github.com/php/php-src/issues/22567
if (PHP_OS_FAMILY === 'Windows' && (
    PHP_VERSION_ID < 80427 ||
    (PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80512)
)) {
    die('skip requires Windows CLI fix GH-22567');
}
?>
--FILE--
<?php
MyPHPExt\Metrics::reset();
try {
    throw new RuntimeException('main');
} catch (RuntimeException) {
}
// Workers synchronize active requests, repeat requests on the same TSRM storage,
// then free/recreate that storage. Repeat the helper to also exercise new OS threads.
var_dump(MyPHPExt\Test\checkNativeTsrmThreads());
var_dump(MyPHPExt\Test\checkNativeTsrmThreads());
// The calling thread must remain usable after worker teardown.
echo greet('main'), "\n";
$snapshot = MyPHPExt\Metrics::snapshot();
var_dump($snapshot['function_calls']['by_name']['greet']['calls']);
var_dump($snapshot['exceptions']['by_class']['RuntimeException']);
?>
--EXPECT--
bool(true)
bool(true)
Hello, main!
int(1)
int(1)
