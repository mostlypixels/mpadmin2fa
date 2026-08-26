<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DateTimeColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PendingApprovalGridDefinitionFactory extends AbstractGridDefinitionFactory
{

    /** @var AuthorizationCheckerInterface */
    private $authorizationChecker;

    public const GRID_ID = 'mp2fa_pending_approval';

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
        return $this->trans('2FA setups waiting for approval', [], 'Modules.Mpadmin2fa.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add($this->createDataColumn('id_employee')
                ->setName($this->trans('ID', [], 'Admin.Global'))
                ->setOptions(['field' => 'id_employee']))
            ->add($this->createDataColumn('employee')
                ->setName($this->trans('Employee', [], 'Admin.Global'))
                ->setOptions(['field' => 'employee']))
            ->add($this->createDataColumn('email')
                ->setName($this->trans('Email', [], 'Admin.Global'))
                ->setOptions(['field' => 'email']))
            ->add((new DateTimeColumn('date_add'))
                ->setName($this->trans('Requested', [], 'Modules.Mpadmin2fa.Admin'))
                ->setOptions(['field' => 'date_add', 'format' => 'Y-m-d H:i:s']))
            ->add((new ActionColumn('actions'))
                ->setName($this->trans('Actions', [], 'Admin.Global'))
                ->setOptions([
                    'actions' => (new RowActionCollection())
                        ->add((new SubmitRowAction('approve'))
                            ->setName($this->trans('Approve', [], 'Admin.Actions'))
                            ->setIcon('check')
                            ->setOptions([
                                'accessibility_checker' => function (array $record): bool {
                                    return empty($record['is_current_employee'])
                                        && $this->authorizationChecker->isGranted(
                                            'update',
                                            'AdminMpAdmin2faEnrollment'
                                        );
                                },
                                'extra_route_params' => ['token' => 'approval_token'],
                                'route' => 'mpadmin2fa_approve',
                                'route_param_field' => 'id_employee',
                                'route_param_name' => 'employeeId',
                            ])),
                ]));
    }

    private function createDataColumn(string $id)
    {
        if (class_exists(\PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn::class)) {
            return new \PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn($id);
        }

        return new \PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn($id);
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
            ->add((new Filter('actions', SearchAndResetType::class))
                ->setTypeOptions([
                    'reset_route' => 'admin_common_reset_search_by_filter_id',
                    'reset_route_params' => ['filterId' => self::GRID_ID],
                    'redirect_route' => 'mpadmin2fa_enrollment_approvals',
                ])
                ->setAssociatedColumn('actions'));
    }
}
