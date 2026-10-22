--TEST--
PHP exit signals remain pending and return UnwindExit across native boundaries
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open is required');
?>
--FILE--
<?php
$probe = proc_open([PHP_BINARY, '-n', '-r', 'exit(extension_loaded("my_php_extension") ? 0 : 1);'], [], $pipes);
if (!is_resource($probe)) throw new RuntimeException('Cannot detect builtin extension');
$builtin = proc_close($probe) === 0;
$command = [PHP_BINARY, '-n', '-d', 'display_errors=1', '-d', 'log_errors=0'];
if (!$builtin) {
    $module = realpath(__DIR__ . '/../modules/' . (PHP_OS_FAMILY === 'Windows' ? 'php_my_php_extension.dll' : 'my_php_extension.so'));
    if ($module === false) throw new RuntimeException('Cannot find extension module');
    $command[] = '-d';
    $command[] = 'extension=' . $module;
}
$cases = [
    'eval' => ['eval', "'exit(23);'"],
    'tryEval' => ['tryEval', "'exit(23);'"],
    'exit-zero' => ['tryEval', "'exit(0);'", '', 0],
    'callable' => ['callable', 'static fn() => exit(23)'],
    'function' => ['function', 'static fn() => exit(23)'],
    'discarded-result' => ['callable', 'static fn() => new Target', 'class Target { function __destruct() { exit(23); } }'],
    'new' => ['new', 'Target::class', 'class Target { function __construct() { exit(23); } }'],
    'clone' => ['clone', 'new Target', 'class Target { function __clone() { exit(23); } }'],
    'lookup' => ['lookup', "'MissingTarget'", 'spl_autoload_register(static fn() => exit(23));'],
    'constant' => ['constant', "'MissingTarget::VALUE'", 'spl_autoload_register(static fn() => exit(23));'],
    'classConstant' => ['classConstant', 'Target::class', 'class Target { const VALUE = MissingTarget::VALUE; } spl_autoload_register(static fn() => exit(23));'],
    'staticProperty' => ['staticProperty', 'Target::class', 'class Target { public static $value = MissingTarget::VALUE; } spl_autoload_register(static fn() => exit(23));'],
    'init' => ['init', 'Target::class', 'class Target { public $value = MissingTarget::VALUE; } spl_autoload_register(static fn() => exit(23));'],
    'get' => ['get', 'new Target', 'class Target { function __get($key) { exit(23); } }'],
    'set' => ['set', 'new Target', 'class Target { function __set($key, $value) { exit(23); } }'],
    'zvalSet' => ['zvalSet', 'new Target', 'class Target { function __set($key, $value) { exit(23); } }'],
    'dimension' => ['dimension', 'new Target', 'class Target implements ArrayAccess { function offsetGet($key): mixed { exit(23); } function offsetSet($key, $value): void {} function offsetExists($key): bool { return true; } function offsetUnset($key): void {} }'],
    'cast' => ['cast', 'new Target', 'class Target { function __toString(): string { exit(23); } }'],
    'convert' => ['convert', 'new Target', 'class Target { function __toString(): string { exit(23); } }'],
];
foreach (['apply', 'reverseApply', 'applyWithArg', 'compare', 'sort', 'throw', 'throwEx'] as $api) {
    $cases[$api] = [$api, 'null'];
}
$helpers = [
    'type' => TypeError::class,
    'argument-type' => TypeError::class,
    'argument-value' => ValueError::class,
    'argument-count' => ArgumentCountError::class,
    'extra-named' => ArgumentCountError::class,
    'wrong-count' => ArgumentCountError::class,
    'none' => ArgumentCountError::class,
    'error' => Error::class,
];
foreach ($helpers as $helper => $class) {
    $cases["helper-$helper-exit"] = ["helper-$helper", 'true'];
    $cases["helper-$helper-ordinary"] = ["helper-$helper", 'null', '', 0, 'PhpException', $class];
}
foreach (['argument-type', 'argument-value'] as $helper) {
    $cases["helper-$helper-pending"] = ["helper-$helper", "'pending'", '', 0, 'PhpException', Exception::class, 'original'];
}
$cases['argument-string-exit'] = ['argument-string', 'new Target', 'class Target { function __toString(): string { exit(23); } }'];
$cases['argument-string-exception'] = ['argument-string', 'new Target', 'class Target { function __toString(): string { throw new RuntimeException("original"); } }', 0, 'PhpException', RuntimeException::class, 'original'];
foreach (['stream', 'argument-stream'] as $api) {
    $setup = '$resource = fopen("php://memory", "r"); fclose($resource);';
    $cases["$api-exit"] = ["$api-exit", '$resource', $setup];
    $cases["$api-ordinary"] = [$api, '$resource', $setup, 0, 'PhpException', TypeError::class];
}
foreach (['new', 'new-direct'] as $api) {
    foreach ([0, 23] as $status) {
        // PHP 8.4 may attach the original exception to UnwindExit as a dynamic property.
        $setup = 'error_reporting(E_ALL & ~E_DEPRECATED);'
            . 'class ExitOnFree { function __destruct() { exit(' . $status . '); } }'
            . 'class Target { public $child; function __construct() { $this->child = new ExitOnFree; throw new RuntimeException("constructor"); }'
            . 'function __destruct() { echo "unexpected parent destructor\\n"; } }';
        $cases["$api-cleanup-exit-$status"] = [$api, 'Target::class', $setup, $status];
    }
    $setup = 'class Target { function __construct() { throw new RuntimeException("constructor"); }'
        . 'function __destruct() { echo "unexpected parent destructor\\n"; } }';
    $cases["$api-constructor-exception"] = [$api, 'Target::class', $setup, 0, 'PhpException', RuntimeException::class, 'constructor'];
}
$cases['ordinary-exception'] = ['callable', "static fn() => throw new RuntimeException('ordinary')", '', 0, 'PhpException'];
$cases['no-exception'] = ['none', 'null', '', 0, null];
foreach ($cases as $name => $case) {
    [$api, $value] = $case;
    $setup = $case[2] ?? '';
    $expectedStatus = $case[3] ?? 23;
    $expectedError = array_key_exists(4, $case) ? $case[4] : 'UnwindExit';
    $checkException = '';
    if (isset($case[5])) {
        $checkException .= 'if (get_class($e) !== ' . var_export($case[5], true) . ') throw new RuntimeException("Unexpected exception class");';
    }
    if (isset($case[6])) {
        $checkException .= 'if ($e->getMessage() !== ' . var_export($case[6], true) . ') throw new RuntimeException("Original exception replaced");';
    }
    $code = 'register_shutdown_function(static function() { echo "shutdown\n"; });' . $setup
        . 'try { MyPHPExt\\Test\\checkExceptionBoundary(' . var_export($api, true) . ', ' . $value . '); }'
        . 'catch (Throwable $e) {' . $checkException . ' echo "caught ordinary\n"; } echo "continued\n";';
    $process = proc_open([...$command, '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start test process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $expectedOut = ($expectedError === 'PhpException' ? "caught ordinary\ncontinued\n" : ($expectedError === null ? "continued\n" : '')) . "shutdown\n";
    $expectedErr = ($expectedError === null ? "Zig returned\n" : "Zig error: $expectedError; pending=true\n") . "Zig cleanup\n";
    if ($status !== $expectedStatus || $stdout !== $expectedOut || $stderr !== $expectedErr) {
        throw new RuntimeException("$name: exit=$status\nstdout: $stdout\nstderr: $stderr");
    }
    echo "PASS $name\n";
}
?>
--EXPECT--
PASS eval
PASS tryEval
PASS exit-zero
PASS callable
PASS function
PASS discarded-result
PASS new
PASS clone
PASS lookup
PASS constant
PASS classConstant
PASS staticProperty
PASS init
PASS get
PASS set
PASS zvalSet
PASS dimension
PASS cast
PASS convert
PASS apply
PASS reverseApply
PASS applyWithArg
PASS compare
PASS sort
PASS throw
PASS throwEx
PASS helper-type-exit
PASS helper-type-ordinary
PASS helper-argument-type-exit
PASS helper-argument-type-ordinary
PASS helper-argument-value-exit
PASS helper-argument-value-ordinary
PASS helper-argument-count-exit
PASS helper-argument-count-ordinary
PASS helper-extra-named-exit
PASS helper-extra-named-ordinary
PASS helper-wrong-count-exit
PASS helper-wrong-count-ordinary
PASS helper-none-exit
PASS helper-none-ordinary
PASS helper-error-exit
PASS helper-error-ordinary
PASS helper-argument-type-pending
PASS helper-argument-value-pending
PASS argument-string-exit
PASS argument-string-exception
PASS stream-exit
PASS stream-ordinary
PASS argument-stream-exit
PASS argument-stream-ordinary
PASS new-cleanup-exit-0
PASS new-cleanup-exit-23
PASS new-constructor-exception
PASS new-direct-cleanup-exit-0
PASS new-direct-cleanup-exit-23
PASS new-direct-constructor-exception
PASS ordinary-exception
PASS no-exception
