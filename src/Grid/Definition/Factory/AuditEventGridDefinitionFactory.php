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
                        $this->trans('2FA setup approved', [], 'Modules.Mpadmin2fa.Admin') => 'enrollment.approved',
                        $this->trans('Authenticator set up', [], 'Modules.Mpadmin2fa.Admin') => 'enrollment.confirmed',
                        $this->trans('Authenticator setup failed', [], 'Modules.Mpadmin2fa.Admin') => 'enrollment.failed',
                        $this->trans('Authenticator settings confirmed', [], 'Modules.Mpadmin2fa.Admin') => 'factor_change.verified',
                        $this->trans('Authenticator-settings check failed', [], 'Modules.Mpadmin2fa.Admin') => 'factor_change.failed',
                        $this->trans('Recovery code rejected', [], 'Modules.Mpadmin2fa.Admin') => 'recovery.failed',
                        $this->trans('Recovery code used', [], 'Modules.Mpadmin2fa.Admin') => 'recovery.used',
                        $this->trans('Recovery codes replaced', [], 'Modules.Mpadmin2fa.Admin') => 'recovery.regenerated',
                        $this->trans('Security-change 2FA confirmed', [], 'Modules.Mpadmin2fa.Admin') => 'step_up.verified',
                        $this->trans('Security-change 2FA failed', [], 'Modules.Mpadmin2fa.Admin') => 'step_up.failed',
                        $this->trans('Sign-in 2FA confirmed', [], 'Modules.Mpadmin2fa.Admin') => 'challenge.verified',
                        $this->trans('Sign-in 2FA failed', [], 'Modules.Mpadmin2fa.Admin') => 'challenge.failed',
                        $this->trans('Two-factor authentication reset', [], 'Modules.Mpadmin2fa.Admin') => 'factor.reset',
                        $this->trans('Two-factor authentication settings changed', [], 'Modules.Mpadmin2fa.Admin') => 'policy.updated',
                    ],
                    'choice_translation_domain' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
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
