<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core;

use Doctrine\DBAL\Connection as DoctrineConnection;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\ConflictDropClosure;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Exception\MissingUniqueIndexForConcurrentRefresh;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\Lock\PrimaryConnectionGuard;
use Th3Mouk\MaterializedView\Core\Lock\StableLockKeyGenerator;
use Th3Mouk\MaterializedView\Core\Lock\ViewRefreshLock;
use Th3Mouk\MaterializedView\Core\Privilege\GrantStatementGenerator;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshotter;
use Th3Mouk\MaterializedView\Core\Rebuild\DropCreateRebuilder;
use Th3Mouk\MaterializedView\Core\Rebuild\RebuildContext;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\PostgreSqlMaterializedViewSqlGenerator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewSynchronizer;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;
use Th3Mouk\MaterializedView\Pdo\PdoConnection;
use Throwable;

final readonly class MaterializedViewManager
{
    public const int DEFAULT_REFRESH_LOCK_NAMESPACE = 392817;

    private function __construct(
        private Connection $connection,
        private PrimaryConnectionGuard $primaryConnectionGuard,
        private ExternalDependencyGuard $externalDependencyGuard,
        private PostgreSqlMaterializedViewSqlGenerator $sqlGenerator,
        private PostgreSqlMaterializedViewIntrospector $introspector,
        private ReadinessChecker $readinessChecker,
        private ViewRefreshLock $refreshLock,
        private DefinitionHasher $hasher,
        private MaterializedViewSynchronizer $synchronizer,
        private IdentifierQuoter $quoter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Framework-agnostic factory: wire the manager onto any {@see Connection}
     * adapter (Doctrine DBAL, bare PDO, or your own).
     */
    public static function forDriver(
        Connection $connection,
        ?LoggerInterface $logger = null,
        int $refreshLockNamespace = self::DEFAULT_REFRESH_LOCK_NAMESPACE,
    ): self {
        $logger ??= new NullLogger();

        $quoter = new IdentifierQuoter();
        $sqlGenerator = new PostgreSqlMaterializedViewSqlGenerator($quoter);
        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $hasher = DefinitionHasher::create();
        $dependencyResolver = new CatalogDependencyResolver($connection);
        $externalDependencyGuard = new ExternalDependencyGuard($dependencyResolver, $logger);
        $grantStatementGenerator = new GrantStatementGenerator($quoter);

        $synchronizer = new MaterializedViewSynchronizer(
            connection: $connection,
            comparator: new MaterializedViewComparator($introspector, $hasher),
            dependencyResolver: $dependencyResolver,
            externalDependencyGuard: $externalDependencyGuard,
            privilegeSnapshotter: new PrivilegeSnapshotter($connection, $logger),
            grantStatementGenerator: $grantStatementGenerator,
            sqlGenerator: $sqlGenerator,
            introspector: $introspector,
            hasher: $hasher,
            quoter: $quoter,
            logger: $logger,
        );

        return new self(
            connection: $connection,
            primaryConnectionGuard: new PrimaryConnectionGuard($connection),
            externalDependencyGuard: $externalDependencyGuard,
            sqlGenerator: $sqlGenerator,
            introspector: $introspector,
            readinessChecker: new ReadinessChecker($connection, $logger),
            refreshLock: new ViewRefreshLock($connection, new StableLockKeyGenerator($refreshLockNamespace), $logger),
            hasher: $hasher,
            synchronizer: $synchronizer,
            quoter: $quoter,
            logger: $logger,
        );
    }

    /**
     * Convenience factory for Doctrine DBAL users.
     *
     * Wraps the connection so the manager keeps DBAL's primary/replica routing,
     * middlewares and profiling. Requires `doctrine/dbal`; projects without
     * Doctrine should use {@see self::forPdo()} instead.
     */
    public static function forConnection(
        DoctrineConnection $connection,
        ?LoggerInterface $logger = null,
        int $refreshLockNamespace = self::DEFAULT_REFRESH_LOCK_NAMESPACE,
    ): self {
        return self::forDriver(new DbalConnection($connection), $logger, $refreshLockNamespace);
    }

    /**
     * Convenience factory for projects without Doctrine: pass a bare PDO handle
     * connected to PostgreSQL. Requires `ext-pdo_pgsql`.
     */
    public static function forPdo(
        PDO $pdo,
        ?LoggerInterface $logger = null,
        int $refreshLockNamespace = self::DEFAULT_REFRESH_LOCK_NAMESPACE,
    ): self {
        return self::forDriver(new PdoConnection($pdo), $logger, $refreshLockNamespace);
    }

    public function create(MaterializedViewDefinition $definition): void
    {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();

        $this->externalDependencyGuard->assertSafeToRebuild(
            $definition->name(),
            MaterializedViewRegistry::fromDefinitions([$definition]),
        );

        $this->logger->debug('Creating materialized view "{view}".', [
            'view' => $definition->name()->qualifiedName(),
            'strategy' => $definition->rebuildStrategy()->value,
        ]);

        $context = RebuildContext::create(
            managementComment: $this->managementComment($definition),
        );

        new DropCreateRebuilder($this->connection, $this->logger)->rebuild($definition, $context);

        $this->readinessChecker->forget($definition->name());

        $this->logger->info('Created materialized view "{view}".', [
            'view' => $definition->name()->qualifiedName(),
        ]);
    }

    public function drop(
        MaterializedViewName $name,
        bool $ifExists = true,
        DropDependentPolicy $dropDependentPolicy = DropDependentPolicy::Refuse,
    ): void {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();

        $this->externalDependencyGuard->assertSafeToDrop(
            $name,
            MaterializedViewRegistry::fromDefinitions([]),
            $dropDependentPolicy,
        );

        $cascade = DropDependentPolicy::Cascade === $dropDependentPolicy;

        $this->logger->debug('Dropping materialized view "{view}".', [
            'view' => $name->qualifiedName(),
            'cascade' => $cascade,
        ]);

        $this->connection->executeStatement($this->sqlGenerator->drop($name, $ifExists, $cascade));

        $this->readinessChecker->forget($name);

        $this->logger->info('Dropped materialized view "{view}".', ['view' => $name->qualifiedName()]);
    }

    /**
     * Reactively clear a migration DDL conflict by dropping ONLY the managed materialized
     * views that block it (and their managed dependents), in safe drop order. The blocked
     * relation is read from the parsed PostgreSQL error, but the drop closure is resolved
     * from the system catalog — never from the locale-dependent error text alone.
     *
     * Refuses (throws {@see UnmanagedDependentFound}) when the closure contains an unmanaged
     * dependent, unless the policy is Cascade. Returns the views dropped, in drop order; an
     * empty array means nothing was resolved (e.g. an unparsable 0A000) and the caller must
     * fall back. Opens no transaction of its own: the caller wraps the call so the surgical
     * drop and the migration retry share one unit of work.
     *
     * @return list<MaterializedViewName>
     */
    public function dropConflictClosure(
        PostgresDependencyConflict $conflict,
        DropDependentPolicy $dropDependentPolicy = DropDependentPolicy::Refuse,
    ): array {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();

        $closure = $this->resolveConflictClosure($conflict);

        if (DropDependentPolicy::Cascade !== $dropDependentPolicy && $closure->hasUnmanagedDependents()) {
            throw UnmanagedDependentFound::blockingConflictDrop($closure->unmanagedDependents);
        }

        $cascade = DropDependentPolicy::Cascade === $dropDependentPolicy;
        $dropped = [];

        foreach ($closure->managedDropOrder as $name) {
            $this->connection->executeStatement($this->sqlGenerator->drop($name, true, $cascade));
            $this->readinessChecker->forget($name);
            $dropped[] = $name;

            $this->logger->info('Reactively dropped materialized view "{view}" to clear a migration dependency conflict ({sql_state}).', [
                'view' => $name->qualifiedName(),
                'sql_state' => $conflict->sqlState(),
            ]);
        }

        return $dropped;
    }

    private function resolveConflictClosure(PostgresDependencyConflict $conflict): ConflictDropClosure
    {
        $resolver = new CatalogDependencyResolver($this->connection);

        // The DETAIL dependents are the *precise* blockers (the views actually using the
        // dropped column / altered type), so seed from each of them — including the seed
        // itself, which must be dropped — rather than from the blocked table, whose other
        // dependents may not block this DDL at all. Fall back to the table walk only when
        // the (locale-dependent) DETAIL could not be parsed.
        if ($conflict->isParsed()) {
            $closure = ConflictDropClosure::empty();

            foreach ($conflict->dependents() as $dependent) {
                $closure = $closure->merge($resolver->resolveConflictClosure($dependent->name, true));
            }

            return $closure;
        }

        $blockedRelation = $conflict->blockedRelation();

        if (null === $blockedRelation) {
            return ConflictDropClosure::empty();
        }

        return $resolver->resolveConflictClosure($blockedRelation);
    }

    public function refresh(MaterializedViewDefinition $definition, ?RefreshOptions $options = null): void
    {
        $options ??= RefreshOptions::default();
        $name = $definition->name();

        $this->primaryConnectionGuard->ensureConnectedToPrimary();
        $this->applyTimeouts($options);

        $this->refreshLock->acquire($name);

        try {
            if ($options->concurrently) {
                $this->assertConcurrentlySupported($definition);
            }

            $this->logger->debug('Refreshing materialized view "{view}".', [
                'view' => $name->qualifiedName(),
                'concurrently' => $options->concurrently,
                'with_data' => $options->withData,
            ]);

            $startedAtNanoseconds = hrtime(true);
            $this->connection->executeStatement($this->sqlGenerator->refresh($name, $options));

            if ($options->analyzeAfterRefresh && $options->withData) {
                $this->connection->executeStatement($this->analyzeStatement($name));
            }

            $this->readinessChecker->forget($name);

            $this->logger->info('Refreshed materialized view "{view}".', [
                'view' => $name->qualifiedName(),
                'concurrently' => $options->concurrently,
                'duration_ms' => intdiv(hrtime(true) - $startedAtNanoseconds, 1_000_000),
            ]);
        } finally {
            $this->refreshLock->release($name);
            $this->resetTimeouts($options);
        }
    }

    public function sync(MaterializedViewDefinition $definition, ?SyncOptions $options = null): SyncOutcome
    {
        return $this->syncAll(MaterializedViewRegistry::fromDefinitions([$definition]), $options);
    }

    public function syncAll(MaterializedViewRegistry $registry, ?SyncOptions $options = null): SyncOutcome
    {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();

        $outcome = $this->synchronizer->synchronize($registry, $options);

        $this->readinessChecker->forgetAll();

        return $outcome;
    }

    public function refreshAll(MaterializedViewRegistry $registry, ?RefreshOptions $options = null): void
    {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();

        $resolver = new CatalogDependencyResolver($this->connection);
        $order = $resolver->orderedForRefresh($registry);
        $total = \count($order);

        $this->logger->info('Materialized view refresh-all started.', ['total' => $total]);

        $refreshed = 0;
        foreach ($order as $qualifiedName) {
            try {
                $this->refresh($registry->get($qualifiedName), $options);
            } catch (Throwable $exception) {
                $this->logger->error(
                    'Materialized view refresh-all aborted at "{view}".',
                    [
                        'view' => $qualifiedName,
                        'refreshed' => $refreshed,
                        'remaining' => $total - $refreshed - 1,
                        'total' => $total,
                    ],
                );

                throw $exception;
            }

            ++$refreshed;
        }

        $this->logger->info('Materialized view refresh-all completed.', [
            'refreshed' => $refreshed,
            'total' => $total,
        ]);
    }

    private function assertConcurrentlySupported(MaterializedViewDefinition $definition): void
    {
        $name = $definition->name();

        if (!$this->introspector->exists($name)) {
            throw ViewNotPopulated::forConcurrentRefresh($name);
        }

        if (!$this->readinessChecker->isReady($name)) {
            throw ViewNotPopulated::forConcurrentRefresh($name);
        }

        if (!$this->hasFullUniqueIndex($definition)) {
            throw MissingUniqueIndexForConcurrentRefresh::forView($name);
        }
    }

    private function hasFullUniqueIndex(MaterializedViewDefinition $definition): bool
    {
        return array_any($definition->indexes(), fn ($index) => $index->coversAllRowsByColumnNamesOnly());
    }

    private function applyTimeouts(RefreshOptions $options): void
    {
        if (null !== $options->lockTimeout) {
            $this->connection->executeStatement(\sprintf(
                'SET lock_timeout = %s',
                $this->quoter->quoteStringLiteral($options->lockTimeout),
            ));
        }

        if (null !== $options->statementTimeout) {
            $this->connection->executeStatement(\sprintf(
                'SET statement_timeout = %s',
                $this->quoter->quoteStringLiteral($options->statementTimeout),
            ));
        }
    }

    private function resetTimeouts(RefreshOptions $options): void
    {
        if (null !== $options->lockTimeout) {
            $this->connection->executeStatement('SET lock_timeout = DEFAULT');
        }

        if (null !== $options->statementTimeout) {
            $this->connection->executeStatement('SET statement_timeout = DEFAULT');
        }
    }

    private function analyzeStatement(MaterializedViewName $name): string
    {
        return \sprintf(
            'ANALYZE %s.%s',
            $this->quoter->quoteIdentifier($name->schema),
            $this->quoter->quoteIdentifier($name->name),
        );
    }

    private function managementComment(MaterializedViewDefinition $definition): string
    {
        return ManagementMarker::create(
            hash: $this->hasher->hash($definition),
            version: DefinitionHasher::CANONICALIZATION_VERSION,
            source: $definition->hasSqlSource() ? $definition->sqlSource()->identifier() : null,
        )->toJson();
    }
}
