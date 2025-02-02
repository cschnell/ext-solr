<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\ContentObject;

use ApacheSolrForTypo3\Solr\System\Language\FrontendOverlayService;
use ApacheSolrForTypo3\Solr\System\TCA\TCAService;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception as DBALException;
use ReflectionClass;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\AbstractContentObject;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * A content object (cObj) to resolve relations between database records
 *
 * Configuration options:
 *
 * localField: the record's field to use to resolve relations
 * foreignLabelField: Usually the label field to retrieve from the related records is determined automatically using TCA, using this option the desired field can be specified explicitly
 * multiValue: whether to return related records suitable for a multi value field
 * singleValueGlue: when not using multiValue, the related records need to be concatenated using a glue string, by default this is ", ". Using this option a custom glue can be specified. The custom value must be wrapped by pipe (|) characters.
 * relationTableSortingField: field in a mm relation table to sort by, usually "sorting"
 * enableRecursiveValueResolution: if the specified remote table's label field is a relation to another table, the value will be resolved by following the relation recursively.
 * removeEmptyValues: Removes empty values when resolving relations, defaults to TRUE
 * removeDuplicateValues: Removes duplicate values
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class Relation extends AbstractContentObject
{
    public const CONTENT_OBJECT_NAME = 'SOLR_RELATION';

    /**
     * Content object configuration
     *
     * @var array
     */
    protected array $configuration = [];

    /**
     * @var TCAService
     */
    protected TCAService $tcaService;

    /**
     * @var FrontendOverlayService
     */
    protected FrontendOverlayService $frontendOverlayService;

    /**
     * @var TypoScriptFrontendController|null
     */
    protected ?TypoScriptFrontendController $typoScriptFrontendController = null;

    /**
     * Relation constructor.
     * @param TCAService|null $tcaService
     * @param FrontendOverlayService|null $frontendOverlayService
     */
    public function __construct(
        ContentObjectRenderer $cObj,
        TCAService $tcaService = null,
        FrontendOverlayService $frontendOverlayService = null
    ) {
        parent::__construct($cObj);
        $this->configuration['enableRecursiveValueResolution'] = 1;
        $this->configuration['removeEmptyValues'] = 1;
        $this->tcaService = $tcaService ?? GeneralUtility::makeInstance(TCAService::class);
        $this->typoScriptFrontendController = $this->getProtectedTsfeObjectFromContentObjectRenderer($cObj);
        $this->frontendOverlayService = $frontendOverlayService ?? GeneralUtility::makeInstance(FrontendOverlayService::class, $this->tcaService, $this->typoScriptFrontendController);
    }

    /**
     * Executes the SOLR_RELATION content object.
     *
     * Resolves relations between records. Currently, supported relations are
     * TYPO3-style m:n relations.
     * May resolve single value and multi value relations.
     *
     * @inheritDoc
     *
     * @param array $conf
     * @return string
     * @throws AspectNotFoundException
     * @throws DBALException
     * @noinspection PhpMissingReturnTypeInspection, because foreign source inheritance See {@link AbstractContentObject::render()}
     */
    public function render($conf = [])
    {
        $this->configuration = array_merge($this->configuration, $conf);

        $relatedItems = $this->getRelatedItems($this->cObj);

        if (!empty($this->configuration['removeDuplicateValues'])) {
            $relatedItems = array_unique($relatedItems);
        }

        if (empty($conf['multiValue'])) {
            // single value, need to concatenate related items
            $singleValueGlue = !empty($conf['singleValueGlue']) ? trim($conf['singleValueGlue'], '|') : ', ';
            $result = implode($singleValueGlue, $relatedItems);
        } else {
            // multi value, need to serialize as content objects must return strings
            $result = serialize($relatedItems);
        }

        return $result;
    }

    /**
     * Gets the related items of the current record's configured field.
     *
     * @param ContentObjectRenderer $parentContentObject parent content object
     *
     * @return array Array of related items, values already resolved from related records
     *
     * @throws AspectNotFoundException
     * @throws DBALException
     */
    protected function getRelatedItems(ContentObjectRenderer $parentContentObject): array
    {
        list($table, $uid) = explode(':', $parentContentObject->currentRecord);
        $uid = (int)$uid;
        $field = $this->configuration['localField'];

        if (!$this->tcaService->/** @scrutinizer ignore-call */ getHasConfigurationForField($table, $field)) {
            return [];
        }

        $overlayUid = $this->frontendOverlayService->/** @scrutinizer ignore-call */ getUidOfOverlay($table, $field, $uid);
        $fieldTCA = $this->tcaService->getConfigurationForField($table, $field);

        if (isset($fieldTCA['config']['MM']) && trim($fieldTCA['config']['MM']) !== '') {
            $relatedItems = $this->getRelatedItemsFromMMTable($table, $overlayUid, $fieldTCA);
        } else {
            $relatedItems = $this->getRelatedItemsFromForeignTable($table, $overlayUid, $fieldTCA, $parentContentObject);
        }

        return $relatedItems;
    }

    /**
     * Gets the related items from a table using the n:m relation.
     *
     * @param string $localTableName Local table name
     * @param int $localRecordUid Local record uid
     * @param array $localFieldTca The local table's TCA
     * @return array Array of related items, values already resolved from related records
     *
     * @throws AspectNotFoundException
     * @throws DBALException
     */
    protected function getRelatedItemsFromMMTable(string $localTableName, int $localRecordUid, array $localFieldTca): array
    {
        $relatedItems = [];
        $foreignTableName = $localFieldTca['config']['foreign_table'];
        $foreignTableTca = $this->tcaService->getTableConfiguration($foreignTableName);
        $foreignTableLabelField = $this->resolveForeignTableLabelField($foreignTableTca);
        $mmTableName = $localFieldTca['config']['MM'];

        // Remove the first option of foreignLabelField for recursion
        if (strpos($this->configuration['foreignLabelField'] ?? '', '.') !== false) {
            $foreignTableLabelFieldArr = explode('.', $this->configuration['foreignLabelField']);
            unset($foreignTableLabelFieldArr[0]);
            $this->configuration['foreignLabelField'] = implode('.', $foreignTableLabelFieldArr);
        }

        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->start('', $foreignTableName, $mmTableName, $localRecordUid, $localTableName, $localFieldTca['config']);
        $selectUids = $relationHandler->tableArray[$foreignTableName];
        if (!is_array($selectUids) || count($selectUids) <= 0) {
            return $relatedItems;
        }

        $relatedRecords = $this->getRelatedRecords($foreignTableName, ...$selectUids);
        foreach ($relatedRecords as $record) {
            if (isset($foreignTableTca['columns'][$foreignTableLabelField]['config']['foreign_table'])
                && !empty($this->configuration['enableRecursiveValueResolution'])
            ) {
                if (strpos($this->configuration['foreignLabelField'], '.') !== false) {
                    $foreignTableLabelFieldArr = explode('.', $this->configuration['foreignLabelField']);
                    unset($foreignTableLabelFieldArr[0]);
                    $this->configuration['foreignLabelField'] = implode('.', $foreignTableLabelFieldArr);
                }

                $this->configuration['localField'] = $foreignTableLabelField;

                $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
                $contentObject->start($record, $foreignTableName);

                return $this->getRelatedItems($contentObject);
            }
            if ($this->getLanguageUid() > 0) {
                $record = $this->frontendOverlayService->getOverlay($foreignTableName, $record);
            }
            $relatedItems[] = $record[$foreignTableLabelField];
        }

        return $relatedItems;
    }

    /**
     * Resolves the field to use as the related item's label depending on TCA
     * and TypoScript configuration
     *
     * @param array $foreignTableTca The foreign table's TCA
     *
     * @return string|null The field to use for the related item's label
     */
    protected function resolveForeignTableLabelField(array $foreignTableTca): ?string
    {
        $foreignTableLabelField = $foreignTableTca['ctrl']['label'] ?? null;

        // when foreignLabelField is not enabled we can return directly
        if (empty($this->configuration['foreignLabelField'])) {
            return $foreignTableLabelField;
        }

        if (strpos($this->configuration['foreignLabelField'] ?? '', '.') !== false) {
            list($foreignTableLabelField) = explode('.', $this->configuration['foreignLabelField'], 2);
        } else {
            $foreignTableLabelField = $this->configuration['foreignLabelField'];
        }

        return $foreignTableLabelField;
    }

    /**
     * Gets the related items from a table using a 1:n relation.
     *
     * @param string $localTableName Local table name
     * @param int $localRecordUid Local record uid
     * @param array $localFieldTca The local table's TCA
     * @param ContentObjectRenderer $parentContentObject parent content object
     * @return array Array of related items, values already resolved from related records
     *
     * @throws AspectNotFoundException
     * @throws DBALException
     */
    protected function getRelatedItemsFromForeignTable(
        string $localTableName,
        int $localRecordUid,
        array $localFieldTca,
        ContentObjectRenderer $parentContentObject
    ): array {
        $relatedItems = [];
        $foreignTableName = $localFieldTca['config']['foreign_table'];
        $foreignTableTca = $this->tcaService->getTableConfiguration($foreignTableName);
        $foreignTableLabelField = $this->resolveForeignTableLabelField($foreignTableTca);

        /** @var $relationHandler RelationHandler */
        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);

        $itemList = $parentContentObject->data[$this->configuration['localField']] ?? '';

        $relationHandler->start($itemList, $foreignTableName, '', $localRecordUid, $localTableName, $localFieldTca['config']);
        $selectUids = $relationHandler->tableArray[$foreignTableName];

        if (!is_array($selectUids) || count($selectUids) <= 0) {
            return $relatedItems;
        }

        $relatedRecords = $this->getRelatedRecords($foreignTableName, ...$selectUids);

        foreach ($relatedRecords as $relatedRecord) {
            $resolveRelatedValue = $this->resolveRelatedValue(
                $relatedRecord,
                $foreignTableTca,
                $foreignTableLabelField,
                $parentContentObject,
                $foreignTableName
            );
            if (!empty($resolveRelatedValue) || !$this->configuration['removeEmptyValues']) {
                $relatedItems[] = $resolveRelatedValue;
            }
        }

        return $relatedItems;
    }

    /**
     * Resolves the value of the related field. If the related field's value is
     * a relation itself, this method takes care of resolving it recursively.
     *
     * @param array $relatedRecord Related record as array
     * @param array $foreignTableTca TCA of the related table
     * @param string $foreignTableLabelField Field name of the foreign label field
     * @param ContentObjectRenderer $parentContentObject cObject
     * @param string $foreignTableName Related record table name
     *
     * @return string
     *
     * @throws AspectNotFoundException
     * @throws DBALException
     */
    protected function resolveRelatedValue(
        array $relatedRecord,
        array $foreignTableTca,
        string $foreignTableLabelField,
        ContentObjectRenderer $parentContentObject,
        string $foreignTableName = ''
    ): string {
        if ($this->getLanguageUid() > 0 && !empty($foreignTableName)) {
            $relatedRecord = $this->frontendOverlayService->getOverlay($foreignTableName, $relatedRecord);
        }

        $value = $relatedRecord[$foreignTableLabelField];

        if (
            !empty($foreignTableName)
            && isset($foreignTableTca['columns'][$foreignTableLabelField]['config']['foreign_table'])
            && $this->configuration['enableRecursiveValueResolution']
        ) {
            // backup
            $backupRecord = $parentContentObject->data;
            $backupConfiguration = $this->configuration;

            // adjust configuration for next level
            $this->configuration['localField'] = $foreignTableLabelField;
            $parentContentObject->data = $relatedRecord;
            if (strpos($this->configuration['foreignLabelField'], '.') !== false) {
                list(, $this->configuration['foreignLabelField']) = explode(
                    '.',
                    $this->configuration['foreignLabelField'],
                    2
                );
            } else {
                $this->configuration['foreignLabelField'] = '';
            }

            // recursion
            $relatedItemsFromForeignTable = $this->getRelatedItemsFromForeignTable(
                $foreignTableName,
                $relatedRecord['uid'],
                $foreignTableTca['columns'][$foreignTableLabelField],
                $parentContentObject
            );
            $value = array_pop($relatedItemsFromForeignTable);

            // restore
            $this->configuration = $backupConfiguration;
            $parentContentObject->data = $backupRecord;
        }

        return $parentContentObject->stdWrap($value, $this->configuration) ?? '';
    }

    /**
     * Return records via relation.
     *
     * @param string $foreignTable The table to fetch records from.
     * @param int ...$uids The uids to fetch from table.
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getRelatedRecords(string $foreignTable, int ...$uids): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($foreignTable);
        $queryBuilder->select('*')
            ->from($foreignTable)
            ->where(/** @scrutinizer ignore-type */ $queryBuilder->expr()->in('uid', $uids));
        if (isset($this->configuration['additionalWhereClause'])) {
            $queryBuilder->andWhere($this->configuration['additionalWhereClause']);
        }
        $statement = $queryBuilder->execute();

        return $this->sortByKeyInIN($statement, 'uid', ...$uids);
    }

    /**
     * Sorts the result set by key in array for IN values.
     *   Simulates MySqls ORDER BY FIELD(fieldname, COPY_OF_IN_FOR_WHERE)
     *   Example: SELECT * FROM a_table WHERE field_name IN (2, 3, 4) SORT BY FIELD(field_name, 2, 3, 4)
     *
     *
     * @param Statement $statement
     * @param string $columnName
     * @param array $arrayWithValuesForIN
     * @return array
     */
    protected function sortByKeyInIN(Statement $statement, string $columnName, ...$arrayWithValuesForIN): array
    {
        $records = [];
        while ($record = $statement->fetchAssociative()) {
            $indexNumber = array_search($record[$columnName], $arrayWithValuesForIN);
            $records[$indexNumber] = $record;
        }
        ksort($records);
        return $records;
    }

    /**
     * Returns current language id fetched from object properties chain TSFE->context->language aspect->id.
     *
     * @return int
     * @throws AspectNotFoundException
     */
    protected function getLanguageUid(): int
    {
        return (int)$this->typoScriptFrontendController->/** @scrutinizer ignore-call */ getContext()->getPropertyFromAspect('language', 'id');
    }

    /**
     * Returns inaccessible {@link ContentObjectRenderer::typoScriptFrontendController} via reflection.
     *
     * The TypoScriptFrontendController object must be delegated to the whole object aggregation on indexing stack,
     * to be able to use Contexts properties and proceed indexing request.
     *
     * @param ContentObjectRenderer $cObj
     * @return TypoScriptFrontendController|null
     */
    protected function getProtectedTsfeObjectFromContentObjectRenderer(ContentObjectRenderer $cObj): ?TypoScriptFrontendController
    {
        $reflection = new ReflectionClass($cObj);
        $property = $reflection->getProperty('typoScriptFrontendController');
        $property->setAccessible(true);
        return $property->getValue($cObj);
    }
}
