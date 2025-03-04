<?php

namespace MongoDB\Laravel\Tests\Scout;

use ArrayIterator;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Jobs\RemoveFromSearch;
use LogicException;
use Mockery as m;
use MongoDB\BSON\Document;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\CursorInterface;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Scout\ScoutEngine;
use MongoDB\Laravel\Tests\Scout\Models\ScoutUser;
use MongoDB\Laravel\Tests\Scout\Models\SearchableModel;
use MongoDB\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_replace_recursive;
use function count;
use function serialize;
use function unserialize;

/** Unit tests that do not require an Atlas Search cluster */
class ScoutEngineTest extends TestCase
{
    private const EXPECTED_TYPEMAP = ['root' => 'object', 'document' => 'bson', 'array' => 'bson'];

    public function testCreateIndexInvalidDefinition(): void
    {
        $database = m::mock(Database::class);
        $engine = new ScoutEngine($database, false, ['collection_invalid' => ['foo' => 'bar']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid search index definition for collection "collection_invalid", the "mappings" key is required.');
        $engine->createIndex('collection_invalid');
    }

    public function testCreateIndex(): void
    {
        $collectionName = 'collection_custom';
        $expectedDefinition = [
            'mappings' => [
                'dynamic' => true,
            ],
        ];

        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('createCollection')
            ->once()
            ->with($collectionName);
        $database->shouldReceive('selectCollection')
            ->with($collectionName)
            ->andReturn($collection);
        $collection->shouldReceive('createSearchIndex')
            ->once()
            ->with($expectedDefinition, ['name' => 'scout']);
        $collection->shouldReceive('listSearchIndexes')
            ->once()
            ->with(['name' => 'scout', 'typeMap' => ['root' => 'bson']])
            ->andReturn(new ArrayIterator([Document::fromPHP(['name' => 'scout', 'status' => 'READY'])]));

        $engine = new ScoutEngine($database, false, []);
        $engine->createIndex($collectionName);
    }

    public function testCreateIndexCustomDefinition(): void
    {
        $collectionName = 'collection_custom';
        $expectedDefinition = [
            'mappings' => [
                [
                    'analyzer' => 'lucene.standard',
                    'fields' => [
                        [
                            'name' => 'wildcard',
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
        ];

        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('createCollection')
            ->once()
            ->with($collectionName);
        $database->shouldReceive('selectCollection')
            ->with($collectionName)
            ->andReturn($collection);
        $collection->shouldReceive('createSearchIndex')
            ->once()
            ->with($expectedDefinition, ['name' => 'scout']);
        $collection->shouldReceive('listSearchIndexes')
            ->once()
            ->with(['name' => 'scout', 'typeMap' => ['root' => 'bson']])
            ->andReturn(new ArrayIterator([Document::fromPHP(['name' => 'scout', 'status' => 'READY'])]));

        $engine = new ScoutEngine($database, false, [$collectionName => $expectedDefinition]);
        $engine->createIndex($collectionName);
    }

    /** @param callable(): Builder $builder  */
    #[DataProvider('provideSearchPipelines')]
    public function testSearch(Closure $builder, array $expectedPipeline): void
    {
        $data = [['_id' => 'key_1', '__count' => 15], ['_id' => 'key_2', '__count' => 15]];
        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_searchable')
            ->andReturn($collection);
        $cursor = m::mock(CursorInterface::class);
        $cursor->shouldReceive('setTypeMap')->once()->with(self::EXPECTED_TYPEMAP);
        $cursor->shouldReceive('toArray')->once()->with()->andReturn($data);

        $collection->shouldReceive('getCollectionName')
            ->zeroOrMoreTimes()
            ->andReturn('collection_searchable');
        $collection->shouldReceive('aggregate')
            ->once()
            ->withArgs(function ($pipeline) use ($expectedPipeline) {
                self::assertEquals($expectedPipeline, $pipeline);

                return true;
            })
            ->andReturn($cursor);

        $engine = new ScoutEngine($database, softDelete: false);
        $result = $engine->search($builder());
        $this->assertEquals($data, $result);
    }

    public static function provideSearchPipelines(): iterable
    {
        $defaultPipeline = [
            [
                '$search' => [
                    'index' => 'scout',
                    'compound' => [
                        'should' => [
                            [
                                'text' => [
                                    'path' => ['wildcard' => '*'],
                                    'query' => 'lar',
                                    'fuzzy' => ['maxEdits' => 2],
                                    'score' => ['boost' => ['value' => 5]],
                                ],
                            ],
                            [
                                'wildcard' => [
                                    'query' => 'lar*',
                                    'path' => ['wildcard' => '*'],
                                    'allowAnalyzedField' => true,
                                ],
                            ],
                        ],
                        'minimumShouldMatch' => 1,
                    ],
                    'count' => [
                        'type' => 'lowerBound',
                    ],
                ],
            ],
            [
                '$addFields' => [
                    '__count' => '$$SEARCH_META.count.lowerBound',
                ],
            ],
        ];

        yield 'simple string' => [
            function () {
                return new Builder(new SearchableModel(), 'lar');
            },
            $defaultPipeline,
        ];

        yield 'where conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->where('foo', 'bar');
                $builder->where('key', 'value');

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['equals' => ['path' => 'foo', 'value' => 'bar']],
                                ['equals' => ['path' => 'key', 'value' => 'value']],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'where in conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->where('foo', 'bar');
                $builder->where('bar', 'baz');
                $builder->whereIn('qux', [1, 2]);
                $builder->whereIn('quux', [1, 2]);

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['equals' => ['path' => 'foo', 'value' => 'bar']],
                                ['equals' => ['path' => 'bar', 'value' => 'baz']],
                                ['in' => ['path' => 'qux', 'value' => [1, 2]]],
                                ['in' => ['path' => 'quux', 'value' => [1, 2]]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'where not in conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->where('foo', 'bar');
                $builder->where('bar', 'baz');
                $builder->whereIn('qux', [1, 2]);
                $builder->whereIn('quux', [1, 2]);
                $builder->whereNotIn('eaea', [3]);

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['equals' => ['path' => 'foo', 'value' => 'bar']],
                                ['equals' => ['path' => 'bar', 'value' => 'baz']],
                                ['in' => ['path' => 'qux', 'value' => [1, 2]]],
                                ['in' => ['path' => 'quux', 'value' => [1, 2]]],
                            ],
                            'mustNot' => [
                                ['in' => ['path' => 'eaea', 'value' => [3]]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'where in conditions without other conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->whereIn('qux', [1, 2]);
                $builder->whereIn('quux', [1, 2]);

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['in' => ['path' => 'qux', 'value' => [1, 2]]],
                                ['in' => ['path' => 'quux', 'value' => [1, 2]]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'where not in conditions without other conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->whereIn('qux', [1, 2]);
                $builder->whereIn('quux', [1, 2]);
                $builder->whereNotIn('eaea', [3]);

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['in' => ['path' => 'qux', 'value' => [1, 2]]],
                                ['in' => ['path' => 'quux', 'value' => [1, 2]]],
                            ],
                            'mustNot' => [
                                ['in' => ['path' => 'eaea', 'value' => [3]]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'empty where in conditions' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->whereIn('qux', [1, 2]);
                $builder->whereIn('quux', [1, 2]);
                $builder->whereNotIn('eaea', [3]);

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['in' => ['path' => 'qux', 'value' => [1, 2]]],
                                ['in' => ['path' => 'quux', 'value' => [1, 2]]],
                            ],
                            'mustNot' => [
                                ['in' => ['path' => 'eaea', 'value' => [3]]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'exclude soft-deleted' => [
            function () {
                return new Builder(new SearchableModel(), 'lar', softDelete: true);
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['equals' => ['path' => '__soft_deleted', 'value' => false]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'only trashed' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar', softDelete: true);
                $builder->onlyTrashed();

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'compound' => [
                            'filter' => [
                                ['equals' => ['path' => '__soft_deleted', 'value' => true]],
                            ],
                        ],
                    ],
                ],
            ]),
        ];

        yield 'with callback' => [
            fn () => new Builder(new SearchableModel(), 'query', callback: function (...$args) {
                self::assertCount(3, $args);
                self::assertInstanceOf(Collection::class, $args[0]);
                self::assertSame('collection_searchable', $args[0]->getCollectionName());
                self::assertSame('query', $args[1]);
                self::assertNull($args[2]);

                return $args[0]->aggregate(['pipeline']);
            }),
            ['pipeline'],
        ];

        yield 'ordered' => [
            function () {
                $builder = new Builder(new SearchableModel(), 'lar');
                $builder->orderBy('name', 'desc');
                $builder->orderBy('age', 'asc');

                return $builder;
            },
            array_replace_recursive($defaultPipeline, [
                [
                    '$search' => [
                        'sort' => [
                            'name' => -1,
                            'age' => 1,
                        ],
                    ],
                ],
            ]),
        ];
    }

    public function testPaginate()
    {
        $perPage = 5;
        $page = 3;

        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $cursor = m::mock(CursorInterface::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_searchable')
            ->andReturn($collection);
        $collection->shouldReceive('aggregate')
            ->once()
            ->withArgs(function (...$args) {
                self::assertSame([
                    [
                        '$search' => [
                            'index' => 'scout',
                            'compound' => [
                                'should' => [
                                    [
                                        'text' => [
                                            'query' => 'mustang',
                                            'path' => ['wildcard' => '*'],
                                            'fuzzy' => ['maxEdits' => 2],
                                            'score' => ['boost' => ['value' => 5]],
                                        ],
                                    ],
                                    [
                                        'wildcard' => [
                                            'query' => 'mustang*',
                                            'path' => ['wildcard' => '*'],
                                            'allowAnalyzedField' => true,
                                        ],
                                    ],
                                ],
                                'minimumShouldMatch' => 1,
                            ],
                            'count' => [
                                'type' => 'lowerBound',
                            ],
                            'sort' => [
                                'name' => -1,
                            ],
                        ],
                    ],
                    [
                        '$addFields' => [
                            '__count' => '$$SEARCH_META.count.lowerBound',
                        ],
                    ],
                    [
                        '$skip' => 10,
                    ],
                    [
                        '$limit' => 5,
                    ],
                ], $args[0]);

                return true;
            })
            ->andReturn($cursor);
        $cursor->shouldReceive('setTypeMap')->once()->with(self::EXPECTED_TYPEMAP);
        $cursor->shouldReceive('toArray')
            ->once()
            ->with()
            ->andReturn([['_id' => 'key_1', '__count' => 17], ['_id' => 'key_2', '__count' => 17]]);

        $engine = new ScoutEngine($database, softDelete: false);
        $builder = new Builder(new SearchableModel(), 'mustang');
        $builder->orderBy('name', 'desc');
        $engine->paginate($builder, $perPage, $page);
    }

    public function testMapMethodRespectsOrder()
    {
        $database = m::mock(Database::class);
        $engine = new ScoutEngine($database, false);

        $model = m::mock(Model::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->get')
            ->andReturn(LaravelCollection::make([
                new ScoutUser(['id' => 1]),
                new ScoutUser(['id' => 2]),
                new ScoutUser(['id' => 3]),
                new ScoutUser(['id' => 4]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            ['_id' => 1, '__count' => 4],
            ['_id' => 2, '__count' => 4],
            ['_id' => 4, '__count' => 4],
            ['_id' => 3, '__count' => 4],
        ], $model);

        $this->assertEquals(4, count($results));
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function testLazyMapMethodRespectsOrder()
    {
        $lazy = false;
        $database = m::mock(Database::class);
        $engine = new ScoutEngine($database, false);

        $model = m::mock(Model::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')
            ->andReturn(LazyCollection::make([
                new ScoutUser(['id' => 1]),
                new ScoutUser(['id' => 2]),
                new ScoutUser(['id' => 3]),
                new ScoutUser(['id' => 4]),
            ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            ['_id' => 1, '__count' => 4],
            ['_id' => 2, '__count' => 4],
            ['_id' => 4, '__count' => 4],
            ['_id' => 3, '__count' => 4],
        ], $model);

        $this->assertEquals(4, count($results));
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function testUpdate(): void
    {
        $date = new DateTimeImmutable('2000-01-02 03:04:05');
        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_indexable')
            ->andReturn($collection);
        $collection->shouldReceive('bulkWrite')
            ->once()
            ->with([
                [
                    'updateOne' => [
                        ['_id' => 'key_1'],
                        ['$set' => ['id' => 1, 'date' => new UTCDateTime($date)]],
                        ['upsert' => true],
                    ],
                ],
                [
                    'updateOne' => [
                        ['_id' => 'key_2'],
                        ['$set' => ['id' => 2]],
                        ['upsert' => true],
                    ],
                ],
            ]);

        $engine = new ScoutEngine($database, softDelete: false);
        $engine->update(EloquentCollection::make([
            new SearchableModel([
                'id' => 1,
                'date' => $date,
            ]),
            new SearchableModel([
                'id' => 2,
            ]),
        ]));
    }

    public function testUpdateWithSoftDelete(): void
    {
        $date = new DateTimeImmutable('2000-01-02 03:04:05');
        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_indexable')
            ->andReturn($collection);
        $collection->shouldReceive('bulkWrite')
            ->once()
            ->withArgs(function ($pipeline) {
                $this->assertSame([
                    [
                        'updateOne' => [
                            ['_id' => 'key_1'],
                            ['$set' => ['id' => 1, '__soft_deleted' => false]],
                            ['upsert' => true],
                        ],
                    ],
                ], $pipeline);

                return true;
            });

        $model = new SearchableModel(['id' => 1]);
        $model->delete();

        $engine = new ScoutEngine($database, softDelete: true);
        $engine->update(EloquentCollection::make([$model]));
    }

    public function testDelete(): void
    {
        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_indexable')
            ->andReturn($collection);
        $collection->shouldReceive('deleteMany')
            ->once()
            ->with(['_id' => ['$in' => ['key_1', 'key_2']]]);

        $engine = new ScoutEngine($database, softDelete: false);
        $engine->delete(EloquentCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
        ]));
    }

    public function testDeleteWithRemoveableScoutCollection(): void
    {
        $job = new RemoveFromSearch(EloquentCollection::make([
            new SearchableModel(['id' => 5, 'scout_key' => 'key_5']),
        ]));

        $job = unserialize(serialize($job));

        $database = m::mock(Database::class);
        $collection = m::mock(Collection::class);
        $database->shouldReceive('selectCollection')
            ->with('collection_indexable')
            ->andReturn($collection);
        $collection->shouldReceive('deleteMany')
            ->once()
            ->with(['_id' => ['$in' => ['key_5']]]);

        $engine = new ScoutEngine($database, softDelete: false);
        $engine->delete($job->models);
    }
}
