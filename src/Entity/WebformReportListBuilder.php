<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Lists grid reports with their target webform.
 */
class WebformReportListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Report');
    $header['webform'] = $this->t('Webform');
    $header['cards'] = $this->t('Cards');
    $header['author'] = $this->t('Author');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\webform_report_grid\Entity\WebformReportInterface $entity */
    $row['id'] = $entity->id();
    $row['label']['data'] = $entity->toLink($entity->label())->toRenderable();
    $webform = $entity->getWebform();
    $row['webform'] = $webform ? $webform->label() . ' (' . $webform->id() . ')' : $entity->getWebformId();
    $row['cards'] = (string) count($entity->getCards());
    $row['author'] = $entity->getOwner()->getDisplayName();
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
      'url' => Url::fromRoute('webform_report_grid.view_front', ['webform_report' => $entity->id()]),
    ];
    $operations['build'] = [
      'title' => $this->t('Build'),
      'weight' => -15,
      'url' => Url::fromRoute('webform_report_grid.build', ['webform_report' => $entity->id()]),
    ];
    return $operations;
  }

}
