<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform_report_grid\Entity\WebformReport;

/**
 * Loads webform submissions and computes card aggregations in PHP.
 *
 * Volumetry target: a few hundred submissions per webform, computed
 * on the fly. Rows are flattened to ['n' => display name, 'f' => [key => value]]
 * mirroring the prototype payload (const D).
 */
class ReportDataService {

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
   * Loads flattened rows for a report, optionally filtered.
   *
   * @return array<int, array{n: string, full: string, contacts: array, f: array}>
   */
  public function loadRows(WebformReport $report, string $filter_value = ''): array {
    $webform_id = $report->getWebformId();
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $ids = $storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return [];
    }
    /** @var \Drupal\webform\Entity\WebformSubmission[] $submissions */
    $submissions = $storage->loadMultiple($ids);
    $filter_field = $report->getFilterField();
    // Pre-load contact field keys for this webform.
    $contact_keys = $this->getContactKeys($webform_id);
    $rows = [];
    foreach ($submissions as $submission) {
      $data = $submission->getData();
      if ($filter_value !== '' && $filter_field !== '') {
        if ((string) ($data[$filter_field] ?? '') !== $filter_value) {
          continue;
        }
      }
      $rows[] = $this->flatten($data, $contact_keys);
    }

    return $rows;
  }

  /**
   * Returns contact field keys for a webform: ['first_name' => 'key', 'last_name' => 'key', 'email' => 'key', 'phone' => 'key'].
   */
  protected function getContactKeys(string $webform_id): array {
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
   */
  protected function flatten(array $data, array $contact_keys = []): array {
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
      // For boolean kind, missing key should be falsy (not '—').
      // For choice fields, missing key shows as '—' in UI but should not count.
      $val = $row['f'][$key] ?? null;
      if ($val === null && $kind === 'r') {
        return '—'; // Display placeholder for choice fields.
      }
      return $val;
    };

    // Helper to get who indexes for a row (handles composite).
    $getWho = function ($row, $value) use ($card, $key, $is_composite, $kind) {
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
        // For composite cards in rep mode, collect ALL values from all source fields (no deduplication).
        if ($is_composite) {
          $source_fields = $card['source_fields'] ?? [$key];
          $mode = $card['mode'] ?? 'union';
          $value_filter = $card['value_filter'] ?? null;
          $value_filters = $card['value_filters'] ?? null;
          $values = [];
          foreach ($source_fields as $src) {
            if (!array_key_exists($src, $row['f'])) {
              continue;
            }
            $val = $row['f'][$src];
            // Apply per-source value_filter (detail mode).
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
          // Apply card-level value_filter.
          if ($value_filter !== null && is_array($value_filter)) {
            $values = array_values(array_filter($values, fn($v) => $this->matchesValueFilter($v, $value_filter)));
          }
          // For rep aggregation, use ALL values (no deduplication).
          foreach ($values as $v) {
            $counts[(string) $v] = ($counts[(string) $v] ?? 0) + 1;
            $groups[(string) $v][] = $i;
          }
        }
        else {
          $value = $getValue($row) ?? '—';
          if (is_array($value)) {
            foreach ($value as $v) {
              $counts[(string) $v] = ($counts[(string) $v] ?? 0) + 1;
              $groups[(string) $v][] = $i;
            }
          }
          else {
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
            $groups[(string) $value][] = $i;
          }
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
      return $agg === 'list'
        ? ['val' => count($who), 'suffix' => '', 'who' => $who]
        : ['val' => count($who), 'suffix' => '', 'who' => $who];
    }

    // Boolean: sum of checked, pct over filtered respondents.
    // Also support 'rep' aggregation for Oui/Non breakdown.
    if ($agg === 'rep') {
      $counts = [];
      $groups = [];
      foreach ($rows as $i => $row) {
        // For composite cards, collect ALL values from all source fields (no deduplication).
        if ($is_composite) {
          $source_fields = $card['source_fields'] ?? [$key];
          $mode = $card['mode'] ?? 'union';
          $value_filter = $card['value_filter'] ?? null;
          $value_filters = $card['value_filters'] ?? null;
          $values = [];
          foreach ($source_fields as $src) {
            if (!array_key_exists($src, $row['f'])) {
              continue;
            }
            $val = $row['f'][$src];
            // Apply per-source value_filter (detail mode).
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
          // Apply card-level value_filter.
          if ($value_filter !== null && is_array($value_filter)) {
            $values = array_values(array_filter($values, fn($v) => $this->matchesValueFilter($v, $value_filter)));
          }
          // For rep aggregation, use ALL values (no deduplication).
          foreach ($values as $v) {
            $label = $v ? 'Oui' : 'Non';
            if ($v === NULL || $v === '') {
              $label = 'Non répondu';
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
            $groups[$label][] = $i;
          }
        }
        else {
          $value = $getValue($row);
          $label = $value ? 'Oui' : 'Non';
          if ($value === NULL || $value === '') {
            $label = 'Non répondu';
          }
          $counts[$label] = ($counts[$label] ?? 0) + 1;
          $groups[$label][] = $i;
        }
      }
      return ['rep' => $counts, 'groups' => $groups];
    }
    $sum = 0;
    $who = [];
    foreach ($rows as $i => $row) {
      $value = $getValue($row);
      if (!empty($value)) {
        $sum += is_numeric($value) ? (int) $value : 1;
        $who[] = $i;
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
   */
  public function publicRows(array $rows): array {
    $see = $this->canSeeContacts();
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'n' => $see ? $row['full'] : $row['n'],
        'f' => $row['f'],
      ] + ($see ? ['contacts' => $row['contacts']] : []);
    }
    return $out;
  }

}
