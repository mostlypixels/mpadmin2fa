<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class SecurityAlertCatalog
{
    private const ALERTS = [
        'enrollment.confirmed' => [
            'title' => 'Authenticator set up',
            'priority' => 'Routine',
            'badge_class' => 'badge-info',
            'meaning' => 'An employee finished setting up 2FA.',
            'action' => 'No action if expected. If the employee did not do this, reset their 2FA and investigate.',
        ],
        'recovery.used' => [
            'title' => 'Recovery code used',
            'priority' => 'Important',
            'badge_class' => 'badge-warning',
            'meaning' => 'An employee signed in with a one-use recovery code instead of their authenticator.',
            'action' => 'Confirm that the employee used it. If not, secure the account and review the security activity log.',
        ],
        'recovery.regenerated' => [
            'title' => 'Recovery codes regenerated',
            'priority' => 'Routine',
            'badge_class' => 'badge-info',
            'meaning' => 'An employee replaced all their recovery codes.',
            'action' => 'No action if expected. If not, contact the employee and secure the account.',
        ],
        'factor.reset' => [
            'title' => '2FA reset',
            'priority' => 'Important',
            'badge_class' => 'badge-warning',
            'meaning' => '2FA was disabled by the employee, reset by a SuperAdmin, or reset from the command line.',
            'action' => 'Check the reason in the security activity log. Investigate immediately if nobody authorized it.',
        ],
        'authentication.repeated_failures' => [
            'title' => 'Repeated failed attempts',
            'priority' => 'Important',
            'badge_class' => 'badge-warning',
            'meaning' => 'Someone entered several incorrect 2FA or recovery codes.',
            'action' => 'Review the employee, check type, count, and IP address in the security activity log, and contact the employee if unexpected.',
        ],
        'authentication.succeeded_after_failures' => [
            'title' => 'Login after repeated failures',
            'priority' => 'Urgent',
            'badge_class' => 'badge-danger',
            'meaning' => 'An employee successfully signed in after at least five failed 2FA attempts.',
            'action' => 'Contact the employee immediately. If they do not recognize it, change their password, reset 2FA, end active back-office sessions, and investigate.',
        ],
        'encryption_key.failure' => [
            'title' => 'Encryption key failure',
            'priority' => 'Critical',
            'badge_class' => 'badge-danger',
            'meaning' => 'PrestaShop cannot safely read the stored 2FA secrets.',
            'action' => 'Contact your hosting provider or technical team immediately. Do not delete 2FA setups until they check whether the encryption key can be restored.',
        ],
    ];

    /**
     * @return list<array{
     *     event: string,
     *     title: string,
     *     priority: string,
     *     badge_class: string,
     *     meaning: string,
     *     action: string
     * }>
     */
    public function all(): array
    {
        $alerts = [];
        foreach (self::ALERTS as $event => $alert) {
            $alerts[] = ['event' => $event] + $alert;
        }

        return $alerts;
    }

    /**
     * @return array{
     *     title: string,
     *     priority: string,
     *     badge_class: string,
     *     meaning: string,
     *     action: string
     * }|null
     */
    public function find(string $event): ?array
    {
        return self::ALERTS[$event] ?? null;
    }
}
