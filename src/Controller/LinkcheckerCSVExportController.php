<?php

namespace Drupal\linkchecker_teams_webhook\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

        $file_path = \Drupal::service('file_system')->getTempDirectory() . '/' . date('d-m-Y') . '_broken-links.csv';
        $file_path = $builder->exportCSV($content_types, $file_path);

        if (!$file_path || !file_exists($file_path)) {
            \Drupal::messenger()->addMessage(t('No need to generate a new CSV file.'));
            return new RedirectResponse(\Drupal::request()->headers->get('referer'));
        }

        $response = new BinaryFileResponse($file_path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            date('d-m-Y') . '_broken-links.csv'
        );

        return $response;
    }
}
