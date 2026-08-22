<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Context\EmployeeContext;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

final class PendingApprovalQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
        private readonly EmployeeContext $employeeContext,
    ) {
        parent::__construct($connection, $dbPrefix);
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->baseQuery($searchCriteria)
            ->select(
                'e.id_employee',
                'CONCAT(e.firstname, " ", e.lastname) AS employee',
                'e.email',
                'a.date_add',
                'CASE WHEN e.id_employee = :current_employee_id THEN 1 ELSE 0 END AS is_current_employee'
            )
            ->setParameter(
                'current_employee_id',
                $this->employeeContext->getEmployee()?->getId() ?? 0
            );

        $this->searchCriteriaApplicator
            ->applyPagination($searchCriteria, $queryBuilder)
            ->applySorting($searchCriteria, $queryBuilder);

        return $queryBuilder;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->baseQuery($searchCriteria)->select('COUNT(DISTINCT a.id_approval)');
    }

    private function baseQuery(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'mp2fa_approval', 'a')
            ->innerJoin('a', $this->dbPrefix . 'employee', 'e', 'e.id_employee = a.id_employee')
            ->andWhere('a.status = :approval_status')
            ->setParameter('approval_status', 'pending');

        $filterMap = [
            'email' => 'e.email',
            'employee' => 'CONCAT(e.firstname, " ", e.lastname)',
        ];
        foreach ($searchCriteria->getFilters() as $name => $value) {
            if ('id_employee' === $name) {
                $queryBuilder->andWhere('e.id_employee = :id_employee')->setParameter('id_employee', (int) $value);
            } elseif (isset($filterMap[$name])) {
                $queryBuilder->andWhere($filterMap[$name] . ' LIKE :' . $name)
                    ->setParameter($name, '%' . $value . '%');
            }
        }

        return $queryBuilder;
    }
}
