<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Service;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Finds and deletes sys_file_processedfile records matching a CleanupCriteria.
 */
final class ProcessedFileCleanupService
{
    private const TABLE = 'sys_file_processedfile';

    /**
     * Value of sys_file.type for images. Not taken from the core API, since TYPO3 v12 only provides
     * AbstractFile::FILETYPE_IMAGE, which is deprecated in v13 and removed in v14 in favour of FileType::IMAGE.
     */
    private const FILE_TYPE_IMAGE = 2;

    /**
     * Resolved absolute base paths per storage uid. Null means "not resolvable".
     *
     * @var array<int, string|null>
     */
    private array $storageBasePaths = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationMatcher $configurationMatcher,
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns all matching processed file rows. Configuration/crop-variant filters are evaluated per row after
     * unserializing the configuration.
     */
    public function findDemanded(CleanupDemand $criteria): array
    {
        $rows = $this->createQuery($criteria)->executeQuery()->fetchAllAssociative();

        if (!$criteria->hasConfigurationFilter()) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->configurationMatches($row, $criteria),
        ));
    }

    /**
     * Returns the size in bytes of the physical file belonging to the given processed file row. If the file
     * can not be located, 0 is returned.
     *
     * Note: The size is determined with plain filesystem functions instead of the TYPO3 FAL API on purpose,
     * since asking FAL for the size of a processed file is very slow when the physical file is not available.
     */
    public function getProcessedFileSize(array $row): int
    {
        $absolutePath = $this->resolveAbsolutePath(
            (int)($row['storage'] ?? 0),
            (string)($row['identifier'] ?? '')
        );
        if ($absolutePath === null || !is_file($absolutePath)) {
            return 0;
        }

        $size = @filesize($absolutePath);

        return $size === false ? 0 : $size;
    }

    /**
     * Deletes the given processed file and its database record.
     *
     * Note: When the physical file does not exist any more, only the database record is removed instead of
     * going through the TYPO3 FAL API, since deleting a processed file through FAL is very slow in that case.
     * The record is removed exactly like TYPO3 itself does it in its FileDeletionAspect event listener.
     */
    public function delete(ProcessedFile $processedFile, int $processedFileSize): void
    {
        $uid = $processedFile->getUid();

        // A size of 0 means the file is either missing or really empty, so the filesystem has the final say
        if ($processedFileSize === 0 && !$this->physicalFileExists($processedFile)) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);

            $this->logger->info(
                'Deleted processed file record with uid {uid}. The physical file did not exist.',
                ['uid' => $uid]
            );

            return;
        }

        $processedFile->getStorage()->setEvaluatePermissions(false);
        $processedFile->delete(true);

        $this->logger->info('Deleted processed file with uid {uid}', ['uid' => $uid]);
    }

    private function physicalFileExists(ProcessedFile $processedFile): bool
    {
        $absolutePath = $this->resolveAbsolutePath(
            $processedFile->getStorage()->getUid(),
            $processedFile->getIdentifier()
        );

        return $absolutePath !== null && is_file($absolutePath);
    }

    /**
     * Returns the absolute path of the given storage/identifier combination or null, if it can not be resolved.
     */
    private function resolveAbsolutePath(int $storageUid, string $identifier): ?string
    {
        $identifier = ltrim($identifier, '/');
        $basePath = $this->getStorageBasePath($storageUid);
        if ($identifier === '' || $basePath === null) {
            return null;
        }

        return $basePath . '/' . $identifier;
    }

    /**
     * Returns the absolute base path of the given storage. Results are cached, since usually a lot of
     * processed files share the same storage.
     */
    private function getStorageBasePath(int $storageUid): ?string
    {
        if (!array_key_exists($storageUid, $this->storageBasePaths)) {
            $this->storageBasePaths[$storageUid] = $this->resolveStorageBasePath($storageUid);
        }

        return $this->storageBasePaths[$storageUid];
    }

    /**
     * Resolves the absolute base path of the given storage. Returns null, if the storage does not exist or
     * is not located on the local filesystem.
     */
    private function resolveStorageBasePath(int $storageUid): ?string
    {
        try {
            $storage = $this->storageRepository->findByUid($storageUid);
        } catch (\Throwable) {
            $storage = null;
        }

        $configuration = $storage?->getDriverType() === 'Local' ? $storage->getConfiguration() : [];
        $basePath = rtrim((string)($configuration['basePath'] ?? ''), '/');
        if ($basePath === '') {
            $this->logger->warning(
                'Could not resolve the base path of storage {storage}. File sizes of processed files in this storage are reported as 0.',
                ['storage' => $storageUid]
            );

            return null;
        }

        // Same rule as in the TYPO3 LocalDriver: the base path is only relative to the public path when explicitly configured
        if (($configuration['pathType'] ?? '') === 'relative') {
            $basePath = rtrim(Environment::getPublicPath(), '/') . '/' . ltrim($basePath, '/');
        }

        return $basePath;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configurationMatches(array $row, CleanupDemand $criteria): bool
    {
        $configuration = $this->unserializeConfiguration((string)($row['configuration'] ?? ''));
        if ($configuration === null) {
            return false;
        }

        return $this->configurationMatcher->matches($configuration, $criteria->configuration);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unserializeConfiguration(string $configuration): ?array
    {
        if ($configuration === '') {
            return null;
        }
        $data = @unserialize($configuration, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    private function createQuery(CleanupDemand $demand): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr = $queryBuilder->expr();

        $queryBuilder
            ->select('p.*')
            ->from(self::TABLE, 'p')
            ->orderBy('p.uid');

        $queryBuilder->join(
            'p',
            'sys_file',
            'f',
            $expr->eq('f.uid', $queryBuilder->quoteIdentifier('p.original'))
        );

        $conditions = [
            // Only process files whose original is an image
            $expr->eq(
                'f.type',
                $queryBuilder->createNamedParameter(self::FILE_TYPE_IMAGE, Connection::PARAM_INT)
            ),
            // Never touch "uses original file" placeholder rows. When TYPO3 decides no processing is
            // needed it stores a row with an empty identifier (and no name) to cache that decision;
            // no physical file exists, so there is nothing to clean up.
            $expr->isNotNull('p.identifier'),
            $expr->neq('p.identifier', $queryBuilder->createNamedParameter('')),
        ];

        if ($demand->storage !== null) {
            $conditions[] = $expr->eq(
                'f.storage',
                $queryBuilder->createNamedParameter($demand->storage, Connection::PARAM_INT)
            );
        }

        if ($demand->folder !== null) {
            $conditions[] = $expr->like(
                'f.identifier',
                $queryBuilder->createNamedParameter(
                    $queryBuilder->escapeLikeWildcards($this->normalizeFolder($demand->folder)) . '%'
                )
            );
        }

        if ($demand->identifiers !== []) {
            $identifiers = array_map($this->normalizeIdentifier(...), $demand->identifiers);
            $conditions[] = $expr->in(
                'f.identifier',
                $queryBuilder->createNamedParameter($identifiers, ArrayParameterType::STRING)
            );
        }

        if ($demand->fileUids !== []) {
            $conditions[] = $expr->in(
                'p.original',
                $queryBuilder->createNamedParameter($demand->fileUids, ArrayParameterType::INTEGER)
            );
        }

        $queryBuilder->where(...$conditions);

        return $queryBuilder;
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim(trim($folder), '/');

        return $folder === '' ? '/' : '/' . $folder . '/';
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return '/' . ltrim(trim($identifier), '/');
    }
}
