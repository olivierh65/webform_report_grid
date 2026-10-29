<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\webform\Entity\Webform;

/**
 * Grid report definition over submissions of one webform.
 *
 * Content entity (business object): a report belongs to one specific
 * webform instance and lives in the database, not in configuration.
 *
 * @ContentEntityType(
 *   id = "webform_report",
 *   label = @Translation("Webform grid report"),
 *   label_collection = @Translation("Webform grid reports"),
 *   label_singular = @Translation("grid report"),
 *   label_plural = @Translation("grid reports"),
 *   handlers = {
 *     "list_builder" = "Drupal\webform_report_grid\Entity\WebformReportListBuilder",
 *     "form" = {
 *       "add" = "Drupal\webform_report_grid\Form\WebformReportForm",
 *       "edit" = "Drupal\webform_report_grid\Form\WebformReportForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\webform_report_grid\Access\WebformReportAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "webform_report",
 *   translatable = FALSE,
 *   admin_permission = "administer webform reports",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/structure/webform-report",
 *     "add-form" = "/admin/structure/webform-report/add",
 *     "edit-form" = "/webform-report/{webform_report}/edit",
 *     "delete-form" = "/webform-report/{webform_report}/delete",
 *     "build-form" = "/webform-report/{webform_report}/build",
 *     "canonical" = "/webform-report/{webform_report}/front",
 *   },
 * )
 */
class WebformReport extends ContentEntityBase implements WebformReportInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    parent::preCreate($storage, $values);
    $values += ['uid' => \Drupal::currentUser()->id()];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -5])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['webform'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Webform'))
      ->setDescription(t('The webform this report is built on.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'webform')
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 0])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['filter_field'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Global filter field'))
      ->setDescription(t('Field key used as global filter (usually a radios/select).'))
      ->setSetting('max_length', 255)
      ->setDefaultValue('')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Grid cards: arbitrary nested structure (folders + data cards).
    // Stored as JSON; edited via the visual viewer or the JSON textarea.
    $fields['cards'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Grid cards'))
      ->setDescription(t('Grid cards edited visually in the viewer.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // Roles allowed to view this report (in addition to users with the
    // 'administer webform reports' permission). Empty = admins only.
    $fields['viewer_roles'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Viewer roles'))
      ->setDescription(t('Roles allowed to view this report. Administrators can always view all reports.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('allowed_values_function', [static::class, 'viewerRoleOptions'])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    // Share-by-token: free (anonymous) read-only access to the viewer.
    $fields['share_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enable share link'))
      ->setDescription(t('Allow anonymous read-only access to this report via a secret link.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['share_token'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Share token'))
      ->setDescription(t('Secret token for the anonymous share link.'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId')
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'author', 'weight' => 10])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'timestamp', 'weight' => 11])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  /**
   * Default value callback for the uid base field.
   */
  public static function getCurrentUserId(): array {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * Allowed values callback for the viewer_roles field.
   *
   * @return array<string, string>
   *   Role ID => label. The anonymous role is excluded on purpose: anonymous
   *   access must go through the explicit share token, which is read-only and
   *   never exposes exports or contact details.
   */
  public static function viewerRoleOptions(): array {
    $options = [];
    foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple() as $id => $role) {
      if ($id === AccountInterface::ANONYMOUS_ROLE) {
        continue;
      }
      $options[$id] = $role->label();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getWebformId(): string {
    $webform = $this->get('webform')->entity;
    if ($webform instanceof Webform) {
      return $webform->id();
    }
    return (string) ($this->get('webform')->target_id ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getWebform(): ?Webform {
    $webform = $this->get('webform')->entity;
    return $webform instanceof Webform ? $webform : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setWebformId(string $webform_id): static {
    $this->set('webform', ['target_id' => $webform_id]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterField(): string {
    return (string) ($this->get('filter_field')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function setFilterField(string $filter_field): static {
    $this->set('filter_field', $filter_field);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCards(): array {
    $raw = $this->get('cards')->value;
    if (is_string($raw) && $raw !== '') {
      $decoded = json_decode($raw, TRUE);
      return is_array($decoded) ? array_values($decoded) : [];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setCards(array $cards): static {
    $this->set('cards', json_encode(array_values($cards), JSON_UNESCAPED_UNICODE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getViewerRoles(): array {
    $roles = [];
    foreach ($this->get('viewer_roles') as $item) {
      if (!empty($item->value)) {
        $roles[] = $item->value;
      }
    }
    return array_values(array_unique($roles));
  }

  /**
   * {@inheritdoc}
   */
  public function setViewerRoles(array $roles): static {
    $this->set('viewer_roles', array_values(array_unique(array_filter($roles))));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isShareEnabled(): bool {
    return (bool) $this->get('share_enabled')->value
      && (string) ($this->get('share_token')->value ?? '') !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function setShareEnabled(bool $enabled): static {
    $this->set('share_enabled', $enabled);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getShareToken(): string {
    return (string) ($this->get('share_token')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function regenerateShareToken(): string {
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $this->set('share_token', $token);
    $this->set('share_enabled', TRUE);
    return $token;
  }

  /**
   * {@inheritdoc}
   */
  public function revokeShareToken(): static {
    $this->set('share_token', '');
    $this->set('share_enabled', FALSE);
    return $this;
  }

}
