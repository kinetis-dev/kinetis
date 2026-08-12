<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for Kinetis\SimpleCache\RedisSimpleCache
 * — set()/get()/has()/delete()/getMultiple()/setMultiple()/
 * deleteMultiple()/clear(), a non-scalar value round-tripping through
 * serialization, and a TTL actually expiring against a real Redis.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\SimpleCache\RedisSimpleCache;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

$config = new Config([
    'REDIS_HOST' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'REDIS_PORT' => getenv('REDIS_PORT') ?: '6379',
]);

$cache = RedisSimpleCache::fromConfig($config);

if ($cache === null) {
    fwrite(STDERR, "RedisSimpleCache::fromConfig() returned null — check REDIS_HOST/REDIS_PORT.\n");
    exit(1);
}

$cache->clear();

check('set()/get() round-trips a scalar', $cache->set('greeting', 'hello') && $cache->get('greeting') === 'hello');
check('set()/get() round-trips a non-scalar value', $cache->set('user', ['id' => 1, 'name' => 'Alon']) && $cache->get('user') === ['id' => 1, 'name' => 'Alon']);
check('has() reflects a stored key', $cache->has('greeting'));
check('has() is false for a missing key', !$cache->has('missing-key'));

check('delete() removes the key', $cache->delete('greeting') && !$cache->has('greeting'));

check('get() with a default returns it for a missing key', $cache->get('nope', 'fallback') === 'fallback');

$cache->set('ttl-key', 'will-expire', ttl: 1);
check('a TTL-bearing key exists immediately', $cache->has('ttl-key'));
sleep(2);
check('a TTL-bearing key actually expires', !$cache->has('ttl-key'));

$cache->set('zero-ttl', 'value', ttl: 0);
check('a zero TTL deletes rather than writing', !$cache->has('zero-ttl'));

check('setMultiple() stores every key', $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]));
$multi = $cache->getMultiple(['a', 'b', 'c', 'missing'], default: 'none');
check('getMultiple() reads every key back, missing ones default', iterator_to_array($multi) === ['a' => 1, 'b' => 2, 'c' => 3, 'missing' => 'none']);

check('deleteMultiple() removes every key', $cache->deleteMultiple(['a', 'b', 'c']) && !$cache->has('a') && !$cache->has('b') && !$cache->has('c'));

$cache->set('will-clear', 'value');
check('clear() wipes everything', $cache->clear() && !$cache->has('will-clear'));

echo "ALL CHECKS PASSED\n";
