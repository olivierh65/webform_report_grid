<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform_report_grid\Entity\WebformReport;
use Drupal\webform_report_grid\Service\ReportDataService;
use Drupal\webform_report_grid\Service\ReportFieldDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Displays the grid viewer and serves JSON payloads.
 */
class ReportController extends ControllerBase {

  public function __construct(
    protected ReportFieldDiscovery $discovery,
    protected ReportDataService $data,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('webform_report_grid.field_discovery'),
      $container->get('webform_report_grid.data'),
    );
  }

  public function title(WebformReport $webform_report): string {
    return (string) $webform_report->label();
  }

  /**
   * Lists reports targeting one webform (tab on the webform edit page).
   */
  public function byWebform(string $webform): array {
    $reports = $this->entityTypeManager()->getStorage('webform_report')
      ->loadByProperties(['webform_id' => $webform]);
    $items = [];
    foreach ($reports as $report) {
      $items[] = $report->toLink($report->label() . ' (' . count($report->getCards()) . ' cards)')->toRenderable();
    }
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Grid reports for webform @id', ['@id' => $webform]),
      '#items' => $items ?: [$this->t('No report yet.')],
    ];
  }

  /**
   * Grid viewer: palette + cards + dialogs, data loaded as JSON.
   */
  public function view(WebformReport $webform_report): array {
    return $this->buildView($webform_report, FALSE);
  }

  /**
   * Front-office grid viewer: read-only mode without palette or card controls.
   */
  public function viewFront(WebformReport $webform_report): array {
    return $this->buildView($webform_report, TRUE);
  }

  /**
   * Build page: editable grid viewer with palette, toolbar, drag & drop.
   * Requires 'administer webform reports' permission.
   */
  public function build(WebformReport $webform_report): array {
    return $this->buildView($webform_report, FALSE);
  }

  /**
   * Builds the view array for the grid.
   *
   * @param \Drupal\webform_report_grid\Entity\WebformReport $webform_report
   *   The report entity.
   * @param bool $read_only
   *   TRUE for front-office (read-only), FALSE for back-office (editable).
   *
   * @return array
   *   Render array.
   */
  protected function buildView(WebformReport $webform_report, bool $read_only): array {
    $settings = \Drupal::config('webform_report_grid.settings');
    $display = [
      'fontSize' => (float) ($settings->get('font_size') ?? 0.95),
      'cardGap' => (int) ($settings->get('card_gap') ?? 16),
      'columnWidth' => (int) ($settings->get('column_width') ?? 280),
      'columns' => min(24, max(4, (int) ($settings->get('columns') ?? 12))),
      'autoSizeCards' => (bool) ($settings->get('auto_size_cards') ?? TRUE),
    ];
    return [
      '#theme' => 'webform_report_grid_view',
      '#title' => $webform_report->label(),
      '#report_id' => $webform_report->id(),
      '#read_only' => $read_only,
      '#attached' => [
        'library' => ['webform_report_grid/viewer'],
        'drupalSettings' => [
          'webformReportGrid' => [
            'reportId' => $webform_report->id(),
            'cards' => $webform_report->getCards(),
            'filterField' => $webform_report->getFilterField(),
            'dataUrl' => '/webform-report/' . $webform_report->id() . '/data',
            'csrfToken' => \Drupal::csrfToken()->get('webform-report/' . $webform_report->id() . '/save'),
            'readOnly' => $read_only,
            'display' => $display,
          ],
        ],
      ],
    ];
  }

  /**
   * JSON: groups + fields + rows + aggregations (powers the viewer).
   */
  public function data(WebformReport $webform_report, Request $request): JsonResponse {
    $filter = $request->query->get('filter', '');
    $rows = $this->data->loadRows($webform_report, (string) $filter);
    return new JsonResponse([
      'groups' => $this->discovery->getGroups($webform_report->getWebformId()),
      'rows' => $this->data->publicRows($rows),
      'total' => count($rows),
      'aggregations' => ReportFieldDiscovery::aggregations(),
      'canSeeContacts' => $this->data->canSeeContacts(),
    ]);
  }

  /**
   * JSON: field catalog only (palette refresh after webform change).
   */
  public function fields(WebformReport $webform_report): JsonResponse {
    return new JsonResponse([
      'groups' => $this->discovery->getGroups($webform_report->getWebformId()),
      'aggregations' => ReportFieldDiscovery::aggregations(),
    ]);
  }

  /**
   * POST: persists the grid layout (cards) edited in the viewer.
   *
   * Body JSON: {"cards": [{"field": "...", "source_fields": ["..."], "value_filter": [...], "agg": "...", "w": 1|2, "label": "..."}]}.
   * - Simple card: uses "field" (single source)
   * - Composite/group card: uses "source_fields" (array of field keys)
   * - value_filter: optional array of values to include (e.g., ["1"] for boolean true only)
   * Fields and aggregations are validated against the discovery service.
   */
  public function saveCards(WebformReport $webform_report, Request $request): JsonResponse {
    // Validate CSRF token (sent via X-CSRF-Token header or token query param).
    $token = $request->headers->get('X-CSRF-Token') ?? $request->query->get('token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'webform-report/' . $webform_report->id() . '/save')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }

    $payload = json_decode($request->getContent() ?: '{}', TRUE);
    if (!is_array($payload) || !isset($payload['cards']) || !is_array($payload['cards'])) {
      return new JsonResponse(['error' => 'Invalid payload, expected {"cards": [...]}.'], 400);
    }
    $fields = $this->discovery->getFields($webform_report->getWebformId());
    $valid_aggs = ReportFieldDiscovery::aggregations();
    $cards = [];
    $count = 0;
    foreach (array_slice($payload['cards'], 0, 200) as $card) {
      $sanitized = $this->sanitizeCard($card, $fields, $valid_aggs, $count);
      if ($sanitized !== NULL) {
        $cards[] = $sanitized;
      }
    }
    $webform_report->setCards($cards);
    $webform_report->save();
    return new JsonResponse(['saved' => count($cards)]);
  }

  /**
   * Sanitizes one card (data card or folder container), recursively.
   *
   * Folder shape: ['type' => 'folder', 'label' => ..., 'collapsed' => bool,
   *   'w' => 1..4, 'h' => 1..10, 'children' => [cards...]].
   * Data cards keep the existing shape (field/source_fields/agg/...).
   * Unknown/invalid cards return NULL (skipped).
   *
   * @param mixed $card
   *   Raw card payload.
   * @param array $fields
   *   Discovered fields keyed by field key.
   * @param array $valid_aggs
   *   Aggregations per kind.
   * @param int $count
   *   Running total of data cards (folders excluded), capped at 200.
   * @param int $depth
   *   Nesting depth (folders cannot nest deeper than 1 level).
   *
   * @return array|null
   *   Sanitized card or NULL to skip.
   */
  protected function sanitizeCard(mixed $card, array $fields, array $valid_aggs, int &$count, int $depth = 0): ?array {
    if (!is_array($card)) {
      return NULL;
    }

    $sanitize_label = fn($v) => mb_substr(trim((string) $v), 0, 160);

    // Folder container: no field/agg, just label + children.
    if (($card['type'] ?? '') === 'folder') {
      if ($depth > 0) {
        // No nested folders (1 level max) — skip.
        return NULL;
      }
      $children = [];
      foreach (array_slice($card['children'] ?? [], 0, 50) as $child) {
        if ($count >= 200) {
          break;
        }
        $sanitized = $this->sanitizeCard($child, $fields, $valid_aggs, $count, $depth + 1);
        if ($sanitized !== NULL) {
          $children[] = $sanitized;
        }
      }
      return [
        'type' => 'folder',
        'label' => mb_substr((string) ($card['label'] ?? 'Dossier'), 0, 120) ?: 'Dossier',
        'collapsed' => !empty($card['collapsed']),
        'x' => isset($card['x']) ? max(0, min(24, (int) $card['x'])) : NULL,
        'y' => isset($card['y']) ? max(0, min(100, (int) $card['y'])) : NULL,
        'w' => max(1, min(24, (int) ($card['w'] ?? 2))),
        'h' => max(1, min(10, (int) ($card['h'] ?? 2))),
        'px_w' => isset($card['px_w']) ? max(100, min(2400, (int) $card['px_w'])) : NULL,
        'children' => array_values($children),
      ];
    }

    if ($count >= 200) {
      return NULL;
    }
    if (!isset($card['agg'])) {
      return NULL;
    }

    // Support both simple cards (field) and composite cards (source_fields)
    $source_fields = [];
    if (isset($card['source_fields']) && is_array($card['source_fields'])) {
      $source_fields = array_filter(array_map('strval', $card['source_fields']));
    }
    elseif (isset($card['field'])) {
      $source_fields = [(string) $card['field']];
    }
    else {
      return NULL;
    }

    // Validate all source fields exist.
    $valid_sources = array_filter($source_fields, fn($k) => isset($fields[$k]));
    if (empty($valid_sources)) {
      return NULL;
    }

    // Determine effective kind for validation.
    // For composite cards (multiple source_fields), the kind depends on mode.
    $is_composite = count($valid_sources) > 1;
    $mode = $card['mode'] ?? 'union';
    $kind = $fields[$valid_sources[0]]['kind'] ?? 'b';

    if ($is_composite) {
      if ($mode === 'union') {
        $kind = 'r'; // Union of choices acts as choice field.
      }
      elseif ($mode === 'sum') {
        $kind = 'n'; // Sum of numbers acts as number field.
      }
      // first_non_empty keeps original kind.
    }

    $agg = (string) $card['agg'];
    if (!isset($valid_aggs[$kind][$agg])) {
      return NULL;
    }

    // Validate value_filter if provided (card-level).
    $value_filter = NULL;
    if (isset($card['value_filter']) && is_array($card['value_filter'])) {
      $value_filter = array_values(array_filter($card['value_filter'], fn($v) => $v !== '' && $v !== NULL));
    }

    // Validate value_filters (detail mode, per source field).
    $value_filters = NULL;
    if (isset($card['value_filters']) && is_array($card['value_filters'])) {
      $value_filters = [];
      foreach ($card['value_filters'] as $src => $filters) {
        if (is_array($filters)) {
          $value_filters[$src] = array_values(array_filter($filters, fn($v) => $v !== '' && $v !== NULL));
        }
      }
    }

    // Custom labels (editable in build mode, reused in front view).
    $source_labels = NULL;
    if (isset($card['source_labels']) && is_array($card['source_labels'])) {
      $source_labels = [];
      foreach ($card['source_labels'] as $src => $label) {
        $label = $sanitize_label($label);
        if ($label !== '' && in_array((string) $src, $valid_sources, TRUE)) {
          $source_labels[(string) $src] = $label;
        }
      }
      if (empty($source_labels)) {
        $source_labels = NULL;
      }
    }
    $section_labels = NULL;
    if (isset($card['section_labels']) && is_array($card['section_labels'])) {
      $section_labels = [];
      foreach ($card['section_labels'] as $src => $label) {
        $label = $sanitize_label($label);
        if ($label !== '' && in_array((string) $src, $valid_sources, TRUE)) {
          $section_labels[(string) $src] = $label;
        }
      }
      if (empty($section_labels)) {
        $section_labels = NULL;
      }
    }
    $group_label = isset($card['group_label']) ? $sanitize_label($card['group_label']) : '';
    $summary_label = isset($card['summary_label']) ? $sanitize_label($card['summary_label']) : '';

    $count++;
    return [
      'field' => $valid_sources[0],
      'source_fields' => array_values($valid_sources),
      'mode' => $mode,
      'value_filter' => $value_filter,
      'value_filters' => $value_filters,
      'agg' => $agg,
      'x' => isset($card['x']) ? max(0, min(24, (int) $card['x'])) : NULL,
      'y' => isset($card['y']) ? max(0, min(100, (int) $card['y'])) : NULL,
      'w' => max(1, min(24, (int) ($card['w'] ?? 1))),
      'h' => max(1, min(10, (int) ($card['h'] ?? 2))),
      'px_w' => isset($card['px_w']) ? max(100, min(2400, (int) $card['px_w'])) : NULL,
      'min_h' => max(1, min(10, (int) ($card['min_h'] ?? 1))),
      'max_h' => max(1, min(10, (int) ($card['max_h'] ?? 5))),
      'display_mode' => in_array($card['display_mode'] ?? '', ['combined', 'detail'], TRUE) ? $card['display_mode'] : 'combined',
      'label' => mb_substr((string) ($card['label'] ?? ''), 0, 120),
      'group_label' => $group_label !== '' ? $group_label : NULL,
      'source_labels' => $source_labels,
      'section_labels' => $section_labels,
      'summary_label' => $summary_label !== '' ? $summary_label : NULL,
    ];
  }

  /**
   * Page with webform + report pickers, redirecting to the chosen viewer.
   *
   * Generic entry point: /webform-report/switch?webform=ID&report=ID.
   * Without parameters it shows the two selects; with both it redirects.
   */
  public function switchContext(Request $request): array|\Symfony\Component\HttpFoundation\RedirectResponse {
    $webform_id = (string) $request->query->get('webform', '');
    $report_id = (string) $request->query->get('report', '');
    if ($webform_id !== '' && $report_id !== '') {
      $report = $this->entityTypeManager()->getStorage('webform_report')->load($report_id);
      if ($report) {
        return new \Symfony\Component\HttpFoundation\RedirectResponse($report->toUrl()->toString());
      }
      $this->messenger()->addError($this->t('Report @id not found.', ['@id' => $report_id]));
    }

    $webforms = [];
    foreach ($this->entityTypeManager()->getStorage('webform')->loadMultiple() as $id => $webform) {
      $webforms[$id] = $webform->label() . ' (' . $id . ')';
    }
    natcasesort($webforms);

    $reports = [];
    if ($webform_id !== '') {
      foreach ($this->entityTypeManager()->getStorage('webform_report')->loadByProperties(['webform_id' => $webform_id]) as $id => $report) {
        $reports[$id] = $report->label() . ' (' . count($report->getCards()) . ' cards)';
      }
    }

    return [
      '#theme' => 'webform_report_grid_switch',
      '#webforms' => $webforms,
      '#reports' => $reports,
      '#current_webform' => $webform_id,
      '#current_report' => $report_id,
      '#attached' => ['library' => ['webform_report_grid/viewer']],
    ];
  }

  /**
   * JSON: one person detail (all their answers grouped).
   */
  public function person(WebformReport $webform_report, int $index, Request $request): JsonResponse {
    $filter = $request->query->get('filter', '');
    $rows = $this->data->loadRows($webform_report, (string) $filter);
    if (!isset($rows[$index])) {
      return new JsonResponse(['error' => 'Not found'], 404);
    }
    $row = $rows[$index];
    $fields = $this->discovery->getFields($webform_report->getWebformId());
    $groups = [];
    foreach ($fields as $key => $field) {
      if (!array_key_exists($key, $row['f'])) {
        continue;
      }
      $value = $row['f'][$key];
      $label = $field['options'][(string) $value] ?? (is_array($value) ? implode(', ', $value) : (string) $value);
      $groups[$field['group']][] = $field['label'] . (is_numeric($value) && $value != 1 ? ' : ' . $value : ($field['kind'] === 'r' ? ' ' . $label : ''));
    }
    return new JsonResponse([
      'name' => $this->data->canSeeContacts() ? $row['full'] : $row['n'],
      'contacts' => $this->data->canSeeContacts() ? $row['contacts'] : NULL,
      'groups' => $groups,
    ]);
  }

}
