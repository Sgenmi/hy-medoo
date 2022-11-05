<?php

declare(strict_types=1);

/**
 * Created by IntelliJ IDEA.
 * Author: sgenmi
 * Date: 2022/11/5 4:53 PM
 * Email: 150560159@qq.com
 */

namespace Sgenmi\HyMedoo;

use Hyperf\DB\DB;
use Hyperf\Utils\ApplicationContext;
use Medoo\Medoo;

/**
 * @method array select(array $columns, array $where)
 * @method null select(array $columns, callable $callback)
 * @method null select(array $columns, array $where, callable $callback)
 * @method null select(array $join, array $columns, array $where, callable $callback)
 * @method mixed get(array|string $columns, array $where)
 * @method bool has(array $where)
 * @method mixed rand(array|string $column, array $where)
 * @method int count(array $where)
 * @method int max(string $column)
 * @method int min(string $column)
 * @method int avg(string $column)
 * @method int sum(string $column)
 * @method int max(string $column, array $where)
 * @method int min(string $column, array $where)
 * @method int avg(string $column, array $where)
 * @method int sum(string $column, array $where)
 */
abstract class HyMedoo
{
    protected string $table = '';

    public function __call(string $name, array $arguments)
    {
        return self::call($name, $arguments);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return self::call($name, $arguments);
    }

    public function getTable(bool $isComplete = true): string
    {
        $prefix = config('db.default.prefix', '');
        return $isComplete ? $prefix . $this->table : $this->table;
    }

    private static function call(string $name, array $arguments)
    {
        $options = [
            'database_type' => 'mysql',
        ];
        $table = (new static())->getTable();
        return ApplicationContext::getContainer()->get(DB::class)->run(
            function (\PDO $pdo) use ($options, $name, $arguments, $table) {
                $options['pdo'] = $pdo;
                $medoo = new Medoo($options);
                array_unshift($arguments, $table);
                return $medoo->{$name}(...$arguments);
            }
        );
    }
}