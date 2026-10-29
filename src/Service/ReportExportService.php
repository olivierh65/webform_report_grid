<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Service;

use Drupal\webform_report_grid\Entity\WebformReportInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV (native) and XLSX (PhpSpreadsheet) exports of a report.
 */
class ReportExportService {

  public function __construct(
    protected ReportDataService $data,
    protected ReportFieldDiscovery $discovery,
  ) {}

  /**
   * Builds [summary rows, per-person rows] honoring the active filter.
   */
  public function tables(WebformReportInterface $report, string $filter_value = ''): array {
    $fields = $this->discovery->getFields($report->getWebformId());
    $rows = $this->data->loadRows($report, $filter_value);
    $see_contacts = $this->data->canSeeContacts();
    // Exports must honor the same privacy rules as the viewer.
    $display_rows = $see_contacts ? $rows : $this->data->publicRows($report->getWebformId(), $rows, TRUE);
    $contact_keys = $see_contacts ? [] : $this->data->getContactKeys($report->getWebformId());
    $name = fn(int $i): string => $see_contacts
      ? (string) ($rows[$i]['full'] ?? '')
      : (string) ($display_rows[$i]['n'] ?? '');
    $aggs = ReportFieldDiscovery::aggregations();
    $summary = [['Group', 'Field', 'Summary', 'Value', 'Names']];
    foreach ($this->flattenCards($report->getCards()) as $card) {
      $key = $card['field'] ?? '';
      if (!isset($fields[$key])) {
        continue;
      }
      $field = $fields[$key];
      // Never aggregate a contact field without the contacts permission.
      if (!$see_contacts && (!empty($field['contact']) || in_array($key, $contact_keys, TRUE))) {
        continue;
      }
      $agg_label = $aggs[$field['kind']][$card['agg']] ?? $card['agg'];
      $res = $this->data->aggregate($card, $field, $rows);
      if (isset($res['rep'])) {
        foreach ($res['groups'] as $value => $indexes) {
          $names = array_map(fn($i) => $name((int) $i), $indexes);
          $option = $field['options'][$value] ?? $value;
          $summary[] = [$field['group'], $field['label'] . ' : ' . $option, 'Breakdown', count($indexes), implode(', ', $names)];
        }
      }
      else {
        $names = array_map(fn($w) => $name((int) (is_array($w) ? $w['i'] : $w)), $res['who']);
        $summary[] = [$field['group'], $card['label'] ?? $field['label'], $agg_label, $res['val'], implode(', ', $names)];
      }
    }
    $keys = array_keys($fields);
    if (!$see_contacts) {
      $keys = array_values(array_filter($keys, fn($k) => empty($fields[$k]['contact']) && !in_array($k, $contact_keys, TRUE)));
    }
    $per_person = [array_merge(['Name'], array_map(fn($k) => $fields[$k]['group'] . ' – ' . $fields[$k]['label'], $keys))];
    foreach ($display_rows as $row) {
      $line = [$see_contacts ? $row['full'] : $row['n']];
      foreach ($keys as $key) {
        $value = $row['f'][$key] ?? '';
        $line[] = is_array($value) ? implode(',', $value) : $value;
      }
      $per_person[] = $line;
    }
    return [$summary, $per_person];
  }

  /**
   * Neutralizes spreadsheet formula injection (CSV and XLSX).
   */
  protected function sanitizeCell(mixed $value): mixed {
    if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
      return "'" . $value;
    }
    return $value;
  }

  /**
   * Applies sanitizeCell() to every cell of a table.
   */
  protected function sanitizeTable(array $table): array {
    return array_map(
      fn(array $line) => array_map(fn($value) => $this->sanitizeCell($value), $line),
      $table,
    );
  }

  /**
   * Flattens cards: folder containers are replaced by their children
   * (prefixed with the folder label), data cards pass through.
   *
   * @param array<int, array<string, mixed>> $cards
   * @return array<int, array<string, mixed>>
   */
  protected function flattenCards(array $cards): array {
    $flat = [];
    foreach ($cards as $card) {
      if (($card['type'] ?? 'card') === 'folder') {
        $prefix = trim((string) ($card['label'] ?? ''));
        foreach ($card['children'] ?? [] as $child) {
          if (($child['type'] ?? 'card') === 'folder') {
            continue;
          }
          if ($prefix !== '' && empty($child['label'])) {
            $child['label'] = $prefix;
          }
          elseif ($prefix !== '' && !empty($child['label'])) {
            $child['label'] = $prefix . ' — ' . $child['label'];
          }
          $flat[] = $child;
        }
        continue;
      }
      $flat[] = $card;
    }
    return $flat;
  }

  /**
   * CSV streamed response (BOM + ; separator for LibreOffice).
   */
  public function csvResponse(WebformReportInterface $report, string $filter_value = ''): Response {
    [$summary] = $this->tables($report, $filter_value);
    $summary = $this->sanitizeTable($summary);
    $response = new StreamedResponse(function () use ($summary) {
      $out = fopen('php://output', 'w');
      fwrite($out, "\xEF\xBB\xBF");
      foreach ($summary as $line) {
        fputcsv($out, array_map('strval', $line), ';');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="report-' . $report->id() . '.csv"');
    return $response;
  }

  /**
   * XLSX response with Summary + Per person sheets.
   */
  public function xlsxResponse(WebformReportInterface $report, string $filter_value = ''): Response {
    [$summary, $per_person] = $this->tables($report, $filter_value);
    $summary = $this->sanitizeTable($summary);
    $per_person = $this->sanitizeTable($per_person);
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Summary');
    $sheet->fromArray($summary);
    $sheet->getColumnDimension('A')->setWidth(26);
    $sheet->getColumnDimension('B')->setWidth(34);
    $sheet->getColumnDimension('C')->setWidth(16);
    $sheet->getColumnDimension('E')->setWidth(70);
    $second = $spreadsheet->createSheet();
    $second->setTitle('Per person');
    $second->fromArray($per_person);
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $response = new StreamedResponse(function () use ($writer) {
      $writer->save('php://output');
    });
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="report-' . $report->id() . '.xlsx"');
    return $response;
  }

}
