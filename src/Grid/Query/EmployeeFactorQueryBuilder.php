<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

final class EmployeeFactorQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
        private readonly int $languageId,
        private readonly int $currentEmployeeId,
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
                'COALESCE(pl.name, "Unknown profile") AS profile_name',
                'COALESCE(f.status, "not_enrolled") AS status',
                'CASE COALESCE(f.status, "not_enrolled")'
                    . ' WHEN "active" THEN "Active"'
                    . ' WHEN "pending" THEN "Waiting for approval"'
                    . ' ELSE "Not set up" END AS status_label',
                'f.confirmed_at',
                'CASE WHEN f.id_employee IS NULL THEN 0 ELSE 1 END AS has_factor',
                'CASE WHEN e.id_employee = :current_employee_id THEN 1 ELSE 0 END AS is_current_employee'
            )
            ->setParameter(
                'current_employee_id', $this->currentEmployeeId);

        $this->searchCriteriaApplicator
            ->applyPagination($searchCriteria, $queryBuilder)
            ->applySorting($searchCriteria, $queryBuilder);

        return $queryBuilder;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->baseQuery($searchCriteria)->select('COUNT(DISTINCT e.id_employee)');
    }

    private function baseQuery(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'employee', 'e')
            ->leftJoin('e', $this->dbPrefix . 'mp2fa_employee', 'f', 'f.id_employee = e.id_employee')
            ->leftJoin(
                'e',
                $this->dbPrefix . 'profile_lang',
                'pl',
                'pl.id_profile = e.id_profile AND pl.id_lang = :language_id'
            )
            ->setParameter('language_id', $this->languageId);

        $filterMap = [
            'email' => 'e.email',
            'employee' => 'CONCAT(e.firstname, " ", e.lastname)',
            'id_employee' => 'e.id_employee',
            'profile_name' => 'pl.name',
        ];
        foreach ($searchCriteria->getFilters() as $name => $value) {
            if ('status' === $name) {
                if ('not_enrolled' === $value) {
                    $queryBuilder->andWhere('f.status IS NULL');
                } else {
                    $queryBuilder->andWhere('f.status = :status')->setParameter('status', $value);
                }

                continue;
            }
            if (!isset($filterMap[$name])) {
                continue;
            }
            if ('id_employee' === $name) {
                $queryBuilder->andWhere('e.id_employee = :id_employee')->setParameter('id_employee', (int) $value);
            } else {
                $queryBuilder->andWhere($filterMap[$name] . ' LIKE :' . $name)
                    ->setParameter($name, '%' . $value . '%');
            }
        }

        return $queryBuilder;
    }
}
