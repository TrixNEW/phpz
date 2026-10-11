--TEST--
Object dimension handlers support ArrayAccess, ownership and exceptions
--EXTENSIONS--
my_php_extension
spl
--FILE--
<?php
use function MyPHPExt\Test\objectDimension;

function check(bool $ok): void {
    if (!$ok) throw new Exception('check failed');
}

class Offsets implements ArrayAccess {
    public array $data = [];
    public function offsetGet(mixed $key): mixed {
        if ($key === 'fail') throw new Exception('get');
        return $this->data[$key] ?? null;
    }
    public function offsetSet(mixed $key, mixed $value): void {
        if ($key === 'fail') throw new Exception('set');
        if ($key === null) $this->data[] = $value;
        else $this->data[$key] = $value;
    }
    public function offsetExists(mixed $key): bool {
        if ($key === 'fail') throw new Exception('exists');
        return isset($this->data[$key]);
    }
    public function offsetUnset(mixed $key): void {
        if ($key === 'fail') throw new Exception('unset');
        unset($this->data[$key]);
    }
}
foreach ([new Offsets, new ArrayObject] as $object) {
    $input = (object)['x' => 1];
    $weak = WeakReference::create($input);
    objectDimension($object, 'write', 'key', $input);
    unset($input);
    $result = objectDimension($object, 'read', 'key', null);
    check($result->x === 1 && $weak->get() === $result);
    unset($result);
    check(objectDimension($object, 'isset', 'key', null));
    check(objectDimension($object, 'not-empty', 'key', null));
    objectDimension($object, 'write', 'zero', 0);
    check(objectDimension($object, 'isset', 'zero', null));
    check(!objectDimension($object, 'not-empty', 'zero', null));
    objectDimension($object, 'write', 'nil', null);
    check(!objectDimension($object, 'isset', 'nil', null));
    objectDimension($object, 'append', null, 'tail');
    check(objectDimension($object, 'read', 0, null) === 'tail');
    objectDimension($object, 'unset', 'key', null);
    check(!objectDimension($object, 'isset', 'key', null));
    check($weak->get() === null);
}
$object = new Offsets;
foreach (['read' => 'get', 'write' => 'set', 'isset' => 'exists', 'not-empty' => 'exists', 'unset' => 'unset'] as $action => $message) {
    try {
        objectDimension($object, $action, 'fail', null);
        throw new Exception('expected offset exception');
    } catch (Exception $error) {
        check($error->getMessage() === $message);
    }
}
foreach (['read', 'write', 'append', 'isset', 'not-empty', 'unset'] as $action) {
    try {
        objectDimension(new stdClass, $action, 'key', null);
        throw new Exception('expected unsupported dimension error');
    } catch (Error $error) {}
}
echo "Object dimensions passed\n";
?>
--EXPECT--
Object dimensions passed
