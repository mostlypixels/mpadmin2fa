<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Component\Translation\TranslatorInterface;

final class SecurityAlertMessageFactory
{
    use TranslatesMessages;

    /** @var SecurityAlertCatalog */
    private $catalog;

    /** @var TranslatorInterface|null */
    private $translator;

    public function __construct(SecurityAlertCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * Alerts follow the shop language, not the language of the request that triggered them.
     */
    public function withTranslator(TranslatorInterface $translator): self
    {
        $factory = clone $this;
        $factory->translator = $translator;

        return $factory;
    }

    /**
     * @param array{name: string, email: string}|null $employee
     *
     * @return array{subject: string, details: string, details_html: string}
     */
    public function create(string $event, ?array $employee, array $metadata): array
    {
        $guidance = $this->guidance($event);

        if ('authentication.succeeded_after_failures' === $event) {
            $name = $employee['name'] ?? $this->trans('An employee', [], 'Modules.Mpadmin2fa.Emails');
            $email = $employee['email'] ?? $this->trans('unknown email', [], 'Modules.Mpadmin2fa.Emails');
            $failures = max(0, (int) ($metadata['failures'] ?? 0));
            $duration = $this->duration(max(0, (int) ($metadata['elapsed_seconds'] ?? 0)));
            $ip = is_string($metadata['ip'] ?? null) && '' !== $metadata['ip']
                ? $metadata['ip']
                : $this->trans('unavailable', [], 'Modules.Mpadmin2fa.Emails');
            $firstFailure = (string) ($metadata['first_failure_at'] ?? $this->trans('unknown', [], 'Modules.Mpadmin2fa.Emails'));
            $details = [
                $this->trans('Employee', [], 'Modules.Mpadmin2fa.Emails') => sprintf('%s (%s)', $name, $email),
                $this->trans('Failed attempts', [], 'Modules.Mpadmin2fa.Emails') => $failures,
                $this->trans('Time between first failure and login', [], 'Modules.Mpadmin2fa.Emails') => $duration,
                $this->trans('Successful login IP', [], 'Modules.Mpadmin2fa.Emails') => $ip,
                $this->trans('First failed attempt', [], 'Modules.Mpadmin2fa.Emails') => $firstFailure . ' UTC',
            ];
            $subjectParameters = ['%name%' => $name, '%count%' => (string) $failures];

            return [
                'subject' => 1 === $failures
                    ? $this->trans('Security alert: %name% logged in after %count% failed attempt', $subjectParameters, 'Modules.Mpadmin2fa.Emails')
                    : $this->trans('Security alert: %name% logged in after %count% failed attempts', $subjectParameters, 'Modules.Mpadmin2fa.Emails'),
                'details' => $this->plainText($guidance, $details),
                'details_html' => $this->html($guidance, $details),
            ];
        }

        $details = [];
        foreach ($metadata as $key => $value) {
            $details[$this->label((string) $key)] = $value;
        }

        return [
            'subject' => $this->trans('PrestaShop back-office security alert', [], 'Modules.Mpadmin2fa.Emails'),
            'details' => $this->plainText($guidance, $details),
            'details_html' => $this->html($guidance, $details),
        ];
    }

    /**
     * @return array{meaning: string, action: string}|null
     */
    private function guidance(string $event): ?array
    {
        $alert = $this->catalog->find($event);
        if (null === $alert) {
            return null;
        }

        return [
            'meaning' => $this->trans($alert['meaning'], [], 'Modules.Mpadmin2fa.Emails'),
            'action' => $this->trans($alert['action'], [], 'Modules.Mpadmin2fa.Emails'),
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
            $lines[] = $this->trans('What it means', [], 'Modules.Mpadmin2fa.Emails') . ":\n" . $guidance['meaning'];
            $lines[] = $this->trans('What to do', [], 'Modules.Mpadmin2fa.Emails') . ":\n" . $guidance['action'];
        }

        $detailLines = [];
        foreach ($details as $label => $value) {
            $detailLines[] = $label . ': ' . $this->value($value);
        }
        $lines[] = $this->trans('Details', [], 'Modules.Mpadmin2fa.Emails') . ":\n" . implode("\n", $detailLines);

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
            $html .= '<p style="margin:0 0 16px;"><strong>'
                . $this->escape($this->trans('What it means', [], 'Modules.Mpadmin2fa.Emails')) . '</strong><br>'
                . $this->escape($guidance['meaning']) . '</p>';
            $html .= '<p style="margin:0 0 16px;"><strong>'
                . $this->escape($this->trans('What to do', [], 'Modules.Mpadmin2fa.Emails')) . '</strong><br>'
                . $this->escape($guidance['action']) . '</p>';
        }

        $html .= '<p style="margin:0 0 8px;"><strong>'
            . $this->escape($this->trans('Details', [], 'Modules.Mpadmin2fa.Emails')) . '</strong></p>';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">';
        foreach ($details as $label => $value) {
            $html .= '<tr><th align="left" valign="top" style="border:1px solid #d6d8db;background:#f1f2f4;padding:8px;width:40%;">'
                . $this->escape((string) $label)
                . '</th><td valign="top" style="border:1px solid #d6d8db;padding:8px;">'
                . $this->escape($this->value($value)) . '</td></tr>';
        }

        return $html . '</table>';
    }

    private function label(string $key): string
    {
        switch ($key) {
            case 'employee_email':
                return $this->trans('Employee email', [], 'Modules.Mpadmin2fa.Emails');
            case 'failures':
                return $this->trans('Failures', [], 'Modules.Mpadmin2fa.Emails');
            case 'ip':
                return $this->trans('IP address', [], 'Modules.Mpadmin2fa.Emails');
            case 'message':
                return $this->trans('Message', [], 'Modules.Mpadmin2fa.Emails');
            case 'occurred_at':
                return $this->trans('Time', [], 'Modules.Mpadmin2fa.Emails');
            case 'reason':
                return $this->trans('Reason', [], 'Modules.Mpadmin2fa.Emails');
            case 'reset_by':
                return $this->trans('Reset by', [], 'Modules.Mpadmin2fa.Emails');
            case 'scope':
                return $this->trans('Scope', [], 'Modules.Mpadmin2fa.Emails');
            default:
                return ucfirst(str_replace('_', ' ', $key));
        }
    }

    private function value($value): string
    {
        if (is_bool($value)) {
            return $value
                ? $this->trans('Yes', [], 'Modules.Mpadmin2fa.Emails')
                : $this->trans('No', [], 'Modules.Mpadmin2fa.Emails');
        }
        if (is_array($value)) {
            return implode(', ', array_map(function ($item): string { return $this->value($item); }, $value));
        }
        if (null === $value || '' === $value) {
            return $this->trans('Not available', [], 'Modules.Mpadmin2fa.Emails');
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
            return $this->trans('less than a minute', [], 'Modules.Mpadmin2fa.Emails');
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $this->minutes($minutes);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $duration = 1 === $hours
            ? $this->trans('%count% hour', ['%count%' => '1'], 'Modules.Mpadmin2fa.Emails')
            : $this->trans('%count% hours', ['%count%' => (string) $hours], 'Modules.Mpadmin2fa.Emails');
        if ($remainingMinutes > 0) {
            $duration .= ' ' . $this->minutes($remainingMinutes);
        }

        return $duration;
    }

    private function minutes(int $minutes): string
    {
        return 1 === $minutes
            ? $this->trans('%count% minute', ['%count%' => '1'], 'Modules.Mpadmin2fa.Emails')
            : $this->trans('%count% minutes', ['%count%' => (string) $minutes], 'Modules.Mpadmin2fa.Emails');
    }
}
