<?php

namespace ItStably\ClickhouseBuilder;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use PHPUnit\Framework\TestCase;
use ItStably\Clickhouse\Common\FileFromString;
use ItStably\ClickhouseBuilder\Exceptions\BuilderException;
use ItStably\ClickhouseBuilder\Exceptions\NotSupportedException;
use ItStably\ClickhouseBuilder\Integrations\Laravel\Builder;
use ItStably\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider;
use ItStably\ClickhouseBuilder\Integrations\Laravel\Connection;
use ItStably\ClickhouseBuilder\Query\Enums\Format;
use ItStably\ClickhouseBuilder\Query\Expression;

class LaravelIntegrationTest extends TestCase
{
    public function getSimpleConfig()
    {
        return [
            'servers' => [
                [
                    'host'     => 'localhost',
                    'port'     => 8123,
                    'database' => 'default',
                    'username' => 'default',
                    'password' => '',
                    'options'  => [
                        'timeout'  => 10,
                        'protocol' => 'http',
                    ],
                ]
            ]
        ];
    }

    public function getClusterConfig()
    {
        return [
            'clusters' => [
                'test' => [
                    'server-1' => [
                        'host'     => 'localhost',
                        'port'     => 8123,
                        'database' => 'default',
                        'username' => 'default',
                        'password' => '',
                    ],
                    'server2'  => [
                        'host'     => 'localhost',
                        'port'     => 8123,
                        'database' => 'default',
                        'username' => 'default',
                        'password' => '',
                        'options'=> [
                            'timeout'=> 10
                        ]
                    ],
                    'server3'  => [
                        'host'     => 'not_local_host',
                        'port'     => 8123,
                        'database' => 'default',
                        'username' => 'default',
                        'password' => '',
                        'options'=> [
                            'timeout'=> 10
                        ]
                    ],
                ]
            ],
        ];
    }

    public function test_service_provider()
    {
        $clickHouseServiceProvider = new ClickhouseServiceProvider(Container::getInstance());
        $databaseServiceProvider = new DatabaseServiceProvider(Container::getInstance());
        $eventsServiceProvider = new EventServiceProvider(Container::getInstance());
        Container::getInstance()->singleton('config', function () {
            return new Repository([
                'database' => [
                    'connections' => [
                        'clickhouse' => [
                            'driver'   => 'clickhouse',
                            'host'     => 'localhost',
                            'port'     => 8123,
                            'database' => 'database',
                            'username' => 'default',
                            'password' => '',
                        ],
                    ],
                ],
            ]);
        });

        $eventsServiceProvider->register();
        $databaseServiceProvider->register();
        $databaseServiceProvider->boot();
        $clickHouseServiceProvider->boot();

        $database = Container::getInstance()->make('db')->connection('clickhouse');

        $this->assertInstanceOf(Connection::class, $database);
    }

    public function test_connection_construct()
    {
        $simpleConnection = new Connection($this->getSimpleConfig());
        $clusterConnection = new Connection($this->getClusterConfig());
    
        $clusterConnection->onCluster('test')->usingRandomServer();
        
        $simpleClient = $simpleConnection->getClient();
        $clusterClient = $clusterConnection->getClient();
        
        $clusterServer = $clusterClient->getServer();
        $secondClusterServer = $clusterClient->getServer();
        
        while ($secondClusterServer === $clusterServer) {
            $secondClusterServer = $clusterClient->getServer();
        }
        
        $this->assertNotSame($clusterServer, $secondClusterServer);
        $this->assertEquals($simpleClient->getServer(), $simpleClient->getServer());
    }

    public function test_connection_get_config()
    {
        $connection = new Connection($this->getSimpleConfig());

        $this->assertEquals($this->getSimpleConfig(), $connection->getConfig());
    }

    public function test_connection_query()
    {
        $connection = new Connection($this->getSimpleConfig());

        $this->assertInstanceOf(Builder::class, $connection->query());
    }

    public function test_connection_table()
    {
        $connection = new Connection($this->getSimpleConfig());
        $builder = $connection->table('table');

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertEquals('SELECT * FROM `table`', $builder->toSql());
    }

    public function test_connection_raw()
    {
        $connection = new Connection($this->getSimpleConfig());

        $this->assertInstanceOf(Expression::class, $connection->raw('value'));
    }

    public function test_connection_select()
    {
        $connection = new Connection($this->getSimpleConfig());

        $result = $connection->select('select * from numbers(0, 10)');
        $this->assertEquals(10, count($result));
    }

    public function test_connection_select_one()
    {
        $connection = new Connection($this->getSimpleConfig());
    
        $result = $connection->selectOne('select * from numbers(0, 10)');
        $this->assertEquals(10, count($result));
    }

    public function test_connection_statement()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(0, $result[0]['count']);
        
        $connection->statement('create table test (test String) Engine = Memory');
        
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(1, $result[0]['count']);
    
        $connection->statement('drop table if exists test');
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(0, $result[0]['count']);
    }

    public function test_connection_unprepared()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->unprepared('drop table if exists test');
    
        $result = $connection->select('select count() as count from system.tables where name = \'test\'');
        $this->assertEquals(0, $result[0]['count']);
    
        $connection->statement('create table test (test String) Engine = Memory');
    
        $result = $connection->select('select count() as count from system.tables where name = \'test\'');
        $this->assertEquals(1, $result[0]['count']);
    
        $connection->statement('drop table if exists test');
        $result = $connection->select('select count() as count from system.tables where name = \'test\'');
        $this->assertEquals(0, $result[0]['count']);
    }

    public function test_connection_select_async()
    {
        $connection = new Connection($this->getSimpleConfig());
        
        $result = $connection->selectAsync([
            ['query' => 'select * from numbers(0, 10)'],
            ['query' => 'select * from numbers(10, 10)'],
        ]);
        
        $this->assertEquals(2, count($result));
        $this->assertEquals(['0','1','2','3','4','5','6','7','8','9'], array_column($result[0], 'number'));
        $this->assertEquals(['10','11','12','13','14','15','16','17','18','19'], array_column($result[1], 'number'));
    }

    public function test_connection_insert()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = Memory');
        
        $result = $connection->insert('insert into test (number) values (?), (?), (?)', [0, 1, 2]);
        $this->assertTrue($result);
        
        $result = $connection->select('select * from test');
    
        $this->assertEquals(3, count($result));
    }

    public function test_connection_insert_files()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = Memory');
    
        $result = $connection->insertFiles('test', ['number'], [
            new FileFromString('0'.PHP_EOL.'1'.PHP_EOL.'2')
        ]);
        $this->assertTrue($result[0][0]);
    
        $result = $connection->select('select * from test');
    
        $this->assertEquals(3, count($result));
    }
    
    /*
     * Not supported functions
     */
    
    public function test_connection_begin_transaction()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->beginTransaction();
    }

    public function test_connection_update()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->update('query');
    }

    public function test_connection_commit()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->commit();
    }
    
    public function test_last_query_statistic()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->table($connection->raw('numbers(0,10)'))->select('number')->get();
        
        $firstStatistic = $connection->getLastQueryStatistic();
    
        $connection->table($connection->raw('numbers(0,10000)'))->select('number')->get();
    
        $secondStatistic = $connection->getLastQueryStatistic();
        
        $this->assertNotSame($firstStatistic, $secondStatistic);
        
        $this->expectException(BuilderException::class);
        $this->expectExceptionMessage('Run query before trying to get statistic');
        
        $connection = new Connection($this->getSimpleConfig());
        $connection->getLastQueryStatistic();
    }

    public function test_connection_delete()
    {
        /*
         * delete redirects call to statement method, so
         * just test it like statement
         */
        $connection = new Connection($this->getSimpleConfig());
        $connection->delete('drop table if exists test');
    
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(0, $result[0]['count']);
    
        $connection->delete('create table test (test String) Engine = Memory');
    
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(1, $result[0]['count']);
    
        $connection->delete('drop table if exists test');
        $result = $connection->select("select count() as count from system.tables where name = 'test'");
        $this->assertEquals(0, $result[0]['count']);
    }

    public function test_connection_affecting_statement()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->affectingStatement('query');
    }

    public function test_connection_rollback()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->rollBack();
    }

    public function test_connection_transaction_level()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->transactionLevel();
    }

    public function test_connection_transaction()
    {
        $connection = new Connection($this->getSimpleConfig());
        $this->expectException(NotSupportedException::class);
        $connection->transaction(function () {
        });
    }

    public function test_connection_using()
    {
        $connection = new Connection($this->getClusterConfig());
        
        $connection->onCluster('test')->using('server-1')->statement('drop table if exists test1');
        $connection->onCluster('test')->using('server2')->statement('drop table if exists test2');
        
        $connection->onCluster('test')->using('server-1')->statement('create database if not exists cluster1');
        $connection->onCluster('test')->using('server2')->statement('create database if not exists cluster2');
    
        $connection->onCluster('test')->using('server-1')->statement('create table test1 (number UInt8) Engine = Memory');
        $connection->onCluster('test')->using('server2')->statement('create table test2 (number UInt8) Engine = Memory');
        
        $result = $connection->onCluster('test')->using('server-1')->insert('insert into test1 (number) values (?), (?), (?)', [0, 1, 2]);
        $this->assertTrue($result);
    
        $result = $connection->select('select * from test1');
    
        $this->assertEquals(3, count($result));
    
        $result = $connection->onCluster('test')->using('server2')->insert('insert into test2 (number) values (?), (?), (?), (?)', [0, 1, 2, 4]);
        $this->assertTrue($result);
    
        $result = $connection->select('select * from test2');
    
        $this->assertEquals(4, count($result));
    
        $connection->onCluster('test')->using('server-1')->statement('drop table if exists test1');
        $connection->onCluster('test')->using('server2')->statement('drop table if exists test2');
    }

    public function test_builder_get()
    {
        $connection = new Connection($this->getSimpleConfig());
        
        $result = $connection->table($connection->raw('numbers(0,10)'))->select('number')->get();
        
        $this->assertEquals(10, count($result));
    }

    public function test_builder_async_get()
    {
        $connection = new Connection($this->getSimpleConfig());
        $result = $connection->table($connection->raw('numbers(0,10)'))->select('number')->asyncWithQuery(function ($builder) use($connection) {
            $builder->table($connection->raw('numbers(10,10)'))->select('number');
        })->get();
    
        $this->assertEquals(2, count($result));
        $this->assertEquals(['0','1','2','3','4','5','6','7','8','9'], array_column($result[0], 'number'));
        $this->assertEquals(['10','11','12','13','14','15','16','17','18','19'], array_column($result[1], 'number'));
    }

    public function test_builder_insert_files()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = Memory');
    
        $result = $connection->table('test')->insertFiles(['number'], [
            new FileFromString('0'.PHP_EOL.'1'.PHP_EOL.'2')
        ]);
        $this->assertTrue($result[0][0]);
    
        $result = $connection->table('test')->get();
    
        $this->assertEquals(3, count($result));
    
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = Memory');
    
        $result = $connection->table('test')->insertFile(['number'], new FileFromString('0'.PHP_EOL.'1'.PHP_EOL.'2'));
        $this->assertTrue($result);
    
        $result = $connection->table('test')->get();
    
        $this->assertEquals(3, count($result));
    }

    public function test_builder_insert()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = Memory');

        $connection->table('test')->insert(['number' => 1]);
        $connection->table('test')->insert([['number' => 2], ['number' => 3]]);
        
        $connection->table('test')->insert([4]);
        $connection->table('test')->insert([[5], [6]]);

        $result = $connection->table('test')->select('number')->get();
        $this->assertEquals(6, count($result));
        
        $this->assertFalse($connection->table('table')->insert([]));
    }
    
    public function test_builder_delete()
    {
        $connection = new Connection($this->getSimpleConfig());
        $connection->statement('drop table if exists test');
        $connection->statement('create table test (number UInt64) engine = MergeTree order by number');
    
        $connection->table('test')->insertFiles(['number'], [
            new FileFromString('0'.PHP_EOL.'1'.PHP_EOL.'2')
        ]);
        
        /*
         * We have to sleep for 3 seconds while mutation in progress
         */
        sleep(3);
        
        $connection->table('test')->where('number', '=', 1)->delete();
        
        $result = $connection->table('test')->select($connection->raw('count() as count'))->get();
        
        $this->assertEquals(2, $result[0]['count']);
    }
    
    public function test_builder_count()
    {
        $connection = new Connection($this->getSimpleConfig());
        $result = $connection->table($connection->raw('numbers(0,10)'))->count();
        
        $this->assertEquals(10, $result);
    
        $result = $connection->table($connection->raw('numbers(0,10)'))->groupBy($connection->raw('number % 2'))->count();
    
        $this->assertEquals(2, $result);
    }
    
    public function test_builder_first()
    {
        $connection = new Connection($this->getSimpleConfig());
        $result = $connection->table($connection->raw('numbers(2,10)'))->first();
        
        $this->assertEquals(2, $result['number']);
    }
}
