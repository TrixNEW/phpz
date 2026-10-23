--TEST--
expectArgs checks declarations in Debug, including closures of registered functions
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open is required');
?>
--FILE--
<?php
use function MyPHPExt\Test\checkArgumentDeclaration;

$debug = checkArgumentDeclaration('valid');
if (checkArgumentDeclaration('valid', 42) !== $debug) throw new Exception('Invalid build mode');
$closure = Closure::fromCallable('MyPHPExt\\Test\\checkArgumentDeclaration');
if ($closure('valid') !== $debug) throw new Exception('Invalid closure');
echo "matching declarations OK\n";

$probe = proc_open([PHP_BINARY, '-n', '-r', 'exit(extension_loaded("my_php_extension") ? 0 : 1);'], [], $pipes);
if (!is_resource($probe)) throw new RuntimeException('Cannot detect builtin extension');
$command = [PHP_BINARY, '-n'];
if (proc_close($probe) !== 0) {
    $module = realpath(__DIR__ . '/../modules/' . (PHP_OS_FAMILY === 'Windows' ? 'php_my_php_extension.dll' : 'my_php_extension.so'));
    if ($module === false) throw new RuntimeException('Cannot find extension module');
    array_push($command, '-d', 'extension=' . $module);
}
foreach (['fixed', 'required', 'variadic'] as $mode) {
    // One mismatch is enough to verify that closures retain declaration checks.
    foreach (($mode === 'fixed' ? [false, true] : [false]) as $asClosure) {
        $target = $asClosure
            ? "Closure::fromCallable('MyPHPExt\\\\Test\\\\checkArgumentDeclaration')"
            : "'MyPHPExt\\\\Test\\\\checkArgumentDeclaration'";
        $arguments = var_export($mode, true) . ($mode === 'fixed' ? '' : ', 42');
        $code = '$call = ' . $target . '; $call(' . $arguments . '); echo "returned";';
        $process = proc_open([...$command, '-r', $code], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start probe');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        if ($debug) {
            $counts = match ($mode) {
                'fixed' => 'specs require 1, fixed 1, variadic false',
                'required' => 'specs require 2, fixed 2, variadic false',
                'variadic' => 'specs require 1, fixed 2, variadic true',
            };
            $message = 'MyPHPExt\\Test\\checkArgumentDeclaration(): expectArgs declaration mismatch: ' . $counts
                . '; declaration requires 1, fixed 2, variadic false';
            if ($status === 0 || !str_contains($output, $message) || str_contains($output, 'returned')) {
                throw new Exception("Missing declaration diagnostic: $output");
            }
        } elseif ($status !== 0 || $output !== 'returned') {
            throw new Exception("Declaration checks were not removed: $output");
        }
    }
    echo "$mode OK\n";
}
?>
--EXPECT--
matching declarations OK
fixed OK
required OK
variadic OK
