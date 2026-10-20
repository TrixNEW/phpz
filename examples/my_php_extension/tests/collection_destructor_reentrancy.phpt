--TEST--
Collection rejects method reentry from an element destructor after backing is cleared
--EXTENSIONS--
my_php_extension
--FILE--
<?php
final class ReentrantCollectionElement
{
    public WeakReference $owner;

    public function __destruct()
    {
        // Native backing is cleared before zend_object_std_dtor invalidates
        // the owner's weak references, so the owner is still reachable here.
        $owner = $this->owner->get();
        echo 'owner visible: ', $owner !== null ? 'yes' : 'no', "\n";
        if ($owner !== null) {
            try {
                $owner->count();
                echo "unexpected method success\n";
            } catch (Error $error) {
                echo get_class($error), ': ', $error->getMessage(), "\n";
            }
        }
    }
}

$element = new ReentrantCollectionElement();
$collection = new MyPHPExt\Collection([$element]);
$weak = WeakReference::create($collection);
$element->owner = $weak;

// Leave the collection as the element's only owner. Releasing the collection
// then destroys the element during native backing cleanup, without a cycle.
unset($element);
unset($collection);

echo 'owner released: ', $weak->get() === null ? 'yes' : 'no', "\n";
echo "completed\n";
?>
--EXPECT--
owner visible: yes
Error: MyPHPExt\Collection object has no initialized backing
owner released: yes
completed
