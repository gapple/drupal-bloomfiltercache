<?php

namespace Drupal\Tests\bloomfiltercache\Cache;

use Drupal\bloomfiltercache\Cache\BloomFilterCacheDecorator;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Tests\UnitTestCase;

/**
 * Class BloomFilterDecoratorTests.
 *
 * @coversDefaultClass \Drupal\bloomfiltercache\Cache\BloomFilterCacheDecorator
 */
class BloomFilterDecoratorTests extends UnitTestCase {

  /**
   * The Bloom Filter Cache Decorator under test.
   *
   * @var \Drupal\bloomfiltercache\Cache\BloomFilterCacheDecorator
   */
  private $bloomFilterCacheDecorator;

  /**
   * The decorated cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  private $decoratedCache;

  /**
   * The storage cache for bloom filters.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  private $storageCache;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->decoratedCache = $this->createMock(MemoryBackend::class);
    $this->storageCache = $this->createMock(MemoryBackend::class);
    /** @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit_Framework_MockObject_MockObject $timeService */
    $timeService = $this->createMock(TimeInterface::class);

    $this->bloomFilterCacheDecorator = new BloomFilterCacheDecorator(
      $this->decoratedCache,
      $this->storageCache,
      $timeService,
      'test'
    );
  }

  /**
   * Test that an item is not set to the decorated cache on first attempt.
   *
   * @covers ::__construct
   * @covers ::getStorageCid
   * @covers ::initializeFilter
   * @covers ::get
   * @covers ::getMultiple
   * @covers ::set
   * @covers ::setMultiple
   */
  public function testFirstSet() {
    $this->decoratedCache->expects($this->atLeastOnce())
      ->method('getMultiple')
      ->with(['testcid'])
      ->willReturn([]);
    // Set shouldn't pass through on the first request, no matter how many times
    // it is called.
    $this->decoratedCache->expects($this->never())
      ->method('set');

    $this->bloomFilterCacheDecorator->get('testcid');
    $this->bloomFilterCacheDecorator->set('testcid', 'testValue');
    $this->bloomFilterCacheDecorator->get('testcid');
    $this->bloomFilterCacheDecorator->set('testcid', 'testValue');
  }

  /**
   * Simulate a second request where the set should pass through.
   *
   * @covers ::__construct
   * @covers ::initializeFilter
   * @covers ::getStorageCid
   * @covers ::destruct
   * @covers ::get
   * @covers ::getMultiple
   * @covers ::set
   * @covers ::setMultiple
   */
  public function testSecondSet() {
    $this->decoratedCache->expects($this->atLeastOnce())
      ->method('getMultiple')
      ->with(['testcid'])
      ->willReturn([]);
    $this->storageCache->expects($this->once())
      ->method('set')
      ->with('bloomfiltercache.test', $this->anything());
    $this->decoratedCache->expects($this->once())
      ->method('setMultiple')
      ->with([
        'testcid' => [
          'data' => 'testValue',
          'expire' => -1,
          'tags' => [],
        ],
      ]);

    $this->bloomFilterCacheDecorator->get('testcid');
    $this->bloomFilterCacheDecorator->set('testcid', 'testValue');

    // Simulate end of first request - new filter entries should be persisted.
    $this->bloomFilterCacheDecorator->destruct();

    $this->bloomFilterCacheDecorator->get('testcid');
    $this->bloomFilterCacheDecorator->set('testcid', 'testValue');
  }

  /**
   * Test that the filter in storage is deleted if bin removed.
   *
   * @covers ::removeBin
   */
  public function testRemoveBin() {
    $this->storageCache->expects($this->once())
      ->method('delete')
      ->with('bloomfiltercache.test');
    $this->decoratedCache->expects($this->once())
      ->method('removeBin');

    $this->bloomFilterCacheDecorator->removeBin();
  }

}
