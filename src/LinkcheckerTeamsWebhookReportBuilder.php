<?php

namespace Drupal\linkchecker_teams_webhook;

use Drupal;
use Drupal\ckeditor5\Plugin\CKEditor4To5Upgrade\Core;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The class that builds and sends the summary mail.
 */
class LinkcheckerTeamsWebhookReportBuilder
{
    /**
     * The guzzle http client instance.
     *
     * @var GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The linkchecker_teams_webook.settings config.
     *
     * @var \Drupal\Core\Config\ImmutableConfig
     */
    protected $config;

    /**
     * The language manager.
     *
     * @var \Drupal\Core\Language\LanguageManagerInterface
     */
    protected $languageManager;

    /**
     * The linkcheckerlink storage.
     *
     * @var \Drupal\Core\Entity\EntityStorageInterface
     */
    protected $linkcheckerlinkStorage;

    /**
     * The logger channel.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface
     */
    protected $logger;

    /**
     * The state.
     *
     * @var \Drupal\Core\State\StateInterface
     */
    protected $state;

    /**
     * The time service.
     *
     * @var \Drupal\Component\Datetime\TimeInterface
     */
    protected $time;

    /**
     * Day in seconds.
     */
    protected const DAY = (60 * 60 * 24);

    /**
     * Key used to get the last time checked from the state.
     */
    protected const LAST_CHECKED_STATE_KEY = 'linkchecker_teams_webook.last_checked';

    /**
     * LinkcheckerTeamsWebhookReportBuilder constructor.
     */
    public function __construct(ClientInterface $client, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager, LoggerChannelInterface $logger, StateInterface $state, TimeInterface $time)
    {
        $this->client = $client;
        $this->config = $configFactory->get('linkchecker_teams_webhook.settings');
        $this->languageManager = $languageManager;
        $this->linkcheckerlinkStorage = $entityTypeManager->getStorage('linkcheckerlink');
        $this->logger = $logger;
        $this->state = $state;
        $this->time = $time;
    }

    /**
     * Check if the summary report should be sent.
     *
     * Called in hook_cron().
     *
     * @see linkchecker_teams_webook_cron()
     */
    public function runCronCheck(bool $override = FALSE): void
    {
        if ($override) {
            $this->buildReport($this->config->get('interval'), FALSE);
        }
        $last_checked = $this->state->get(self::LAST_CHECKED_STATE_KEY, FALSE);
        $this->logger->info('Last checked: @last_checked', ['@last_checked' => date('Y-m-d H:i:s', $last_checked)]);

        $time_ago = NULL;
        switch ($this->config->get('interval')) {
            case LinkcheckerTeamsWebhookInterval::DAILY:
                $time_ago = $this->time->getRequestTime() - self::DAY;
                break;

            case LinkcheckerTeamsWebhookInterval::WEEKLY:
                $time_ago = $this->time->getRequestTime() - (self::DAY * 7);
                break;
        }

        $this->logger->info('Time ago: @time_ago', ['@time_ago' => date('Y-m-d H:i:s', $time_ago)]);

        if ($last_checked <= $time_ago) {
            $this->buildReport($this->config->get('interval'), $last_checked);
            $this->state->set(self::LAST_CHECKED_STATE_KEY, $this->time->getRequestTime());
        }
    }

    public function exportCSV(array $content_types, $file_path)
    {
        \Drupal::logger('linkchecker')->debug('Types: @value', [
            '@value' => print_r($content_types, TRUE),
        ]);

        $links = $this->runQuery(FALSE);

        if (empty($links)) {
            \Drupal::messenger()->addMessage(t("No new links to report."));
            return;
        }

        $links = $this->linkcheckerlinkStorage->loadMultiple($links);

        $handle = fopen($file_path, 'w');
        if ($handle === FALSE) {
            $this->logger->error('Unable to open the file @file for writing.', ['@file' => $file_path]);
            \Drupal::messenger()->addError(t("Failed to export the report..."));
            return;
        }

        // Write the CSV header.
        fputcsv($handle, ['URL', 'Title', 'Reference', 'Status Code']);

        // Write link data to the CSV file.
        foreach ($links as $link) {

            $code = $link->code->value;
            $url = $link->url->value;
            $nid = $link->parent_entity_id->value;

            $node = Node::load($nid);

            if (in_array($node->getType(), $content_types)) {
                $title = $node ? $node->getTitle() : t('Unknown');
                $reference = $node
                    ? Url::fromRoute('entity.node.canonical', ['node' => $nid], ['absolute' => TRUE])->toString()
                    : t('N/A');

                fputcsv($handle, [$url, $title, $reference, $code]);
            }
        }

        // Close the CSV file.
        fclose($handle);

        $this->logger->info('Report successfully exported to @file.', ['@file' => $file_path]);

        return $file_path;
    }

    /**
     * Builds and sends the actual summary mail.
     *
     * @param string $interval
     *   Interval for sending the mail.
     * @param int $last_checked
     *   Timestamp the last time the mail was sent.
     */
    protected function buildReport(string $interval, $last_checked): void
    {
        $this->logger->info('Checking for a summary for link checker with period @period', ['@period' => $interval]);

        $links = $this->runQuery($last_checked);

        if (empty($links)) {
            \Drupal::messenger()->addMessage(t("No new links to report."));
            return;
        }

        $links = $this->linkcheckerlinkStorage->loadMultiple($links);
        $code_count = [];
        $code_links = [];

        foreach ($links as $link) {
            $code = $link->code->value;
            $url = $link->url->value;
            $nid = $link->entity_id->target_id;

            $node = Node::load($nid);
            $title = $node->title->value;
            $reference = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['absolute' => TRUE])->toString();

            if (array_key_exists($code, $code_count)) $code_count[$code] += 1;
            else $code_count[$code] = 1;

            $report_row = $this->buildReportRow($url, $title, $reference, $code);
            $code_links[$code][] = $report_row;
        }

        $summary = [];

        foreach ($code_count as $code => $count) {
            $summary[] = $this->buildSummaryRow($code, $count);
        }

        $this->postSummary($summary);
        $requests_posted = 1;

        foreach ($code_links as $code => $links) {
            $total = count($links);
            $chunk_size = 30;

            if ($total > $chunk_size) {
                for ($i = 0; $i < $total; $i += $chunk_size) {
                    $chunk = array_slice($links, $i, $chunk_size);
                    $this->postReport($code, $chunk);
                    $requests_posted++;

                    if ($requests_posted == 4) {
                        $requests_posted = 0;
                        sleep(1);
                    }
                }
            } else {
                $this->postReport($code, $links);
                $requests_posted++;

                if ($requests_posted == 4) {
                    $requests_posted = 0;
                    sleep(1);
                }
            }
        }
    }

    private function runQuery($last_checked)
    {
        $query = $this->linkcheckerlinkStorage->getQuery();
        $query->condition('fail_count', 0, '>')
            ->condition('status', 1)
            ->condition('code', 200, '<>')
            ->condition('code', 206, '<>')
            ->condition('code', 301, '<>')
            ->condition('code', 302, '<>')
            ->condition('code', 302, '<>')
            ->condition('code', 304, '<>');

        if ($last_checked !== FALSE) {
            $query->condition('last_check', $last_checked, '>');
        }

        $query->accessCheck();
        $links = $query->execute();

        $this->logger->info('Found a total of @num links to send a summary about.', ['@num' => count($links)]);

        return $links;
    }

    private function postSummary($summary)
    {
        $summary = [
            "type" => "AdaptiveCard",
            "body" => [
                [
                    "type" => "TextBlock",
                    "text" => "Broken Links Report",
                    "wrap" => true,
                    "style" => "heading",
                    "weight" => "Bolder",
                    "size" => "ExtraLarge",
                ],
                [
                    "type" => "TextBlock",
                    "text" => 'Date: ' . date('Y-m-d'),
                    "wrap" => true
                ],
                [
                    'type' => 'Table',
                    'columns' => [
                        [
                            'width' => 1
                        ],
                        [
                            'width' => 1
                        ],
                    ],
                    'rows' =>
                    [
                        [
                            'type' => 'TableRow',
                            'cells' => [
                                [
                                    'type' => 'TableCell',
                                    'items' =>
                                    [
                                        [
                                            'type' => 'TextBlock',
                                            'text' => 'Error code',
                                            'wrap' => 1,
                                            'fontType' => 'Default',
                                            'size' => 'Medium',
                                            'weight' => 'Bolder',
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'TableCell',
                                    'items' =>
                                    [
                                        [
                                            'type' => 'TextBlock',
                                            'text' => 'Count',
                                            'wrap' => 1,
                                            'size' => 'Medium',
                                            'fontType' => 'Default',
                                            'weight' => 'Bolder',
                                        ]
                                    ]
                                ],

                            ],
                            'horizontalAlignment' => 'Left',
                            'spacing' => 'None',
                            'style' => 'emphasis',
                        ],
                        ...$summary,
                    ],
                    'gridStyle' => 'default'
                ],
            ],
            '$schema' => "http://adaptivecards.io/schemas/adaptive-card.json",
            "version" => "1.4"
        ];

        $this->post($summary);
    }

    private function postReport($code, $links)
    {
        $report = [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => 'Error code: ' . $code,
                    'wrap' => 1,
                    'style' => 'heading',
                    "weight" => "Bolder",
                    "size" => "ExtraLarge",
                ],
                [
                    'type' => 'TextBlock',
                    'text' => 'Date: ' . date('Y-m-d'),
                    'wrap' => 1,
                ],
                [
                    'type' => 'Table',
                    'columns' => [
                        [
                            'width' => 1
                        ],
                        [
                            'width' => 1
                        ],
                    ],
                    'rows' =>
                    [
                        [
                            'type' => 'TableRow',
                            'cells' => [
                                [
                                    'type' => 'TableCell',
                                    'items' =>
                                    [
                                        [
                                            'type' => 'TextBlock',
                                            'text' => 'URL',
                                            'wrap' => 1,
                                            'fontType' => 'Default',
                                            'size' => 'Medium',
                                            'weight' => 'Bolder',
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'TableCell',
                                    'items' =>
                                    [
                                        [
                                            'type' => 'TextBlock',
                                            'text' => 'Found here',
                                            'wrap' => 1,
                                            'size' => 'Medium',
                                            'fontType' => 'Default',
                                            'weight' => 'Bolder',
                                        ]
                                    ]
                                ],
                            ],
                            'horizontalAlignment' => 'Left',
                            'spacing' => 'None',
                            'style' => 'emphasis',
                        ],
                        ...$links,
                    ],
                    'gridStyle' => 'default'
                ]
            ]
        ];

        $this->post($report);
    }

    private function buildSummaryRow($code, $count)
    {
        $row = [
            'type' => 'TableRow',
            'cells' =>
            [
                [
                    'type' => 'TableCell',
                    'items' =>
                    [
                        [
                            'type' => 'TextBlock',
                            'text' => $code,
                        ]
                    ]
                ],
                [
                    'type' => 'TableCell',
                    'items' => [
                        [
                            'type' => 'TextBlock',
                            'text' => $count,
                        ]
                    ]
                ],
            ]
        ];

        return $row;
    }

    private function buildReportRow(string $url, string $title, string $reference)
    {
        $row = [
            'type' => 'TableRow',
            'cells' =>
            [
                [
                    'type' => 'TableCell',
                    'items' =>
                    [
                        [
                            "type" => "ActionSet",
                            "actions" => [
                                [
                                    'type' => "Action.OpenUrl",
                                    'title' => substr($url, 0, 20) . "...",
                                    'url' => $url
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'TableCell',
                    'items' => [
                        [
                            "type" => "ActionSet",
                            "actions" => [
                                [
                                    'type' => "Action.OpenUrl",
                                    'title' => substr($title, 0, 20) . "...",
                                    'url' => $reference
                                ]
                            ]
                        ]
                    ]
                ],
            ]
        ];

        return $row;
    }

    private function post($content)
    {
        // Prepare headers, for example, to send JSON data.
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'type' => 'message',
                'attachments' => [
                    [
                        "contentType" => "application/vnd.microsoft.card.adaptive",
                        "contentUrl" => null,
                        "content" => $content
                    ]
                ]
            ], // The data to send in the POST request.
        ];

        try {
            $url = $this->config->get('url');

            $this->logger->info('Making a post request to @url.', ['@url' => $url]);

            // Make the POST request.
            $response = $this->client->post($url, $options);

            $this->logger->info('JSON: @json', ['@json' => json_encode($content)]);

            // Check the response status code.
            $statusCode = $response->getStatusCode();
            // Get the response body as a string
            $response_body = $response->getBody()->getContents();

            if ($statusCode == 200) {
                $this->logger->info('Response: @response_body', ['@response_body' => $response_body]);
                if (str_contains($response_body, "Microsoft Teams endpoint returned HTTP error 429")) {
                    // Retry logic
                }
            } else {
                $this->logger->error('POST request failed with status code @code', ['@code' => $statusCode]);
            }
        } catch (RequestException $e) {
            $this->logger->error('An error occurred during the POST request: @message', [
                '@message' => $e->getMessage(),
            ]);
        }
    }
}
