<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Create/edit a grid report: webform, filter field, cards JSON.
 *
 * Cards are edited visually in the viewer; this form stores the definition.
 */
class WebformReportForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $report */
    $report = $this->entity;

    // Keep the default entity_reference widget for the webform field but
    // refresh the filter-field options when it changes.
    if (isset($form['webform']['widget'][0]['target_id'])) {
      $form['webform']['widget'][0]['target_id']['#ajax'] = [
        'callback' => [static::class, 'filterFieldAjax'],
        'wrapper' => 'filter-field-wrapper',
      ];
    }

    // Replace the plain textfield widget of filter_field with a select of
    // discovered fields for the chosen webform.
    $webform_id = $this->getSelectedWebformId($form_state, $report->getWebformId());
    $form['filter_field']['widget'][0]['value']['#type'] = 'select';
    $form['filter_field']['widget'][0]['value']['#title'] = $this->t('Global filter field');
    $form['filter_field']['widget'][0]['value']['#options'] = $this->getFilterChoices($webform_id);
    $form['filter_field']['widget'][0]['value']['#description'] = $this->t('Field used for the global filter (any type: choice, text, number, boolean).');
    $form['filter_field']['widget'][0]['value']['#prefix'] = '<div id="filter-field-wrapper">';
    $form['filter_field']['widget'][0]['value']['#suffix'] = '</div>';

    // Cards: JSON textarea instead of the raw string_long widget.
    $form['cards']['widget'][0]['value']['#type'] = 'textarea';
    $form['cards']['widget'][0]['value']['#title'] = $this->t('Cards (JSON)');
    $form['cards']['widget'][0]['value']['#default_value'] = json_encode($report->getCards(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $form['cards']['widget'][0]['value']['#description'] = $this->t('Edited visually in the viewer. Format: [{"field": "key", "agg": "sum", "w": 1}].');
    $form['cards']['widget'][0]['value']['#rows'] = 8;
    $form['cards']['widget'][0]['value']['#element_validate'][] = [static::class, 'validateCardsJson'];

    // Viewer roles: checkboxes with a clearer label.
    if (isset($form['viewer_roles']['widget']['#title'])) {
      $form['viewer_roles']['widget']['#title'] = $this->t('Roles allowed to view this report');
    }
    if (isset($form['viewer_roles']['widget']['#description'])) {
      $form['viewer_roles']['widget']['#description'] = $this->t('In addition to administrators (who always see all reports) and users with the global "view webform report" permission. Leave empty = admins only.');
    }

    // Share-by-token block: hide raw fields, show link + actions.
    // Actions use AJAX so the #edit-share details is rebuilt in place
    // (no redirect to the collection page).
    $form['share_enabled']['#access'] = FALSE;
    $form['share_token']['#access'] = FALSE;
    $form['share'] = [
      '#type' => 'details',
      '#title' => $this->t('Anonymous share link'),
      '#open' => TRUE,
      '#weight' => 5,
      '#prefix' => '<div id="edit-share-wrapper">',
      '#suffix' => '</div>',
    ];
    if ($report->isShareEnabled()) {
      $url = Url::fromRoute('webform_report_grid.shared', ['token' => $report->getShareToken()], ['absolute' => TRUE])->toString();
      $form['share']['link'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Share link (read-only viewer, no login required)'),
        '#default_value' => $url,
        '#attributes' => ['readonly' => 'readonly', 'onclick' => 'this.select()', 'style' => 'width:100%'],
        '#description' => $this->t('Anyone with this link can view the report (viewer + data). Exports and contact details are never exposed through the share link.'),
      ];
      $form['share']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate link'),
        '#submit' => [[static::class, 'regenerateShareSubmit']],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [static::class, 'shareAjax'],
          'wrapper' => 'edit-share-wrapper',
        ],
      ];
      $form['share']['revoke'] = [
        '#type' => 'submit',
        '#value' => $this->t('Revoke link'),
        '#submit' => [[static::class, 'revokeShareSubmit']],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [static::class, 'shareAjax'],
          'wrapper' => 'edit-share-wrapper',
        ],
      ];
    }
    else {
      $form['share']['enable'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate share link'),
        '#submit' => [[static::class, 'regenerateShareSubmit']],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [static::class, 'shareAjax'],
          'wrapper' => 'edit-share-wrapper',
        ],
      ];
    }

    $form['uid']['#access'] = $this->currentUser()->hasPermission('administer webform reports');
    $form['created']['#access'] = FALSE;
    $form['changed']['#access'] = FALSE;

    return $form;
  }

  /**
   * AJAX callback: re-renders the share details block in place.
   */
  public static function shareAjax(array $form, \Drupal\Core\Form\FormStateInterface $form_state): array {
    return $form['share'];
  }

  /**
   * Submit handler: (re)generates the share token, rebuilds the form.
   *
   * No redirect: the AJAX callback replaces #edit-share-wrapper in place.
   * For non-JS fallback the form simply rebuilds on the same edit page.
   */
  public static function regenerateShareSubmit(array $form, \Drupal\Core\Form\FormStateInterface $form_state): void {
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $report */
    $report = $form_state->getFormObject()->getEntity();
    $report->regenerateShareToken();
    $report->save();
    \Drupal::messenger()->addStatus(t('New share link generated.'));
    $form_state->setRebuild();
  }

  /**
   * Submit handler: revokes the share token, rebuilds the form.
   */
  public static function revokeShareSubmit(array $form, \Drupal\Core\Form\FormStateInterface $form_state): void {
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $report */
    $report = $form_state->getFormObject()->getEntity();
    $report->revokeShareToken();
    $report->save();
    \Drupal::messenger()->addStatus(t('Share link revoked.'));
    $form_state->setRebuild();
  }

  /**
   * Reads the webform selected in the (possibly AJAX-rebuilt) form.
   */
  protected function getSelectedWebformId(FormStateInterface $form_state, string $fallback): string {
    $value = $form_state->getValue('webform');
    if (is_array($value)) {
      $value = $value[0]['target_id'] ?? $fallback;
    }
    return (string) ($value ?: $fallback);
  }

  /**
   * Builds the filter-field select options for a webform.
   */
  protected function getFilterChoices(string $webform_id): array {
    $choices = ['' => $this->t('- None -')];
    if ($webform_id === '' || !$this->entityTypeManager->getStorage('webform')->load($webform_id)) {
      return $choices;
    }
    /** @var \Drupal\webform_report_grid\Service\ReportFieldDiscovery $discovery */
    $discovery = \Drupal::service('webform_report_grid.field_discovery');
    foreach ($discovery->getFields($webform_id) as $key => $field) {
      $kind_label = match ($field['kind'] ?? '') {
        'r' => $this->t('Choice'),
        't' => $this->t('Text'),
        'n' => $this->t('Number'),
        'b' => $this->t('Boolean'),
        default => '',
      };
      $choices[$key] = ($kind_label ? '[' . $kind_label . '] ' : '') . $field['group'] . ' — ' . $field['label'];
    }
    return $choices;
  }

  public static function filterFieldAjax(array $form, FormStateInterface $form_state): array {
    return $form['filter_field'];
  }

  /**
   * Validates the cards JSON textarea.
   */
  public static function validateCardsJson(array $element, FormStateInterface $form_state): void {
    $raw = trim((string) $element['#value']);
    if ($raw === '') {
      $form_state->setValueForElement($element, '[]');
      return;
    }
    $cards = json_decode($raw, TRUE);
    if (!is_array($cards) || !array_is_list($cards)) {
      $form_state->setError($element, t('Cards must be a valid JSON array.'));
      return;
    }
    foreach ($cards as $card) {
      if (!is_array($card)) {
        $form_state->setError($element, t('Each card must be a JSON object.'));
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $entity */
    $entity = parent::buildEntity($form, $form_state);
    // Normalize cards: pretty JSON in, compact JSON stored.
    $cards = json_decode((string) $entity->get('cards')->value, TRUE);
    $entity->setCards(is_array($cards) ? array_values($cards) : []);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $report */
    $report = $this->entity;
    $status = $report->save();
    $this->messenger()->addStatus($this->t('Report %label saved.', ['%label' => $report->label()]));
    $form_state->setRedirectUrl($report->toUrl('collection'));
    return $status;
  }

}
