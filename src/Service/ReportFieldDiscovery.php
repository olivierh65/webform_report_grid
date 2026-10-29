<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Utility\WebformElementHelper;

/**
 * Discovers reportable fields of any webform, grouped for the palette.
 *
 * Walks the element tree and keeps wizard_page > fieldset as groups,
 * ignoring pure layout containers (container, flexbox, html, actions...).
 * Each field is classified into a kind driving available aggregations:
 *   b = boolean/checkbox, n = number, r = choice (radios/select/checkboxes),
 *   t = text, x = excluded (files, hidden internals, composites internals).
 */
class ReportFieldDiscovery {

  /**
   * Element types never shown in the palette.
   */
  private const SKIPPED_TYPES = [
    'container', 'webform_flexbox', 'webform_actions', 'actions',
    'processed_text', 'webform_markup', 'html', 'horizontal_rule',
    'webform_horizontal_rule', 'hidden', 'webform_wizard_page',
    'wizard_page',
  ];

  /**
   * Element types excluded from reports (privacy / binary).
   */
  private const EXCLUDED_TYPES = [
    'managed_file', 'webform_document_file', 'webform_image_file',
    'webform_audio_file', 'webform_video_file', 'password', 'captcha',
    'webform_captcha', 'webform_honeypot', 'webform_token',
  ];

  /**
   * Composite element types that should be expanded into sub-fields.
   */
  private const COMPOSITE_TYPES = [
    'identity_login_composite',
    'name',
    'address',
    'email',
    'phone',
    'webform_name',
    'webform_address',
    'webform_email',
    'webform_phone',
  ];

  /**
   * Sub-field definitions for known composite types.
   *
   * @var array<string, array<string, string>>
   */
  private const COMPOSITE_SUBFIELDS = [
    'name' => [
      'first' => 'First name',
      'middle' => 'Middle name',
      'last' => 'Last name',
    ],
    'address' => [
      'address_line1' => 'Address line 1',
      'address_line2' => 'Address line 2',
      'city' => 'City',
      'state_province' => 'State/Province',
      'postal_code' => 'Postal code',
      'country' => 'Country',
    ],
    'email' => [
      'email' => 'Email',
    ],
    'phone' => [
      'phone' => 'Phone',
    ],
    'webform_name' => [
      'first' => 'First name',
      'middle' => 'Middle name',
      'last' => 'Last name',
    ],
    'webform_address' => [
      'address_line1' => 'Address line 1',
      'address_line2' => 'Address line 2',
      'city' => 'City',
      'state_province' => 'State/Province',
      'postal_code' => 'Postal code',
      'country' => 'Country',
    ],
    'webform_email' => [
      'email' => 'Email',
    ],
    'webform_phone' => [
      'phone' => 'Phone',
    ],
    'identity_login_composite' => [
      'first_name' => 'First name',
      'last_name' => 'Last name',
      'email' => 'Email',
      'phone' => 'Phone',
    ],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Token $tokenService,
  ) {}

  /**
   * Loads the target webform or NULL.
   */
  public function loadWebform(string $webform_id): ?Webform {
    $webform = $this->entityTypeManager->getStorage('webform')->load($webform_id);
    return $webform instanceof Webform ? $webform : NULL;
  }

  /**
   * Flat map key => field, for aggregation and validation.
   */
  public function getFields(string $webform_id): array {
    $flat = [];
    foreach ($this->getGroups($webform_id) as $group) {
      foreach ($group['fields'] as $field) {
        $flat[$field['key']] = $field + ['group' => $group['title']];
      }
    }
    return $flat;
  }

  /**
   * Returns groups of fields from the webform structure.
   */
  public function getGroups(string $webform_id): array {
    $webform = $this->loadWebform($webform_id);
    if (!$webform) {
      return [];
    }
    $tree = $webform->getElementsInitialized();
    $groups = [];
    $this->walk($tree, [], $groups, $webform_id);
    return $groups;
  }

  /**
   * Aggregations available per kind.
   *
   * @return array<string, array<string, string>>
   */
  public static function aggregations(): array {
    return [
      'b' => ['sum' => 'Sum', 'pct' => '% of respondents'],
      'n' => ['sum' => 'Sum', 'avg' => 'Average', 'max' => 'Maximum', 'min' => 'Minimum'],
      'r' => ['rep' => 'Breakdown'],
      't' => ['count' => 'Count filled', 'list' => 'List values'],
    ];
  }

  /**
   * Recursive walk. $trail holds [page title, fieldset title, ...].
   */
  private function walk(array $elements, array $trail, array &$groups, string $webform_id): void {
    foreach ($elements as $key => $element) {
      if (!is_array($element) || str_starts_with((string) $key, '#')) {
        continue;
      }
      $type = $element['#type'] ?? '';
      $title = $element['#title'] ?? $key;
      if (is_object($title) || is_array($title)) {
        $title = (string) $key;
      }

      // Skip elements with #access = FALSE.
      if (isset($element['#access']) && $element['#access'] === FALSE) {
        continue;
      }

      // Composite elements: expand into sub-fields.
      if ($this->isComposite($type, $key)) {
        $this->addComposite((string) $key, (string) $title, $element, $trail, $groups, $type);
        continue;
      }

      // Structural: descend, extending the trail on pages and fieldsets.
      if (in_array($type, ['webform_wizard_page', 'wizard_page', 'fieldset', 'details'], TRUE)) {
        $this->walk($element, [...$trail, (string) $title], $groups, $webform_id);
        continue;
      }
      if (in_array($type, self::SKIPPED_TYPES, TRUE)) {
        $this->walk($element, $trail, $groups, $webform_id);
        continue;
      }
      if (in_array($type, self::EXCLUDED_TYPES, TRUE)) {
        continue;
      }

      $kind = $this->classify($type, $element);
      if ($kind === 'x') {
        continue;
      }
      $group_title = $this->groupTitle($trail);
      $this->addField($groups, $group_title, [
        'key' => (string) $key,
        'label' => (string) $title,
        'kind' => $kind,
        'options' => $this->resolveOptions($element, $webform_id),
        // Simple email/tel/name fields are contact data too.
        'contact' => $this->isContactElement($type, (string) $key),
      ]);
    }
  }

  /**
   * Checks if an element type is a composite that should be expanded.
   */
  private function isComposite(string $type, string $key): bool {
    if (in_array($type, self::COMPOSITE_TYPES, TRUE)) {
      return TRUE;
    }
    // Also catch any *_composite suffix.
    return str_ends_with($type, '_composite');
  }

  private function groupTitle(array $trail): string {
    $trail = array_values(array_filter($trail));
    return $trail ? implode(' · ', array_slice($trail, -2)) : 'Other';
  }

  private function addField(array &$groups, string $group_title, array $field): void {
    foreach ($groups as &$group) {
      if ($group['title'] === $group_title) {
        $group['fields'][] = $field;
        return;
      }
    }
    unset($group);
    $groups[] = ['title' => $group_title, 'fields' => [$field]];
  }

  /**
   * Expands a composite element into its sub-fields.
   */
  private function addComposite(string $key, string $title, array $element, array $trail, array &$groups, string $type): void {
    $group_title = $this->groupTitle($trail);
    $subfields = self::COMPOSITE_SUBFIELDS[$type] ?? [];

    // Fallback: try to detect sub-elements from the element tree.
    if (empty($subfields) && isset($element['#elements']) && is_array($element['#elements'])) {
      foreach ($element['#elements'] as $sub_key => $sub_element) {
        if (!is_array($sub_element) || str_starts_with($sub_key, '#')) {
          continue;
        }
        $sub_title = $sub_element['#title'] ?? $sub_key;
        if (is_object($sub_title) || is_array($sub_title)) {
          $sub_title = (string) $sub_key;
        }
        $subfields[$sub_key] = (string) $sub_title;
      }
    }

    foreach ($subfields as $sub_key => $sub_label) {
      $this->addField($groups, $group_title, [
        'key' => $key . '__' . $sub_key,
        'label' => $title . ' — ' . $sub_label,
        'kind' => 't',
        'options' => [],
        // Mark name parts and contact info as contact fields.
        'contact' => in_array($sub_key, ['first', 'first_name', 'last', 'last_name', 'email', 'phone'], TRUE),
      ]);
    }
  }

  /**
   * Whether a simple (non-composite) element holds contact data.
   *
   * Covers email/tel element types and conventional field keys such as
   * "email", "phone", "first_name" or "identite__last_name".
   */
  private function isContactElement(string $type, string $key): bool {
    if (in_array($type, ['email', 'tel', 'webform_email', 'webform_tel'], TRUE)) {
      return TRUE;
    }
    return (bool) preg_match('/(?:^|__|_)(first_name|last_name|first|last|email|e_mail|phone|tel)$/i', $key);
  }

  /**
   * Classifies an element type into a kind: b, n, r, t, or x.
   */
  private function classify(string $type, array $element): string {
    // Boolean / checkbox.
    if ($type === 'checkbox') {
      return 'b';
    }

    // Numeric types.
    if (in_array($type, ['number', 'range', 'webform_number', 'webform_slider'], TRUE)) {
      return 'n';
    }

    // Choice types (radios, select, checkboxes).
    if (in_array($type, [
      'radios', 'select', 'checkboxes',
      'webform_radios_other', 'webform_select_other',
      'webform_radios', 'webform_select', 'webform_checkboxes',
      'webform_autocomplete', 'webform_autocomplete_tags',
    ], TRUE)) {
      return 'r';
    }

    // Text types.
    if (in_array($type, [
      'textfield', 'textarea', 'email', 'tel', 'date', 'datetime', 'time',
      'webform_email', 'webform_tel', 'webform_textfield', 'webform_textarea',
      'webform_date', 'webform_datetime', 'webform_time',
      'webform_url', 'webform_color',
    ], TRUE)) {
      return 't';
    }

    // If element has #options, treat as choice.
    if (isset($element['#options'])) {
      return 'r';
    }

    // Unknown type: exclude.
    return 'x';
  }

  /**
   * Resolves options for choice elements, supporting dynamic sources.
   *
   * Handles:
   *  - Static #options array
   *  - Callable #options (e.g., '::getOptions')
   *  - #options callback via webform_select_options_info
   *  - Webform options elements (taxonomy, views, etc.)
   */
  private function resolveOptions(array $element, string $webform_id): array {
    $options = $element['#options'] ?? [];

    // Static array options.
    if (is_array($options)) {
      return $this->flattenOptions($options);
    }

    // Callable options (e.g., [ClassName::class, 'methodName'] or 'function_name').
    if (is_callable($options)) {
      try {
        $resolved = $options($element);
        if (is_array($resolved)) {
          return $this->flattenOptions($resolved);
        }
      } catch (\Throwable) {
        // Fall through to empty.
      }
      return [];
    }

    // String callback (e.g., 'my_module_my_callback').
    if (is_string($options) && function_exists($options)) {
      try {
        $resolved = $options($element);
        if (is_array($resolved)) {
          return $this->flattenOptions($resolved);
        }
      } catch (\Throwable) {
        // Fall through.
      }
      return [];
    }

    // Webform select options info (via hook_webform_select_options_info).
    if (isset($element['#webform_select_options_info'])) {
      $info = $element['#webform_select_options_info'];
      if (is_array($info) && isset($info['options'])) {
        return $this->flattenOptions($info['options']);
      }
    }

    // Try to get options from WebformElementHelper for options elements.
    if (isset($element['#webform_element_type']) && $element['#webform_element_type'] === 'options') {
      try {
        $resolved = WebformElementHelper::getOptions($element);
        if (is_array($resolved)) {
          return $this->flattenOptions($resolved);
        }
      } catch (\Throwable) {
        // Fall through.
      }
    }

    return [];
  }

  /**
   * Flattens options array to [value => label], skipping nested optgroups.
   */
  private function flattenOptions(array $options): array {
    $flat = [];
    foreach ($options as $value => $label) {
      if (is_array($label)) {
        // Optgroup: skip for now, could recurse.
        continue;
      }
      $flat[(string) $value] = is_object($label) ? (string) $label : (string) $label;
    }
    return $flat;
  }

}
