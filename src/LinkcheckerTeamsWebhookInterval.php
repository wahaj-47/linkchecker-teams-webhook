<?php

namespace Drupal\linkchecker_teams_webhook;

/**
 * Helper class that holds the interval values.
 */
abstract class LinkcheckerTeamsWebhookInterval
{

    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';

    /**
     * Helper to get a human-readable (and translated) version of a period.
     *
     * @param string $period
     *   A period string. Preferably a known one (one of the constants).
     *
     * @return string
     *   A better version of the string, hopefully.
     */
    public static function periodToString(string $period): string
    {
        $map = [
            self::DAILY => t('day'),
            self::WEEKLY => t('week'),
        ];
        return !empty($map[$period]) ? $map[$period] : $period;
    }
}
