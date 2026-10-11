--TEST--
Object property views preserve ownership and release results on exception
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\{objectView, checkObjectViews};

function check(bool $ok): void {
    if (!$ok) throw new Exception('check failed');
}

checkObjectViews([]);
foreach (['debug', 'array_cast', 'serialize', 'var_export', 'json'] as $purpose) {
    $object = (object)['item' => new stdClass];
    $weak = WeakReference::create($object->item);
    $view = objectView($object, $purpose);
    check($view['item'] === $object->item);
    unset($object);
    check($weak->get() === $view['item']);
    unset($view);
    check($weak->get() === null);
}

class DebugView {
    public int $value = 42;
    public function __debugInfo(): array { return ['debug' => true]; }
}
$object = new DebugView;
check(objectView($object, 'debug') === ['debug' => true]);
check(objectView($object, 'array_cast') === ['value' => 42]);
echo "Object property views passed\n";
?>
--EXPECT--
Object property views passed
