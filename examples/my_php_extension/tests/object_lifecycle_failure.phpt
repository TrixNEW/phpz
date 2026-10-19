--TEST--
Failed backing initialization or cloning never runs __destruct on the half-built object
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use MyPHPExt\Test\FallibleValue;
use function MyPHPExt\Test\failNextFallible;

class TracedFallible extends FallibleValue
{
    public function __destruct()
    {
        echo "destruct ", $this->value(), "\n";
    }
}

$a = new TracedFallible();
var_dump($a->value());

failNextFallible('init');
try {
    new TracedFallible();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

failNextFallible('clone');
try {
    clone $a;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

$b = clone $a;
var_dump($b->value());
unset($a, $b);
echo "done\n";
?>
--EXPECT--
int(7)
InitRefused while initializing MyPHPExt\Test\FallibleValue
CloneRefused while cloning MyPHPExt\Test\FallibleValue
int(7)
destruct 7
destruct 7
done
