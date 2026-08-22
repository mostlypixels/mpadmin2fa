<?php

declare(strict_types=1);

namespace Mpadmin2fa\Configuration;

use PrestaShop\PrestaShop\Core\Configuration\DataConfigurationInterface;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use Mpadmin2fa\Security\Policy;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SecurityPolicyConfiguration implements DataConfigurationInterface
{
    public function __construct(
        private readonly ConfigurationInterface $configuration,
        private readonly Policy $policy,
    ) {
    }

    public function getConfiguration(): array
    {
        return [
            'mode' => (string) ($this->configuration->get(Policy::CONFIG_MODE) ?: 'superadmins'),
            'profiles' => $this->parseProfileIds((string) $this->configuration->get(Policy::CONFIG_PROFILES)),
            'step_up_seconds' => $this->policy->stepUpSeconds(),
            'password_max_age' => $this->policy->passwordMaximumAge(),
            'audit_days' => $this->policy->auditDays(),
            'approval_profiles' => $this->parseProfileIds(
                (string) $this->configuration->get(Policy::CONFIG_APPROVAL_PROFILES)
            ),
            'security_recipients' => (string) $this->configuration->get(Policy::CONFIG_SECURITY_RECIPIENTS),
        ];
    }

    public function updateConfiguration(array $configuration): array
    {
        $configuration = $this->buildResolver()->resolve($configuration);

        $this->configuration->set(Policy::CONFIG_MODE, $configuration['mode']);
        $this->configuration->set(Policy::CONFIG_PROFILES, $this->serializeProfileIds($configuration['profiles']));
        $this->configuration->set(Policy::CONFIG_STEP_UP_SECONDS, $configuration['step_up_seconds']);
        $this->configuration->set(Policy::CONFIG_PASSWORD_MAX_AGE, $configuration['password_max_age']);
        $this->configuration->set(Policy::CONFIG_AUDIT_DAYS, $configuration['audit_days']);
        $this->configuration->set(
            Policy::CONFIG_APPROVAL_PROFILES,
            $this->serializeProfileIds($configuration['approval_profiles'])
        );
        $recipients = trim($configuration['security_recipients']);
        $this->configuration->set(
            Policy::CONFIG_SECURITY_RECIPIENTS,
            '' === $recipients ? '' : implode(', ', array_map('trim', explode(',', $recipients)))
        );

        return [];
    }

    public function validateConfiguration(array $configuration): bool
    {
        $this->buildResolver()->resolve($configuration);

        return true;
    }

    private function buildResolver(): OptionsResolver
    {
        return (new OptionsResolver())
            ->setRequired([
                'approval_profiles',
                'audit_days',
                'mode',
                'password_max_age',
                'profiles',
                'security_recipients',
                'step_up_seconds',
            ])
            ->setAllowedTypes('approval_profiles', 'array')
            ->setAllowedTypes('audit_days', 'int')
            ->setAllowedTypes('mode', 'string')
            ->setAllowedTypes('password_max_age', 'int')
            ->setAllowedTypes('profiles', 'array')
            ->setAllowedTypes('security_recipients', 'string')
            ->setAllowedTypes('step_up_seconds', 'int')
            ->setAllowedValues('mode', ['all', 'profiles', 'superadmins']);
    }

    /**
     * @return int[]
     */
    private function parseProfileIds(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $value)),
            static fn (int $profileId): bool => $profileId > 0
        )));
    }

    /**
     * @param mixed[] $profileIds
     */
    private function serializeProfileIds(array $profileIds): string
    {
        return implode(',', array_unique(array_map('intval', $profileIds)));
    }
}
