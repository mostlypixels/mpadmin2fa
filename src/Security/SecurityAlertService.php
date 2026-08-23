<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Configuration;
use Language;
use Mail;
use Mpadmin2fa\Repository\SecurityRepository;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Throwable;

final class SecurityAlertService
{
    public function __construct(
        private readonly SecurityRepository $repository,
        private readonly ConfigurationInterface $configuration,
        private readonly SecurityAlertMessageFactory $messages,
    ) {
    }

    public function notify(?int $employeeId, string $event, array $metadata = []): void
    {
        $this->send($employeeId, $event, $metadata, true);
    }

    public function notifySecurityRecipients(?int $employeeId, string $event, array $metadata = []): void
    {
        $this->send($employeeId, $event, $metadata, false);
    }

    private function send(?int $employeeId, string $event, array $metadata, bool $includeEmployee): void
    {
        try {
            $recipients = [];
            if ($includeEmployee && null !== $employeeId) {
                $email = $this->repository->employeeEmail($employeeId);
                if (null !== $email) {
                    $recipients[] = $email;
                }
            }

            foreach (explode(',', (string) $this->configuration->get(Policy::CONFIG_SECURITY_RECIPIENTS)) as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }

            $recipients = array_values(array_unique($recipients));
            if ([] === $recipients) {
                return;
            }

            $message = $this->messages->create(
                $event,
                null === $employeeId ? null : $this->repository->employeeIdentity($employeeId),
                $metadata
            );

            $languageId = (int) Language::getIdByIso('en');
            if ($languageId <= 0) {
                $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
            }

            Mail::send(
                $languageId,
                'mpadmin2fa_alert',
                $message['subject'],
                [
                    '{event}' => $event,
                    '{details}' => $message['details'],
                    '{details_html}' => $message['details_html'],
                ],
                $recipients,
                null,
                null,
                null,
                null,
                null,
                dirname(__DIR__, 2) . '/mails/'
            );
        } catch (Throwable) {
            // Authentication must remain deterministic even if the merchant mail transport is unavailable.
        }
    }
}
