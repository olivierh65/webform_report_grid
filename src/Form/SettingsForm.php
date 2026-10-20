<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Display settings for webform grid reports.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['webform_report_grid.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webform_report_grid_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('webform_report_grid.settings');

    $form['typography'] = [
      '#type' => 'details',
      '#title' => $this->t('Typography'),
      '#open' => TRUE,
    ];
    $form['typography']['font_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Base font size (rem)'),
      '#description' => $this->t('Base text size for cards, palette and dialogs. 1 rem ≈ browser default (usually 16 px). Range 0.7 – 1.5.'),
      '#default_value' => $config->get('font_size') ?? 0.95,
      '#min' => 0.7,
      '#max' => 1.5,
      '#step' => 0.05,
      '#required' => TRUE,
    ];

    $form['layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Grid layout'),
      '#open' => TRUE,
    ];
    $form['layout']['card_gap'] = [
      '#type' => 'number',
      '#title' => $this->t('Gap between cards (px)'),
      '#description' => $this->t('Spacing between cards in the grid (GridStack margin + inner CSS grid gap). Range 0 – 40.'),
      '#default_value' => $config->get('card_gap') ?? 16,
      '#min' => 0,
      '#max' => 40,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['layout']['column_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Column width (px)'),
      '#description' => $this->t('Target width of one grid column (GridStack columnWidth option). Cards sized in columns scale from this. Range 140 – 600.'),
      '#default_value' => $config->get('column_width') ?? 280,
      '#min' => 140,
      '#max' => 600,
      '#step' => 10,
      '#required' => TRUE,
    ];
    $form['layout']['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of columns'),
      '#description' => $this->t('Grid columns (GridStack column option). Higher = finer card widths: with 12 columns a card can span 1–12 units instead of 1–4. Card widths set in build are kept as column counts in front. Range 4 – 24.'),
      '#default_value' => $config->get('columns') ?? 12,
      '#min' => 4,
      '#max' => 24,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['sizing'] = [
      '#type' => 'details',
      '#title' => $this->t('Card sizing'),
      '#open' => TRUE,
    ];
    $form['sizing']['auto_size_cards'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-size cards to fit their content'),
      '#description' => $this->t('Checked: cards grow vertically to show all content (GridStack sizeToContent, no fixed height). Unchecked: cards keep the height set at creation (resizable with the SE handle).'),
      '#default_value' => (bool) ($config->get('auto_size_cards') ?? TRUE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('webform_report_grid.settings')
      ->set('font_size', (float) $form_state->getValue('font_size'))
      ->set('card_gap', (int) $form_state->getValue('card_gap'))
      ->set('column_width', (int) $form_state->getValue('column_width'))
      ->set('columns', (int) $form_state->getValue('columns'))
      ->set('auto_size_cards', (bool) $form_state->getValue('auto_size_cards'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
