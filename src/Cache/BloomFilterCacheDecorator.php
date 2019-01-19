<?php

namespace Drupal\bloomfiltercache\Cache;

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
   * The bloom filter.
   *
   * @var \Pleo\BloomFilter\BloomFilter
   */
  private $filter;

  /**
   * Flag if any new keys have been added to the filter.
   *
   * @var bool
   */
  private $filterUpdated = FALSE;

  /**
   * The id for this filter.
   *
   * @var string
   */
  private $bin;

  /**
   * Approximate number of items to be stored in the cache.
   *
   * @var int
   */
  private $approximateSize;

  /**
   * Probability of the bloom filter to return a false positive.
   *
   * @var float
   */
  private $falsePositiveProbability;

  /**
   * Create a new Bloom Filter Cache Decorator.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $decoratedCache
   *   The original cache service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $bloomFilterStorage
   *   The service to store bloom filter data.
   * @param string $bin
   *   The cache bin for this filter.
   * @param int $approximateSize
   *   Approximate number of items stored in the cache.
   * @param float $falsePositiveProbability
   *   Probability of the bloom filter returning a false positive.
   */
  public function __construct(CacheBackendInterface $decoratedCache, CacheBackendInterface $bloomFilterStorage, $bin, $approximateSize = 1000, $falsePositiveProbability = 0.01) {
    $this->decoratedCache = $decoratedCache;
    $this->storage = $bloomFilterStorage;
    $this->bin = $bin;
    $this->approximateSize = $approximateSize;
    $this->falsePositiveProbability = $falsePositiveProbability;
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
        $this->filter = BloomFilter::init($this->approximateSize, $this->falsePositiveProbability);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if ($this->filterUpdated) {
      $this->storage->set($this->getStorageCid(), $this->filter);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->decoratedCache->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return $this->decoratedCache->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->initializeFilter();

    if ($this->filter->exists($cid)) {
      $this->decoratedCache->set($cid, $data, $expire, $tags);
    }
    else {
      $this->filter->add($cid);
      $this->filterUpdated = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    $this->initializeFilter();

    foreach ($items as $cid => $item) {
      if (!$this->filter->exists($cid)) {
        unset($items[$cid]);
        $this->filter->add($cid);
        $this->filterUpdated = TRUE;
      }
    }

    $this->decoratedCache->setMultiple($items);
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
