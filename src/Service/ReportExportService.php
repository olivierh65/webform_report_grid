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
    $aggs = ReportFieldDiscovery::aggregations();
    $summary = [['Group', 'Field', 'Summary', 'Value', 'Names']];
    foreach ($this->flattenCards($report->getCards()) as $card) {
      $key = $card['field'] ?? '';
      if (!isset($fields[$key])) {
        continue;
      }
      $field = $fields[$key];
      $agg_label = $aggs[$field['kind']][$card['agg']] ?? $card['agg'];
      $res = $this->data->aggregate($card, $field, $rows);
      if (isset($res['rep'])) {
        foreach ($res['groups'] as $value => $indexes) {
          $names = array_map(fn($i) => $rows[$i]['full'], $indexes);
          $option = $field['options'][$value] ?? $value;
          $summary[] = [$field['group'], $field['label'] . ' : ' . $option, 'Breakdown', count($indexes), implode(', ', $names)];
        }
      }
      else {
        $names = array_map(fn($w) => $rows[is_array($w) ? $w['i'] : $w]['full'], $res['who']);
        $summary[] = [$field['group'], $card['label'] ?? $field['label'], $agg_label, $res['val'], implode(', ', $names)];
      }
    }
    $keys = array_keys($fields);
    $per_person = [array_merge(['Name'], array_map(fn($k) => $fields[$k]['group'] . ' – ' . $fields[$k]['label'], $keys))];
    foreach ($rows as $row) {
      $line = [$row['full']];
      foreach ($keys as $key) {
        $value = $row['f'][$key] ?? '';
        $line[] = is_array($value) ? implode(',', $value) : $value;
      }
      $per_person[] = $line;
    }
    return [$summary, $per_person];
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
