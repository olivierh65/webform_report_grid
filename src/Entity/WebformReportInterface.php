<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\webform\Entity\Webform;

/**
 * Interface for webform grid report content entities.
 */
interface WebformReportInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the target webform ID.
   */
  public function getWebformId(): string;

  /**
   * Gets the target webform entity, if available.
   */
  public function getWebform(): ?Webform;

  /**
   * Sets the target webform by ID.
   */
  public function setWebformId(string $webform_id): static;

  /**
   * Gets the global filter field key.
   */
  public function getFilterField(): string;

  /**
   * Sets the global filter field key.
   */
  public function setFilterField(string $filter_field): static;

  /**
   * Gets the grid cards definition.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getCards(): array;

  /**
   * Sets the grid cards definition.
   *
   * @param array<int, array<string, mixed>> $cards
   */
  public function setCards(array $cards): static;

  /**
   * Gets the role IDs allowed to view this report (in addition to admins).
   *
   * @return string[]
   */
  public function getViewerRoles(): array;

  /**
   * Sets the role IDs allowed to view this report.
   *
   * @param string[] $roles
   */
  public function setViewerRoles(array $roles): static;

  /**
   * Whether the share-by-token link is enabled for this report.
   */
  public function isShareEnabled(): bool;

  /**
   * Enables or disables the share-by-token link.
   */
  public function setShareEnabled(bool $enabled): static;

  /**
   * Gets the share token (empty string if none generated yet).
   */
  public function getShareToken(): string;

  /**
   * (Re)generates the share token and returns it.
   */
  public function regenerateShareToken(): string;

  /**
   * Revokes the share token (clears it and disables sharing).
   */
  public function revokeShareToken(): static;

}
