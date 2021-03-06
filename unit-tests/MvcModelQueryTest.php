<?php
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 2015/12/27
 * Time: 20:13
 */
defined('UNIT_TESTS_ROOT') || require __DIR__ . '/bootstrap.php';

use Models\Address;
use Models\City;
use Models\Country;
use Models\Payment;

class MvcModelQueryTest extends TestCase
{
    public function setUp()
    {
        $di = new ManaPHP\Di\FactoryDefault();

        $di->setShared('db', function () {
            $config = require __DIR__ . '/config.database.php';
            //$db = new ManaPHP\Db\Adapter\Mysql($config['mysql']);
            $db = new ManaPHP\Db\Adapter\Proxy(['masters' => ['mysql://root@localhost:/manaphp_unit_test'], 'slaves' => ['mysql://root@localhost:/manaphp_unit_test']]);
            //   $db = new ManaPHP\Db\Adapter\Sqlite($config['sqlite']);

            $db->attachEvent('db:beforeQuery', function (ManaPHP\DbInterface $source) {
                var_dump($source->getSQL());
                var_dump($source->getEmulatedSQL());
            });

            echo get_class($db), PHP_EOL;
            return $db;
        });
    }

    public function test_distinct()
    {
        //select all implicitly
        $query = Address::createQuery()->select('city_id');
        $this->assertCount(603, $query->execute());

        //select all explicitly
        $query = Address::createQuery()
            ->select('city_id')
            ->distinct(false);
        $this->assertCount(603, $query->execute());

        //select distinct
        $query = Address::createQuery()
            ->select('city_id')
            ->distinct(true);
        $this->assertCount(599, $query->execute());
    }

    public function test_columns()
    {
        //default select all columns
        $query = Address::createQuery()->limit(2);
        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(8, $rows[0]);

        //select all columns explicitly
        $query = Address::createQuery()->select('*')->limit(2);
        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(8, $rows[0]);

        // select all columns explicitly and use table alias
        $query = Address::createQuery('a')
            ->select('a.*, c.*')
            ->leftJoin(get_class(new City()), 'c.city_id =a.city_id', 'c')
            ->limit(2);
        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(10, $rows[0]);

        //string format columns
        $query = Address::createQuery('a')
            ->select('a.address_id, a.address, a.phone')
            ->limit(2);
        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(3, $rows[0]);

        //dense multi space to only one for columns
        $query = Address::createQuery('a')
            ->select('a.address_id,
                        a.address,
                        c.city')
            ->leftJoin(get_class(new City()), 'c.city_id =a.city_id', 'c')
            ->limit(2)
            ->orderBy('a.address_id');
        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(3, $rows[0]);

        $query = Address::createQuery()
            ->select('count(address_id) as address_count');
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]);
    }

    public function test_from()
    {
        $query = Address::createQuery()
            ->select('address_id,address,phone')
            ->limit(2);

        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(3, $rows[0]);
    }

    public function test_addFrom()
    {
        //one model without alias
        $query = Address::createQuery()
            ->select('address_id,address,phone')
            ->limit(2);

        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(3, $rows[0]);

        //one model with alias
        $query = Address::createQuery('a')
            ->select('a.address_id,a.address,a.phone')
            ->limit(2);

        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(3, $rows[0]);

        //multi-models with alias
        $query = Address::createQuery('a')
            ->select('a.*, c.*')
            ->addFrom(get_class(new City()), 'c')
            ->limit(2);

        $rows = $query->execute();
        $this->assertCount(2, $rows);
        $this->assertCount(10, $rows[0]);

        $query = (new \ManaPHP\Db\Query())
            ->select('*')
            ->from(City::createQuery()
                ->select('*')
                ->where('city_id<=5'), 'cc');
        $rows = $query->execute();
        $this->assertCount(5, $rows);
    }

    public function test_join()
    {
        if (\ManaPHP\Di::getDefault()->getShared('db') instanceof ManaPHP\Db\Adapter\Sqlite) {
            return;
        }

        //with model
        $query = Address::createQuery()
            ->select('count(address_id) as address_count')
            ->join(get_class(new City()));
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertEquals(361800, $rows[0]['address_count']);

        //with model,conditions
        $query = Address::createQuery('a')
            ->select('count(address_id) as address_count')
            ->join(get_class(new City()), 'city.city_id =a.city_id');
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertEquals(603, $rows[0]['address_count']);

        //with model,conditions,alias
        $query = Address::createQuery('a')
            ->select('count(address_id) as address_count')
            ->join(get_class(new City()), 'c.city_id =a.city_id', 'c');
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertEquals(603, $rows[0]['address_count']);

        //with model,conditions,alias,join
        $query = Address::createQuery('a')
            ->select('a.address_id, a.address, a.city_id, c.city')
            ->join(get_class(new City()), 'c.city_id =a.city_id', 'c', 'LEFT');
        $rows = $query->execute();
        $this->assertCount(603, $rows);

        $query = Address::createQuery('a')
            ->select('a.address_id, a.address, a.city_id, c.city')
            ->join(City::createQuery()
                ->select('*')
                ->where('city_id <:city_id', ['city_id' => 50]), 'c.city_id =a.city_id', 'c', 'RIGHT');
        $rows = $query->execute();
        $this->assertCount(50, $rows);
    }

    public function test_innerJoin()
    {
        $countCity = City::count();
        $this->assertEquals(600, $countCity);

        $countCountry = Country::count();
        $this->assertEquals(109, $countCountry);

        $builder = City::createQuery('c1')
            ->select('c1.*,c2.*')
            ->innerJoin(get_class(new Country()), 'c1.city_id=c2.country_id', 'c2');
        $this->assertCount($countCountry, $builder->execute());
    }

    public function test_leftJoin()
    {
        $countCity = City::count();
        $this->assertEquals(600, $countCity);

        $countCountry = Country::count();
        $this->assertEquals(109, $countCountry);

        $query = City::createQuery('c1')
            ->select('c1.*,c2.*')
            ->leftJoin(get_class(new Country()), 'c1.city_id=c2.country_id', 'c2');
        $this->assertCount($countCity, $query->execute());
    }

    public function test_rightJoin()
    {
        if (\ManaPHP\Di::getDefault()->getShared('db') instanceof ManaPHP\Db\Adapter\Sqlite) {
            return;
        }

        $countCity = City::count();
        $this->assertEquals(600, $countCity);

        $countCountry = Country::count();
        $this->assertEquals(109, $countCountry);

        $query = City::createQuery('c1')
            ->select('c1.*,c2.*')
            ->rightJoin(get_class(new Country()), 'c1.city_id=c2.country_id', 'c2');
        $this->assertCount($countCountry, $query->execute());
    }

    public function test_where()
    {
        $query = Address::createQuery()->where('address_id <=100');
        $this->assertCount(100, $query->execute());

        $query = Address::createQuery()
            ->where('address_id <=:max_address_id', ['max_address_id' => 100]);
        $this->assertCount(100, $query->execute());

        $query = Address::createQuery()
            ->where('address_id', 100);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery()
            ->where('address_id >=:min_address_id AND address_id <=:max_address_id',
                ['min_address_id' => 51, 'max_address_id' => 100]);
        $this->assertCount(50, $query->execute());
    }

    public function test_andWhere()
    {
        $query = Address::createQuery()
            ->andWhere('address_id <=100');
        $this->assertCount(100, $query->execute());

        $query = Address::createQuery()
            ->andWhere('address_id <=:max_address_id', ['max_address_id' => 100]);
        $this->assertCount(100, $query->execute());

        $query = Address::createQuery()
            ->andWhere('address_id >=:min_address_id', ['min_address_id' => 51])
            ->andWhere('address_id <=:max_address_id', ['max_address_id' => 100]);
        $this->assertCount(50, $query->execute());

        $query = Address::createQuery()
            ->andWhere('address_id', 1);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery()
            ->andWhere('address_id =', 1);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery()
            ->andWhere('address_id <', 2);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery('a')
            ->andWhere('a.address_id', 1);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery('a')
            ->andWhere('a.address_id', 1);
        $this->assertCount(1, $query->execute());
    }

    public function test_betweenWhere()
    {
        $query = Address::createQuery()
            ->betweenWhere('address_id', 51, 100);
        $this->assertCount(50, $query->execute());

        $query = Address::createQuery()
            ->betweenWhere('address_id', 51, 100)
            ->betweenWhere('address_id', 61, 70);
        $this->assertCount(10, $query->execute());
    }

    public function test_notBetweenWhere()
    {
        $query = Address::createQuery()
            ->notBetweenWhere('address_id', 51, 1000000);
        $this->assertCount(50, $query->execute());

        $query = Address::createQuery()
            ->notBetweenWhere('address_id', 51, 1000000)
            ->notBetweenWhere('address_id', 71, 7000000);
        $this->assertCount(50, $query->execute());
    }

    public function test_inWhere()
    {
        $query = Address::createQuery()->inWhere('address_id', []);
        $this->assertCount(0, $query->execute());

        $query = Address::createQuery()->inWhere('address_id', [1]);
        $this->assertCount(1, $query->execute());

        $query = Address::createQuery()
            ->inWhere('address_id', [1, 2, 3, 4, 5]);
        $this->assertCount(5, $query->execute());

        $query = Address::createQuery()
            ->inWhere('address_id', [-3, -2, -1, 0, 1, 2]);
        $this->assertCount(2, $query->execute());

        $query = Address::createQuery()
            ->select('*')
            ->inWhere('city_id', Address::createQuery()
                ->select('city_id')
                ->inWhere('city_id', [1, 2, 3, 4]));
        $this->assertCount(4, $query->execute());
    }

    public function test_likeWhere()
    {
        $query = Address::createQuery()->likeWhere('address', '14%');
        $this->assertCount(33, $query->execute());

        $query = Address::createQuery()->likeWhere(['address', 'district'], 'we%');
        $this->assertCount(15, $query->execute());
    }

    public function test_orderBy()
    {
        $query = Address::createQuery()
            ->select('address_id')
            ->where('address_id <=:max_address_id', ['max_address_id' => 10])
            ->orderBy('address_id');
        $rows = $query->execute();
        $this->assertCount(10, $query->execute());

        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $this->assertTrue($rows[$i]['address_id'] < $rows[$i + 1]['address_id']);
        }

        $query = Address::createQuery()
            ->select('address_id')
            ->where('address_id <=:max_address_id', ['max_address_id' => 10])
            ->orderBy('address_id ASC');
        $rows = $query->execute();
        $this->assertCount(10, $query->execute());

        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $this->assertTrue($rows[$i]['address_id'] < $rows[$i + 1]['address_id']);
        }

        $query = Address::createQuery()
            ->select('address_id')
            ->where('address_id <=:max_address_id', ['max_address_id' => 10])
            ->orderBy('address_id DESC');
        $rows = $query->execute();
        $this->assertCount(10, $query->execute());

        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $this->assertTrue($rows[$i]['address_id'] > $rows[$i + 1]['address_id']);
        }
    }

    public function test_indexBy()
    {
        $query = Address::createQuery()
            ->select('address_id')
            ->where('address_id >=', 5)
            ->indexBy('address_id')
            ->limit(1);
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('5', $rows);

        $query = Address::createQuery()
            ->select('address_id')
            ->where('address_id >=', 5)
            ->indexBy(function ($row) {
                return 'address_' . $row['address_id'];
            })
            ->limit(1);
        $rows = $query->execute();
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('address_5', $rows);
    }

    public function test_having()
    {
        $query = City::createQuery()
            ->select('COUNT(city_id) as count_city, country_id')
            ->groupBy('country_id')
            ->having('COUNT(city_id) >1');
        $rows = $query->execute();
        $this->assertCount(67, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['count_city'] > 1);
        }

        $query = City::createQuery()
            ->select('COUNT(city_id) as count_city, country_id')
            ->groupBy('country_id')
            ->having('COUNT(city_id) >1 AND COUNT(city_id) <7');
        $rows = $query->execute();
        $this->assertCount(46, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['count_city'] > 1);
            $this->assertTrue($row['count_city'] < 7);
        }

        $query = City::createQuery()
            ->select('COUNT(city_id) as count_city, country_id')
            ->groupBy('country_id')
            ->having('COUNT(city_id) >:min_count AND COUNT(city_id) <:max_count', ['min_count' => 1, 'max_count' => 7]);
        $rows = $query->execute();
        $this->assertCount(46, $rows);
    }

    public function test_limit()
    {
        //limit without offset
        $query = City::createQuery()->select('city_id')->limit(1);
        $this->assertCount(1, $query->execute());

        //limit with offset
        $query = City::createQuery()
            ->select('city_id')
            ->orderBy('city_id')
            ->limit(10, 20);

        $rows = $query->execute();
        $this->assertCount(10, $rows);
        $this->assertEquals(21, $rows[0]['city_id']);
        $this->assertEquals(30, $rows[9]['city_id']);
    }

    public function test_page()
    {
        //limit without offset
        $query = City::createQuery()->select('city_id')->limit(1);
        $this->assertCount(1, $query->execute());

        //limit with offset
        $query = City::createQuery()
            ->select('city_id')
            ->orderBy('city_id')
            ->page(10, 3);

        $rows = $query->execute();
        $this->assertCount(10, $rows);
        $this->assertEquals(21, $rows[0]['city_id']);
        $this->assertEquals(30, $rows[9]['city_id']);
    }

    public function test_groupBy()
    {
        $query = City::createQuery()
            ->select('COUNT(city_id) as count_city, country_id')
            ->groupBy('country_id');
        $rows = $query->execute();
        $this->assertCount(109, $rows);

        $query = Payment::createQuery()
            ->select('COUNT(payment_id) AS payment_times, customer_id, amount')
            ->groupBy('customer_id,amount');
        $rows = $query->execute();
        $this->assertCount(4812, $rows);
    }

    public function test_notInWhere()
    {
        $rowAddress = Address::count();
        $this->assertEquals(603, $rowAddress);

        $query = Address::createQuery()
            ->notInWhere('address_id', []);
        $this->assertCount(603, $query->execute());

        $query = Address::createQuery()
            ->notInWhere('address_id', [1]);
        $this->assertCount(602, $query->execute());

        $query = Address::createQuery()
            ->notInWhere('address_id', [1, 2, 3]);
        $this->assertCount(600, $query->execute());

        $query = Address::createQuery()
            ->notInWhere('address_id', [-3, -2, -1, 0, 1, 2]);
        $this->assertCount(601, $query->execute());

        $query = Address::createQuery()
            ->select('*')
            ->notInWhere('city_id', Address::createQuery()
                ->select('city_id')
                ->inWhere('city_id', [1, 2, 3, 4]));
        $this->assertCount(599, $query->execute());
    }

    public function test_executeEx()
    {
        $rows = City::createQuery()->executeEx($totalRows);
        $this->assertCount(600, $rows);
        $this->assertEquals(600, $totalRows);

        $rows = City::createQuery()->limit(100)->executeEx($totalRows);
        $this->assertCount(100, $rows);
        $this->assertEquals(600, $totalRows);

        $rows = City::createQuery()->limit(1000)->executeEx($totalRows);
        $this->assertCount(600, $rows);
        $this->assertEquals(600, $totalRows);

        $rows = City::createQuery()->limit(200, 100)->executeEx($totalRows);
        $this->assertCount(200, $rows);
        $this->assertEquals(600, $totalRows);

        $rows = City::createQuery()->limit(300, 1000)->executeEx($totalRows);
        $this->assertCount(0, $rows);
        $this->assertEquals(600, $totalRows);

        $rows = City::createQuery()
            ->limit(10, 10)
            ->groupBy('country_id')
            ->executeEx($totalRows);
        $this->assertCount(10, $rows);
        $this->assertEquals(109, $totalRows);
    }

    public function test_unionAll()
    {
        if (\ManaPHP\Di::getDefault()->getShared('db') instanceof \ManaPHP\Db\Adapter\Sqlite) {
            return;
        }

        $query = (new \ManaPHP\Db\Query())
            ->union([
                City::createQuery()
                    ->select('*')
                    ->inWhere('city_id', [1, 2, 3, 4, 5])
            ]);
        $this->assertCount(5, $query->execute());

//        $builder = $this->modelsManager->createBuilder()
//            ->union([
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5]),
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5])
//            ])
//            ->orderBy('city_id DESC');
//
//        $rows = $builder->execute();
//        $this->assertCount(10, $rows);
//        $this->assertEquals('5', $rows[0]['city_id']);
//        $this->assertEquals('5', $rows[1]['city_id']);

//        $builder = $this->modelsManager->createBuilder()
//            ->unionAll([
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5]),
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5])
//            ])
//            ->orderBy('city_id ASC');

//        $rows = $builder->execute();
//        $this->assertCount(10, $rows);
//        $this->assertEquals('1', $rows[0]['city_id']);
//        $this->assertEquals('1', $rows[1]['city_id']);
//
//        $builder = $this->modelsManager->createBuilder()
//            ->union([
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5]),
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5])
//            ])
//            ->orderBy('city_id ASC')
//            ->limit(5);
//
//        $rows = $builder->execute();
//        $this->assertCount(5, $rows);
//
//        $builder = $this->modelsManager->createBuilder()
//            ->unionDistinct([
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5]),
//                $this->modelsManager->createBuilder()
//                    ->columns('*')
//                    ->from(City::class)
//                    ->inWhere('city_id', [1, 2, 3, 4, 5])
//            ]);
//
//        $rows = $builder->execute();
//        $this->assertCount(5, $rows);
    }
}