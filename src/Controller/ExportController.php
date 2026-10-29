<?php

declare(strict_types=1);

namespace Drupal\webform_report_grid\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform_report_grid\Entity\WebformReportInterface;
use Drupal\webform_report_grid\Service\ReportExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV / XLSX exports honoring the active filter.
 */
class ExportController extends ControllerBase {

  public function __construct(
    protected ReportExportService $export,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('webform_report_grid.export'));
  }

  public function csv(WebformReportInterface $webform_report, Request $request): Response {
    return $this->export->csvResponse($webform_report, (string) $request->query->get('filter', ''));
  }

  public function xlsx(WebformReportInterface $webform_report, Request $request): Response {
    return $this->export->xlsxResponse($webform_report, (string) $request->query->get('filter', ''));
  }

}
