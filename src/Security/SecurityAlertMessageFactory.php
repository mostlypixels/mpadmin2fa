<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class SecurityAlertMessageFactory
{
    /** @var SecurityAlertCatalog */
    private $catalog;

    public function __construct(SecurityAlertCatalog $catalog)
    {
        $this->catalog = $catalog;

    }

    /**
     * @param array{name: string, email: string}|null $employee
     *
     * @return array{subject: string, details: string, details_html: string}
     */
    public function create(string $event, ?array $employee, array $metadata): array
    {
        $guidance = $this->catalog->find($event);

        if ('authentication.succeeded_after_failures' === $event) {
            $name = $employee['name'] ?? 'An employee';
            $email = $employee['email'] ?? 'unknown email';
            $failures = max(0, (int) ($metadata['failures'] ?? 0));
            $attempts = 1 === $failures ? 'attempt' : 'attempts';
            $duration = $this->duration(max(0, (int) ($metadata['elapsed_seconds'] ?? 0)));
            $ip = is_string($metadata['ip'] ?? null) && '' !== $metadata['ip']
                ? $metadata['ip']
                : 'unavailable';
            $details = [
                'Employee' => sprintf('%s (%s)', $name, $email),
                'Failed attempts' => $failures,
                'Time between first failure and login' => $duration,
                'Successful login IP' => $ip,
                'First failed attempt' => (string) ($metadata['first_failure_at'] ?? 'unknown') . ' UTC',
            ];

            return [
                'subject' => sprintf('Security alert: %s logged in after %d failed %s', $name, $failures, $attempts),
                'details' => $this->plainText($guidance, $details),
                'details_html' => $this->html($guidance, $details),
            ];
        }

        return [
            'subject' => 'PrestaShop back-office security alert',
            'details' => $this->plainText($guidance, $metadata),
            'details_html' => $this->html($guidance, $metadata),
        ];
    }

    /**
     * @param array{meaning: string, action: string}|null $guidance
     * @param array<string, mixed> $details
     */
    private function plainText(?array $guidance, array $details): string
    {
        $lines = [];
        if (null !== $guidance) {
            $lines[] = "What it means:\n" . $guidance['meaning'];
            $lines[] = "What to do:\n" . $guidance['action'];
        }

        $detailLines = [];
        foreach ($details as $label => $value) {
            $detailLines[] = $this->label((string) $label) . ': ' . $this->value($value);
        }
        $lines[] = "Details:\n" . implode("\n", $detailLines);

        return implode("\n\n", $lines);
    }

    /**
     * @param array{meaning: string, action: string}|null $guidance
     * @param array<string, mixed> $details
     */
    private function html(?array $guidance, array $details): string
    {
        $html = '';
        if (null !== $guidance) {
            $html .= '<p style="margin:0 0 16px;"><strong>What it means</strong><br>'
                . $this->escape($guidance['meaning']) . '</p>';
            $html .= '<p style="margin:0 0 16px;"><strong>What to do</strong><br>'
                . $this->escape($guidance['action']) . '</p>';
        }

        $html .= '<p style="margin:0 0 8px;"><strong>Details</strong></p>';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">';
        foreach ($details as $label => $value) {
            $html .= '<tr><th align="left" valign="top" style="border:1px solid #d6d8db;background:#f1f2f4;padding:8px;width:40%;">'
                . $this->escape($this->label((string) $label))
                . '</th><td valign="top" style="border:1px solid #d6d8db;padding:8px;">'
                . $this->escape($this->value($value)) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private function label(string $label): string
    {
        $labels = [
            'ip' => 'IP address',
            'occurred_at' => 'Time',
            'employee_email' => 'Employee email',
            'reset_by' => 'Reset by',
        ];

        return $labels[$label] ?? ucfirst(str_replace('_', ' ', $label));
    }

    private function value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map(function ($item): string { return $this->value($item); }, $value));
        }
        if (null === $value || '' === $value) {
            return 'Not available';
        }

        return (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'less than a minute';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' ' . (1 === $minutes ? 'minute' : 'minutes');
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $duration = $hours . ' ' . (1 === $hours ? 'hour' : 'hours');
        if ($remainingMinutes > 0) {
            $duration .= ' ' . $remainingMinutes . ' ' . (1 === $remainingMinutes ? 'minute' : 'minutes');
        }

        return $duration;
    }
}
