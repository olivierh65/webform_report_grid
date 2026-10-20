<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_report_grid\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\webform_report_grid\Service\ReportFieldDiscovery;

/**
 * Tests the ReportFieldDiscovery service (unit tests).
 *
 * @group webform_report_grid
 */
class ReportFieldDiscoveryTest extends UnitTestCase {

  /**
   * The field discovery service.
   *
   * @var \Drupal\webform_report_grid\Service\ReportFieldDiscovery
   */
  protected ReportFieldDiscovery $discovery;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a mock entity type manager.
    $entity_type_manager = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $token_service = $this->createMock(\Drupal\Core\Utility\Token::class);

    $this->discovery = new ReportFieldDiscovery($entity_type_manager, $token_service);
  }

  /**
   * Tests aggregations static method returns expected structure.
   */
  public function testAggregations(): void {
    $aggs = ReportFieldDiscovery::aggregations();

    $this->assertArrayHasKey('b', $aggs);
    $this->assertArrayHasKey('n', $aggs);
    $this->assertArrayHasKey('r', $aggs);
    $this->assertArrayHasKey('t', $aggs);

    $this->assertArrayHasKey('sum', $aggs['b']);
    $this->assertArrayHasKey('pct', $aggs['b']);

    $this->assertArrayHasKey('sum', $aggs['n']);
    $this->assertArrayHasKey('avg', $aggs['n']);
    $this->assertArrayHasKey('max', $aggs['n']);
    $this->assertArrayHasKey('min', $aggs['n']);

    $this->assertArrayHasKey('rep', $aggs['r']);

    $this->assertArrayHasKey('count', $aggs['t']);
    $this->assertArrayHasKey('list', $aggs['t']);
  }

  /**
   * Tests classification of known element types.
   */
  public function testClassifyKnownTypes(): void {
    $reflection = new \ReflectionClass($this->discovery);
    $method = $reflection->getMethod('classify');
    $method->setAccessible(TRUE);

    // Boolean.
    $this->assertEquals('b', $method->invoke($this->discovery, 'checkbox', []));

    // Numeric.
    $this->assertEquals('n', $method->invoke($this->discovery, 'number', []));
    $this->assertEquals('n', $method->invoke($this->discovery, 'range', []));
    $this->assertEquals('n', $method->invoke($this->discovery, 'webform_number', []));
    $this->assertEquals('n', $method->invoke($this->discovery, 'webform_slider', []));

    // Choice.
    $this->assertEquals('r', $method->invoke($this->discovery, 'radios', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'select', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'checkboxes', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'webform_radios', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'webform_select', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'webform_checkboxes', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'webform_autocomplete', []));
    $this->assertEquals('r', $method->invoke($this->discovery, 'webform_autocomplete_tags', []));

    // Text.
    $this->assertEquals('t', $method->invoke($this->discovery, 'textfield', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'textarea', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'email', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'tel', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'date', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'datetime', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'time', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_email', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_tel', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_textfield', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_textarea', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_date', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_datetime', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_time', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_url', []));
    $this->assertEquals('t', $method->invoke($this->discovery, 'webform_color', []));

    // Element with #options -> choice.
    $this->assertEquals('r', $method->invoke($this->discovery, 'unknown_type', ['#options' => ['a' => 'A', 'b' => 'B']]));

    // Unknown type -> excluded.
    $this->assertEquals('x', $method->invoke($this->discovery, 'unknown_type', []));
  }

  /**
   * Tests composite detection.
   */
  public function testIsComposite(): void {
    $reflection = new \ReflectionClass($this->discovery);
    $method = $reflection->getMethod('isComposite');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($this->discovery, 'identity_login_composite', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'name', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'address', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'email', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'phone', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'webform_name', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'webform_address', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'webform_email', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'webform_phone', 'key'));
    $this->assertTrue($method->invoke($this->discovery, 'my_custom_composite', 'key')); // *_composite suffix

    $this->assertFalse($method->invoke($this->discovery, 'textfield', 'key'));
    $this->assertFalse($method->invoke($this->discovery, 'select', 'key'));
  }

  /**
   * Tests options flattening.
   */
  public function testFlattenOptions(): void {
    $reflection = new \ReflectionClass($this->discovery);
    $method = $reflection->getMethod('flattenOptions');
    $method->setAccessible(TRUE);

    // Simple flat options.
    $input = ['a' => 'Option A', 'b' => 'Option B'];
    $expected = ['a' => 'Option A', 'b' => 'Option B'];
    $this->assertEquals($expected, $method->invoke($this->discovery, $input));

    // With optgroups (nested arrays) - should skip optgroups.
    $input = ['a' => 'Option A', 'group' => ['b' => 'Option B']];
    $expected = ['a' => 'Option A'];
    $this->assertEquals($expected, $method->invoke($this->discovery, $input));

    // Empty.
    $this->assertEquals([], $method->invoke($this->discovery, []));
  }

}