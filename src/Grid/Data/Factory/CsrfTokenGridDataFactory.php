<?php

declare(strict_types=1);

namespace Mpadmin2fa\Grid\Data\Factory;

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CsrfTokenGridDataFactory implements GridDataFactoryInterface
{
    public function __construct(
        private readonly GridDataFactoryInterface $dataFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $idField,
        private readonly string $tokenField,
        private readonly string $tokenPrefix,
    ) {
    }

    public function getData(SearchCriteriaInterface $searchCriteria): GridData
    {
        $gridData = $this->dataFactory->getData($searchCriteria);
        $records = $gridData->getRecords()->all();

        foreach ($records as &$record) {
            $record[$this->tokenField] = $this->csrfTokenManager
                ->getToken($this->tokenPrefix . (int) $record[$this->idField])
                ->getValue();
        }
        unset($record);

        return new GridData(
            new RecordCollection($records),
            $gridData->getRecordsTotal(),
            $gridData->getQuery()
        );
    }
}
