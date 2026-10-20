<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists grid reports with their target webform.
 */
class WebformReportListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Report');
    $header['webform'] = $this->t('Webform');
    $header['cards'] = $this->t('Cards');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\webform_report_grid\Entity\WebformReport $entity */
    $row['label'] = $entity->label() . ' (' . $entity->id() . ')';
    $row['webform'] = $entity->getWebformId();
    $row['cards'] = (string) count($entity->getCards());
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $operations['view'] = [
      'title' => $this->t('View'),
      'weight' => -20,
      'url' => \Drupal\Core\Url::fromRoute('webform_report_grid.view_front', ['webform_report' => $entity->id()]),
    ];
    $operations['build'] = [
      'title' => $this->t('Build'),
      'weight' => -15,
      'url' => \Drupal\Core\Url::fromRoute('webform_report_grid.build', ['webform_report' => $entity->id()]),
    ];
    $operations['edit'] = [
      'title' => $this->t('Edit'),
      'weight' => -10,
      'url' => \Drupal\Core\Url::fromRoute('entity.webform_report.edit_form', ['webform_report' => $entity->id()]),
    ];
    return $operations;
  }

}
