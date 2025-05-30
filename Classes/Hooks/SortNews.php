<?php

declare(strict_types=1);

namespace B13\NewspageSorting\Hooks;

/*
 * This file is part of TYPO3 CMS-based extension "newspage-sorting" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

class SortNews
{
    protected array $pagesToSort = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        $id,
        array &$fieldArray,
        DataHandler &$dataHandlerObject
    ): void {
        if ($table === 'pages') {
            if (!is_numeric($id) && isset($dataHandlerObject->substNEWwithIDs[$id])) {
                $recordUid = $dataHandlerObject->substNEWwithIDs[$id];
            } else {
                $recordUid = $id;
            }
            $recordUid = (int)$recordUid;
            $currentPage = BackendUtility::getRecord('pages', $recordUid);
            if ($currentPage['doktype'] !== 24 || $currentPage['tx_newspage_date'] === null) {
                return;
            }

            $rootLine = (new RootlineUtility($recordUid))->get();

            $newsRootLine = [];
            foreach ($rootLine as $page) {
                $record = BackendUtility::getRecord('pages', $page['pid']);

                // only sort news into year/month subfolders if they are in a
                // news folder (=module field)
                if ($record['doktype'] === 254 && $record['module'] === 'newspage') {
                    $newsRootLine[] = $record;
                } else {
                    break;
                }
            }
            if (empty($newsRootLine)) {
                return;
            }
            $newsRootLine = array_reverse($newsRootLine);
            $date = new \DateTime($currentPage['tx_newspage_date']);

            $configuration = $this->extensionConfiguration->get('newspage_sorting');
            $pid = $this->getStoragePage($date, $newsRootLine, (bool)$configuration['sortByMonth'], (bool)$configuration['sortByDay']);
            $this->updatePid($pid, $recordUid);
            if (!empty($this->pagesToSort)) {
                $this->sortStoragePages();
            }
            BackendUtility::setUpdateSignal('updatePageTree');
        }
    }

    protected function updatePid(int $pid, int $uid): void
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->update('pages')
            ->set('pid', $pid, type: ParameterType::INTEGER)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->executeQuery();
    }

    protected function getStoragePage(\DateTime $date, array $rootLine, bool $sortByMonth, bool $sortByDay): int
    {
        $root = array_shift($rootLine);
        $yearFolder = array_shift($rootLine);
        if (($yearFolder['title'] ?? '') !== $date->format('Y')) {
            $yearFolder = $this->resolveFolder($root['uid'], $date->format('Y'));
            $rootLine = []; // don't look in wrong year folder
        } else {
            $yearFolder = $yearFolder['uid'];
            $this->pagesToSort[] = $yearFolder;
        }

        if ($sortByMonth === false) {
            return $yearFolder;
        }
        $monthFolder = array_shift($rootLine);
        if (($monthFolder['title'] ?? '') !== $date->format('m')) {
            $monthFolder = $this->resolveFolder($yearFolder, $date->format('m'));
        } else {
            $monthFolder = $monthFolder['uid'];
            $this->pagesToSort[] = $monthFolder;
        }

        if ($sortByDay === false) {
            return $monthFolder;
        }
        $dayFolder = array_shift($rootLine);
        if (($dayFolder['title'] ?? '') !== $date->format('d')) {
            $dayFolder = $this->resolveFolder($monthFolder, $date->format('d'));
        } else {
            $dayFolder = $dayFolder['uid'];
            $this->pagesToSort[] = $dayFolder;
        }

        return $dayFolder;
    }

    protected function resolveFolder(int $pid, string $title): int
    {
        $this->pagesToSort[] = $pid;
        $uid = $this->getFolder($pid, $title);
        if ($uid === null) {
            $uid = $this->createFolder($pid, $title);
        }
        return $uid;
    }

    protected function getFolder(int $pid, string $title): ?int
    {
        $queryBuilder = $this->getQueryBuilder();
        return $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title))
            )
            ->executeQuery()
            ->fetchOne() ?: null;
    }

    protected function createFolder(int $pid, string $title): int
    {
        $execTime = GeneralUtility::makeInstance(Context::class)->getAspect('date')->getDateTime()->getTimestamp();
        $newKey = uniqid('NEW');
        $data = [
            'pages' => [
                $newKey => [
                    'pid' => $pid,
                    'hidden' => 0,
                    'doktype' => 254,
                    'title' => $title,
                    'tstamp' => $execTime,
                    'crdate' => $execTime,
                    'module' => 'newspage',
                    'perms_userid' => 1,
                    'perms_groupid' => 1,
                    'perms_user' => 31,
                    'perms_group' => 31,
                    'perms_everybody' => 1,
                ],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        return $dataHandler->substNEWwithIDs[$newKey];
    }

    protected function sortStoragePages(): void
    {
        foreach ($this->pagesToSort as $pageToSort) {
            $queryBuilder = $this->getQueryBuilder();
            $pages = $queryBuilder
                ->select('uid', 'title', 'sorting')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageToSort, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(254, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('module', $queryBuilder->createNamedParameter('newspage'))
                )
                ->orderBy('title', 'DESC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($pages as $key => $page) {
                $queryBuilder = $this->getQueryBuilder();
                $queryBuilder
                    ->update('pages')
                    ->set('sorting', $key)
                    ->where(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($page['uid'], ParameterType::INTEGER))
                    )
                    ->executeStatement();
            }
        }
    }

    protected function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable('pages');
    }
}
