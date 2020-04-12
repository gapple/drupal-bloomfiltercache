<?php

namespace Drupal\bloomfiltercache\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Cache Factory Decorator to add bloom filter to requested cache bins.
 */
class BloomFilterCacheFactoryDecorator implements CacheFactoryInterface, ContainerAwareInterface, DestructableInterface {

  use ContainerAwareTrait;

  /**
   * The initialized Bloom Filter decorators.
   *
   * @var \Drupal\bloomfiltercache\Cache\BloomFilterCacheDecorator[]
   */
  private $backends = [];

  /**
   * The decorated Cache Factory service.
   *
   * @var \Drupal\Core\Cache\CacheFactoryInterface
   */
  private $decoratedFactory;

  /**
   * Cache Backend for storing bloom filter data.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $bloomFilterStorage;

  /**
   * The Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private $time;

  /**
   * The Lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  private $lock;

  /**
   * The Settings service.
   *
   * @var \Drupal\Core\Site\Settings
   */
  private $settings;

  /**
   * BloomFilterCacheFactoryDecorator constructor.
   *
   * @param \Drupal\Core\Cache\CacheFactoryInterface $decoratedFactory
   *   The decorated Cache Factory service.
   * @param \Drupal\Core\Site\Settings $settings
   *   The Settings service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The Time service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The Lock service.
   */
  public function __construct(CacheFactoryInterface $decoratedFactory, Settings $settings, TimeInterface $time, LockBackendInterface $lock) {
    $this->decoratedFactory = $decoratedFactory;
    $this->settings = $settings;
    $this->time = $time;
    $this->lock = $lock;

    // The bloom filter cache storage bin can't be injected into this factory,
    // because it would result in a circular reference.
    $this->bloomFilterStorage = $this->decoratedFactory->get('bloomfilter');
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    // Make sure the bloom filter data storage is not decorated.
    if ($bin == 'bloomfilter') {
      return $this->bloomFilterStorage;
    }

    if (isset($this->backends[$bin])) {
      return $this->backends[$bin];
    }

    $backend = $this->decoratedFactory->get($bin);

    $bloomFilterSettings = $this->settings->get('bloomfiltercache');
    if (isset($bloomFilterSettings['bins'][$bin])) {
      $backend = new BloomFilterCacheDecorator(
        $bin,
        $backend,
        $this->bloomFilterStorage,
        $this->time,
        $this->lock,
        $bloomFilterSettings['bins'][$bin] + ($bloomFilterSettings['default'] ?? [])
      );
      $this->backends[$bin] = $backend;
    }

    return $backend;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    foreach ($this->backends as $backend) {
      $backend->destruct();
    }
  }

}
