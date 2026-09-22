<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Context;
use Language;
use Mail;
use Mpadmin2fa\Repository\SecurityRepository;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Throwable;

final class SecurityAlertService
{
    private const FAILURE_EXCEPTION = 'exception';
    private const FAILURE_SEND_RETURNED_FALSE = 'send_returned_false';
    private const TEMPLATE = 'mpadmin2fa_alert';

    /** @var SecurityRepository */
    private $repository;

    /** @var ConfigurationInterface */
    private $configuration;

    /** @var SecurityAlertMessageFactory */
    private $messages;

    public function __construct(
        SecurityRepository $repository,
        ConfigurationInterface $configuration,
        SecurityAlertMessageFactory $messages
    ) {
        $this->repository = $repository;
        $this->configuration = $configuration;
        $this->messages = $messages;
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

            list($languageId, $messages) = $this->localizedMessages();
            $message = $messages->create(
                $event,
                null === $employeeId ? null : $this->repository->employeeIdentity($employeeId),
                $metadata
            );

            $sent = Mail::send(
                $languageId,
                self::TEMPLATE,
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
                $this->mailDirectory()
            );

            if (false === $sent) {
                $this->recordDeliveryFailure($employeeId, $event, self::FAILURE_SEND_RETURNED_FALSE);
            }
        } catch (Throwable $exception) {
            // Authentication must remain deterministic even if the merchant mail transport is unavailable.
            $this->recordDeliveryFailure($employeeId, $event, self::FAILURE_EXCEPTION);
        }
    }

    private function recordDeliveryFailure(?int $employeeId, string $event, string $category): void
    {
        try {
            $this->repository->audit($employeeId, 'alert.delivery_failed', null, [
                'original_event' => $event,
                'failure_category' => $category,
            ]);
        } catch (Throwable $exception) {
            // Audit recording is best effort and must not change authentication or recovery behavior.
        }
    }

    /**
     * Use the shop language when its alert template exists; otherwise keep the English alert.
     *
     * @return array{0: int, 1: SecurityAlertMessageFactory}
     */
    private function localizedMessages(): array
    {
        $defaultLanguageId = (int) $this->configuration->get('PS_LANG_DEFAULT');
        $iso = $defaultLanguageId > 0 ? (string) Language::getIsoById($defaultLanguageId) : '';
        if ('' !== $iso && 'en' !== $iso
            && is_file($this->mailDirectory() . $iso . '/' . self::TEMPLATE . '.txt')
            && is_file($this->mailDirectory() . $iso . '/' . self::TEMPLATE . '.html')
        ) {
            try {
                $translator = Context::getContext()->getTranslatorFromLocale(
                    (string) Language::getLocaleById($defaultLanguageId)
                );

                return [$defaultLanguageId, $this->messages->withTranslator($translator)];
            } catch (Throwable $exception) {
                // A missing catalog must not suppress the alert itself.
            }
        }

        $englishLanguageId = (int) Language::getIdByIso('en');

        return [$englishLanguageId > 0 ? $englishLanguageId : $defaultLanguageId, $this->messages];
    }

    private function mailDirectory(): string
    {
        return dirname(__DIR__, 2) . '/mails/';
    }
}
