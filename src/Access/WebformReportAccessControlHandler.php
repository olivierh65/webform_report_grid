<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for webform grid report content entities.
 *
 * - View: 'administer webform reports' always wins; otherwise the legacy
 *   'view webform report' permission acts as a global fallback, and each
 *   report may additionally grant view access to specific roles via its
 *   viewer_roles field.
 * - Update/delete: 'administer webform reports' (build viewer, save, forms).
 * - Create: 'administer webform reports'.
 */
class WebformReportAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    switch ($operation) {
      case 'view':
        // Admins can view everything.
        if ($account->hasPermission('administer webform reports')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        // Legacy global fallback.
        if ($account->hasPermission('view webform report')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        // Per-report roles. The anonymous pseudo-role is never honored here:
        // anonymous access must use the share token instead.
        if ($entity instanceof \Drupal\webform_report_grid\Entity\WebformReportInterface) {
          $viewer_roles = array_diff($entity->getViewerRoles(), [AccountInterface::ANONYMOUS_ROLE]);
          $allowed = array_intersect($viewer_roles, $account->getRoles());
          if ($allowed) {
            return AccessResult::allowed()
              ->addCacheContexts(['user.roles'])
              ->addCacheableDependency($entity);
          }
        }
        return AccessResult::neutral()->addCacheContexts(['user.roles'])->addCacheableDependency($entity);

      case 'update':
      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'administer webform reports');

      default:
        return parent::checkAccess($entity, $operation, $account);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer webform reports');
  }

}
