<?php

declare(strict_types=1);

/**
 * Created by IntelliJ IDEA.
 * Author: sgenmi
 * Date: 2022/11/5 4:53 PM
 * Email: 150560159@qq.com
 */

namespace Sgenmi\HyMedoo;

use Hyperf\Context\Context;
use Hyperf\DB\DB;
use Hyperf\Utils\ApplicationContext;
use Medoo\Medoo;
use Medoo\Raw;
use PDOStatement;


/**
 * @method insert(array $values)
 * @method update(array $data,array $where=null)
 * @method delete(array|Raw $where)
 * @method replace(array $columns, array $where=null)
 *
 * @method array select(array|string $columns, array $where=null)
 * @method array select(array $join,array $columns=[], array $where=null)
 * @method null select(array $columns, callable $callback=null)
 * @method null select(array $join, array $columns, array $where=null, callable $callback=null)
 * @method null select(array|string $columns, array $where=null, callable $callback=null)
 *
 * @method mixed get(array|string $columns, array $where=null)
 * @method mixed get(array $join, array|string $columns, array $where=null)

 *
 * @method void action(callable $actions )
 *
 * @method bool has(array $where)
 * @method bool has(array $join, array $wher=null)
 *
 * @method mixed rand(array|string $column, array $where=null)
 * @method mixed rand(array $join,array|string $column, array $where)
 *
 * @method int count(string $column, array $where=null)
 * @method int count(array $join, string $column, array $where=null)
 *
 * @method string max(string $column,array $where=null)
 * @method string max(array $join,string $column,array $where=null)
 *
 * @method string min(string $column,array $where=null)
 * @method string min(array $join,string $column, array $where=null)
 *
 * @method string avg(string $column,array $where=null)
 * @method string avg(array $join,string $column,array $where=null)
 *
 * @method string sum(string $column,array $where=null)
 * @method string sum(array $join,string $column,array $where=null)
 *
 * @method mixed debug();
 * @method array log();
 * @method array info();
 * @method Medoo lock(string $lock='FOR UPDATE')
 * @method Medoo forceIndex(string $index)
 *
 */
abstract class AbstractModel
{
    protected string $table = '';

    const LOCK_FOR_UPDATE = 'FOR UPDATE';
    const LOCK_SHARE = 'LOCK IN SHARE MODE';


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

    /**
     * 获取最后一次执行的sql
     * @return string
     */
    public function last(): string
    {
        return  strval(Context::get(self::getUnionKey('model:lastSql'),''));
    }

    private static function call(string $name, array $arguments)
    {
        $unionDebugKey = self::getUnionKey('model:debug');
        $unionLastSqlKey = self::getUnionKey('model:lastSql');
        $unionLockSqlKey = self::getUnionKey('model:lock');
        $unionforceIndexSqlKey = self::getUnionKey('model:forceIndex');

        $cls = ApplicationContext::getContainer()->get(static::class);
        if($name=='debug'){
            Context::set($unionDebugKey,true);
            return $cls;
        }
        if($name=='lock'){
            Context::set($unionLockSqlKey,$arguments[0]??self::LOCK_FOR_UPDATE);
            return $cls;
        }

        if($name=='forceIndex'){
            Context::set($unionforceIndexSqlKey,$arguments[0]??'');
            return $cls;
        }

        $table = $cls->getTable();
        //debug() mode
        $debug = Context::get($unionDebugKey);

        //取出lock后 立即设置为空
        $lockStr = Context::get($unionLockSqlKey,'');
        Context::set($unionLockSqlKey,'');
        //强制索引
        $forceIndex = Context::get($unionforceIndexSqlKey,'');
        Context::set($unionforceIndexSqlKey,'');

        $res =  ApplicationContext::getContainer()->get(DB::class)->run(
            function (\PDO $pdo) use ($name, $arguments, $table,$debug,$unionLastSqlKey,$lockStr,$forceIndex) {
                $options = [
                    'database_type' => 'mysql',
                ];
                $ret = null;
                $options['pdo'] = $pdo;
                $medoo = new class($options) extends Medoo{
                    private string $lock='';
                    private string $forceIndex='';
                    /**
                     * @param string $type
                     * @author Sgenmi
                     */
                    public function lock(string $type= AbstractModel::LOCK_FOR_UPDATE):self{
                        $this->lock = $type;
                        return $this;
                    }

                    /**
                     * @param string $index
                     * @author Sgenmi
                     */
                    public function forceIndex(string $index):self {
                        $this->forceIndex = $index;
                        return $this;
                    }

                    /**
                     * @param string $statement
                     * @param array $map
                     * @param callable|null $callback
                     * @return PDOStatement|null
                     * @author Sgenmi
                     */
                    public function exec(string $statement, array $map = [], callable $callback = null): ?PDOStatement
                    {
                        if($this->forceIndex && str_starts_with($statement,'SELECT')){
                            if(str_contains($statement,'JOIN')){
                                preg_match("/FROM \"(.*?)\"\s*JOIN/is",$statement, $m);
                                $table = $m[1]??'';
                                $mathStr = 'JOIN';
                            }else{
                                preg_match("/FROM \"(.*?)\"\s*WHERE/is",$statement, $m);
                                $table = $m[1]??'';
                                $mathStr = 'WHERE';
                            }
                            if($table){
                                $_str = sprintf('FROM "%s" %s',$table,$mathStr);
                                $statement = str_replace($_str, sprintf('FROM "%s" FORCE INDEX(%s) %s',$table,$this->forceIndex,$mathStr)  ,$statement);
                            }
                        }
                        $statement = $statement.' '. trim($this->lock);
                        $this->lock='';
                        $this->forceIndex='';
                        return parent::exec($statement,$map,$callback);
                    }
                };
                array_unshift($arguments, $table);

                $medoo = $medoo->lock($lockStr)->forceIndex($forceIndex);
                //debug 返回medoo debug()方式
                if($debug ){
                    return $medoo->debug()->lock($lockStr)->{$name}(...$arguments);
                }
                switch ($name){
                    case 'insert':
                        $stm = $medoo->{$name}(...$arguments);
                        if($stm){
                            $ret =  $medoo->id();
                        }
                        break;
                    default:
                        $ret = $medoo->{$name}(...$arguments);
                }
                Context::set($unionLastSqlKey,$medoo->last());
                return $ret;
            }
        );

        if(Context::has($unionDebugKey)){
            Context::set($unionDebugKey,false);
        }
        return $res;
    }

    /**
     * @param string $name
     * @return string
     */
    private static function getUnionKey(string $name):string {
        return static::class.':'.$name;
    }
}
