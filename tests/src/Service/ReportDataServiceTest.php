<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_report_grid\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\webform_report_grid\Service\ReportDataService;
use Drupal\webform_report_grid\Service\ReportExportService;
use Drupal\webform_report_grid\Service\ReportFieldDiscovery;

/**
 * Tests the ReportDataService aggregate logic (unit tests).
 *
 * @group webform_report_grid
 */
class ReportDataServiceTest extends UnitTestCase {

  /**
   * The data service.
   *
   * @var \Drupal\webform_report_grid\Service\ReportDataService
   */
  protected ReportDataService $dataService;

  /**
   * The field discovery service (mocked).
   *
   * @var \Drupal\webform_report_grid\Service\ReportFieldDiscovery|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $discovery;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->discovery = $this->createMock(ReportFieldDiscovery::class);

    $entity_type_manager = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $current_user = $this->createMock(\Drupal\Core\Session\AccountInterface::class);
    $current_user->method('hasPermission')->willReturn(TRUE);

    $this->dataService = new ReportDataService($entity_type_manager, $current_user, $this->discovery);
  }

  /**
   * Tests aggregate for choice field (breakdown).
   */
  public function testAggregateChoiceBreakdown(): void {
    $field = ['kind' => 'r', 'options' => ['a' => 'Option A', 'b' => 'Option B']];
    $card = ['field' => 'test_field', 'agg' => 'rep'];

    $rows = [
      ['f' => ['test_field' => 'a'], 'n' => 'User 1'],
      ['f' => ['test_field' => 'b'], 'n' => 'User 2'],
      ['f' => ['test_field' => 'a'], 'n' => 'User 3'],
      ['f' => ['test_field' => 'b'], 'n' => 'User 4'],
      ['f' => ['test_field' => 'a'], 'n' => 'User 5'],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertArrayHasKey('rep', $result);
    $this->assertEquals(3, $result['rep']['a']);
    $this->assertEquals(2, $result['rep']['b']);

    $this->assertArrayHasKey('groups', $result);
    $this->assertCount(3, $result['groups']['a']);
    $this->assertCount(2, $result['groups']['b']);
  }

  /**
   * Tests aggregate for number field (sum).
   */
  public function testAggregateNumberSum(): void {
    $field = ['kind' => 'n'];
    $card = ['field' => 'age', 'agg' => 'sum'];

    $rows = [
      ['f' => ['age' => 25]],
      ['f' => ['age' => 30]],
      ['f' => ['age' => 35]],
      ['f' => ['age' => '']], // Empty value.
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertEquals(90, $result['val']);
    $this->assertEquals('', $result['suffix']);
  }

  /**
   * Tests aggregate for number field (average).
   */
  public function testAggregateNumberAvg(): void {
    $field = ['kind' => 'n'];
    $card = ['field' => 'score', 'agg' => 'avg'];

    $rows = [
      ['f' => ['score' => 10]],
      ['f' => ['score' => 20]],
      ['f' => ['score' => 30]],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertEquals(20.0, $result['val']);
    $this->assertEquals('/ answer', $result['suffix']);
  }

  /**
   * Tests aggregate for number field (max).
   */
  public function testAggregateNumberMax(): void {
    $field = ['kind' => 'n'];
    $card = ['field' => 'score', 'agg' => 'max'];

    $rows = [
      ['f' => ['score' => 10]],
      ['f' => ['score' => 50]],
      ['f' => ['score' => 30]],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertEquals(50, $result['val']);
  }

  /**
   * Tests aggregate for number field (min).
   */
  public function testAggregateNumberMin(): void {
    $field = ['kind' => 'n'];
    $card = ['field' => 'score', 'agg' => 'min'];

    $rows = [
      ['f' => ['score' => 10]],
      ['f' => ['score' => 50]],
      ['f' => ['score' => 30]],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertEquals(10, $result['val']);
  }

  /**
   * Tests aggregate for boolean field (sum).
   */
  public function testAggregateBooleanSum(): void {
    $field = ['kind' => 'b'];
    $card = ['field' => 'agree', 'agg' => 'sum'];

    $rows = [
      ['f' => ['agree' => 1]],
      ['f' => ['agree' => 1]],
      ['f' => ['agree' => 0]],
      ['f' => ['agree' => '']], // Empty.
      ['f' => []], // Missing.
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    $this->assertEquals(2, $result['val']);
  }

  /**
   * Tests aggregate for boolean field (percentage).
   */
  public function testAggregateBooleanPct(): void {
    $field = ['kind' => 'b'];
    $card = ['field' => 'agree', 'agg' => 'pct'];

    $rows = [
      ['f' => ['agree' => 1]],
      ['f' => ['agree' => 1]],
      ['f' => ['agree' => 0]],
      ['f' => ['agree' => 1]],
      ['f' => ['agree' => 0]],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    // 3 out of 5 = 60%
    $this->assertEquals(60, $result['val']);
    $this->assertEquals('%', $result['suffix']);
  }

  /**
   * Tests aggregate for text field (count).
   *
   * Note: text field aggregation counts rows that HAVE the key, even if value is empty.
   */
  public function testAggregateTextCount(): void {
    $field = ['kind' => 't'];
    $card = ['field' => 'comments', 'agg' => 'count'];

    $rows = [
      ['f' => ['comments' => 'Great!']],
      ['f' => ['comments' => 'OK']],
      ['f' => ['comments' => '']], // Empty value but key exists.
      ['f' => []], // Missing key.
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    // 3 rows have the 'comments' key (including empty string).
    $this->assertEquals(3, $result['val']);
  }

  /**
   * Tests aggregate for text field (list).
   *
   * Note: text field aggregation counts rows that HAVE the key, even if value is empty.
   */
  public function testAggregateTextList(): void {
    $field = ['kind' => 't'];
    $card = ['field' => 'comments', 'agg' => 'list'];

    $rows = [
      ['f' => ['comments' => 'Great!']],
      ['f' => ['comments' => 'OK']],
      ['f' => ['comments' => '']], // Empty value but key exists.
      ['f' => []], // Missing key.
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    // 3 rows have the 'comments' key.
    $this->assertEquals(3, $result['val']);
    $this->assertCount(3, $result['who']);
    $this->assertContains('Great!', $result['values']);
    $this->assertContains('OK', $result['values']);
    $this->assertNotContains('', $result['values']);
  }

  /**
   * Tests who() method for number field with multiplicity.
   */
  public function testWhoNumberWithMultiplicity(): void {
    $reflection = new \ReflectionClass($this->dataService);
    $method = $reflection->getMethod('who');
    $method->setAccessible(TRUE);

    $rows = [
      ['f' => ['quantity' => 3]],
      ['f' => ['quantity' => 2]], // Use 2 instead of 1 to trigger multiplicity.
      ['f' => ['quantity' => 4]],
    ];

    $result = $method->invoke($this->dataService, $rows, 'quantity', 'n');

    $this->assertCount(3, $result);
    $this->assertEquals(['i' => 0, 'mult' => 3], $result[0]);
    $this->assertEquals(['i' => 1, 'mult' => 2], $result[1]);
    $this->assertEquals(['i' => 2, 'mult' => 4], $result[2]);
  }

  /**
   * Tests publicRows strips contact values from the JSON payload.
   */
  public function testPublicRowsRedactsContacts(): void {
    $this->discovery->method('getFields')->willReturn([
      'identite__first_name' => ['kind' => 't', 'label' => 'First name', 'contact' => TRUE, 'options' => [], 'group' => 'Identity'],
      'identite__email' => ['kind' => 't', 'label' => 'Email', 'contact' => TRUE, 'options' => [], 'group' => 'Identity'],
      'age' => ['kind' => 'n', 'label' => 'Age', 'contact' => FALSE, 'options' => [], 'group' => 'Other'],
    ]);
    $rows = [[
      'n' => 'Julien D.',
      'full' => 'Julien Dupont',
      'contacts' => ['email' => 'julien@example.com', 'phone' => '+33123456789'],
      'f' => [
        'identite__first_name' => 'Julien',
        'identite__email' => 'julien@example.com',
        'age' => 42,
      ],
    ]];

    // User without the contacts permission.
    $user = $this->createMock(\Drupal\Core\Session\AccountInterface::class);
    $user->method('hasPermission')->willReturn(FALSE);
    $service = new ReportDataService(
      $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class),
      $user,
      $this->discovery,
    );

    $result = $service->publicRows('test_webform', $rows);
    $this->assertSame('Julien D.', $result[0]['n']);
    $this->assertArrayNotHasKey('contacts', $result[0]);
    $this->assertArrayNotHasKey('identite__first_name', $result[0]['f']);
    $this->assertArrayNotHasKey('identite__email', $result[0]['f']);
    $this->assertSame(42, $result[0]['f']['age']);

    // Share links force redaction even when the user has the permission.
    $result = $this->dataService->publicRows('test_webform', $rows, TRUE);
    $this->assertSame('Julien D.', $result[0]['n']);
    $this->assertArrayNotHasKey('contacts', $result[0]);
    $this->assertArrayNotHasKey('identite__email', $result[0]['f']);
  }

  /**
   * Tests CSV/XLSX formula injection neutralization.
   */
  public function testSanitizeCell(): void {
    $service = new ReportExportService(
      $this->createMock(ReportDataService::class),
      $this->createMock(ReportFieldDiscovery::class),
    );
    $method = new \ReflectionMethod($service, 'sanitizeCell');
    $method->setAccessible(TRUE);
    $this->assertSame("'=SUM(A1)", $method->invoke($service, '=SUM(A1)'));
    $this->assertSame("'+1", $method->invoke($service, '+1'));
    $this->assertSame("'-1", $method->invoke($service, '-1'));
    $this->assertSame("'@cmd", $method->invoke($service, '@cmd'));
    $this->assertSame('safe', $method->invoke($service, 'safe'));
    $this->assertSame(42, $method->invoke($service, 42));
  }

  /**
   * Tests publicRows keeps contact values for authorized users.
   */
  public function testPublicRowsKeepsContactsWhenAllowed(): void {
    $this->discovery->method('getFields')->willReturn([]);
    $rows = [[
      'n' => 'Julien D.',
      'full' => 'Julien Dupont',
      'contacts' => ['email' => 'julien@example.com'],
      'f' => ['identite__email' => 'julien@example.com'],
    ]];

    $result = $this->dataService->publicRows('test_webform', $rows);
    $this->assertSame('Julien Dupont', $result[0]['n']);
    $this->assertSame('julien@example.com', $result[0]['f']['identite__email']);
    $this->assertSame(['email' => 'julien@example.com'], $result[0]['contacts']);
  }

  /**
   * Composite union breakdowns must deduplicate values per submission.
   */
  public function testAggregateCompositeUnionDeduplicatesPerRow(): void {
    $field = ['kind' => 'b', 'options' => []];
    $card = [
      'field' => 'source_a',
      'source_fields' => ['source_a', 'source_b'],
      'mode' => 'union',
      'value_filter' => NULL,
      'value_filters' => [
        'source_a' => ['1'],
        'source_b' => ['1'],
      ],
      'agg' => 'rep',
    ];
    $rows = [
      ['_i' => 0, 'f' => ['source_a' => 1, 'source_b' => 1]],
      ['_i' => 1, 'f' => ['source_a' => 1, 'source_b' => 0]],
      ['_i' => 2, 'f' => ['source_a' => 0, 'source_b' => 0]],
    ];

    $result = $this->dataService->aggregate($card, $field, $rows);

    // Both sources checked in row 0 must count as one "Oui", not two.
    $this->assertSame(2, $result['rep']['Oui'] ?? 0);
    $this->assertSame([0, 1], $result['groups']['Oui'] ?? []);
  }

  /**
   * Detail-mode sections and totals are exported with unique names.
   */
  public function testExportDetailCompositeRows(): void {
    $fields = [
      'source_a' => ['kind' => 'b', 'label' => 'Source A', 'group' => 'Group', 'options' => [], 'contact' => FALSE],
      'source_b' => ['kind' => 'b', 'label' => 'Source B', 'group' => 'Group', 'options' => [], 'contact' => FALSE],
    ];

    $rows = [
      ['_i' => 0, 'n' => 'Alice', 'full' => 'Alice A', 'contacts' => [], 'f' => ['source_a' => 1, 'source_b' => 1]],
      ['_i' => 1, 'n' => 'Bob', 'full' => 'Bob B', 'contacts' => [], 'f' => ['source_a' => 1, 'source_b' => 0]],
      ['_i' => 2, 'n' => 'Carol', 'full' => 'Carol C', 'contacts' => [], 'f' => ['source_a' => 0, 'source_b' => 1]],
    ];

    $card = [
      'field' => 'source_a',
      'source_fields' => ['source_a', 'source_b'],
      'mode' => 'union',
      'value_filter' => NULL,
      'value_filters' => [
        'source_a' => ['1'],
        'source_b' => ['1'],
      ],
      'agg' => 'rep',
      'display_mode' => 'detail',
      'label' => 'My card',
      'group_label' => NULL,
      'section_labels' => NULL,
      'source_labels' => NULL,
      'summary_label' => NULL,
    ];

    $service = new ReportExportService($this->dataService, $this->discovery);
    $method = new \ReflectionMethod($service, 'appendCardRows');
    $method->setAccessible(TRUE);
    $name = fn(int $i): string => $rows[$i]['full'];

    $summary = [];
    $section_a = $card;
    $section_a['field'] = 'source_a';
    $section_a['source_fields'] = ['source_a'];
    $section_a['value_filter'] = ['1'];
    unset($section_a['value_filters']);
    $method->invokeArgs($service, [&$summary, $section_a, $fields['source_a'], $rows, $name, 'Source A', NULL, 'Group']);

    $section_b = $section_a;
    $section_b['field'] = 'source_b';
    $section_b['source_fields'] = ['source_b'];
    $method->invokeArgs($service, [&$summary, $section_b, $fields['source_b'], $rows, $name, 'Source B', NULL, 'Group']);

    $method->invokeArgs($service, [&$summary, $card, $fields['source_a'], $rows, $name, 'Total (Union)', NULL, 'Group']);

    $by_label = [];
    foreach ($summary as $line) {
      $by_label[$line[1]] = $line;
    }

    $this->assertSame(
      ['Group', 'Source A : Oui', 'Breakdown', 2, 'Alice A, Bob B'],
      $by_label['Source A : Oui'],
    );
    $this->assertSame(
      ['Group', 'Source B : Oui', 'Breakdown', 2, 'Alice A, Carol C'],
      $by_label['Source B : Oui'],
    );
    $this->assertSame(
      ['Group', 'Total (Union) : Oui', 'Breakdown', 3, 'Alice A, Bob B, Carol C'],
      $by_label['Total (Union) : Oui'],
    );
  }

  /**
   * Untitled cards in a folder use "Folder — field label".
   */
  public function testCardLabelUsesFolderForUntitledCards(): void {
    $service = new ReportExportService($this->dataService, $this->discovery);
    $method = new \ReflectionMethod($service, 'cardLabel');
    $method->setAccessible(TRUE);

    $this->assertSame('Jeudi — Faire les courses', $method->invoke(
      $service,
      ['label' => ''],
      ['label' => 'Faire les courses'],
      'Jeudi',
    ));
    $this->assertSame('Bénévoles jeudi et vendredi', $method->invoke(
      $service,
      ['label' => 'Bénévoles jeudi et vendredi'],
      ['label' => 'Faire les courses'],
      'Synthèse',
    ));
  }

}
