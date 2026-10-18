--TEST--
Concurrent ZTS threads keep TSRM access, module globals, observers and native objects isolated
--EXTENSIONS--
my_php_extension
--SKIPIF--
<?php
if (!PHP_ZTS || !extension_loaded('pmmpthread')) die('skip requires ZTS PHP with pmmpthread');
?>
--FILE--
<?php
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

final class Hammer extends Thread
{
    public function __construct(private ThreadSafeArray $results, private int $id) {}

    public function run(): void
    {
        $failures = [];
        MyPHPExt\Metrics::reset();
        $rounds = 400 + $this->id * 50;
        for ($i = 0; $i < $rounds; $i++) {
            if (!MyPHPExt\Test\checkTsrmCache()) $failures['tsrm'] = true;
            if (greet("t{$this->id}") !== "Hello, t{$this->id}!") $failures['greet'] = true;
            if (MyPHPExt\Test\typedArgument('u8', true, '42') !== 42) $failures['coerce'] = true;
            $list = MyPHPExt\Test\buildList(64, 4, $i);
            if (count($list) !== 64 || $list[62] !== $i) $failures['list'] = true;
            $value = new MyPHPExt\Test\FallibleValue();
            $copy = clone $value;
            if ($copy->value() !== 7) $failures['object'] = true;
            if ($i % 50 === 0 && MyPHPExt\Test\allocatorAlignment() !== true) $failures['alloc'] = true;
            try {
                throw new RuntimeException('thread');
            } catch (RuntimeException) {
            }
        }
        $snapshot = MyPHPExt\Metrics::snapshot();
        // Observers and globals are per thread, so counts must match this thread exactly.
        if (($snapshot['function_calls']['by_name']['greet']['calls'] ?? -1) !== $rounds) $failures['globals'] = true;
        if (($snapshot['exceptions']['by_class']['RuntimeException'] ?? -1) !== $rounds) $failures['exceptions'] = true;
        $this->results[$this->id] = $failures === [] ? 'ok' : implode(',', array_keys($failures));
    }
}

$results = new ThreadSafeArray();
$threads = [];
for ($t = 0; $t < 8; $t++) {
    $threads[] = $thread = new Hammer($results, $t);
    $thread->start(Thread::INHERIT_ALL);
}
foreach ($threads as $thread) $thread->join();
var_dump(MyPHPExt\Test\checkTsrmCache());
for ($t = 0; $t < 8; $t++) echo $t, ': ', $results[$t] ?? 'missing', "\n";
?>
--EXPECT--
bool(true)
0: ok
1: ok
2: ok
3: ok
4: ok
5: ok
6: ok
7: ok
