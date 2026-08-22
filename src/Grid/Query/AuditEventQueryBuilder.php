<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

final class AuditEventQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
    ) {
        parent::__construct($connection, $dbPrefix);
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->baseQuery($searchCriteria)
            ->select(
                'a.id_audit',
                'a.date_add',
                'CASE'
                . ' WHEN a.id_employee IS NULL THEN "System"'
                . ' WHEN e.id_employee IS NULL THEN CONCAT(a.id_employee, " - Deleted employee")'
                . ' ELSE CONCAT(a.id_employee, " - ", e.firstname, " ", e.lastname)'
                . ' END AS employee',
                'a.event',
                'a.ip'
            );

        $this->searchCriteriaApplicator
            ->applyPagination($searchCriteria, $queryBuilder)
            ->applySorting($searchCriteria, $queryBuilder);

        return $queryBuilder;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->baseQuery($searchCriteria)->select('COUNT(DISTINCT a.id_audit)');
    }

    private function baseQuery(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'mp2fa_audit', 'a')
            ->leftJoin('a', $this->dbPrefix . 'employee', 'e', 'e.id_employee = a.id_employee');

        foreach ($searchCriteria->getFilters() as $name => $value) {
            if ('id_audit' === $name) {
                $queryBuilder->andWhere('a.id_audit = :id_audit')->setParameter('id_audit', (int) $value);
            } elseif ('employee' === $name) {
                $queryBuilder
                    ->andWhere('CONCAT(COALESCE(e.firstname, ""), " ", COALESCE(e.lastname, ""), " ", COALESCE(e.email, "")) LIKE :employee')
                    ->setParameter('employee', '%' . $value . '%');
            } elseif ('event' === $name) {
                $queryBuilder->andWhere('a.event LIKE :event')->setParameter('event', '%' . $value . '%');
            } elseif ('ip' === $name) {
                $queryBuilder->andWhere('a.ip LIKE :ip')->setParameter('ip', '%' . $value . '%');
            }
        }

        return $queryBuilder;
    }
}
