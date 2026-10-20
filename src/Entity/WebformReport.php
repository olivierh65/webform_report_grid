<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Grid report definition over submissions of one webform.
 *
 * @ConfigEntityType(
 *   id = "webform_report",
 *   label = @Translation("Webform grid report"),
 *   label_collection = @Translation("Webform grid reports"),
 *   handlers = {
 *     "list_builder" = "Drupal\webform_report_grid\Entity\WebformReportListBuilder",
 *     "form" = {
 *       "add" = "Drupal\webform_report_grid\Form\WebformReportForm",
 *       "edit" = "Drupal\webform_report_grid\Form\WebformReportForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_prefix = "webform_report",
 *   config_export = {
 *     "id",
 *     "label",
 *     "webform_id",
 *     "filter_field",
 *     "cards",
 *   },
 *   admin_permission = "administer webform reports",
*   links = {
  *     "collection" = "/admin/structure/webform-report",
  *     "add-form" = "/admin/structure/webform-report/add",
  *     "edit-form" = "/admin/structure/webform-report/{webform_report}/edit",
  *     "delete-form" = "/admin/structure/webform-report/{webform_report}/delete",
  *     "build-form" = "/webform-report/{webform_report}/build",
  *     "canonical" = "/webform-report/{webform_report}/front",
  *   },
 * )
 */
class WebformReport extends ConfigEntityBase {

  /**
   * Target webform machine name.
   */
  protected string $webform_id = '';

  /**
   * Field key used as global filter (usually a radios/select).
   */
  protected string $filter_field = '';

  /**
   * Grid cards: [['field' => ..., 'agg' => ..., 'w' => 1|2, 'label' => ...]].
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $cards = [];

  public function getWebformId(): string {
    return $this->webform_id;
  }

  public function setWebformId(string $webform_id): static {
    $this->webform_id = $webform_id;
    return $this;
  }

  public function getFilterField(): string {
    return $this->filter_field;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function getCards(): array {
    return $this->cards;
  }

  /**
   * @param array<int, array<string, mixed>> $cards
   */
  public function setCards(array $cards): static {
    $this->cards = array_values($cards);
    return $this;
  }

}
