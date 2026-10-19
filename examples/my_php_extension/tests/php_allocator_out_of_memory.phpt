--TEST--
PHP allocator OutOfMemory preserves its diagnostic and a later native OutOfMemory is reported
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (memory_get_usage() === 0) {
    die('skip Zend memory manager is disabled');
}
?>
--INI--
display_errors=0
log_errors=0
--FILE--
<?php
register_shutdown_function(static function () use (&$line) {
    $error = error_get_last();
    var_dump($error['type'] === E_ERROR);
    var_dump(str_starts_with($error['message'], 'Allowed memory size of '));
    var_dump($error['file'] === __FILE__);
    var_dump($error['line'] === $line);

    ini_set('display_errors', '1');
    MyPHPExt\Test\nativeOutOfMemory();
    echo "unreachable shutdown\n";
});
// Leave less room than allocatorAlignment's first 3 MiB allocation requires.
ini_set('memory_limit', (string) (memory_get_usage(true) + 2 * 1024 * 1024));
$line = __LINE__ + 1;
MyPHPExt\Test\allocatorAlignment();
echo "unreachable\n";
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)

Fatal error: Out of memory in %s on line %d
