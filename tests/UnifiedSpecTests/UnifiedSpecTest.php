<?php

namespace MongoDB\Tests\UnifiedSpecTests;

use MongoDB\Client;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Operation\DatabaseCommand;
use MongoDB\Tests\FunctionalTestCase;
use stdClass;
use Symfony\Bridge\PhpUnit\SetUpTearDownTrait;
use Throwable;
use function assertTrue;
use function file_get_contents;
use function glob;
use function MongoDB\BSON\fromJSON;
use function MongoDB\BSON\toPHP;
use function sprintf;
use function version_compare;

/**
 * Unified spec test runner.
 *
 * @see https://github.com/mongodb/specifications/pull/846
 */
class UnifiedSpecTest extends FunctionalTestCase
{
    use SetUpTearDownTrait;

    const SERVER_ERROR_INTERRUPTED = 11601;

    const TOPOLOGY_SINGLE = 'single';
    const TOPOLOGY_REPLICASET = 'replicaset';
    const TOPOLOGY_SHARDED = 'sharded';
    const TOPOLOGY_SHARDED_REPLICASET = 'sharded-replicaset';

    /** @var MongoDB\Client */
    private static $internalClient;

    /** @var FailPointObserver */
    private $failPointObserver;

    private static function doSetUpBeforeClass()
    {
        parent::setUpBeforeClass();

        /* Provide internal client unmodified URI, since it may need to execute
         * commands on multiple mongoses (e.g. killAllSessions) */
        self::$internalClient = new Client(static::getUri(true));
        self::killAllSessions();
    }

    private function doSetUp()
    {
        parent::setUp();

        /* TODO: The transactions spec advises calling killAllSessions only at
         * the start of the test suite and after failed tests; however, the
         * "unpin after transient error within a transaction" pinning test
         * causes the subsequent transaction test to block. This can be removed
         * once that is addressed. */
        self::killAllSessions();

        $this->failPointObserver = new FailPointObserver();
        $this->failPointObserver->start();
    }

    private function doTearDown()
    {
        if ($this->hasFailed()) {
            self::killAllSessions();
        }

        $this->failPointObserver->stop();
        $this->failPointObserver->disableFailPoints();

        /* TODO: Consider manually invoking gc_collect_cycles since each test is
         * prone to create cycles (perhaps due to EntityMap), which can leak and
         * prevent sessions from being released back into the pool. */

        parent::tearDown();
    }

    /**
     * @dataProvider providePassingTests
     */
    public function testPassingTests(stdClass $test, string $schemaVersion, array $runOnRequirements = null, array $createEntities = null, array $initialData = null)
    {
        if (! $this->isSchemaVersionSupported($schemaVersion)) {
            $this->markTestIncomplete(sprintf('Test format schema version "%s" is not supported', $schemaVersion));
        }

        if (isset($runOnRequirements)) {
            $this->checkRunOnRequirements($runOnRequirements);
        }

        if (isset($test->skipReason)) {
            $this->assertInternalType('string', $test->skipReason);
            $this->markTestSkipped($test->skipReason);
        }

        if (isset($test->runOnRequirements)) {
            $this->assertInternalType('array', $test->runOnRequirements);
            $this->checkRunOnRequirements($test->runOnRequirements);
        }

        if (isset($initialData)) {
            $this->prepareInitialData($initialData);
        }

        // Give Context unmodified URI so it can enforce useMultipleMongoses
        $context = new Context(self::$internalClient, static::getUri(true));

        if (isset($createEntities)) {
            $context->createEntities($createEntities);
        }

        // TODO handle distinct commands in sharded transactions

        $context->startEventObservers();

        $this->assertInternalType('array', $test->operations);

        foreach ($test->operations as $o) {
            $operation = new Operation($o, $context);
            $operation->assert();
        }

        $context->stopEventObservers();

        if (isset($test->expectEvents)) {
            $this->assertInternalType('array', $test->expectEvents);
            $context->assertExpectedEventsForClients($test->expectEvents);
        }

        if (isset($test->outcome)) {
            $this->assertInternalType('array', $test->outcome);
            $this->assertOutcome($test->outcome);
        }
    }

    public function providePassingTests()
    {
        return $this->provideTests('/home/jmikola/workspace/mongodb/specifications/source/unified-test-format/tests/valid-pass');
    }

    /**
     * @dataProvider provideFailingTests
     */
    public function testFailingTests(...$args)
    {
        // Cannot use expectException(), as it ignores PHPUnit Exceptions
        $failed = false;

        try {
            $this->testCase(...$args);
        } catch (Throwable $e) {
            $failed = true;
        }

        assertTrue($failed, 'Expected test to throw an exception');
    }

    public function provideFailingTests()
    {
        return $this->provideTests('/home/jmikola/workspace/mongodb/specifications/source/unified-test-format/tests/valid-fail');
    }

    private function provideTests(string $dir)
    {
        $testArgs = [];

        foreach (glob($dir . '/*.json') as $filename) {
            /* Decode the file through the driver's extended JSON parser to
             * ensure proper handling of special types. */
            $json = toPHP(fromJSON(file_get_contents($filename)));

            $description = $json->description;
            $schemaVersion = $json->schemaVersion;
            $runOnRequirements = $json->runOnRequirements ?? null;
            $createEntities = $json->createEntities ?? null;
            $initialData = $json->initialData ?? null;
            $tests = $json->tests;

            /* Assertions in data providers do not count towards test assertions
             * but failures will interrupt the test suite with a warning. */
            $message = 'Invalid test file: ' . $filename;
            $this->assertInternalType('string', $description, $message);
            $this->assertInternalType('string', $schemaVersion, $message);
            $this->assertInternalType('array', $tests, $message);

            foreach ($json->tests as $test) {
                $this->assertInternalType('object', $test, $message);
                $this->assertInternalType('string', $test->description, $message);

                $name = $description . ': ' . $test->description;
                $testArgs[$name] = [$test, $schemaVersion, $runOnRequirements, $createEntities, $initialData];
            }
        }

        return $testArgs;
    }

    /**
     * Checks server version and topology requirements.
     *
     * @param array $runOnRequirements
     * @throws SkippedTest unless one or more runOnRequirements are met
     */
    private function checkRunOnRequirements(array $runOnRequirements)
    {
        $this->assertNotEmpty($runOnRequirements);
        $this->assertContainsOnly('object', $runOnRequirements);

        $serverVersion = $this->getCachedServerVersion();
        $topology = $this->getCachedTopology();

        foreach ($runOnRequirements as $o) {
            $runOnRequirement = new RunOnRequirement($o);
            if ($runOnRequirement->isSatisfied($serverVersion, $topology)) {
                return;
            }
        }

        $this->markTestSkipped(sprintf('Server version "%s" and topology "%s" do not meet test requirements', $serverVersion, $topology));
    }

    /**
     * Return the server version (cached for subsequent calls).
     *
     * @return string
     */
    private function getCachedServerVersion()
    {
        static $cachedServerVersion;

        if (isset($cachedServerVersion)) {
            return $cachedServerVersion;
        }

        $cachedServerVersion = $this->getServerVersion();

        return $cachedServerVersion;
    }

    /**
     * Return the topology type (cached for subsequent calls).
     *
     * @return string
     * @throws UnexpectedValueException if topology is neither single nor RS nor sharded
     */
    private function getCachedTopology()
    {
        static $cachedTopology = null;

        if (isset($cachedTopology)) {
            return $cachedTopology;
        }

        switch ($this->getPrimaryServer()->getType()) {
            case Server::TYPE_STANDALONE:
                $cachedTopology = RunOnRequirement::TOPOLOGY_SINGLE;
                break;

            case Server::TYPE_RS_PRIMARY:
                $cachedTopology = RunOnRequirement::TOPOLOGY_REPLICASET;
                break;

            case Server::TYPE_MONGOS:
                $cachedTopology = $this->isShardedClusterUsingReplicasets()
                    ? RunOnRequirement::TOPOLOGY_SHARDED_REPLICASET
                    : RunOnRequirement::TOPOLOGY_SHARDED;
                break;

            default:
                throw new UnexpectedValueException('Toplogy is neither single nor RS nor sharded');
        }

        return $cachedTopology;
    }

    /**
     * Checks is a test format schema version is supported.
     *
     * @param string $schemaVersion
     * @return boolean
     */
    private function isSchemaVersionSupported($schemaVersion)
    {
        return version_compare($schemaVersion, '1.0', '>=') && version_compare($schemaVersion, '1.1', '<');
    }

    /**
     * Kill all sessions on the cluster.
     *
     * This will clean up any open transactions that may remain from a
     * previously failed test. For sharded clusters, this command will be run
     * on all mongos nodes.
     */
    private static function killAllSessions()
    {
        $manager = self::$internalClient->getManager();
        $primary = $manager->selectServer(new ReadPreference(ReadPreference::PRIMARY));
        $servers = $primary->getType() === Server::TYPE_MONGOS ? $manager->getServers() : [$primary];

        foreach ($servers as $server) {
            try {
                // Skip servers that do not support sessions
                if (! isset($server->getInfo()['logicalSessionTimeoutMinutes'])) {
                    continue;
                }

                $command = new DatabaseCommand('admin', ['killAllSessions' => []]);
                $command->execute($server);
            } catch (ServerException $e) {
                // Interrupted error is safe to ignore (see: SERVER-38335)
                if ($e->getCode() != self::SERVER_ERROR_INTERRUPTED) {
                    throw $e;
                }
            }
        }
    }

    private function assertOutcome(array $outcome)
    {
        $this->assertNotEmpty($outcome);
        $this->assertContainsOnly('object', $outcome);

        foreach ($outcome as $data) {
            $collectionData = new CollectionData($data);
            $collectionData->assertOutcome(self::$internalClient);
        }
    }

    private function prepareInitialData(array $initialData)
    {
        $this->assertNotEmpty($initialData);
        $this->assertContainsOnly('object', $initialData);

        foreach ($initialData as $data) {
            $collectionData = new CollectionData($data);
            $collectionData->prepareInitialData(self::$internalClient);
        }
    }
}
