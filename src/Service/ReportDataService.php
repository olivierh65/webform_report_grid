<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform_report_grid\Entity\WebformReportInterface;

/**
 * Loads webform submissions and computes card aggregations in PHP.
 *
 * Volumetry target: a few hundred submissions per webform, computed
 * on the fly. Rows are flattened to ['n' => display name, 'f' => [key => value]]
 * mirroring the prototype payload (const D).
 */
class ReportDataService {

  /**
   * Hard cap on submissions loaded in a single request (DoS protection).
   */
  private const MAX_ROWS = 5000;

  /**
   * Whether the last loadRows() call was truncated by MAX_ROWS.
   */
  protected bool $lastLoadTruncated = FALSE;

  /**
   * Sentinel returned when a value is excluded by a card value filter.
   */
  private ?\stdClass $filteredOut = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected ReportFieldDiscovery $discovery,
  ) {}

  /**
   * Whether the current user may see contact details.
   */
  public function canSeeContacts(): bool {
    return $this->currentUser->hasPermission('view webform report contacts');
  }

  /**
   * Returns the sentinel used for values excluded by a value filter.
   */
  private function filteredOut(): \stdClass {
    return $this->filteredOut ??= new \stdClass();
  }

  /**
   * Maps a boolean-ish value to the label shown in the viewer.
   */
  private function booleanLabel(mixed $value): string {
    if ($value === NULL || $value === '' || $value === '—') {
      return 'Non répondu';
    }
    if ($value === 0 || $value === '0' || $value === FALSE) {
      return 'Non';
    }
    return 'Oui';
  }

  /**
   * Loads flattened rows for a report, optionally filtered.
   *
   * @return array<int, array{n: string, full: string, contacts: array, f: array}>
   */
  public function loadRows(WebformReportInterface $report, string $filter_value = ''): array {
    $this->lastLoadTruncated = FALSE;
    $webform_id = $report->getWebformId();
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $ids = $storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->accessCheck(FALSE)
      ->range(0, self::MAX_ROWS + 1)
      ->execute();
    if (!$ids) {
      return [];
    }
    if (count($ids) > self::MAX_ROWS) {
      $this->lastLoadTruncated = TRUE;
      $ids = array_slice($ids, 0, self::MAX_ROWS, TRUE);
    }
    /** @var \Drupal\webform\Entity\WebformSubmission[] $submissions */
    $submissions = $storage->loadMultiple($ids);
    $filter_field = $report->getFilterField();
    // Pre-load contact field keys for this webform.
    $contact_keys = $this->getContactKeys($webform_id);
    $rows = [];
    foreach ($submissions as $submission) {
      // Normalize once so the global filter also works on composite subfields.
      $data = $this->normalizeComposites($submission->getData());
      if ($filter_value !== '' && $filter_field !== '') {
        $raw = $data[$filter_field] ?? '';
        if (is_array($raw)) {
          $raw = implode(',', array_map('strval', array_filter($raw, static fn($v) => $v !== '' && $v !== NULL)));
        }
        if ((string) $raw !== $filter_value) {
          continue;
        }
      }
      $row = $this->flatten($data, $contact_keys);
      $row['_i'] = count($rows);
      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * Whether the last loadRows() call was truncated.
   */
  public function wasTruncated(): bool {
    return $this->lastLoadTruncated;
  }

  /**
   * Returns contact field keys for a webform: ['first_name' => 'key', 'last_name' => 'key', 'email' => 'key', 'phone' => 'key'].
   */
  public function getContactKeys(string $webform_id): array {
    $fields = $this->discovery->getFields($webform_id);
    $contact_keys = [];
    foreach ($fields as $key => $field) {
      if (!empty($field['contact'])) {
        // 1) Robust: match on the field KEY suffix (e.g. "identite__first_name").
        // Works regardless of labels / translations.
        if (preg_match('/__(first_name|first|last_name|last|email|phone|tel)$/i', (string) $key, $m)) {
          $norm = strtolower($m[1]);
          if ($norm === 'first') {
            $norm = 'first_name';
          }
          elseif ($norm === 'last') {
            $norm = 'last_name';
          }
          elseif ($norm === 'tel') {
            $norm = 'phone';
          }
          $contact_keys[$norm] ??= $key;
          continue;
        }
        // 1b) Simple key ("email", "phone", "first_name", "contact_email").
        if (preg_match('/(?:^|__|_)(first_name|last_name|first|last|email|e_mail|phone|tel)$/i', (string) $key, $m)) {
          $norm = strtolower($m[1]);
          $norm = match ($norm) {
            'first' => 'first_name',
            'last' => 'last_name',
            'tel' => 'phone',
            'e_mail' => 'email',
            default => $norm,
          };
          $contact_keys[$norm] ??= $key;
          continue;
        }
        // Sub-field label ends with " — First name", " — Last name", " — Email", " — Phone".
        // Extract the sub-field type from the label suffix (EN + FR labels).
        if (preg_match('/ — (First name|Last name|Email|E-mail|Phone|Téléphone|Prénom|Nom)$/iu', $field['label'], $m)) {
          $norm = mb_strtolower($m[1]);
          $map = [
            'first name' => 'first_name',
            'prénom' => 'first_name',
            'prenom' => 'first_name',
            'last name' => 'last_name',
            'nom' => 'last_name',
            'email' => 'email',
            'e-mail' => 'email',
            'phone' => 'phone',
            'téléphone' => 'phone',
            'telephone' => 'phone',
          ];
          if (isset($map[$norm])) {
            $contact_keys[$map[$norm]] ??= $key;
          }
        }
        // Also handle cases where sub_key is 'first'/'last' but label says 'First name'/'Last name'.
        elseif (preg_match('/ — (First|Last)$/i', $field['label'], $m)) {
          $contact_keys[strtolower($m[1]) . '_name'] ??= $key;
        }
      }
    }
    return $contact_keys;
  }

  /**
   * Flattens one submission: display name + contact + field values.
   *
   * Composite elements (identity_login_composite, webform_name,
   * webform_address, address, etc.) may come as nested arrays
   * (['identite' => ['first_name' => ...]]) or as flattened keys
   * (['identite__first_name' => ...]), depending on how the submission
   * was stored. Both formats are normalized to flattened keys.
   */
  protected function flatten(array $data, array $contact_keys = []): array {
    $data = $this->normalizeComposites($data);
    $first = trim((string) ($data[$contact_keys['first_name'] ?? ''] ?? $this->findBySuffix($data, ['__first_name', '__first']) ?? $data['first_name'] ?? ''));
    $last = trim((string) ($data[$contact_keys['last_name'] ?? ''] ?? $this->findBySuffix($data, ['__last_name', '__last']) ?? $data['last_name'] ?? ''));
    $full = trim($first . ' ' . $last) ?: 'Anonymous';
    // Short display name by default (privacy): "Julien D.".
    $short = $first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '');
    $contacts = [
      'phone' => (string) ($data[$contact_keys['phone'] ?? ''] ?? $this->findBySuffix($data, ['__phone', '__tel']) ?? $data['phone'] ?? ''),
      'email' => (string) ($data[$contact_keys['email'] ?? ''] ?? $this->findBySuffix($data, ['__email', '__e_mail']) ?? $data['email'] ?? ''),
    ];
    $fields = [];
    foreach ($data as $key => $value) {
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      if (is_array($value)) {
        // Checkboxes multiple: keep checked keys only.
        $checked = array_keys(array_filter($value));
        if ($checked) {
          $fields[$key] = $checked;
        }
        continue;
      }
      $fields[$key] = $value;
    }
    return ['n' => $short ?: $full, 'full' => $full, 'contacts' => $contacts, 'f' => $fields];
  }

  /**
   * Normalizes composite values to flattened "parent__subkey" keys.
   *
   * Handles any composite element generically (identity_login_composite,
   * webform_name, webform_address, address, webform_email, webform_phone,
   * custom composites...), whatever the parent key is:
   *   ['coordonnees' => ['first' => 'Julien']]
   * becomes ['coordonnees' => [...], 'coordonnees__first' => 'Julien'].
   *
   * - Nested assoc arrays are expanded (recursively, for composites
   *   containing composites).
   * - Multiple-value composites (list of arrays) are kept as-is: the
   *   checkbox-style handling in flatten() already covers them.
   * - Existing flattened keys always win (??=): raw submission data
   *   takes precedence over derived values.
   */
  protected function normalizeComposites(array $data): array {
    foreach ($data as $key => $value) {
      if (!is_array($value) || array_is_list($value)) {
        continue;
      }
      foreach ($this->flattenComposite($value) as $sub_key => $sub_value) {
        if (is_scalar($sub_value) || $sub_value === NULL) {
          $data[$key . '__' . $sub_key] ??= $sub_value;
        }
      }
    }
    return $data;
  }

  /**
   * Recursively flattens one composite value to [sub_key => scalar].
   *
   * Nested composites use "__" as separator, mirroring Webform storage:
   *   ['address' => ['city' => 'X']] => ['address__city' => 'X'].
   * Non-associative (multiple) values are returned as-is under their key.
   */
  private function flattenComposite(array $value, string $prefix = ''): array {
    $flat = [];
    foreach ($value as $sub_key => $sub_value) {
      $flat_key = $prefix === '' ? (string) $sub_key : $prefix . '__' . $sub_key;
      if (is_array($sub_value) && !array_is_list($sub_value)) {
        foreach ($this->flattenComposite($sub_value, $flat_key) as $k => $v) {
          $flat[$k] = $v;
        }
      }
      else {
        $flat[$flat_key] = $sub_value;
      }
    }
    return $flat;
  }

  /**
   * Fallback lookup: first non-empty value whose key ends with one of $suffixes.
   *
   * Safety net when the webform structure in DB differs from discovery
   * (e.g. config not synced between environments): data keys like
   * "identite__first_name" are still found.
   */
  private function findBySuffix(array $data, array $suffixes): mixed {
    foreach ($data as $key => $value) {
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      foreach ($suffixes as $suffix) {
        if (str_ends_with((string) $key, $suffix)) {
          return $value;
        }
      }
    }
    return NULL;
  }

  /**
   * Checks if a raw value matches a value_filter list.
   * Accepts direct matches plus boolean label equivalents
   * (composite union of booleans shows Oui/Non labels while
   * raw data holds 1/0).
   */
  private function matchesValueFilter(mixed $value, array $filter): bool {
    if (empty($filter)) {
      return TRUE;
    }
    $str_v = (string) $value;
    if (in_array($str_v, $filter, TRUE) || in_array($value, $filter, TRUE)) {
      return TRUE;
    }
    $is_true = $value === 1 || $value === '1' || $value === TRUE;
    $is_false = $value === 0 || $value === '0' || $value === FALSE;
    $is_empty = $value === NULL || $value === '' || $value === '—';
    if ($is_true && (in_array('Oui', $filter, TRUE) || in_array('1', $filter, TRUE))) {
      return TRUE;
    }
    if ($is_false && (in_array('Non', $filter, TRUE) || in_array('0', $filter, TRUE))) {
      return TRUE;
    }
    if ($is_empty && (in_array('Non répondu', $filter, TRUE) || in_array('Non renseigné', $filter, TRUE) || in_array('—', $filter, TRUE) || in_array('', $filter, TRUE))) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets combined values from all source fields of a card for a given row,
   * applying value_filter (card-level) or value_filters (detail mode, per source field) if configured.
   *
   * @param array $card Card config (may have 'source_fields' or 'field', and 'value_filter'/'value_filters')
   * @param array $row Row data with 'f' field values
   * @return array|mixed Combined values after filtering
   */
  private function getCombinedValue(array $card, array $row): array|string|float|null {
    $source_fields = $card['source_fields'] ?? [$card['field'] ?? ''];
    $source_fields = array_filter($source_fields);
    if (empty($source_fields)) {
      return NULL;
    }

    $mode = $card['mode'] ?? 'union';
    $value_filter = $card['value_filter'] ?? null;
    $value_filters = $card['value_filters'] ?? null;
    $values = [];

    foreach ($source_fields as $src) {
      if (!array_key_exists($src, $row['f'])) {
        continue;
      }
      $val = $row['f'][$src];
      // Apply per-source value_filter (detail mode) before collecting values.
      if ($value_filters !== null && isset($value_filters[$src]) && is_array($value_filters[$src])) {
        $src_filter = $value_filters[$src];
        if (is_array($val)) {
          $val = array_values(array_filter($val, fn($v) => $this->matchesValueFilter($v, $src_filter)));
          if (empty($val)) {
            continue;
          }
        }
        elseif (!$this->matchesValueFilter($val, $src_filter)) {
          continue;
        }
      }
      if (is_array($val)) {
        $values = array_merge($values, $val);
      }
      else {
        $values[] = $val;
      }
    }

    if (empty($values)) {
      return NULL;
    }

    // Apply card-level value_filter if configured (before combination mode).
    if ($value_filter !== null && is_array($value_filter)) {
      $values = array_values(array_filter($values, fn($v) => $this->matchesValueFilter($v, $value_filter)));
      if (empty($values)) {
        return NULL;
      }
    }

    // Apply combination mode.
    switch ($mode) {
      case 'sum':
        $sum = 0;
        foreach ($values as $v) {
          if (is_numeric($v)) {
            $sum += (float) $v;
          }
        }
        return $sum;

      case 'first_non_empty':
        foreach ($values as $v) {
          if ($v !== '' && $v !== NULL) {
            return $v;
          }
        }
        return NULL;

      case 'union':
      default:
        $values = array_unique(array_map('strval', $values));
        return count($values) === 1 ? $values[0] : $values;
    }
  }

  /**
   * Aggregates one card over rows. Mirrors the prototype calc().
   *
   * Supports composite cards with multiple source_fields.
   */
  public function aggregate(array $card, array $field, array $rows): array {
    $key = $card['field'];
    $agg = $card['agg'];
    $kind = $field['kind'] ?? 'b';
    $is_virtual = !empty($field['virtual']);
    $is_composite = !empty($card['source_fields']) && count($card['source_fields']) > 1;
    $mode = $card['mode'] ?? 'union';

    // For virtual fields (from field_groups), data is pre-computed.
    if ($is_virtual) {
      $kind = $field['virtual_config']['mode'] ?? 'union';
      if ($field['kind'] === 'r' || $kind === 'union') {
        $kind = 'r';
      }
    }
    // For composite cards (user-created groups), determine effective kind.
    elseif ($is_composite) {
      // If primary field is boolean and union mode, keep as boolean for Oui/Non breakdown.
      // In 'sum' mode with numeric sources, kind is 'n'.
      // In 'first_non_empty', kind follows first source.
      $primaryKind = $field['kind'] ?? 'b';
      if ($mode === 'union' && $primaryKind === 'b') {
        $kind = 'b';
      }
      elseif ($mode === 'sum') {
        $kind = 'n';
      }
      else {
        $kind = $primaryKind;
      }
    }

    // Helper to get value for a row (handles composite cards).
    $getValue = function ($row) use ($card, $key, $is_composite, $kind) {
      if ($is_composite) {
        return $this->getCombinedValue($card, $row);
      }
      $val = $row['f'][$key] ?? NULL;
      // Apply the card-level value filter exactly like the viewer JS.
      $value_filter = $card['value_filter'] ?? NULL;
      if (is_array($value_filter) && $value_filter !== []) {
        if (is_array($val)) {
          $val = array_values(array_filter($val, fn($v) => $this->matchesValueFilter($v, $value_filter)));
          if ($val === []) {
            return $this->filteredOut();
          }
        }
        elseif (!$this->matchesValueFilter($val, $value_filter)) {
          return $this->filteredOut();
        }
      }
      // For boolean kind, missing key should be falsy (not '—').
      // For choice fields, missing key shows as '—' in UI but should not count.
      if ($val === NULL && $kind === 'r') {
        return '—'; // Display placeholder for choice fields.
      }
      return $val;
    };

    // Helper to get who indexes for a row (handles composite).
    $getWho = function ($row, $value) use ($card, $key, $is_composite, $kind, $rows) {
      if ($is_composite) {
        // For composite, we need to check which source fields contributed.
        $source_fields = $card['source_fields'] ?? [$key];
        $indexes = [];
        foreach ($source_fields as $src) {
          if (array_key_exists($src, $row['f'])) {
            $val = $row['f'][$src];
            if (is_array($val)) {
              foreach ($val as $v) {
                if ((string) $v === (string) $value) {
                  $indexes[] = $row['_i'] ?? array_search($row, $rows);
                }
              }
            }
            elseif ((string) $val === (string) $value) {
              $indexes[] = $row['_i'] ?? array_search($row, $rows);
            }
          }
        }
        return $indexes;
      }
      // Simple card: use existing who logic.
      return [$row['_i'] ?? array_search($row, $rows)];
    };

    if ($kind === 'r') {
      $counts = [];
      $groups = [];
      foreach ($rows as $i => $row) {
        $value = $getValue($row);
        if ($value === $this->filteredOut()) {
          continue;
        }
        // Composite cards with no usable value are filtered out, not counted
        // as "non répondu" (mirrors the viewer JS).
        if ($is_composite && $value === NULL) {
          continue;
        }
        $value ??= '—';
        $row_index = $row['_i'] ?? $i;
        // Union values are already deduplicated by getCombinedValue(); keep a
        // final unique pass so repeated source values never inflate counts.
        $values = is_array($value)
          ? array_values(array_unique(array_map('strval', $value)))
          : [(string) $value];
        foreach ($values as $v) {
          $counts[$v] = ($counts[$v] ?? 0) + 1;
          $groups[$v][] = $row_index;
        }
      }
      return ['rep' => $counts, 'groups' => $groups];
    }

    if ($kind === 'n') {
      $values = [];
      foreach ($rows as $i => $row) {
        $value = $getValue($row);
        if (is_numeric($value)) {
          $values[$i] = (float) $value;
        }
      }
      $sum = array_sum($values);
      return match ($agg) {
        'avg' => ['val' => $values ? round($sum / count($values), 1) : 0, 'suffix' => '/ answer', 'who' => $this->who($rows, $key, $kind)],
        'max' => ['val' => $values ? max($values) : 0, 'suffix' => '', 'who' => $this->who($rows, $key, $kind)],
        'min' => ['val' => $values ? min($values) : 0, 'suffix' => '', 'who' => $this->who($rows, $key, $kind)],
        default => ['val' => $sum, 'suffix' => '', 'who' => $this->who($rows, $key, $kind)],
      };
    }

    if ($kind === 't') {
      $who = [];
      foreach ($rows as $i => $row) {
        if ($is_composite) {
          // For composite: check if any source field has the key.
          $source_fields = $card['source_fields'] ?? [$key];
          $hasKey = false;
          foreach ($source_fields as $src) {
            if (array_key_exists($src, $row['f'])) {
              $hasKey = true;
              break;
            }
          }
          if ($hasKey) {
            $who[] = $i;
          }
        }
        else {
          // Simple card: use array_key_exists (includes empty strings).
          if (array_key_exists($key, $row['f'])) {
            $who[] = $i;
          }
        }
      }
      if ($agg === 'list') {
        $values = [];
        foreach ($rows as $row) {
          $sources = $is_composite ? ($card['source_fields'] ?? [$key]) : [$key];
          foreach ($sources as $src) {
            if (!array_key_exists($src, $row['f'])) {
              continue;
            }
            foreach ((array) $row['f'][$src] as $value) {
              if ($value !== '' && $value !== NULL) {
                $values[(string) $value] = TRUE;
              }
            }
          }
        }
        return ['val' => count($who), 'suffix' => '', 'who' => $who, 'values' => array_keys($values)];
      }
      return ['val' => count($who), 'suffix' => '', 'who' => $who];
    }

    // Boolean: sum of checked, pct over filtered respondents.
    // Also support 'rep' aggregation for Oui/Non breakdown.
    if ($agg === 'rep') {
      $counts = [];
      $groups = [];
      foreach ($rows as $i => $row) {
        $value = $getValue($row);
        if ($value === $this->filteredOut()) {
          continue;
        }
        // Composite cards with no usable value are filtered out, not counted
        // as "non répondu" (mirrors the viewer JS).
        if ($is_composite && $value === NULL) {
          continue;
        }
        $row_index = $row['_i'] ?? $i;
        $values = is_array($value)
          ? array_values(array_unique(array_map('strval', $value)))
          : [$value];
        foreach ($values as $v) {
          $label = $this->booleanLabel($v);
          $counts[$label] = ($counts[$label] ?? 0) + 1;
          $groups[$label][] = $row_index;
        }
      }
      return ['rep' => $counts, 'groups' => $groups];
    }
    $sum = 0;
    $who = [];
    foreach ($rows as $i => $row) {
      $value = $getValue($row);
      if ($value === $this->filteredOut()) {
        continue;
      }
      if ($is_composite && $value === NULL) {
        continue;
      }
      if (!empty($value)) {
        $sum += is_numeric($value) ? (int) $value : 1;
        $who[] = $row['_i'] ?? $i;
      }
    }
    if ($agg === 'pct') {
      return ['val' => $rows ? (int) round(100 * $sum / count($rows)) : 0, 'suffix' => '%', 'who' => $who];
    }
    return ['val' => $sum, 'suffix' => '', 'who' => $who];
  }

  /**
   * Row indexes contributing to a card (for name lists).
   */
  private function who(array $rows, string $key, string $kind): array {
    $who = [];
    foreach ($rows as $i => $row) {
      if (!array_key_exists($key, $row['f'])) {
        continue;
      }
      if ($kind === 'n' && $row['f'][$key] > 1) {
        $who[] = ['i' => $i, 'mult' => $row['f'][$key]];
      }
      else {
        $who[] = $i;
      }
    }
    return $who;
  }

  /**
   * Public payload for the viewer: rows with privacy applied.
   *
   * Contact values are removed from "f" (not merely hidden in "contacts") so
   * the JSON payload itself cannot leak emails, phones or full names.
   *
   * @param string $webform_id
   *   Webform ID whose contact fields must be redacted.
   * @param array $rows
   *   Rows returned by loadRows().
   * @param bool $force_redact
   *   TRUE for anonymous share links, regardless of the viewer's permissions.
   */
  public function publicRows(string $webform_id, array $rows, bool $force_redact = FALSE): array {
    $see = !$force_redact && $this->canSeeContacts();
    $contact_keys = $see ? [] : $this->getContactKeys($webform_id);
    $out = [];
    foreach ($rows as $row) {
      $item = [
        'n' => $see ? $row['full'] : $row['n'],
        'f' => $see ? $row['f'] : $this->stripContactKeys($row['f'], $contact_keys),
      ];
      if ($see) {
        $item['contacts'] = $row['contacts'];
      }
      $out[] = $item;
    }
    return $out;
  }

  /**
   * Removes contact values from a flattened field map.
   */
  protected function stripContactKeys(array $fields, array $contact_keys): array {
    foreach (array_keys($fields) as $key) {
      if (in_array($key, $contact_keys, TRUE) || preg_match('/(?:^|__|_)(first_name|last_name|first|last|email|e_mail|phone|tel)$/i', (string) $key)) {
        unset($fields[$key]);
      }
    }
    return $fields;
  }

}
