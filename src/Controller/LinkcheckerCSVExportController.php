<?php

namespace Drupal\linkchecker_teams_webhook\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller to export broken links CSV and download it.
 */
class LinkcheckerCSVExportController extends ControllerBase
{

    /**
     * Download the CSV report.
     */
    public function download()
    {
        $content_types = $this->config('linkchecker_csv_export.settings')->get('content_types') ?? [];

        $builder = \Drupal::service('linkchecker_teams_webhook.report_builder');

        $file_path = $builder->exportCSV($content_types);

        $response = new BinaryFileResponse($file_path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'broken_links_report.csv'
        );

        return $response;
    }
}
