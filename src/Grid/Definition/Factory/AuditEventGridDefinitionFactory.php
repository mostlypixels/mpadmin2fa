<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DateTimeColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class AuditEventGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'mp2fa_audit_event';

    protected function getId(): string
    {
        return self::GRID_ID;
    }

    protected function getName(): string
    {
        return $this->trans('Security activity', [], 'Modules.Mpadmin2fa.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add((new DataColumn('id_audit'))
                ->setName($this->trans('ID', [], 'Admin.Global'))
                ->setOptions(['field' => 'id_audit']))
            ->add((new DateTimeColumn('date_add'))
                ->setName($this->trans('Time', [], 'Admin.Global'))
                ->setOptions(['field' => 'date_add', 'format' => 'Y-m-d H:i:s']))
            ->add((new DataColumn('employee'))
                ->setName($this->trans('Employee', [], 'Admin.Global'))
                ->setOptions(['field' => 'employee']))
            ->add((new DataColumn('event'))
                ->setName($this->trans('What happened', [], 'Modules.Mpadmin2fa.Admin'))
                ->setOptions(['field' => 'event_label']))
            ->add((new DataColumn('ip'))
                ->setName($this->trans('IP address', [], 'Admin.Global'))
                ->setOptions(['field' => 'ip']));
    }

    protected function getFilters(): FilterCollection
    {
        return (new FilterCollection())
            ->add((new Filter('id_audit', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('id_audit'))
            ->add((new Filter('employee', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('employee'))
            ->add((new Filter('event', ChoiceType::class))
                ->setTypeOptions([
                    'choices' => [
                        '2FA setup approved' => 'enrollment.approved',
                        'Authenticator set up' => 'enrollment.confirmed',
                        'Authenticator setup failed' => 'enrollment.failed',
                        'Authenticator settings confirmed' => 'factor_change.verified',
                        'Authenticator-settings check failed' => 'factor_change.failed',
                        'Recovery code rejected' => 'recovery.failed',
                        'Recovery code used' => 'recovery.used',
                        'Recovery codes replaced' => 'recovery.regenerated',
                        'Security-change 2FA confirmed' => 'step_up.verified',
                        'Security-change 2FA failed' => 'step_up.failed',
                        'Sign-in 2FA confirmed' => 'challenge.verified',
                        'Sign-in 2FA failed' => 'challenge.failed',
                        'Two-factor authentication reset' => 'factor.reset',
                        'Two-factor authentication settings changed' => 'policy.updated',
                    ],
                    'placeholder' => 'All',
                    'required' => false,
                ])
                ->setAssociatedColumn('event'))
            ->add((new Filter('ip', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('ip'))
            ->add((new Filter('actions', SearchAndResetType::class))
                ->setTypeOptions([
                    'reset_route' => 'admin_common_reset_search_by_filter_id',
                    'reset_route_params' => ['filterId' => self::GRID_ID],
                    'redirect_route' => 'mpadmin2fa_security_activity',
                ])
                ->setAssociatedColumn('ip'));
    }
}
