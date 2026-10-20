<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Create/edit a grid report: pick any webform, filter field, cards JSON.
 *
 * Cards are edited visually in the viewer; this form stores the definition
 * (webform picker keeps the module generic).
 */
class WebformReportForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\webform_report_grid\Entity\WebformReport $report */
    $report = $this->entity;

    $webforms = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $id => $webform) {
      $webforms[$id] = $webform->label() . ' (' . $id . ')';
    }
    natcasesort($webforms);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $report->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $report->id(),
      '#machine_name' => ['exists' => '\Drupal\webform_report_grid\Entity\WebformReport::load'],
      '#disabled' => !$report->isNew(),
    ];
    $form['webform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Webform to report on'),
      '#options' => $webforms,
      '#default_value' => $report->getWebformId(),
      '#required' => TRUE,
      '#description' => $this->t('Any webform. Fields are discovered live via the Webform API.'),
      '#ajax' => [
        'callback' => [static::class, 'filterFieldAjax'],
        'wrapper' => 'filter-field-wrapper',
      ],
    ];
    $webform_id = $form_state->getValue('webform_id', $report->getWebformId());
    $choices = ['' => $this->t('- None -')];
    if ($webform_id && $this->entityTypeManager->getStorage('webform')->load($webform_id)) {
      /** @var \Drupal\webform_report_grid\Service\ReportFieldDiscovery $discovery */
      $discovery = \Drupal::service('webform_report_grid.field_discovery');
      foreach ($discovery->getFields((string) $webform_id) as $key => $field) {
        // Allow filtering on any field kind: choice, text, number, boolean.
        $kind_label = match ($field['kind'] ?? '') {
          'r' => $this->t('Choice'),
          't' => $this->t('Text'),
          'n' => $this->t('Number'),
          'b' => $this->t('Boolean'),
          default => '',
        };
        $choices[$key] = ($kind_label ? '[' . $kind_label . '] ' : '') . $field['group'] . ' — ' . $field['label'];
      }
    }
    $form['filter_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Global filter field'),
      '#options' => $choices,
      '#default_value' => $report->getFilterField(),
      '#description' => $this->t('Field used for the global filter (any type: choice, text, number, boolean).'),
      '#prefix' => '<div id="filter-field-wrapper">',
      '#suffix' => '</div>',
    ];
    $form['cards_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cards (JSON)'),
      '#default_value' => json_encode($report->getCards(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
      '#description' => $this->t('Edited visually in the viewer. Format: [{"field": "key", "agg": "sum", "w": 1}].'),
      '#rows' => 8,
      '#element_validate' => [[static::class, 'validateCardsJson']],
    ];
    return $form;
  }

  public static function filterFieldAjax(array $form, FormStateInterface $form_state): array {
    return $form['filter_field'];
  }

  /**
   * Validates the cards JSON without mapping it onto the typed property.
   */
  public static function validateCardsJson(array $element, FormStateInterface $form_state): void {
    $raw = trim((string) $element['#value']);
    if ($raw === '') {
      $form_state->setValueForElement($element, '[]');
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $form_state->setError($element, t('Cards must be a valid JSON array.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\webform_report_grid\Entity\WebformReport $report */
    $report = $this->entity;
    $report->setWebformId((string) $form_state->getValue('webform_id'));
    $cards = json_decode((string) $form_state->getValue('cards_json', '[]'), TRUE);
    $report->setCards(is_array($cards) ? $cards : []);
    $status = $report->save();
    $this->messenger()->addStatus($this->t('Report %label saved.', ['%label' => $report->label()]));
    $form_state->setRedirectUrl($report->toUrl('collection'));
    return $status;
  }

}
