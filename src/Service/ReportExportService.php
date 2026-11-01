<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Service;

use Drupal\webform_report_grid\Entity\WebformReportInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
    // Aggregate on redacted rows when contacts are hidden so composite cards
    // cannot leak contact values through their totals.
    $agg_rows = $see_contacts ? $rows : $display_rows;
    $name = fn(int $i): string => $see_contacts
      ? (string) ($rows[$i]['full'] ?? '')
      : (string) ($display_rows[$i]['n'] ?? '');
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

      $is_composite = !empty($card['source_fields']) && count($card['source_fields']) > 1;
      $display_mode = $card['display_mode'] ?? 'combined';
      $folder = trim((string) ($card['_folder'] ?? ''));
      $group_label = trim((string) ($card['group_label'] ?? ''));
      $group = $group_label !== '' ? $group_label : (string) ($field['group'] ?? '');

      if ($is_composite && $display_mode === 'detail') {
        // Mirror the viewer: one section per source field, then a total row.
        foreach ($card['source_fields'] as $src) {
          if (!isset($fields[$src])) {
            continue;
          }
          $src_field = $fields[$src];
          if (!$see_contacts && (!empty($src_field['contact']) || in_array($src, $contact_keys, TRUE))) {
            continue;
          }
          $section_label = trim((string) ($card['section_labels'][$src] ?? ''));
          if ($section_label === '') {
            $section_label = trim((string) ($card['source_labels'][$src] ?? ''));
          }
          if ($section_label === '') {
            $section_label = (string) ($src_field['label'] ?? $src);
          }
          $temp = $card;
          $temp['field'] = $src;
          $temp['source_fields'] = [$src];
          $temp['value_filter'] = isset($card['value_filters'][$src]) && is_array($card['value_filters'][$src])
            ? $card['value_filters'][$src]
            : NULL;
          unset($temp['value_filters']);
          $section_group = $group_label !== '' ? $group_label : (string) ($src_field['group'] ?? '');
          $this->appendCardRows($summary, $temp, $src_field, $agg_rows, $name, $section_label, NULL, $section_group);
        }

        // Total row(s) for the whole composite card (union by default).
        $res = $this->data->aggregate($card, $field, $agg_rows);
        $summary_label = trim((string) ($card['summary_label'] ?? ''));
        if ($summary_label === '') {
          if (isset($res['rep'])) {
            $mode = (string) ($card['mode'] ?? 'union');
            $summary_label = 'Total (' . ($mode === 'union' ? 'Union' : $mode) . ')';
          }
          else {
            $summary_label = 'Total';
          }
        }
        $this->appendCardRows($summary, $card, $field, $agg_rows, $name, $summary_label, $res, $group);
        continue;
      }

      $label = $this->cardLabel($card, $field, $folder);
      $this->appendCardRows($summary, $card, $field, $agg_rows, $name, $label, NULL, $group);
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
   * Appends summary rows for one card or one detail section.
   *
   * @param array $summary
   *   Summary table, modified by reference.
   * @param array $card
   *   Card configuration.
   * @param array $field
   *   Discovered field metadata.
   * @param array $rows
   *   Submission rows.
   * @param callable $name
   *   Maps a row index to a display name.
   * @param string $label
   *   Row label (card, section or total label).
   * @param array|null $res
   *   Pre-computed aggregate result, if any.
   * @param string|null $group
   *   Group label override.
   */
  protected function appendCardRows(array &$summary, array $card, array $field, array $rows, callable $name, string $label, ?array $res = NULL, ?string $group = NULL): void {
    $aggs = ReportFieldDiscovery::aggregations();
    $res ??= $this->data->aggregate($card, $field, $rows);
    $group = $group ?? (string) ($field['group'] ?? '');
    if (isset($res['rep'])) {
      foreach ($res['groups'] as $value => $indexes) {
        $indexes = array_values(array_unique(array_map('intval', $indexes)));
        $names = array_map(fn(int $i): string => $name($i), $indexes);
        $option = $field['options'][$value] ?? $value;
        $row_label = $label !== '' ? $label . ' : ' . $option : (string) $option;
        $summary[] = [$group, $row_label, 'Breakdown', count($indexes), implode(', ', $names)];
      }
      return;
    }
    $who = array_map(
      static fn($w): int => (int) (is_array($w) ? ($w['i'] ?? 0) : $w),
      $res['who'] ?? [],
    );
    $who = array_values(array_unique($who));
    $names = array_map(fn(int $i): string => $name($i), $who);
    $agg_label = $aggs[$field['kind']][$card['agg']] ?? $card['agg'];
    $summary[] = [$group, $label, $agg_label, $res['val'], implode(', ', $names)];
  }

  /**
   * Builds a card label, falling back to "folder — field" for untitled cards.
   */
  protected function cardLabel(array $card, array $field, string $folder = ''): string {
    $label = trim((string) ($card['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }
    $field_label = trim((string) ($field['label'] ?? ''));
    if ($folder !== '') {
      return $field_label !== '' ? $folder . ' — ' . $field_label : $folder;
    }
    return $field_label;
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
   * Flattens cards: folder containers are replaced by their children.
   *
   * The folder label is preserved under "_folder" so labels can be built
   * without losing the card's own label.
   *
   * @param array<int, array<string, mixed>> $cards
   *   Report cards, possibly containing folder containers.
   *
   * @return array<int, array<string, mixed>>
   *   Flattened data cards.
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
          if ($prefix !== '') {
            $child['_folder'] = $prefix;
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
    $spreadsheet = new Spreadsheet();
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
    $writer = new Xlsx($spreadsheet);
    $response = new StreamedResponse(function () use ($writer) {
      $writer->save('php://output');
    });
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="report-' . $report->id() . '.xlsx"');
    return $response;
  }

}
