<?php

require __DIR__ . '/../../../vendor/autoload.php';

use Freyr\Identity\Id;

echo "Testing UUID validation:\n\n";

echo "Test 1: Valid UUID\n";
try {
    $id = Id::fromString('01234567-89ab-cdef-0123-456789abcdef');
    echo "✅ Valid UUID accepted: " . $id . "\n\n";
} catch (\Exception $e) {
    echo "❌ Unexpected exception: " . $e->getMessage() . "\n\n";
}

echo "Test 2: Invalid UUID (not-a-uuid)\n";
try {
    Id::fromString('not-a-uuid');
    echo "❌ NO EXCEPTION THROWN - This is wrong!\n\n";
} catch (\Exception $e) {
    echo "✅ Exception thrown: " . get_class($e) . "\n";
    echo "   Message: " . $e->getMessage() . "\n\n";
}
