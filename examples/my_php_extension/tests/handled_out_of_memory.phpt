--TEST--
An already handled OOM preserves its diagnostic and outer Zig cleanup
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (ini_get('memory_hard_limit_percent') === false) {
    die('skip delayed OOM is unavailable');
}
if (memory_get_usage() === 0) {
    die('skip Zend memory manager is disabled');
}
?>
--INI--
memory_limit=16M
display_errors=0
log_errors=0
--FILE--
<?php
register_shutdown_function(static function () {
    $error = error_get_last();
    var_dump($error['type'] === E_ERROR);
    var_dump(str_starts_with($error['message'], 'Allowed memory size of '));
});
MyPHPExt\Test\guardResources('outer', static function () {
    MyPHPExt\Test\checkExceptionBoundary('oom-handled', static function () {
        echo "unreachable callback\n";
    });
    echo "unreachable outer callback\n";
});
echo "unreachable\n";
?>
--EXPECT--
Zig error: OutOfMemory; pending=true
Zig cleanup
defer outer
cleanup outer 2
cleanup outer 1
bool(true)
bool(true)
