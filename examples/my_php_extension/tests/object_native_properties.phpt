--TEST--
Native object property access preserves scope, ownership and exceptions
--EXTENSIONS--
my_php_extension
--FILE--
<?php
use function MyPHPExt\Test\{readObjectNative, writeObjectNative};

function check(bool $ok): void {
    if (!$ok) throw new Exception('check failed');
}

foreach (['str', 'handler', 'handler-str', 'standard'] as $api) {
    $object = new stdClass;
    $input = new stdClass;
    $weak = WeakReference::create($input);
    writeObjectNative($object, 'item', $input, $api);
    unset($input);
    check($weak->get() === $object->item);
    foreach (['str', 'handler', 'handler-str'] as $reader) {
        $result = readObjectNative($object, 'item', $reader);
        check($result === $object->item);
        unset($result);
    }
    unset($object);
    check($weak->get() === null);
}

foreach (['string', 'zend-string'] as $api) {
    $object = new stdClass;
    writeObjectNative($object, "binary\0key", "a\0b", $api);
    check(readObjectNative($object, "binary\0key", 'str') === "a\0b");
}
check(readObjectNative(new stdClass, 'absent', 'isset') === null);

class Fixture {
    private int $secret = 42;
    public int $number = 1;
    public function __get(string $name): object {
        if ($name === 'fail') throw new Exception('read');
        return (object)['magic' => true];
    }
    public function __set(string $name, mixed $value): void {
        throw new Exception('write');
    }
}
$object = new Fixture;
check(readObjectNative($object, 'secret', 'str') === 42);
check(readObjectNative($object, 'secret', 'handler')->magic);
foreach (['str', 'handler', 'handler-str'] as $api) {
    $result = readObjectNative($object, 'magic', $api);
    check($result->magic);
    $weak = WeakReference::create($result);
    unset($result);
    check($weak->get() === null);
    try {
        readObjectNative($object, 'fail', $api);
        throw new Exception('expected read exception');
    } catch (Exception $error) {
        check($error->getMessage() === 'read');
    }
}
foreach (['str', 'string', 'zend-string', 'handler', 'handler-str', 'standard'] as $api) {
    try {
        writeObjectNative($object, 'fail', 'payload', $api);
        throw new Exception('expected write exception');
    } catch (Exception $error) {
        check($error->getMessage() === 'write');
    }
    try {
        writeObjectNative($object, 'number', 'non-numeric', $api);
        throw new Exception('expected TypeError');
    } catch (TypeError $error) {}
    check($object->number === 1);
}
foreach (['str', 'handler', 'handler-str', 'standard'] as $api) {
    $input = new stdClass;
    $weak = WeakReference::create($input);
    try {
        writeObjectNative($object, 'fail', $input, $api);
        throw new Exception('expected write exception');
    } catch (Exception $error) {
        check($error->getMessage() === 'write');
    }
    check($weak->get() === $input);
    unset($input, $error);
    check($weak->get() === null);
}
echo "Native object properties passed\n";
?>
--EXPECT--
Native object properties passed
