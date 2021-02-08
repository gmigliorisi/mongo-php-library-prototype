<?php

namespace MongoDB\Tests\SpecTests;

use Closure;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\ClientEncryption;
use MongoDB\Driver\Exception\AuthenticationException;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Exception\EncryptionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\WriteConcern;
use MongoDB\Operation\CreateCollection;
use MongoDB\Tests\CommandObserver;
use PHPUnit\Framework\SkippedTestError;
use stdClass;
use Symfony\Bridge\PhpUnit\SetUpTearDownTrait;
use Throwable;
use UnexpectedValueException;
use function base64_decode;
use function basename;
use function file_get_contents;
use function glob;
use function iterator_to_array;
use function json_decode;
use function sprintf;
use function str_repeat;
use function strlen;
use function unserialize;

/**
 * Client-side encryption spec tests.
 *
 * @see https://github.com/mongodb/specifications/tree/master/source/client-side-encryption
 */
class ClientSideEncryptionSpecTest extends FunctionalTestCase
{
    use SetUpTearDownTrait;

    const LOCAL_MASTERKEY = 'Mng0NCt4ZHVUYUJCa1kxNkVyNUR1QURhZ2h2UzR2d2RrZzh0cFBwM3R6NmdWMDFBMUN3YkQ5aXRRMkhGRGdQV09wOGVNYUMxT2k3NjZKelhaQmRCZGJkTXVyZG9uSjFk';

    private function doSetUp()
    {
        parent::setUp();

        $this->skipIfClientSideEncryptionIsNotSupported();
    }

    /**
     * Assert that the expected and actual command documents match.
     *
     * @param stdClass $expected Expected command document
     * @param stdClass $actual   Actual command document
     */
    public static function assertCommandMatches(stdClass $expected, stdClass $actual)
    {
        static::assertDocumentsMatch($expected, $actual);
    }

    /**
     * Execute an individual test case from the specification.
     *
     * @dataProvider provideTests
     * @param stdClass    $test           Individual "tests[]" document
     * @param array       $runOn          Top-level "runOn" array with server requirements
     * @param array       $data           Top-level "data" array to initialize collection
     * @param array|null  $keyVaultData   Top-level "key_vault_data" array to initialize keyvault.datakeys collection
     * @param object|null $jsonSchema     Top-level "json_schema" array to initialize collection
     * @param string      $databaseName   Name of database under test
     * @param string      $collectionName Name of collection under test
     */
    public function testClientSideEncryption(stdClass $test, array $runOn = null, array $data, array $keyVaultData = null, $jsonSchema = null, $databaseName = null, $collectionName = null)
    {
        if (isset($runOn)) {
            $this->checkServerRequirements($runOn);
        }

        if (isset($test->skipReason)) {
            $this->markTestSkipped($test->skipReason);
        }

        $databaseName = $databaseName ?? $this->getDatabaseName();
        $collectionName = $collectionName ?? $this->getCollectionName();

        try {
            $context = Context::fromClientSideEncryption($test, $databaseName, $collectionName);
        } catch (SkippedTestError $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->setContext($context);

        $this->insertKeyVaultData($keyVaultData);
        $this->dropTestAndOutcomeCollections();
        $this->createTestCollection($jsonSchema);
        $this->insertDataFixtures($data);

        if (isset($test->failPoint)) {
            $this->configureFailPoint($test->failPoint);
        }

        $context->enableEncryption();

        if (isset($test->expectations)) {
            $commandExpectations = CommandExpectations::fromClientSideEncryption($test->expectations);
            $commandExpectations->startMonitoring();
        }

        foreach ($test->operations as $operation) {
            Operation::fromClientSideEncryption($operation)->assert($this, $context);
        }

        if (isset($commandExpectations)) {
            $commandExpectations->stopMonitoring();
            $commandExpectations->assert($this, $context);
        }

        $context->disableEncryption();

        if (isset($test->outcome->collection->data)) {
            $this->assertOutcomeCollectionData($test->outcome->collection->data, ResultExpectation::ASSERT_DOCUMENTS_MATCH);
        }
    }

    public function provideTests()
    {
        $testArgs = [];

        foreach (glob(__DIR__ . '/client-side-encryption/tests/*.json') as $filename) {
            $group = basename($filename, '.json');

            try {
                $json = $this->decodeJson(file_get_contents($filename));
            } catch (Throwable $e) {
                $testArgs[$group] = [
                    (object) ['skipReason' => sprintf('Exception loading file "%s": %s', $filename, $e->getMessage())],
                    null,
                    [],
                ];

                continue;
            }

            $runOn = $json->runOn ?? null;
            $data = $json->data ?? [];
            $keyVaultData = $json->key_vault_data ?? null;
            $jsonSchema = $json->json_schema ?? null;
            $databaseName = $json->database_name ?? null;
            $collectionName = $json->collection_name ?? null;

            foreach ($json->tests as $test) {
                $name = $group . ': ' . $test->description;
                $testArgs[$name] = [$test, $runOn, $data, $keyVaultData, $jsonSchema, $databaseName, $collectionName];
            }
        }

        return $testArgs;
    }

    /**
     * Prose test: Data key and double encryption
     *
     * @dataProvider dataKeyProvider
     */
    public function testDataKeyAndDoubleEncryption(string $providerName, $masterKey)
    {
        $client = new Client(static::getUri());

        $client->selectCollection('keyvault', 'datakeys')->drop();
        $client->selectCollection('db', 'coll')->drop();

        $encryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'aws' => Context::getAWSCredentials(),
                'azure' => Context::getAzureCredentials(),
                'gcp' => Context::getGCPCredentials(),
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
        ];

        $autoEncryptionOpts = $encryptionOpts + [
            'schemaMap' => [
                'db.coll' => [
                    'bsonType' => 'object',
                    'properties' => [
                        'encrypted_placeholder' => [
                            'encrypt' => [
                                'keyId' => '/placeholder',
                                'bsonType' => 'string',
                                'algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_RANDOM,
                            ],
                        ],
                    ],
                ],
            ],
            'keyVaultClient' => $client,
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);
        $clientEncryption = $clientEncrypted->createClientEncryption($encryptionOpts);

        $commands = [];

        $dataKeyId = null;
        $keyAltName = $providerName . '_altname';

        (new CommandObserver())->observe(
            function () use ($clientEncryption, &$dataKeyId, $keyAltName, $providerName, $masterKey) {
                $keyData = ['keyAltNames' => [$keyAltName]];
                if ($masterKey !== null) {
                    $keyData['masterKey'] = $masterKey;
                }

                $dataKeyId = $clientEncryption->createDataKey($providerName, $keyData);
            },
            function ($command) use (&$commands) {
                $commands[] = $command;
            }
        );

        $this->assertInstanceOf(Binary::class, $dataKeyId);
        $this->assertSame(Binary::TYPE_UUID, $dataKeyId->getType());

        $this->assertCount(2, $commands);
        $insert = $commands[1]['started'];
        $this->assertSame('insert', $insert->getCommandName());
        $this->assertSame(WriteConcern::MAJORITY, $insert->getCommand()->writeConcern->w);

        $keys = $client->selectCollection('keyvault', 'datakeys')->find(['_id' => $dataKeyId]);
        $keys = iterator_to_array($keys);
        $this->assertCount(1, $keys);

        $key = $keys[0];
        $this->assertNotNull($key);
        $this->assertSame($providerName, $key['masterKey']['provider']);

        $encrypted = $clientEncryption->encrypt('hello ' . $providerName, ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $dataKeyId]);
        $this->assertInstanceOf(Binary::class, $encrypted);
        $this->assertSame(Binary::TYPE_ENCRYPTED, $encrypted->getType());

        $clientEncrypted->selectCollection('db', 'coll')->insertOne(['_id' => 'local', 'value' => $encrypted]);
        $hello = $clientEncrypted->selectCollection('db', 'coll')->findOne(['_id' => 'local']);
        $this->assertNotNull($hello);
        $this->assertSame('hello ' . $providerName, $hello['value']);

        $encryptedAltName = $clientEncryption->encrypt('hello ' . $providerName, ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyAltName' => $keyAltName]);
        $this->assertEquals($encrypted, $encryptedAltName);

        $this->expectException(BulkWriteException::class);
        $clientEncrypted->selectCollection('db', 'coll')->insertOne(['encrypted_placeholder' => $encrypted]);
    }

    public static function dataKeyProvider()
    {
        return [
            'local' => [
                'providerName' => 'local',
                'masterKey' => null,
            ],
            'aws' => [
                'providerName' => 'aws',
                'masterKey' => [
                    'region' => 'us-east-1',
                    'key' => 'arn:aws:kms:us-east-1:579766882180:key/89fcc2c4-08b0-4bd9-9f25-e30687b580d0',
                ],
            ],
            'azure' => [
                'providerName' => 'azure',
                'masterKey' => [
                    'keyVaultEndpoint' => 'key-vault-csfle.vault.azure.net',
                    'keyName' => 'key-name-csfle',
                ],
            ],
            'gcp' => [
                'providerName' => 'gcp',
                'masterKey' => [
                    'projectId' => 'devprod-drivers',
                    'location' => 'global',
                    'keyRing' => 'key-ring-csfle',
                    'keyName' => 'key-name-csfle',
                ],
            ],
        ];
    }

    /**
     * Prose test: External Key Vault
     *
     * @testWith [false]
     *           [true]
     */
    public function testExternalKeyVault($withExternalKeyVault)
    {
        $client = new Client(static::getUri());

        $client->selectCollection('keyvault', 'datakeys')->drop();
        $client->selectCollection('db', 'coll')->drop();

        $keyId = $client
            ->selectCollection('keyvault', 'datakeys')
            ->insertOne(
                $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/external/external-key.json')),
                ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)]
            )
            ->getInsertedId();

        $encryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
        ];

        if ($withExternalKeyVault) {
            $encryptionOpts['keyVaultClient'] = new Client(static::getUri(), ['username' => 'fake-user', 'password' => 'fake-pwd']);
        }

        $autoEncryptionOpts = $encryptionOpts + [
            'schemaMap' => [
                'db.coll' => $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/external/external-schema.json')),
            ],
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);
        $clientEncryption = $clientEncrypted->createClientEncryption($encryptionOpts);

        try {
            $result = $clientEncrypted->selectCollection('db', 'coll')->insertOne(['encrypted' => 'test']);

            if ($withExternalKeyVault) {
                $this->fail('Expected exception to be thrown');
            } else {
                $this->assertSame(1, $result->getInsertedCount());
            }
        } catch (BulkWriteException $e) {
            if (! $withExternalKeyVault) {
                throw $e;
            }

            $this->assertInstanceOf(AuthenticationException::class, $e->getPrevious());
        }

        if ($withExternalKeyVault) {
            $this->expectException(AuthenticationException::class);
        }

        $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
    }

    public static function provideBSONSizeLimitsAndBatchSplittingTests()
    {
        yield [static function (self $test, Collection $collection) {
            // Test 1
            $collection->insertOne(['_id' => 'over_2mib_under_16mib', 'unencrypted' => str_repeat('a', 2097152)]);
            $test->assertCollectionCount($collection->getNamespace(), 1);
        },
        ];

        yield [static function (self $test, Collection $collection, array $document) {
            // Test 2
            $collection->insertOne(
                ['_id' => 'encryption_exceeds_2mib', 'unencrypted' => str_repeat('a', 2097152 - 2000)] + $document
            );
            $test->assertCollectionCount($collection->getNamespace(), 1);
        },
        ];

        yield [static function (self $test, Collection $collection) {
            // Test 3
            $commands = [];
            (new CommandObserver())->observe(
                function () use ($collection) {
                    $collection->insertMany([
                        ['_id' => 'over_2mib_1', 'unencrypted' => str_repeat('a', 2097152)],
                        ['_id' => 'over_2mib_2', 'unencrypted' => str_repeat('a', 2097152)],
                    ]);
                },
                function ($command) use (&$commands) {
                    $commands[] = $command;
                }
            );

            $test->assertCount(2, $commands);
            foreach ($commands as $command) {
                $test->assertSame('insert', $command['started']->getCommandName());
            }
        },
        ];

        yield [static function (self $test, Collection $collection, array $document) {
            // Test 4
            $commands = [];
            (new CommandObserver())->observe(
                function () use ($collection, $document) {
                    $collection->insertMany([
                        [
                            '_id' => 'encryption_exceeds_2mib_1',
                            'unencrypted' => str_repeat('a', 2097152 - 2000),
                        ] + $document,
                        [
                            '_id' => 'encryption_exceeds_2mib_2',
                            'unencrypted' => str_repeat('a', 2097152 - 2000),
                        ] + $document,
                    ]);
                },
                function ($command) use (&$commands) {
                    $commands[] = $command;
                }
            );

            $test->assertCount(2, $commands);
            foreach ($commands as $command) {
                $test->assertSame('insert', $command['started']->getCommandName());
            }
        },
        ];

        yield [static function (self $test, Collection $collection) {
            // Test 5
            $collection->insertOne(['_id' => 'under_16mib', 'unencrypted' => str_repeat('a', 16777216 - 2000)]);
            $test->assertCollectionCount($collection->getNamespace(), 1);
        },
        ];

        yield [static function (self $test, Collection $collection, array $document) {
            // Test 6
            $test->expectException(BulkWriteException::class);
            $test->expectExceptionMessageMatches('#object to insert too large#');
            $collection->insertOne(['_id' => 'encryption_exceeds_16mib', 'unencrypted' => str_repeat('a', 16777216 - 2000)] + $document);
        },
        ];
    }

    /**
     * Prose test: BSON size limits and batch splitting
     *
     * @dataProvider provideBSONSizeLimitsAndBatchSplittingTests
     */
    public function testBSONSizeLimitsAndBatchSplitting(Closure $test)
    {
        $client = new Client(static::getUri());

        $client->selectCollection('keyvault', 'datakeys')->drop();
        $client->selectCollection('db', 'coll')->drop();

        $client->selectDatabase('db')->createCollection('coll', ['validator' => ['$jsonSchema' => $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/limits/limits-schema.json'))]]);
        $client->selectCollection('keyvault', 'datakeys')->insertOne($this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/limits/limits-key.json')));

        $autoEncryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
            'keyVaultClient' => $client,
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);

        $collection = $clientEncrypted->selectCollection('db', 'coll');

        $document = json_decode(file_get_contents(__DIR__ . '/client-side-encryption/limits/limits-doc.json'), true);

        $test($this, $collection, $document);
    }

    /**
     * Prose test: Views are prohibited
     */
    public function testViewsAreProhibited()
    {
        $client = new Client(static::getUri());

        $client->selectCollection('db', 'view')->drop();
        $client->selectDatabase('db')->command(['create' => 'view', 'viewOn' => 'coll']);

        $autoEncryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);

        try {
            $clientEncrypted->selectCollection('db', 'view')->insertOne(['foo' => 'bar']);
            $this->fail('Expected exception to be thrown');
        } catch (BulkWriteException $e) {
            $previous = $e->getPrevious();

            $this->assertInstanceOf(EncryptionException::class, $previous);
            $this->assertSame('cannot auto encrypt a view', $previous->getMessage());
        }
    }

    /**
     * Prose test: BSON Corpus
     *
     * @testWith [true]
     *           [false]
     */
    public function testCorpus($schemaMap = true)
    {
        $client = new Client(static::getUri());

        $client->selectDatabase('db')->dropCollection('coll');

        $schema = $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-schema.json'));

        if (! $schemaMap) {
            $client
                ->selectDatabase('db')
                ->createCollection('coll', ['validator' => ['$jsonSchema' => $schema]]);
        }

        $client->selectDatabase('keyvault')->dropCollection('datakeys');
        $client->selectCollection('keyvault', 'datakeys')->insertMany([
            $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-key-local.json')),
            $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-key-aws.json')),
            $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-key-azure.json')),
            $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-key-gcp.json')),
        ]);

        $encryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'aws' => Context::getAWSCredentials(),
                'azure' => Context::getAzureCredentials(),
                'gcp' => Context::getGCPCredentials(),
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
        ];

        $autoEncryptionOpts = $encryptionOpts;

        if ($schemaMap) {
            $autoEncryptionOpts += [
                'schemaMap' => ['db.coll' => $schema],
            ];
        }

        $corpus = (array) $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus.json'));
        $corpusCopied = [];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);
        $clientEncryption = $clientEncrypted->createClientEncryption($encryptionOpts);

        $collection = $clientEncrypted->selectCollection('db', 'coll');

        foreach ($corpus as $fieldName => $data) {
            switch ($fieldName) {
                case '_id':
                case 'altname_aws':
                case 'altname_azure':
                case 'altname_gcp':
                case 'altname_local':
                    $corpusCopied[$fieldName] = $data;
                    break;

                default:
                    $corpusCopied[$fieldName] = $this->prepareCorpusData($fieldName, $data, $clientEncryption);
            }
        }

        $collection->insertOne($corpusCopied);
        $corpusDecrypted = $collection->findOne(['_id' => 'client_side_encryption_corpus']);

        $this->assertDocumentsMatch($corpus, $corpusDecrypted);

        $corpusEncryptedExpected = (array) $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/corpus/corpus-encrypted.json'));
        $corpusEncryptedActual = $client->selectCollection('db', 'coll')->findOne(['_id' => 'client_side_encryption_corpus'], ['typeMap' => ['root' => 'array', 'document' => stdClass::class, 'array' => 'array']]);

        foreach ($corpusEncryptedExpected as $fieldName => $expectedData) {
            switch ($fieldName) {
                case '_id':
                case 'altname_aws':
                case 'altname_azure':
                case 'altname_gcp':
                case 'altname_local':
                    continue 2;
            }

            $actualData = $corpusEncryptedActual[$fieldName];

            if ($expectedData->algo === 'det') {
                $this->assertEquals($expectedData->value, $actualData->value, 'Value for field ' . $fieldName . ' does not match expected value.');
            }

            if ($expectedData->allowed) {
                if ($expectedData->algo === 'rand') {
                    $this->assertNotEquals($expectedData->value, $actualData->value, 'Value for field ' . $fieldName . ' does not differ from expected value.');
                }

                $this->assertEquals(
                    $clientEncryption->decrypt($expectedData->value),
                    $clientEncryption->decrypt($actualData->value),
                    'Decrypted value for field ' . $fieldName . ' does not match.'
                );
            } else {
                $this->assertEquals($corpus[$fieldName]->value, $actualData->value, 'Value for field ' . $fieldName . ' does not match original value.');
            }
        }
    }

    /**
     * Prose test: Custom Endpoint
     *
     * @dataProvider customEndpointProvider
     */
    public function testCustomEndpoint(Closure $test)
    {
        $client = new Client(static::getUri());

        $clientEncryption = $client->createClientEncryption([
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'aws' => Context::getAWSCredentials(),
                'azure' => Context::getAzureCredentials() + ['identityPlatformEndpoint' => 'login.microsoftonline.com:443'],
                'gcp' => Context::getGCPCredentials() + ['endpoint' => 'oauth2.googleapis.com:443'],
            ],
        ]);

        $clientEncryptionInvalid = $client->createClientEncryption([
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'azure' => Context::getAzureCredentials() + ['identityPlatformEndpoint' => 'example.com:443'],
                'gcp' => Context::getGCPCredentials() + ['endpoint' => 'example.com:443'],
            ],
        ]);

        $test($this, $clientEncryption, $clientEncryptionInvalid);
    }

    public static function customEndpointProvider()
    {
        $awsMasterKey = ['region' => 'us-east-1', 'key' => 'arn:aws:kms:us-east-1:579766882180:key/89fcc2c4-08b0-4bd9-9f25-e30687b580d0'];
        $azureMasterKey = ['keyVaultEndpoint' => 'key-vault-csfle.vault.azure.net', 'keyName' => 'key-name-csfle'];
        $gcpMasterKey = [
            'projectId' => 'devprod-drivers',
            'location' => 'global',
            'keyRing' => 'key-ring-csfle',
            'keyName' => 'key-name-csfle',
            'endpoint' => 'cloudkms.googleapis.com:443',
        ];

        return [
            'Test 1' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $keyId = $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey]);
                    $encrypted = $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
                    $test->assertSame('test', $clientEncryption->decrypt($encrypted));
                },
            ],
            'Test 2' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $keyId = $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey + ['endpoint' => 'kms.us-east-1.amazonaws.com']]);
                    $encrypted = $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
                    $test->assertSame('test', $clientEncryption->decrypt($encrypted));
                },
            ],
            'Test 3' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $keyId = $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey + [ 'endpoint' => 'kms.us-east-1.amazonaws.com:443']]);
                    $encrypted = $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
                    $test->assertSame('test', $clientEncryption->decrypt($encrypted));
                },
            ],
            'Test 4' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $test->expectException(ConnectionException::class);
                    $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey + ['endpoint' => 'kms.us-east-1.amazonaws.com:12345']]);
                },
            ],
            'Test 5' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $test->expectException(RuntimeException::class);
                    $test->expectExceptionMessageMatches('#us-east-1#');
                    $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey + ['endpoint' => 'kms.us-east-2.amazonaws.com']]);
                },
            ],
            'Test 6' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($awsMasterKey) {
                    $test->expectException(RuntimeException::class);
                    $test->expectExceptionMessageMatches('#parse error#');
                    $clientEncryption->createDataKey('aws', ['masterKey' => $awsMasterKey + ['endpoint' => 'example.com']]);
                },
            ],
            'Test 7' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($azureMasterKey) {
                    $keyId = $clientEncryption->createDataKey('azure', ['masterKey' => $azureMasterKey]);
                    $encrypted = $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
                    $test->assertSame('test', $clientEncryption->decrypt($encrypted));

                    $test->expectException(RuntimeException::class);
                    $test->expectExceptionMessageMatches('#parse error#');
                    $clientEncryptionInvalid->createDataKey('azure', ['masterKey' => $azureMasterKey]);
                },
            ],
            'Test 8' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($gcpMasterKey) {
                    $keyId = $clientEncryption->createDataKey('gcp', ['masterKey' => $gcpMasterKey]);
                    $encrypted = $clientEncryption->encrypt('test', ['algorithm' => ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC, 'keyId' => $keyId]);
                    $test->assertSame('test', $clientEncryption->decrypt($encrypted));

                    $test->expectException(RuntimeException::class);
                    $test->expectExceptionMessageMatches('#parse error#');
                    $clientEncryptionInvalid->createDataKey('gcp', ['masterKey' => $gcpMasterKey]);
                },
            ],
            'Test 9' => [
                static function (self $test, ClientEncryption $clientEncryption, ClientEncryption $clientEncryptionInvalid) use ($gcpMasterKey) {
                    $masterKey = $gcpMasterKey;
                    $masterKey['endpoint'] = 'example.com:443';

                    $test->expectException(RuntimeException::class);
                    $test->expectExceptionMessageMatches('#Invalid KMS response#');
                    $clientEncryption->createDataKey('gcp', ['masterKey' => $masterKey]);
                },
            ],
        ];
    }

    /**
     * Prose test: Bypass spawning mongocryptd (via mongocryptdBypassSpawn)
     */
    public function testBypassSpawningMongocryptdViaBypassSpawn()
    {
        $autoEncryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
            'schemaMap' => [
                'db.coll' => $this->decodeJson(file_get_contents(__DIR__ . '/client-side-encryption/external/external-schema.json')),
            ],
            'extraOptions' => [
                'mongocryptdBypassSpawn' => true,
                'mongocryptdURI' => 'mongodb://localhost:27021/db?serverSelectionTimeoutMS=1000',
                'mongocryptdSpawnArgs' => ['--pidfilepath=bypass-spawning-mongocryptd.pid', '--port=27021'],
            ],
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);

        try {
            $clientEncrypted->selectCollection('db', 'coll')->insertOne(['encrypted' => 'test']);
            $this->fail('Expected exception to be thrown');
        } catch (BulkWriteException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(ConnectionTimeoutException::class, $previous);

            $this->assertStringContainsString('mongocryptd error: No suitable servers found', $previous->getMessage());
        }
    }

    /**
     * Bypass spawning mongocryptd (via bypassAutoEncryption)
     */
    public function testBypassSpawningMongocryptdViaBypassAutoEncryption()
    {
        $autoEncryptionOpts = [
            'keyVaultNamespace' => 'keyvault.datakeys',
            'kmsProviders' => [
                'local' => ['key' => new Binary(base64_decode(self::LOCAL_MASTERKEY), 0)],
            ],
            'bypassAutoEncryption' => true,
            'extraOptions' => [
                'mongocryptdSpawnArgs' => ['--pidfilepath=bypass-spawning-mongocryptd.pid', '--port=27021'],
            ],
        ];

        $clientEncrypted = new Client(static::getUri(), [], ['autoEncryption' => $autoEncryptionOpts]);

        $clientEncrypted->selectCollection('db', 'coll')->insertOne(['encrypted' => 'test']);

        $clientMongocryptd = new Client('mongodb://localhost:27021');

        $this->expectException(ConnectionTimeoutException::class);
        $clientMongocryptd->selectDatabase('db')->command(['isMaster' => true]);
    }

    /**
     * Casts the value for a BSON corpus structure to int64 if necessary.
     *
     * This is a workaround for an issue in mongocryptd which refuses to encrypt
     * int32 values if the schemaMap defines a "long" bsonType for an object.
     *
     * @param object $data
     *
     * @return Int64|mixed
     */
    private function craftInt64($data)
    {
        if ($data->type !== 'long' || $data->value instanceof Int64) {
            return $data->value;
        }

        $class = Int64::class;

        $intAsString = sprintf((string) $data->value);
        $array = sprintf('a:1:{s:7:"integer";s:%d:"%s";}', strlen($intAsString), $intAsString);
        $int64 = sprintf('C:%d:"%s":%d:{%s}', strlen($class), $class, strlen($array), $array);

        return unserialize($int64);
    }

    private function createTestCollection($jsonSchema)
    {
        $options = empty($jsonSchema) ? [] : ['validator' => ['$jsonSchema' => $jsonSchema]];
        $operation = new CreateCollection($this->getContext()->databaseName, $this->getContext()->collectionName, $options);
        $operation->execute($this->getPrimaryServer());
    }

    private function encryptCorpusValue(string $fieldName, stdClass $data, ClientEncryption $clientEncryption)
    {
        $encryptionOptions = [
            'algorithm' => $data->algo === 'rand' ? ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_RANDOM : ClientEncryption::AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC,
        ];

        switch ($data->kms) {
            case 'local':
                $keyId = 'LOCALAAAAAAAAAAAAAAAAA==';
                $keyAltName = 'local';
                break;
            case 'aws':
                $keyId = 'AWSAAAAAAAAAAAAAAAAAAA==';
                $keyAltName = 'aws';
                break;
            case 'azure':
                $keyId = 'AZUREAAAAAAAAAAAAAAAAA==';
                $keyAltName = 'azure';
                break;
            case 'gcp':
                $keyId = 'GCPAAAAAAAAAAAAAAAAAAA==';
                $keyAltName = 'gcp';
                break;

            default:
                throw new UnexpectedValueException('Unexpected KMS "%s"', $data->kms);
        }

        switch ($data->identifier) {
            case 'id':
                $encryptionOptions['keyId'] = new Binary(base64_decode($keyId), 4);
                break;

            case 'altname':
                $encryptionOptions['keyAltName'] = $keyAltName;
                break;

            default:
                throw new UnexpectedValueException('Unexpected value "%s" for identifier', $data->identifier);
        }

        if ($data->allowed) {
            try {
                $encrypted = $clientEncryption->encrypt($this->craftInt64($data), $encryptionOptions);
            } catch (EncryptionException $e) {
                $this->fail('Could not encrypt value for field ' . $fieldName . ': ' . $e->getMessage());
            }

            $this->assertEquals($data->value, $clientEncryption->decrypt($encrypted));

            return $encrypted;
        }

        try {
            $clientEncryption->encrypt($data->value, $encryptionOptions);
            $this->fail('Expected exception to be thrown');
        } catch (RuntimeException $e) {
        }

        return $data->value;
    }

    private function insertKeyVaultData(array $keyVaultData = null)
    {
        if (empty($keyVaultData)) {
            return;
        }

        $context = $this->getContext();
        $collection = $context->selectCollection('keyvault', 'datakeys', ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)] + $context->defaultWriteOptions);
        $collection->drop();
        $collection->insertMany($keyVaultData);

        return;
    }

    private function prepareCorpusData(string $fieldName, stdClass $data, ClientEncryption $clientEncryption)
    {
        if ($data->method === 'auto') {
            $data->value = $this->craftInt64($data);

            return $data;
        }

        $returnData = clone $data;
        $returnData->value = $this->encryptCorpusValue($fieldName, $data, $clientEncryption);

        return $data->allowed ? $returnData : $data;
    }
}
