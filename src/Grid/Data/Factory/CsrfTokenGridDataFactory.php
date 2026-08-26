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

    /** @var GridDataFactoryInterface */
    private $dataFactory;

    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    /** @var string */
    private $idField;

    /** @var string */
    private $tokenField;

    /** @var string */
    private $tokenPrefix;

    public function __construct(
        GridDataFactoryInterface $dataFactory,
        CsrfTokenManagerInterface $csrfTokenManager,
        string $idField,
        string $tokenField,
        string $tokenPrefix
    ) {
        $this->dataFactory = $dataFactory;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->idField = $idField;
        $this->tokenField = $tokenField;
        $this->tokenPrefix = $tokenPrefix;
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
