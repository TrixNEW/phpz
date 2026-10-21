--TEST--
Last recorded E_ERROR is available during request shutdown
--EXTENSIONS--
my_php_extension
--INI--
display_errors=0
log_errors=0
--FILE--
<?php
register_shutdown_function(static function () {
    $last = MyPHPExt\Test\readLastError(false);
    var_dump($last === error_get_last());
    var_dump($last['type'] === E_ERROR);
    var_dump(str_contains($last['message'], 'fatal fixture'));
    var_dump($last['file'] === __FILE__);
    var_dump($last['line'] === $GLOBALS['fatalLine']);
});
$fatalLine = __LINE__ + 1;
throw new RuntimeException('fatal fixture');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
