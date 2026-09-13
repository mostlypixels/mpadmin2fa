<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuditEventQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
        private readonly TranslatorInterface $translator,
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
                . ' WHEN a.id_employee IS NULL THEN '
                . $this->connection->quote($this->translator->trans('System', [], 'Modules.Mpadmin2fa.Admin'))
                . ' WHEN e.id_employee IS NULL THEN CONCAT(a.id_employee, '
                . $this->connection->quote(' - ' . $this->translator->trans('Deleted employee', [], 'Modules.Mpadmin2fa.Admin'))
                . ')'
                . ' ELSE CONCAT(a.id_employee, " - ", e.firstname, " ", e.lastname)'
                . ' END AS employee',
                $this->eventLabelSelect(),
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

    private function eventLabelSelect(): string
    {
        // Labels are resolved in SQL so the grid can still sort by the displayed text.
        $labels = [
            'enrollment.failed' => $this->translator->trans('Authenticator setup failed', [], 'Modules.Mpadmin2fa.Admin'),
            'enrollment.confirmed' => $this->translator->trans('Authenticator set up', [], 'Modules.Mpadmin2fa.Admin'),
            'enrollment.approved' => $this->translator->trans('2FA setup approved', [], 'Modules.Mpadmin2fa.Admin'),
            'challenge.failed' => $this->translator->trans('Sign-in 2FA failed', [], 'Modules.Mpadmin2fa.Admin'),
            'challenge.verified' => $this->translator->trans('Sign-in 2FA confirmed', [], 'Modules.Mpadmin2fa.Admin'),
            'step_up.failed' => $this->translator->trans('Security-change 2FA failed', [], 'Modules.Mpadmin2fa.Admin'),
            'step_up.verified' => $this->translator->trans('Security-change 2FA confirmed', [], 'Modules.Mpadmin2fa.Admin'),
            'factor_change.failed' => $this->translator->trans('Authenticator-settings check failed', [], 'Modules.Mpadmin2fa.Admin'),
            'factor_change.verified' => $this->translator->trans('Authenticator settings confirmed', [], 'Modules.Mpadmin2fa.Admin'),
            'recovery.failed' => $this->translator->trans('Recovery code rejected', [], 'Modules.Mpadmin2fa.Admin'),
            'recovery.used' => $this->translator->trans('Recovery code used', [], 'Modules.Mpadmin2fa.Admin'),
            'recovery.regenerated' => $this->translator->trans('Recovery codes replaced', [], 'Modules.Mpadmin2fa.Admin'),
            'factor.reset' => $this->translator->trans('Two-factor authentication reset', [], 'Modules.Mpadmin2fa.Admin'),
            'policy.updated' => $this->translator->trans('Two-factor authentication settings changed', [], 'Modules.Mpadmin2fa.Admin'),
        ];

        $select = 'CASE a.event';
        foreach ($labels as $event => $label) {
            $select .= ' WHEN ' . $this->connection->quote($event) . ' THEN ' . $this->connection->quote($label);
        }

        return $select . ' ELSE a.event END AS event_label';
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
                $queryBuilder->andWhere('a.event = :event')->setParameter('event', $value);
            } elseif ('ip' === $name) {
                $queryBuilder->andWhere('a.ip LIKE :ip')->setParameter('ip', '%' . $value . '%');
            }
        }

        return $queryBuilder;
    }
}
