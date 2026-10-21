--TEST--
Last recorded PHP errors match error_get_last and remain separate from pending exceptions
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\readLastError;

error_clear_last();
var_dump(readLastError(false) === null);
@trigger_error('recorded warning', E_USER_WARNING);
$expected = error_get_last();
$snapshot = readLastError(false);
var_dump($snapshot === $expected);
var_dump(readLastError(false) === $expected);
var_dump(readLastError(true) === $expected);
var_dump(error_get_last() === $expected);

set_error_handler(static fn () => true);
trigger_error('handled warning', E_USER_WARNING);
restore_error_handler();
var_dump(readLastError(false) === $expected);

@trigger_error('replacement notice', E_USER_NOTICE);
var_dump(readLastError(false) === error_get_last());
var_dump(readLastError(false)['type'] === E_USER_NOTICE);
var_dump($snapshot === $expected);
error_clear_last();
var_dump(readLastError(false) === null);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
