<?php

namespace Drupal\bloomfiltercache\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DestructableInterface;
use Pleo\BloomFilter\BloomFilter;

/**
 * Class BloomFilterCache.
 *
 * @package Drupal\bloomfiltercache
 */
class BloomFilterCacheDecorator implements CacheBackendInterface, DestructableInterface {

  /**
   * The decorated cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $decoratedCache;

  /**
   * The cache to store filter data.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $storage;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private $time;

  /**
   * The bloom filter.
   *
   * @var \Pleo\BloomFilter\BloomFilter
   */
  private $filter;

  /**
   * Array of new keys to be added to the filter.
   *
   * @var array
   */
  private $filterAdditions = [];

  /**
   * The id for this filter.
   *
   * @var string
   */
  private $bin;

  /**
   * Options for the bloom filter.
   *
   * @var array
   */
  private $filterOptions;

  /**
   * Create a new Bloom Filter Cache Decorator.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $decoratedCache
   *   The original cache service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $bloomFilterStorage
   *   The service to store bloom filter data.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param string $bin
   *   The cache bin for this filter.
   * @param array $filterOptions
   *   A keyed array of options to provide to the bloom filter.
   */
  public function __construct(CacheBackendInterface $decoratedCache, CacheBackendInterface $bloomFilterStorage, TimeInterface $time, $bin, array $filterOptions = []) {
    $this->decoratedCache = $decoratedCache;
    $this->storage = $bloomFilterStorage;
    $this->time = $time;
    $this->bin = $bin;
    $this->filterOptions = $filterOptions +
      [
        'size' => 1000,
        'probability' => 0.01,
        'lifetime' => CacheBackendInterface::CACHE_PERMANENT,
      ];
  }

  /**
   * Get the id for persisting the bloom filter to cache.
   *
   * @return string
   *   The bloom filter cache id.
   */
  private function getStorageCid() {
    return 'bloomfiltercache.' . $this->bin;
  }

  /**
   * Initialize the bloom filter, loading from cache if possible.
   */
  private function initializeFilter() {
    if (is_null($this->filter)) {
      if ($cacheItem = $this->storage->get($this->getStorageCid())) {
        $this->filter = $cacheItem->data;
      }
      else {
        $this->filter = BloomFilter::init($this->filterOptions['size'], $this->filterOptions['probability']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if (empty($this->filterAdditions)) {
      return;
    }

    $this->initializeFilter();

    // Filter list to new cids.
    $additions = array_filter(
      array_keys($this->filterAdditions),
      function ($item) {
        return !$this->filter->exists($item);
      }
    );

    // Only update stored filter if changes have been added.
    if (!empty($additions)) {
      foreach ($additions as $cid) {
        $this->filter->add($cid);
      }

      $this->storage->set(
        $this->getStorageCid(),
        $this->filter,
        $this->filterOptions['lifetime'] == CacheBackendInterface::CACHE_PERMANENT ?
          CacheBackendInterface::CACHE_PERMANENT :
          $this->time->getRequestTime() + $this->filterOptions['lifetime']
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $cids = [$cid];
    $cache = $this->getMultiple($cids, $allow_invalid);
    return reset($cache);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    foreach ($cids as $cid) {
      $this->filterAdditions[$cid] = TRUE;
    }

    $items = $this->decoratedCache->getMultiple($cids, $allow_invalid);

    if (!empty($items)) {
      // Add cids returned from the cache so that any sets on the same cid
      // during this request overwrite the cached data.
      $this->initializeFilter();

      foreach ($items as $cid => $item) {
        $this->filter->add($cid);
      }
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->setMultiple([
      $cid => [
        'data' => $data,
        'expire' => $expire,
        'tags' => $tags,
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    $this->initializeFilter();

    foreach ($items as $cid => $item) {
      if (!$this->filter->exists($cid)) {
        unset($items[$cid]);
      }
    }

    if (!empty($items)) {
      $this->decoratedCache->setMultiple($items);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    return $this->decoratedCache->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    return $this->decoratedCache->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    return $this->decoratedCache->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    return $this->decoratedCache->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    return $this->decoratedCache->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    return $this->decoratedCache->invalidateAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    return $this->decoratedCache->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->storage->delete($this->getStorageCid());

    return $this->decoratedCache->removeBin();
  }

}
