--TEST--
my_php_extension allocator bailout preserves Zig cleanup
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
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
MyPHPExt\Test\allocatorBailout();
echo "unreachable\n";
?>
--EXPECT--
out_of_memory=1 scope_defer=1 cleanup_defer=1 freed_blocks=1
