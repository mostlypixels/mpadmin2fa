<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DateTimeColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class EmployeeFactorGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    /** @var AuthorizationCheckerInterface */
    private $authorizationChecker;

    public const GRID_ID = 'mp2fa_employee_factor';

    public function __construct(
        HookDispatcherInterface $hookDispatcher,
        AuthorizationCheckerInterface $authorizationChecker
    ) {
        $this->authorizationChecker = $authorizationChecker;

        parent::__construct($hookDispatcher);
    }

    protected function getId(): string
    {
        return self::GRID_ID;
    }

    protected function getName(): string
    {
        return $this->trans('Employee two-factor authentication', [], 'Modules.Mpadmin2fa.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add((new DataColumn('id_employee'))
                ->setName($this->trans('ID', [], 'Admin.Global'))
                ->setOptions(['field' => 'id_employee']))
            ->add((new DataColumn('employee'))
                ->setName($this->trans('Employee', [], 'Admin.Global'))
                ->setOptions(['field' => 'employee']))
            ->add((new DataColumn('email'))
                ->setName($this->trans('Email', [], 'Admin.Global'))
                ->setOptions(['field' => 'email']))
            ->add((new DataColumn('profile_name'))
                ->setName($this->trans('Profile', [], 'Admin.Global'))
                ->setOptions(['field' => 'profile_name']))
            ->add((new DataColumn('status'))
                ->setName($this->trans('Status', [], 'Admin.Global'))
                ->setOptions(['field' => 'status_label']))
            ->add((new DateTimeColumn('confirmed_at'))
                ->setName($this->trans('Set up on', [], 'Modules.Mpadmin2fa.Admin'))
                ->setOptions(['field' => 'confirmed_at', 'format' => 'Y-m-d H:i:s']))
            ->add((new ActionColumn('actions'))
                ->setName($this->trans('Actions', [], 'Admin.Global'))
                ->setOptions([
                    'actions' => (new RowActionCollection())
                        ->add((new SubmitRowAction('reset_factor'))
                            ->setName($this->trans('Reset two-factor authentication', [], 'Modules.Mpadmin2fa.Admin'))
                            ->setIcon('delete')
                            ->setOptions([
                                'accessibility_checker' => function (array $record): bool {
                                    return !empty($record['has_factor'])
                                        && empty($record['is_current_employee'])
                                        && $this->authorizationChecker->isGranted(
                                            'delete',
                                            'AdminMpAdmin2faEnrollment'
                                        );
                                },
                                'confirm_message' => $this->trans(
                                    'Reset two-factor authentication for this employee? They will need to set it up again.',
                                    [],
                                    'Modules.Mpadmin2fa.Admin'
                                ),
                                'extra_route_params' => ['token' => 'reset_token'],
                                'route' => 'mpadmin2fa_admin_reset',
                                'route_param_field' => 'id_employee',
                                'route_param_name' => 'employeeId',
                            ])),
                ]));
    }

    protected function getFilters(): FilterCollection
    {
        return (new FilterCollection())
            ->add((new Filter('id_employee', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('id_employee'))
            ->add((new Filter('employee', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('employee'))
            ->add((new Filter('email', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('email'))
            ->add((new Filter('profile_name', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('profile_name'))
            ->add((new Filter('status', ChoiceType::class))
                ->setTypeOptions([
                    'choices' => [
                        'Active' => 'active',
                        'Waiting for approval' => 'pending',
                        'Not set up' => 'not_enrolled',
                    ],
                    'placeholder' => 'All',
                    'required' => false,
                ])
                ->setAssociatedColumn('status'))
            ->add((new Filter('actions', SearchAndResetType::class))
                ->setTypeOptions([
                    'reset_route' => 'admin_common_reset_search_by_filter_id',
                    'reset_route_params' => ['filterId' => self::GRID_ID],
                    'redirect_route' => 'mpadmin2fa_enrollment_employees',
                ])
                ->setAssociatedColumn('actions'));
    }
}
