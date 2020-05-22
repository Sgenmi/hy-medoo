<?php

/**
 * Created by IntelliJ IDEA.
 * Author: sgenmi
 * Date: 2020/5/22 下午2:51
 * Email: 150560159@qq.com
 *
 * Medoo database framework
 * https://medoo.in
 * Version 1.7.10
 *
 * Copyright 2020, Angel Lai
 * Released under the MIT license
 */

namespace Sgenmi\HyMedoo;

use Hyperf\DB\DB;
use Hyperf\Utils\ApplicationContext;
use PDO;
use InvalidArgumentException;

class HyMedoo
{
    private $type = 'mysql';
    protected $prefix = "";
    protected $debug_mode = false;
    protected $guid = 0;

    private function getConnection()
    {
        return ApplicationContext::getContainer()->get(DB::class);
    }

    public function query($query, $map = [])
    {
        $raw = $this->raw($query, $map);
        $query = $this->buildRaw($raw, $map);
        return $this->exec($query, $map);
    }

    public function exec($query, $map = [])
    {
        $sql = $this->generate($query, $map);
        if ($this->debug_mode) {
            echo "\n", $sql, "\n";
            $this->debug_mode = false;
            return false;
        }
        return $sql;
    }

    protected function generate($query, $map)
    {
        $identifier = [
            'mysql' => '`$1`',
            'mssql' => '[$1]'
        ];

        $query = preg_replace(
            '/"([a-zA-Z0-9_]+)"/i',
            isset($identifier[$this->type]) ? $identifier[$this->type] : '"$1"',
            $query
        );

        foreach ($map as $key => $value) {
            if ($value[1] === PDO::PARAM_STR) {
                $replace = $this->quote($value[0]);
            } elseif ($value[1] === PDO::PARAM_NULL) {
                $replace = 'NULL';
            } elseif ($value[1] === PDO::PARAM_LOB) {
                $replace = '{LOB_DATA}';
            } else {
                $replace = $value[0];
            }
            $query = str_replace($key, $replace, $query);
        }
        return $query;
    }

    public static function raw($string, $map = [])
    {
        $raw = new Raw();
        $raw->map = $map;
        $raw->value = $string;
        return $raw;
    }

    protected function isRaw($object)
    {
        return $object instanceof Raw;
    }

    protected function buildRaw($raw, &$map)
    {
        if (!$this->isRaw($raw)) {
            return false;
        }
        $query = preg_replace_callback(
            '/(([`\']).*?)?((FROM|TABLE|INTO|UPDATE|JOIN)\s*)?\<(([a-zA-Z0-9_]+)(\.[a-zA-Z0-9_]+)?)\>(.*?\2)?/i',
            function ($matches) {
                if (!empty($matches[2]) && isset($matches[8])) {
                    return $matches[0];
                }
                if (!empty($matches[4])) {
                    return $matches[1] . $matches[4] . ' ' . $this->tableQuote($matches[5]);
                }
                return $matches[1] . $this->columnQuote($matches[5]);
            },
            $raw->value);
        $raw_map = $raw->map;
        if (!empty($raw_map)) {
            foreach ($raw_map as $key => $value) {
                $map[$key] = $this->typeMap($value, gettype($value));
            }
        }

        return $query;
    }

    public function quote($string)
    {
        return $this->getConnection()->quote($string);
    }

    protected function tableQuote($table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/i', $table)) {
            throw new InvalidArgumentException("Incorrect table name \"$table\"");
        }
        return '"' . $this->prefix . $table . '"';
    }

    protected function mapKey()
    {
        return ':MeDoO_' . $this->guid++ . '_mEdOo';
    }

    protected function typeMap($value, $type)
    {
        $map = [
            'NULL' => PDO::PARAM_NULL,
            'integer' => PDO::PARAM_INT,
            'double' => PDO::PARAM_STR,
            'boolean' => PDO::PARAM_BOOL,
            'string' => PDO::PARAM_STR,
            'object' => PDO::PARAM_STR,
            'resource' => PDO::PARAM_LOB
        ];

        if ($type === 'boolean') {
            $value = ($value ? '1' : '0');
        } elseif ($type === 'NULL') {
            $value = null;
        }
        return [$value, $map[$type]];
    }

    protected function columnQuote($string)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+(\.?[a-zA-Z0-9_]+)?$/i', $string)) {
            throw new InvalidArgumentException("Incorrect column name \"$string\"");
        }
        if (strpos($string, '.') !== false) {
            return '"' . $this->prefix . str_replace('.', '"."', $string) . '"';
        }
        return '"' . $string . '"';
    }

    protected function columnPush(&$columns, &$map, $root, $is_join = false)
    {
        if ($columns === '*') {
            return $columns;
        }
        $stack = [];
        if (is_string($columns)) {
            $columns = [$columns];
        }
        foreach ($columns as $key => $value) {
            if (!is_int($key) && is_array($value) && $root && count(array_keys($columns)) === 1) {
                $stack[] = $this->columnQuote($key);
                $stack[] = $this->columnPush($value, $map, false, $is_join);
            } elseif (is_array($value)) {
                $stack[] = $this->columnPush($value, $map, false, $is_join);
            } elseif (!is_int($key) && $raw = $this->buildRaw($value, $map)) {
                preg_match('/(?<column>[a-zA-Z0-9_\.]+)(\s*\[(?<type>(String|Bool|Int|Number))\])?/i', $key, $match);
                $stack[] = $raw . ' AS ' . $this->columnQuote($match['column']);
            } elseif (is_int($key) && is_string($value)) {

                if ($is_join && strpos($value, '*') !== false) {
                    throw new InvalidArgumentException('Cannot use table.* to select all columns while joining table');
                }
                preg_match('/(?<column>[a-zA-Z0-9_\.]+)(?:\s*\((?<alias>[a-zA-Z0-9_]+)\))?(?:\s*\[(?<type>(?:String|Bool|Int|Number|Object|JSON))\])?/i', $value, $match);
                if (!empty($match['alias'])) {
                    $stack[] = $this->columnQuote($match['column']) . ' AS ' . $this->columnQuote($match['alias']);
                    $columns[$key] = $match['alias'];
                    if (!empty($match['type'])) {
                        $columns[$key] .= ' [' . $match['type'] . ']';
                    }
                } else {
                    $stack[] = $this->columnQuote($match['column']);
                }
            }
        }
        return implode(',', $stack);
    }

    protected function arrayQuote($array)
    {
        $stack = [];
        foreach ($array as $value) {
            $stack[] = is_int($value) ? $value : $this->quote($value);
        }
        return implode(',', $stack);
    }

    protected function innerConjunct($data, $map, $conjunctor, $outer_conjunctor)
    {
        $stack = [];
        foreach ($data as $value) {
            $stack[] = '(' . $this->dataImplode($value, $map, $conjunctor) . ')';
        }
        return implode($outer_conjunctor . ' ', $stack);
    }

    protected function dataImplode($data, &$map, $conjunctor)
    {
        $stack = [];
        foreach ($data as $key => $value) {
            $type = gettype($value);
            if ($type === 'array' && preg_match("/^(AND|OR)(\s+#.*)?$/", $key, $relation_match)) {
                $relationship = $relation_match[1];
                $stack[] = $value !== array_keys(array_keys($value)) ?
                    '(' . $this->dataImplode($value, $map, ' ' . $relationship) . ')' :
                    '(' . $this->innerConjunct($value, $map, ' ' . $relationship, $conjunctor) . ')';
                continue;
            }
            $map_key = $this->mapKey();
            if (is_int($key) &&
                preg_match('/([a-zA-Z0-9_\.]+)\[(?<operator>\>\=?|\<\=?|\!?\=)\]([a-zA-Z0-9_\.]+)/i', $value, $match)
            ) {
                $stack[] = $this->columnQuote($match[1]) . ' ' . $match['operator'] . ' ' . $this->columnQuote($match[3]);
            } else {
                preg_match('/([a-zA-Z0-9_\.]+)(\[(?<operator>\>\=?|\<\=?|\!|\<\>|\>\<|\!?~|REGEXP)\])?/i', $key, $match);
                $column = $this->columnQuote($match[1]);
                if (isset($match['operator'])) {
                    $operator = $match['operator'];
                    if (in_array($operator, ['>', '>=', '<', '<='])) {
                        $condition = $column . ' ' . $operator . ' ';
                        if (is_numeric($value)) {
                            $condition .= $map_key;
                            $map[$map_key] = [$value, is_float($value) ? PDO::PARAM_STR : PDO::PARAM_INT];
                        } elseif ($raw = $this->buildRaw($value, $map)) {
                            $condition .= $raw;
                        } else {
                            $condition .= $map_key;
                            $map[$map_key] = [$value, PDO::PARAM_STR];
                        }
                        $stack[] = $condition;
                    } elseif ($operator === '!') {
                        switch ($type) {
                            case 'NULL':
                                $stack[] = $column . ' IS NOT NULL';
                                break;
                            case 'array':
                                $placeholders = [];
                                foreach ($value as $index => $item) {
                                    $stack_key = $map_key . $index . '_i';
                                    $placeholders[] = $stack_key;
                                    $map[$stack_key] = $this->typeMap($item, gettype($item));
                                }
                                $stack[] = $column . ' NOT IN (' . implode(', ', $placeholders) . ')';
                                break;
                            case 'object':
                                if ($raw = $this->buildRaw($value, $map)) {
                                    $stack[] = $column . ' != ' . $raw;
                                }
                                break;
                            case 'integer':
                            case 'double':
                            case 'boolean':
                            case 'string':
                                $stack[] = $column . ' != ' . $map_key;
                                $map[$map_key] = $this->typeMap($value, $type);
                                break;
                        }
                    } elseif ($operator === '~' || $operator === '!~') {
                        if ($type !== 'array') {
                            $value = [$value];
                        }
                        $connector = ' OR ';
                        $data = array_values($value);
                        if (is_array($data[0])) {
                            if (isset($value['AND']) || isset($value['OR'])) {
                                $connector = ' ' . array_keys($value)[0] . ' ';
                                $value = $data[0];
                            }
                        }
                        $like_clauses = [];
                        foreach ($value as $index => $item) {
                            $item = strval($item);
                            if (!preg_match('/(\[.+\]|[\*\?\!\%#^-_]|%.+|.+%)/', $item)) {
                                $item = '%' . $item . '%';
                            }
                            $like_clauses[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $map_key . 'L' . $index;
                            $map[$map_key . 'L' . $index] = [$item, PDO::PARAM_STR];
                        }
                        $stack[] = '(' . implode($connector, $like_clauses) . ')';
                    } elseif ($operator === '<>' || $operator === '><') {
                        if ($type === 'array') {
                            if ($operator === '><') {
                                $column .= ' NOT';
                            }
                            $stack[] = '(' . $column . ' BETWEEN ' . $map_key . 'a AND ' . $map_key . 'b)';
                            $data_type = (is_numeric($value[0]) && is_numeric($value[1])) ? PDO::PARAM_INT : PDO::PARAM_STR;
                            $map[$map_key . 'a'] = [$value[0], $data_type];
                            $map[$map_key . 'b'] = [$value[1], $data_type];
                        }
                    } elseif ($operator === 'REGEXP') {
                        $stack[] = $column . ' REGEXP ' . $map_key;
                        $map[$map_key] = [$value, PDO::PARAM_STR];
                    }
                } else {
                    switch ($type) {
                        case 'NULL':
                            $stack[] = $column . ' IS NULL';
                            break;
                        case 'array':
                            $placeholders = [];
                            foreach ($value as $index => $item) {
                                $stack_key = $map_key . $index . '_i';
                                $placeholders[] = $stack_key;
                                $map[$stack_key] = $this->typeMap($item, gettype($item));
                            }
                            $stack[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
                            break;
                        case 'object':
                            if ($raw = $this->buildRaw($value, $map)) {
                                $stack[] = $column . ' = ' . $raw;
                            }
                            break;
                        case 'integer':
                        case 'double':
                        case 'boolean':
                        case 'string':
                            $stack[] = $column . ' = ' . $map_key;
                            $map[$map_key] = $this->typeMap($value, $type);
                            break;
                    }
                }
            }
        }
        return implode($conjunctor . ' ', $stack);
    }


    protected function whereClause($where, &$map)
    {
        $where_clause = '';
        if (is_array($where)) {
            $where_keys = array_keys($where);
            $conditions = array_diff_key($where, array_flip(
                ['GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH']
            ));
            if (!empty($conditions)) {
                $where_clause = ' WHERE ' . $this->dataImplode($conditions, $map, ' AND');
            }
            if (isset($where['MATCH']) && $this->type === 'mysql') {
                $MATCH = $where['MATCH'];
                if (is_array($MATCH) && isset($MATCH['columns'], $MATCH['keyword'])) {
                    $mode = '';
                    $mode_array = [
                        'natural' => 'IN NATURAL LANGUAGE MODE',
                        'natural+query' => 'IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION',
                        'boolean' => 'IN BOOLEAN MODE',
                        'query' => 'WITH QUERY EXPANSION'
                    ];
                    if (isset($MATCH['mode'], $mode_array[$MATCH['mode']])) {
                        $mode = ' ' . $mode_array[$MATCH['mode']];
                    }
                    $columns = implode(', ', array_map([$this, 'columnQuote'], $MATCH['columns']));
                    $map_key = $this->mapKey();
                    $map[$map_key] = [$MATCH['keyword'], PDO::PARAM_STR];
                    $where_clause .= ($where_clause !== '' ? ' AND ' : ' WHERE') . ' MATCH (' . $columns . ') AGAINST (' . $map_key . $mode . ')';
                }
            }

            if (isset($where['GROUP'])) {
                $GROUP = $where['GROUP'];
                if (is_array($GROUP)) {
                    $stack = [];
                    foreach ($GROUP as $column => $value) {
                        $stack[] = $this->columnQuote($value);
                    }
                    $where_clause .= ' GROUP BY ' . implode(',', $stack);
                } elseif ($raw = $this->buildRaw($GROUP, $map)) {
                    $where_clause .= ' GROUP BY ' . $raw;
                } else {
                    $where_clause .= ' GROUP BY ' . $this->columnQuote($GROUP);
                }

                if (isset($where['HAVING'])) {
                    if ($raw = $this->buildRaw($where['HAVING'], $map)) {
                        $where_clause .= ' HAVING ' . $raw;
                    } else {
                        $where_clause .= ' HAVING ' . $this->dataImplode($where['HAVING'], $map, ' AND');
                    }
                }
            }

            if (isset($where['ORDER'])) {
                $ORDER = $where['ORDER'];
                if (is_array($ORDER)) {
                    $stack = [];
                    foreach ($ORDER as $column => $value) {
                        if (is_array($value)) {
                            $stack[] = 'FIELD(' . $this->columnQuote($column) . ', ' . $this->arrayQuote($value) . ')';
                        } elseif ($value === 'ASC' || $value === 'DESC') {
                            $stack[] = $this->columnQuote($column) . ' ' . $value;
                        } elseif (is_int($column)) {
                            $stack[] = $this->columnQuote($value);
                        }
                    }
                    $where_clause .= ' ORDER BY ' . implode(',', $stack);
                } elseif ($raw = $this->buildRaw($ORDER, $map)) {
                    $where_clause .= ' ORDER BY ' . $raw;
                } else {
                    $where_clause .= ' ORDER BY ' . $this->columnQuote($ORDER);
                }

                if (isset($where['LIMIT']) && in_array($this->type, ['oracle', 'mssql'])) {
                    $LIMIT = $where['LIMIT'];
                    if (is_numeric($LIMIT)) {
                        $LIMIT = [0, $LIMIT];
                    }
                    if (is_array($LIMIT) && is_numeric($LIMIT[0]) && is_numeric($LIMIT[1])) {
                        $where_clause .= ' OFFSET ' . $LIMIT[0] . ' ROWS FETCH NEXT ' . $LIMIT[1] . ' ROWS ONLY';
                    }
                }
            }

            if (isset($where['LIMIT']) && !in_array($this->type, ['oracle', 'mssql'])) {
                $LIMIT = $where['LIMIT'];
                if (is_numeric($LIMIT)) {
                    $where_clause .= ' LIMIT ' . $LIMIT;
                } elseif (is_array($LIMIT) && is_numeric($LIMIT[0]) && is_numeric($LIMIT[1])) {
                    $where_clause .= ' LIMIT ' . $LIMIT[1] . ' OFFSET ' . $LIMIT[0];
                }
            }
        } elseif ($raw = $this->buildRaw($where, $map)) {
            $where_clause .= ' ' . $raw;
        }

        return $where_clause;
    }


    protected function selectContext($table, &$map, $join, &$columns = null, $where = null, $column_fn = null)
    {
        preg_match('/(?<table>[a-zA-Z0-9_]+)\s*\((?<alias>[a-zA-Z0-9_]+)\)/i', $table, $table_match);

        if (isset($table_match['table'], $table_match['alias'])) {
            $table = $this->tableQuote($table_match['table']);
            $table_query = $table . ' AS ' . $this->tableQuote($table_match['alias']);
        } else {
            $table = $this->tableQuote($table);
            $table_query = $table;
        }

        $is_join = false;
        $join_key = is_array($join) ? array_keys($join) : null;

        if (isset($join_key[0]) && strpos($join_key[0], '[') === 0) {
            $is_join = true;
            $table_query .= ' ' . $this->buildJoin($table, $join);
        } else {
            if (is_null($columns)) {
                if (!is_null($where) || (is_array($join) && isset($column_fn))) {
                    $where = $join;
                    $columns = null;
                } else {
                    $where = null;
                    $columns = $join;
                }
            } else {
                $where = $columns;
                $columns = $join;
            }
        }

        if (isset($column_fn)) {
            if ($column_fn === 1) {
                $column = '1';
                if (is_null($where)) {
                    $where = $columns;
                }
            } elseif ($raw = $this->buildRaw($column_fn, $map)) {
                $column = $raw;
            } else {
                if (empty($columns) || $this->isRaw($columns)) {
                    $columns = '*';
                    $where = $join;
                }

                $column = $column_fn . '(' . $this->columnPush($columns, $map, true) . ')';
            }
        } else {
            $column = $this->columnPush($columns, $map, true, $is_join);
        }
        return 'SELECT ' . $column . ' FROM ' . $table_query . $this->whereClause($where, $map);
    }

    protected function buildJoin($table, $join)
    {
        $table_join = [];
        $join_array = [
            '>' => 'LEFT',
            '<' => 'RIGHT',
            '<>' => 'FULL',
            '><' => 'INNER'
        ];

        foreach ($join as $sub_table => $relation) {
            preg_match('/(\[(?<join>\<\>?|\>\<?)\])?(?<table>[a-zA-Z0-9_]+)\s?(\((?<alias>[a-zA-Z0-9_]+)\))?/', $sub_table, $match);

            if ($match['join'] !== '' && $match['table'] !== '') {
                if (is_string($relation)) {
                    $relation = 'USING ("' . $relation . '")';
                }

                if (is_array($relation)) {
                    // For ['column1', 'column2']
                    if (isset($relation[0])) {
                        $relation = 'USING ("' . implode('", "', $relation) . '")';
                    } else {
                        $joins = [];

                        foreach ($relation as $key => $value) {
                            $joins[] = (
                                strpos($key, '.') > 0 ?
                                    // For ['tableB.column' => 'column']
                                    $this->columnQuote($key) :
                                    // For ['column1' => 'column2']
                                    $table . '."' . $key . '"'
                                ) .
                                ' = ' .
                                $this->tableQuote(isset($match['alias']) ? $match['alias'] : $match['table']) . '."' . $value . '"';
                        }
                        $relation = 'ON ' . implode(' AND ', $joins);
                    }
                }
                $table_name = $this->tableQuote($match['table']) . ' ';
                if (isset($match['alias'])) {
                    $table_name .= 'AS ' . $this->tableQuote($match['alias']) . ' ';
                }
                $table_join[] = $join_array[$match['join']] . ' JOIN ' . $table_name . $relation;
            }
        }

        return implode(' ', $table_join);
    }

    protected function columnMap($columns, &$stack, $root)
    {
        if ($columns === '*') {
            return $stack;
        }

        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                preg_match('/([a-zA-Z0-9_]+\.)?(?<column>[a-zA-Z0-9_]+)(?:\s*\((?<alias>[a-zA-Z0-9_]+)\))?(?:\s*\[(?<type>(?:String|Bool|Int|Number|Object|JSON))\])?/i', $value, $key_match);

                $column_key = !empty($key_match['alias']) ?
                    $key_match['alias'] :
                    $key_match['column'];

                if (isset($key_match['type'])) {
                    $stack[$value] = [$column_key, $key_match['type']];
                } else {
                    $stack[$value] = [$column_key, 'String'];
                }
            } elseif ($this->isRaw($value)) {
                preg_match('/([a-zA-Z0-9_]+\.)?(?<column>[a-zA-Z0-9_]+)(\s*\[(?<type>(String|Bool|Int|Number))\])?/i', $key, $key_match);

                $column_key = $key_match['column'];

                if (isset($key_match['type'])) {
                    $stack[$key] = [$column_key, $key_match['type']];
                } else {
                    $stack[$key] = [$column_key, 'String'];
                }
            } elseif (!is_int($key) && is_array($value)) {
                if ($root && count(array_keys($columns)) === 1) {
                    $stack[$key] = [$key, 'String'];
                }
                $this->columnMap($value, $stack, false);
            }
        }

        return $stack;
    }

    protected function dataMap($data, $columns, $column_map, &$stack, $root, &$result)
    {
        if ($root) {
            $columns_key = array_keys($columns);

            if (count($columns_key) === 1 && is_array($columns[$columns_key[0]])) {
                $index_key = array_keys($columns)[0];
                $data_key = preg_replace("/^[a-zA-Z0-9_]+\./i", "", $index_key);
                $current_stack = [];
                foreach ($data as $item) {
                    $this->dataMap($data, $columns[$index_key], $column_map, $current_stack, false, $result);
                    $index = $data[$data_key];
                    $result[$index] = $current_stack;
                }
            } else {
                $current_stack = [];
                $this->dataMap($data, $columns, $column_map, $current_stack, false, $result);
                $result[] = $current_stack;
            }
            return;
        }

        foreach ($columns as $key => $value) {
            $isRaw = $this->isRaw($value);
            if (is_int($key) || $isRaw) {
                $map = $column_map[$isRaw ? $key : $value];
                $column_key = $map[0];
                $item = $data[$column_key];
                if (isset($map[1])) {
                    if ($isRaw && in_array($map[1], ['Object', 'JSON'])) {
                        continue;
                    }
                    if (is_null($item)) {
                        $stack[$column_key] = null;
                        continue;
                    }
                    switch ($map[1]) {
                        case 'Number':
                            $stack[$column_key] = (double)$item;
                            break;
                        case 'Int':
                            $stack[$column_key] = (int)$item;
                            break;
                        case 'Bool':
                            $stack[$column_key] = (bool)$item;
                            break;
                        case 'Object':
                            $stack[$column_key] = unserialize($item);
                            break;
                        case 'JSON':
                            $stack[$column_key] = json_decode($item, true);
                            break;
                        case 'String':
                            $stack[$column_key] = $item;
                            break;
                    }
                } else {
                    $stack[$column_key] = $item;
                }
            } else {
                $current_stack = [];
                $this->dataMap($data, $value, $column_map, $current_stack, false, $result);
                $stack[$key] = $current_stack;
            }
        }
    }

    public function create($table, $columns, $options = null)
    {
        $stack = [];
        $tableName = $this->prefix . $table;
        foreach ($columns as $name => $definition) {
            if (is_int($name)) {
                $stack[] = preg_replace('/\<([a-zA-Z0-9_]+)\>/i', '"$1"', $definition);
            } elseif (is_array($definition)) {
                $stack[] = $name . ' ' . implode(' ', $definition);
            } elseif (is_string($definition)) {
                $stack[] = $name . ' ' . $this->query($definition);
            }
        }
        $table_option = '';
        if (is_array($options)) {
            $option_stack = [];
            foreach ($options as $key => $value) {
                if (is_string($value) || is_int($value)) {
                    $option_stack[] = "$key = $value";
                }
            }
            $table_option = ' ' . implode(', ', $option_stack);
        } elseif (is_string($options)) {
            $table_option = ' ' . $options;
        }
        $sql = $this->exec("CREATE TABLE IF NOT EXISTS $tableName (" . implode(', ', $stack) . ")$table_option");
        if (!$sql) {
            return false;
        }
        return $this->getConnection()->exec($sql);
    }

    public function drop($table)
    {
        $tableName = $this->tableQuote($table);
        $sql = $this->exec("DROP TABLE IF EXISTS $tableName");
        if (!$sql) {
            return false;
        }
        return $this->getConnection()->exec($sql);
    }

    public function select($table, $join, $columns = null, $where = null)
    {
        $map = [];
        $result = [];
        $column_map = [];
        $column = $where === null ? $join : $columns;
        $is_single = (is_string($column) && $column !== '*');
        $querySql = $this->exec($this->selectContext($table, $map, $join, $columns, $where), $map);
        $this->columnMap($columns, $column_map, true);
        $query = $this->getConnection();
        if ($columns === '*') {
            return $query->query($querySql);
        }
        $res = $query->query($querySql);
        foreach ($res as $v) {
            $current_stack = [];
            $this->dataMap($v, $columns, $column_map, $current_stack, true, $result);
        }
        if ($is_single) {
            $single_result = [];
            $result_key = $column_map[$column][0];
            foreach ($result as $item) {
                $single_result[] = $item[$result_key];
            }
            return $single_result;
        }
        return $result;
    }

    public function insert($table, $datas)
    {
        $stack = [];
        $columns = [];
        $fields = [];
        $map = [];

        if (!isset($datas[0])) {
            $datas = [$datas];
        }

        foreach ($datas as $data) {
            foreach ($data as $key => $value) {
                $columns[] = $key;
            }
        }

        $columns = array_unique($columns);

        foreach ($datas as $data) {
            $values = [];

            foreach ($columns as $key) {
                if ($raw = $this->buildRaw($data[$key], $map)) {
                    $values[] = $raw;
                    continue;
                }

                $map_key = $this->mapKey();

                $values[] = $map_key;

                if (!isset($data[$key])) {
                    $map[$map_key] = [null, PDO::PARAM_NULL];
                } else {
                    $value = $data[$key];

                    $type = gettype($value);

                    switch ($type) {
                        case 'array':
                            $map[$map_key] = [
                                strpos($key, '[JSON]') === strlen($key) - 6 ?
                                    json_encode($value) :
                                    serialize($value),
                                PDO::PARAM_STR
                            ];
                            break;

                        case 'object':
                            $value = serialize($value);

                        case 'NULL':
                        case 'resource':
                        case 'boolean':
                        case 'integer':
                        case 'double':
                        case 'string':
                            $map[$map_key] = $this->typeMap($value, $type);
                            break;
                    }
                }
            }

            $stack[] = '(' . implode(', ', $values) . ')';
        }

        foreach ($columns as $key) {
            $fields[] = $this->columnQuote(preg_replace("/(\s*\[JSON\]$)/i", '', $key));
        }
        $sql = $this->exec('INSERT INTO ' . $this->tableQuote($table) . ' (' . implode(', ', $fields) . ') VALUES ' . implode(', ', $stack), $map);
        if(!$sql){
            return false;
        }
        return $this->getConnection()->insert($sql);
    }


    public function insertIgnore($table, $datas)
    {
        $stack = [];
        $columns = [];
        $fields = [];
        $map = [];

        if (!isset($datas[0])) {
            $datas = [$datas];
        }

        foreach ($datas as $data) {
            foreach ($data as $key => $value) {
                $columns[] = $key;
            }
        }

        $columns = array_unique($columns);

        foreach ($datas as $data) {
            $values = [];

            foreach ($columns as $key) {
                if ($raw = $this->buildRaw($data[$key], $map)) {
                    $values[] = $raw;
                    continue;
                }

                $map_key = $this->mapKey();

                $values[] = $map_key;

                if (!isset($data[$key])) {
                    $map[$map_key] = [null, PDO::PARAM_NULL];
                } else {
                    $value = $data[$key];

                    $type = gettype($value);

                    switch ($type) {
                        case 'array':
                            $map[$map_key] = [
                                strpos($key, '[JSON]') === strlen($key) - 6 ?
                                    json_encode($value) :
                                    serialize($value),
                                PDO::PARAM_STR
                            ];
                            break;

                        case 'object':
                            $value = serialize($value);

                        case 'NULL':
                        case 'resource':
                        case 'boolean':
                        case 'integer':
                        case 'double':
                        case 'string':
                            $map[$map_key] = $this->typeMap($value, $type);
                            break;
                    }
                }
            }
            $stack[] = '(' . implode(', ', $values) . ')';
        }
        foreach ($columns as $key) {
            $fields[] = $this->columnQuote(preg_replace("/(\s*\[JSON\]$)/i", '', $key));
        }
        $sql = $this->exec('INSERT IGNORE INTO ' . $this->tableQuote($table) . ' (' . implode(', ', $fields) . ') VALUES ' . implode(', ', $stack), $map);
        if(!$sql){
            return false;
        }
        return $this->getConnection()->insert($sql);
    }

    public function update($table, $data, $where = null)
    {
        $fields = [];
        $map = [];

        foreach ($data as $key => $value) {
            $column = $this->columnQuote(preg_replace("/(\s*\[(JSON|\+|\-|\*|\/)\]$)/i", '', $key));

            if ($raw = $this->buildRaw($value, $map)) {
                $fields[] = $column . ' = ' . $raw;
                continue;
            }

            $map_key = $this->mapKey();

            preg_match('/(?<column>[a-zA-Z0-9_]+)(\[(?<operator>\+|\-|\*|\/)\])?/i', $key, $match);

            if (isset($match['operator'])) {
                if (is_numeric($value)) {
                    $fields[] = $column . ' = ' . $column . ' ' . $match['operator'] . ' ' . $value;
                }
            } else {
                $fields[] = $column . ' = ' . $map_key;

                $type = gettype($value);

                switch ($type) {
                    case 'array':
                        $map[$map_key] = [
                            strpos($key, '[JSON]') === strlen($key) - 6 ?
                                json_encode($value) :
                                serialize($value),
                            PDO::PARAM_STR
                        ];
                        break;

                    case 'object':
                        $value = serialize($value);

                    case 'NULL':
                    case 'resource':
                    case 'boolean':
                    case 'integer':
                    case 'double':
                    case 'string':
                        $map[$map_key] = $this->typeMap($value, $type);
                        break;
                }
            }
        }
        $sql = $this->exec('UPDATE ' . $this->tableQuote($table) . ' SET ' . implode(', ', $fields) . $this->whereClause($where, $map), $map);
        if(!$sql){
            return false;
        }
        return $this->getConnection()->execute($sql);
    }

    public function delete($table, $where)
    {
        $map = [];
        $sql = $this->exec('DELETE FROM ' . $this->tableQuote($table) . $this->whereClause($where, $map), $map);
        if(!$sql){
            return false;
        }
        return $this->getConnection()->execute($sql);
    }

    public function replace($table, $columns, $where = null)
    {
        if (!is_array($columns) || empty($columns)) {
            return false;
        }

        $map = [];
        $stack = [];

        foreach ($columns as $column => $replacements) {
            if (is_array($replacements)) {
                foreach ($replacements as $old => $new) {
                    $map_key = $this->mapKey();

                    $stack[] = $this->columnQuote($column) . ' = REPLACE(' . $this->columnQuote($column) . ', ' . $map_key . 'a, ' . $map_key . 'b)';

                    $map[$map_key . 'a'] = [$old, PDO::PARAM_STR];
                    $map[$map_key . 'b'] = [$new, PDO::PARAM_STR];
                }
            }
        }

        if (!empty($stack)) {
            $sql = $this->exec('UPDATE ' . $this->tableQuote($table) . ' SET ' . implode(', ', $stack) . $this->whereClause($where, $map), $map);
            if(!$sql){
                return false;
            }
            return $this->getConnection()->execute($sql);
        }

        return false;
    }

    public function get($table, $join = null, $columns = null, $where = null)
    {
        $map = [];
        $result = [];
        $column_map = [];
        $current_stack = [];

        if ($where === null) {
            $column = $join;
            unset($columns['LIMIT']);
        } else {
            $column = $columns;
            unset($where['LIMIT']);
        }
        $is_single = (is_string($column) && $column !== '*');
        $sql = $this->exec($this->selectContext($table, $map, $join, $columns, $where) . ' LIMIT 1', $map);
        if (!$sql) {
            return false;
        }
        $data = $this->getConnection()->query($sql);

        if (isset($data[0])) {
            if ($column === '*') {
                return $data[0];
            }
            $this->columnMap($columns, $column_map, true);
            $this->dataMap($data[0], $columns, $column_map, $current_stack, true, $result);

            if ($is_single) {
                return $result[0][$column_map[$column][0]];
            }

            return $result[0];
        }
    }

    public function has($table, $join, $where = null)
    {
        $map = [];
        $column = null;
        $sql = $this->exec('SELECT EXISTS(' . $this->selectContext($table, $map, $join, $column, $where, 1) . ')', $map);
        if (!$sql) {
            return false;
        }
        $result = 0;
        $res = $this->getConnection()->fetch($sql);
        if ($res) {
            $result = $res['c'];
        }
        return $result === '1' || $result === 1 || $result === true;
    }

    public function rand($table, $join = null, $columns = null, $where = null)
    {
        $type = $this->type;
        $order = 'RANDOM()';
        if ($type === 'mysql') {
            $order = 'RAND()';
        } elseif ($type === 'mssql') {
            $order = 'NEWID()';
        }
        $order_raw = $this->raw($order);
        if ($where === null) {
            if ($columns === null) {
                $columns = [
                    'ORDER' => $order_raw
                ];
            } else {
                $column = $join;
                unset($columns['ORDER']);

                $columns['ORDER'] = $order_raw;
            }
        } else {
            unset($where['ORDER']);

            $where['ORDER'] = $order_raw;
        }

        return $this->select($table, $join, $columns, $where);
    }

    private function aggregate($type, $table, $join = null, $column = null, $where = null)
    {
        $map = [];
        $sql = $this->exec($this->selectContext($table, $map, $join, $column, $where, strtoupper($type)), $map);

        if (!$sql) {
            return false;
        }
        $res = $this->getConnection()->fetch($sql);
        $number = 0;
        foreach ($res as $v) {
            $number = $v;
            break;
        }
        return is_numeric($number) ? $number + 0 : $number;
    }

    public function count($table, $join = null, $column = null, $where = null)
    {
        return $this->aggregate('count', $table, $join, $column, $where);
    }

    public function avg($table, $join, $column = null, $where = null)
    {
        return $this->aggregate('avg', $table, $join, $column, $where);
    }

    public function max($table, $join, $column = null, $where = null)
    {
        return $this->aggregate('max', $table, $join, $column, $where);
    }

    public function min($table, $join, $column = null, $where = null)
    {
        return $this->aggregate('min', $table, $join, $column, $where);
    }

    public function sum($table, $join, $column = null, $where = null)
    {
        return $this->aggregate('sum', $table, $join, $column, $where);
    }

    public function action($actions)
    {
        if (is_callable($actions)) {
            $this->getConnection()->beginTransaction();
            try {
                $result = $actions($this);
                if ($result === false) {
                    $this->getConnection()->rollback();
                } else {
                    $this->getConnection()->commit();
                }
            } catch (Exception $e) {
                $this->getConnection()->rollback();
                throw $e;
            }

            return $result;
        }

        return false;
    }

    public function debug()
    {
        $this->debug_mode = true;
        return $this;
    }


}