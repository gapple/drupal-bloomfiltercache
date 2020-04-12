# Bloom Filter Cache Decorator

Prevent one-hit wonder items from being stored to cache.

## Settings

Apply Bloom Filter to cache bins by adding configuration to your site's
`settings.php`. Only bins specified in `$settings['bloomfiltercache']['bins']`
are decorated.

```php
$settings['bloomfiltercache'] = [
  'default' => [
    // Expected number of cached items.
    'size' => \Drupal\Core\Cache\DatabaseBackend::DEFAULT_MAX_ROWS,
    // Probability for false positives as a decimal percentage.
    'probability' => 0.01,
    'lifetime' => \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT,
  ],
  'bins' => [
    // Enable this bin with default settings.
    'render' => [],
    // Enable this bin with specified options overridden.
    'page' => [
      'size' => 10000,
      'lifetime' => 86400,
    ],
  ],
];
```
