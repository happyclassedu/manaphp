<?php
namespace ManaPHP\Db;

use ManaPHP\Component;
use ManaPHP\Db\Query\Exception as QueryException;
use ManaPHP\Di;
use ManaPHP\Utility\Text;

/**
 * Class ManaPHP\Mvc\Model\QueryBuilder
 *
 * @package queryBuilder
 *
 * @property \ManaPHP\Cache\AdapterInterface  $modelsCache
 * @property \ManaPHP\Paginator               $paginator
 * @property \ManaPHP\Mvc\DispatcherInterface $dispatcher
 */
class Query extends Component implements QueryInterface
{
    /**
     * @var array
     */
    protected $_columns;

    /**
     * @var array
     */
    protected $_tables = [];

    /**
     * @var array
     */
    protected $_joins = [];

    /**
     * @var array
     */
    protected $_conditions = [];

    /**
     * @var string
     */
    protected $_group;

    /**
     * @var array
     */
    protected $_having;

    /**
     * @var string
     */
    protected $_order;

    /**
     * @var string|callable
     */
    protected $_index;

    /**
     * @var int
     */
    protected $_limit = 0;

    /**
     * @var int
     */
    protected $_offset = 0;

    /**
     * @var bool
     */
    protected $_forUpdate;

    /**
     * @var array
     */
    protected $_bind = [];

    /**
     * @var bool
     */
    protected $_distinct;

    /**
     * @var int
     */
    protected $_hiddenParamNumber = 0;

    /**
     * @var array
     */
    protected $_union = [];

    /**
     * @var string
     */
    protected $_sql;

    /**
     * @var int|array
     */
    protected $_cacheOptions;

    /**
     * @var \ManaPHP\DbInterface
     */
    protected $_db;

    /**
     * \ManaPHP\Mvc\Model\Query\Builder constructor
     *
     *<code>
     * $params = array(
     *    'models'     => array('Users'),
     *    'columns'    => array('id', 'name', 'status'),
     *    'conditions' => array(
     *        array(
     *            "created > :min: AND created < :max:",
     *            array("min" => '2013-01-01',   'max' => '2015-01-01'),
     *            array("min" => PDO::PARAM_STR, 'max' => PDO::PARAM_STR),
     *        ),
     *    ),
     *    // or 'conditions' => "created > '2013-01-01' AND created < '2015-01-01'",
     *    'group'      => array('id', 'name'),
     *    'having'     => "name = 'lily'",
     *    'order'      => array('name', 'id'),
     *    'limit'      => 20,
     *    'offset'     => 20,
     *    // or 'limit' => array(20, 20),
     *);
     *$queryBuilder = new \ManaPHP\Mvc\Model\Query\Builder($params);
     *</code>
     *
     * @param \ManaPHP\DbInterface|string $db
     */
    public function __construct($db = null)
    {
        $this->_db = $db;
    }

    /**
     * @param \ManaPHP\DbInterface|string $db
     *
     * @return static
     */
    public function setDb($db)
    {
        $this->_db = $db;

        return $this;
    }

    /**
     * Sets SELECT DISTINCT / SELECT ALL flag
     *
     * @param bool $distinct
     *
     * @return static
     */
    public function distinct($distinct = true)
    {
        $this->_distinct = $distinct;

        return $this;
    }

    /**
     * @param string|array $columns
     *
     * @return static
     */
    public function select($columns)
    {
        if (is_string($columns)) {
            $columns = str_replace(["\t", "\r", "\n"], '', $columns);
            if (strpos($columns, '[') === false && strpos($columns, '(') === false) {
                $columns = preg_replace('#\w+#', '[\\0]', $columns);
                $columns = str_ireplace('[as]', 'AS', $columns);
                $columns = preg_replace('#\s+#', ' ', $columns);
            }

            $this->_columns = $columns;
        } else {
            $r = '';
            foreach ($columns as $k => $v) {
                if (strpos($v, '[') === false && strpos($v, '(') === false) {
                    if (is_int($k)) {
                        $r .= preg_replace('#\w+#', '[\\0]', $v) . ', ';
                    } else {
                        $r .= preg_replace('#\w+#', '[\\0]', $v) . ' AS [' . $k . '], ';
                    }
                } else {
                    if (is_int($k)) {
                        $r .= $v . ', ';
                    } else {
                        $r .= $v . ' AS [' . $k . '], ';
                    }
                }
            }
            $this->_columns = substr($r, 0, -2);
        }

        return $this;
    }

    /**
     * @param array $expr
     *
     * @return static
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function aggregate($expr)
    {
        $columns = '';

        foreach ($expr as $k => $v) {
            if (is_int($k)) {
                $columns .= '[' . $v . '], ';
            } else {
                if (preg_match('#^(\w+)\(([\w]+)\)$#', $v, $matches) === 1) {
                    $columns .= strtoupper($matches[1]) . '([' . $matches[2] . '])';
                } else {
                    $columns .= $v;
                }

                $columns .= ' AS [' . $k . '], ';
            }
        }

        $this->_columns = substr($columns, 0, -2);

        return $this;
    }

    /**
     *
     *<code>
     *    $builder->from('Robots');
     *</code>
     *
     * @param string $table
     * @param string $alias
     *
     * @return static
     */
    public function from($table, $alias = null)
    {
        if (is_string($alias)) {
            $this->_tables[$alias] = $table;
        } else {
            $this->_tables[] = $table;
        }

        if ($this->_db === null && $table instanceof Query) {
            $this->_db = $table->_db;
        }

        return $this;
    }

    /**
     * Adds a join to the query
     *
     *<code>
     *    $builder->join('Robots');
     *    $builder->join('Robots', 'r.id = RobotsParts.robots_id');
     *    $builder->join('Robots', 'r.id = RobotsParts.robots_id', 'r');
     *    $builder->join('Robots', 'r.id = RobotsParts.robots_id', 'r', 'LEFT');
     *</code>
     *
     * @param string|\ManaPHP\Db\QueryInterface $table
     * @param string                            $condition
     * @param string                            $alias
     * @param string                            $type
     *
     * @return static
     */
    public function join($table, $condition = null, $alias = null, $type = null)
    {
        if (strpos($condition, '[') === false && strpos($condition, '(') === false) {
            $condition = preg_replace('#\w+#', '[\\0]', $condition);
        }

        $this->_joins[] = [$table, $condition, $alias, $type];

        return $this;
    }

    /**
     * Adds a INNER join to the query
     *
     *<code>
     *    $builder->innerJoin('Robots');
     *    $builder->innerJoin('Robots', 'r.id = RobotsParts.robots_id');
     *    $builder->innerJoin('Robots', 'r.id = RobotsParts.robots_id', 'r');
     *</code>
     *
     * @param string|\ManaPHP\Db\QueryInterface $table
     * @param string                            $condition
     * @param string                            $alias
     *
     * @return static
     */
    public function innerJoin($table, $condition = null, $alias = null)
    {
        return $this->join($table, $condition, $alias, 'INNER');
    }

    /**
     * Adds a LEFT join to the query
     *
     *<code>
     *    $builder->leftJoin('Robots', 'r.id = RobotsParts.robots_id', 'r');
     *</code>
     *
     * @param string|\ManaPHP\Db\QueryInterface $table
     * @param string                            $condition
     * @param string                            $alias
     *
     * @return static
     */
    public function leftJoin($table, $condition = null, $alias = null)
    {
        return $this->join($table, $condition, $alias, 'LEFT');
    }

    /**
     * Adds a RIGHT join to the query
     *
     *<code>
     *    $builder->rightJoin('Robots', 'r.id = RobotsParts.robots_id', 'r');
     *</code>
     *
     * @param string|\ManaPHP\Db\QueryInterface $table
     * @param string                            $condition
     * @param string                            $alias
     *
     * @return static
     */
    public function rightJoin($table, $condition = null, $alias = null)
    {
        return $this->join($table, $condition, $alias, 'RIGHT');
    }

    /**
     * Appends a condition to the current conditions using a AND operator
     *
     *<code>
     *    $builder->andWhere('name = "Peter"');
     *    $builder->andWhere('name = :name: AND id > :id:', array('name' => 'Peter', 'id' => 100));
     *</code>
     *
     * @param string|array           $condition
     * @param int|float|string|array $bind
     *
     * @return static
     */
    public function where($condition, $bind = [])
    {
        if (is_array($condition)) {
            /** @noinspection ForeachSourceInspection */
            foreach ($condition as $k => $v) {
                $this->where($k, $v);
            }
        } else {
            if (is_scalar($bind)) {
                preg_match('#^([\w\.]+)\s*(.*)$#', $condition, $matches);
                list(, $column, $op) = $matches;
                if ($op === '') {
                    $op = '=';
                }

                $bind_key = str_replace('.', '_', $column);
                $this->_conditions[] = preg_replace('#\w+#', '[\\0]', $column) . $op . ':' . $bind_key;
                $this->_bind[$bind_key] = $bind;
            } else {
                $this->_conditions[] = $condition;
                $this->_bind = array_merge($this->_bind, $bind);
            }
        }

        return $this;
    }

    /**
     * Appends a BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->betweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $expr
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function betweenWhere($expr, $min, $max)
    {
        $minKey = '_min_' . $this->_hiddenParamNumber;
        $maxKey = '_max_' . $this->_hiddenParamNumber;

        $this->_hiddenParamNumber++;

        if (strpos($expr, '[') === false && strpos($expr, '(') === false) {
            if (strpos($expr, '.') !== false) {
                $expr = '[' . str_replace('.', '].[', $expr) . ']';
            } else {
                $expr = '[' . $expr . ']';
            }
        }

        $this->_conditions[] = "$expr BETWEEN :$minKey AND :$maxKey";

        $this->_bind[$minKey] = $min;
        $this->_bind[$maxKey] = $max;

        return $this;
    }

    /**
     * Appends a NOT BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->notBetweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $expr
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function notBetweenWhere($expr, $min, $max)
    {
        $minKey = '_min_' . $this->_hiddenParamNumber;
        $maxKey = '_max_' . $this->_hiddenParamNumber;

        $this->_hiddenParamNumber++;

        if (strpos($expr, '[') === false && strpos($expr, '(') === false) {
            if (strpos($expr, '.') !== false) {
                $expr = '[' . str_replace('.', '].[', $expr) . ']';
            } else {
                $expr = '[' . $expr . ']';
            }
        }

        $this->_conditions[] = "$expr NOT BETWEEN :$minKey AND :$maxKey";

        $this->_bind[$minKey] = $min;
        $this->_bind[$maxKey] = $max;

        return $this;
    }

    /**
     * Appends an IN condition to the current conditions
     *
     *<code>
     *    $builder->inWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string                           $expr
     * @param array|\ManaPHP\Db\QueryInterface $values
     *
     * @return static
     */
    public function inWhere($expr, $values)
    {
        if ($values instanceof $this) {
            $this->where($expr . ' IN (' . $values->getSql() . ')');
            $this->_bind = array_merge($this->_bind, $values->getBind());
        } else {
            if (count($values) === 0) {
                $this->_conditions[] = '1=2';
            } else {
                if (strpos($expr, '[') === false && strpos($expr, '(') === false) {
                    if (strpos($expr, '.') !== false) {
                        $expr = '[' . str_replace('.', '].[', $expr) . ']';
                    } else {
                        $expr = '[' . $expr . ']';
                    }
                }

                $bindKeys = [];

                /** @noinspection ForeachSourceInspection */
                foreach ($values as $k => $value) {
                    $key = '_in_' . $this->_hiddenParamNumber . '_' . $k;
                    $bindKeys[] = ":$key";
                    $this->_bind[$key] = $value;
                }

                $this->_conditions[] = $expr . ' IN (' . implode(', ', $bindKeys) . ')';
            }

            $this->_hiddenParamNumber++;
        }

        return $this;
    }

    /**
     * Appends a NOT IN condition to the current conditions
     *
     *<code>
     *    $builder->notInWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string                           $expr
     * @param array|\ManaPHP\Db\QueryInterface $values
     *
     * @return static
     */
    public function notInWhere($expr, $values)
    {
        if ($values instanceof $this) {
            $this->where($expr . ' NOT IN (' . $values->getSql() . ')');
            $this->_bind = array_merge($this->_bind, $values->getBind());
        } else {
            if (count($values) !== 0) {
                if (strpos($expr, '[') === false && strpos($expr, '(') === false) {
                    if (strpos($expr, '.') !== false) {
                        $expr = '[' . str_replace('.', '].[', $expr) . ']';
                    } else {
                        $expr = '[' . $expr . ']';
                    }
                }

                $bindKeys = [];

                /** @noinspection ForeachSourceInspection */
                foreach ($values as $k => $value) {
                    $key = '_in_' . $this->_hiddenParamNumber . '_' . $k;
                    $bindKeys[] = ':' . $key;
                    $this->_bind[$key] = $value;
                }

                $this->_hiddenParamNumber++;

                $this->_conditions[] = $expr . ' NOT IN (' . implode(', ', $bindKeys) . ')';
            }
        }
        return $this;
    }

    /**
     * @param string|array $expr
     * @param string       $like
     *
     * @return static
     */
    public function likeWhere($expr, $like)
    {
        if (is_array($expr)) {
            $conditions = [];
            /** @noinspection ForeachSourceInspection */
            foreach ($expr as $column) {
                $key = str_replace('.', '_', $column);
                if (strpos($column, '.') !== false) {
                    $conditions[] = '[' . str_replace('.', '].[', $column) . ']' . ' LIKE :' . $key;
                } else {
                    $conditions[] = '[' . $column . '] LIKE :' . $key;
                }

                $this->_bind[$key] = $like;
            }

            $this->where(implode(' OR ', $conditions));
        } else {
            $key = str_replace('.', '_', $expr);

            if (strpos($expr, '[') === false && strpos($expr, '(') === false) {
                if (strpos($expr, '.') !== false) {
                    $expr = '[' . str_replace('.', '].[', $expr) . ']';
                } else {
                    $expr = '[' . $expr . ']';
                }
            }

            $this->_conditions[] = $expr . ' LIKE :' . $key;

            $this->_bind[$key] = $like;
        }

        return $this;
    }

    /**
     * Sets a ORDER BY condition clause
     *
     *<code>
     *    $builder->orderBy('Robots.name');
     *    $builder->orderBy(array('1', 'Robots.name'));
     *</code>
     *
     * @param string|array $orderBy
     *
     * @return static
     */
    public function orderBy($orderBy)
    {
        if (is_string($orderBy)) {
            if (strpos($orderBy, '[') === false && strpos($orderBy, '(') === false) {
                $orderBy = preg_replace('#\w+#', '[\\0]', $orderBy);
                $orderBy = str_ireplace(['[ASC]', '[DESC]'], ['ASC', 'DESC'], $orderBy);
            }
            $this->_order = $orderBy;
        } else {
            $r = '';
            /** @noinspection ForeachSourceInspection */
            foreach ($orderBy as $k => $v) {
                if (is_int($k)) {
                    $type = 'ASC';
                    $column = $v;
                } else {
                    $column = $k;
                    if (is_int($v)) {
                        $type = $v === SORT_ASC ? 'ASC' : 'DESC';
                    } else {
                        $type = strtoupper($v);
                    }
                }

                if (strpos($column, '[') === false && strpos($column, '(') === false) {
                    if (strpos($column, '.') !== false) {
                        $r .= '[' . str_replace('.', '].[', $column) . '] ' . $type . ', ';
                    } else {
                        $r .= '[' . $column . '] ' . $type . ', ';
                    }
                }
                $this->_order = substr($r, 0, -2);
            }
        }

        return $this;
    }

    /**
     * Sets a HAVING condition clause. You need to escape SQL reserved words using [ and ] delimiters
     *
     *<code>
     *    $builder->having('SUM(Robots.price) > 0');
     *</code>
     *
     * @param string|array $having
     * @param array        $bind
     *
     * @return static
     */
    public function having($having, $bind = [])
    {
        if (is_array($having)) {
            if (count($having) === 1) {
                $this->_having = $having[0];
            } else {
                $items = [];
                /** @noinspection ForeachSourceInspection */
                foreach ($having as $item) {
                    $items[] = '(' . $item . ')';
                }
                $this->_having = implode(' AND ', $items);
            }
        } else {
            $this->_having = $having;
        }

        if (count($bind) !== 0) {
            $this->_bind = array_merge($this->_bind, $bind);
        }

        return $this;
    }

    /**
     * Sets a FOR UPDATE clause
     *
     *<code>
     *    $builder->forUpdate(true);
     *</code>
     *
     * @param bool $forUpdate
     *
     * @return static
     */
    public function forUpdate($forUpdate = true)
    {
        $this->_forUpdate = (bool)$forUpdate;

        return $this;
    }

    /**
     * Sets a LIMIT clause, optionally a offset clause
     *
     *<code>
     *    $builder->limit(100);
     *    $builder->limit(100, 20);
     *</code>
     *
     * @param int $limit
     * @param int $offset
     *
     * @return static
     */
    public function limit($limit, $offset = 0)
    {
        $this->_limit = (int)$limit;
        $this->_offset = (int)$offset;

        return $this;
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return static
     */
    public function page($size, $page = 1)
    {
        return $this->limit($size, (max(1, $page) - 1) * $size);
    }

    /**
     * Sets a GROUP BY clause
     *
     *<code>
     *    $builder->groupBy(array('Robots.name'));
     *</code>
     *
     * @param string|array $groupBy
     *
     * @return static
     */
    public function groupBy($groupBy)
    {
        if (is_string($groupBy)) {
            if (strpos($groupBy, '[') === false && strpos($groupBy, '(') === false) {
                $this->_group = preg_replace('#\w+#', '[\\0]', $groupBy);
            } else {
                $this->_group = $groupBy;
            }
        } else {
            $r = '';
            /** @noinspection ForeachSourceInspection */
            foreach ($groupBy as $item) {
                if (strpos($item, '[') === false && strpos($item, '(') === false) {
                    $r .= preg_replace('#\w+#', '[\\0]', $item) . ', ';
                } else {
                    $r .= $item . ', ';
                }
            }
            $this->_group = substr($r, 0, -2);
        }

        return $this;
    }

    /**
     * @param callable|string $indexBy
     *
     * @return static
     */
    public function indexBy($indexBy)
    {
        $this->_index = $indexBy;

        return $this;
    }

    /**
     * @return string
     * @throws \ManaPHP\Db\Query\Exception
     */
    protected function _getUnionSql()
    {
        $unions = [];

        /**
         * @var \ManaPHP\Db\QueryInterface $queries
         */
        /** @noinspection ForeachSourceInspection */
        foreach ($this->_union['queries'] as $queries) {
            $unions[] = '(' . $queries->getSql() . ')';

            /** @noinspection SlowArrayOperationsInLoopInspection */
            $this->_bind = array_merge($this->_bind, $queries->getBind());
        }

        $sql = implode(' ' . $this->_union['type'] . ' ', $unions);

        $params = [];

        /**
         * Process order clause
         */
        if ($this->_order !== null) {
            $params['order'] = $this->_order;
        }

        /**
         * Process limit parameters
         */
        if ($this->_limit !== 0) {
            $params['limit'] = $this->_limit;
        }

        if ($this->_offset !== 0) {
            $params['offset'] = $this->_offset;
        }

        $sql .= $this->_db->buildSql($params);

        $this->_tables[] = $queries->getTables()[0];

        return $sql;
    }

    /**
     * @return string
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function getSql()
    {
        if ($this->_sql === null) {
            $this->_sql = $this->_buildSql();
        }

        return $this->_sql;
    }

    /**
     * Returns a SQL statement built based on the builder parameters
     *
     * @return string
     * @throws \ManaPHP\Db\Query\Exception
     */
    protected function _buildSql()
    {
        if ($this->_db === null || is_string($this->_db)) {
            $this->_db = ($this->_dependencyInjector ?: Di::getDefault())->getShared($this->_db ?: 'db');
        }

        if (count($this->_union) !== 0) {
            return $this->_getUnionSql();
        }

        if (count($this->_tables) === 0) {
            throw new QueryException('at least one model is required to build the query'/**m09d10c2135a4585fa*/);
        }

        $params = [];
        if ($this->_distinct) {
            $params['distinct'] = true;
        }

        if ($this->_columns !== null) {
            $columns = $this->_columns;
        } else {
            if (count($this->_tables) === 1) {
                $columns = '*';
            } else {
                $columns = '';
                $selectedColumns = [];
                foreach ($this->_tables as $alias => $table) {
                    $selectedColumns[] = '[' . (is_int($alias) ? $table : $alias) . '].*';
                }
                $columns .= implode(', ', $selectedColumns);
            }
        }
        $params['columns'] = $columns;

        $selectedTables = [];

        foreach ($this->_tables as $alias => $table) {
            if ($table instanceof $this) {
                if (is_int($alias)) {
                    throw new QueryException('if using SubQuery, you must assign an alias for it'/**m0e5f4aa93dc102dde*/);
                }

                $selectedTables[] = '(' . $table->getSql() . ') AS [' . $alias . ']';
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $this->_bind = array_merge($this->_bind, $table->getBind());
            } else {
                if (is_string($alias)) {
                    $selectedTables[] = '[' . $table . '] AS [' . $alias . ']';
                } else {
                    $selectedTables[] = '[' . $table . ']';
                }
            }
        }

        $params['from'] = implode(', ', $selectedTables);

        $joinSQL = '';
        /** @noinspection ForeachSourceInspection */
        foreach ($this->_joins as $join) {
            list($joinTable, $joinCondition, $joinAlias, $joinType) = $join;

            if ($joinAlias !== null) {
                $this->_tables[$joinAlias] = $joinTable;
            } else {
                $this->_tables[] = $joinTable;
            }

            if ($joinType !== null) {
                $joinSQL .= ' ' . $joinType;
            }

            if ($joinTable instanceof $this) {
                $joinSQL .= ' JOIN (' . $joinTable->getSql() . ')';
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $this->_bind = array_merge($this->_bind, $joinTable->getBind());
                if ($joinAlias === null) {
                    throw new QueryException('if using SubQuery, you must assign an alias for it'/**m0a80f96a41e1596cb*/);
                }
            } else {
                $joinSQL .= ' JOIN [' . $joinTable . ']';
            }

            if ($joinAlias !== null) {
                $joinSQL .= ' AS [' . $joinAlias . ']';
            }

            if ($joinCondition) {
                $joinSQL .= ' ON ' . $joinCondition;
            }
        }
        $params['join'] = $joinSQL;

        $wheres = [];
        foreach ($this->_conditions as $v) {
            $wheres[] = Text::contains($v, ' or ', true) ? "($v)" : $v;
        }

        if (count($wheres) !== 0) {
            $params['where'] = implode(' AND ', $wheres);
        }

        if ($this->_group !== null) {
            $params['group'] = $this->_group;
        }

        if ($this->_having !== null) {
            $params['having'] = $this->_having;
        }

        if ($this->_order !== null) {
            $params['order'] = $this->_order;
        }

        if ($this->_limit !== 0) {
            $params['limit'] = $this->_limit;
        }

        if ($this->_offset !== 0) {
            $params['offset'] = $this->_offset;
        }

        if ($this->_forUpdate) {
            $params['forUpdate'] = $this->_forUpdate;
        }

        $sql = $this->_db->buildSql($params);
        //compatible with other SQL syntax
        $replaces = [];
        foreach ($this->_bind as $key => $_) {
            $replaces[':' . $key . ':'] = ':' . $key;
        }

        $sql = strtr($sql, $replaces);

        foreach ($this->_tables as $table) {
            if (!$table instanceof $this) {
                $source = $table;
                if (strpos($source, '.')) {
                    $source = '[' . implode('].[', explode('.', $source)) . ']';
                } else {
                    $source = '[' . $source . ']';
                }
                $sql = str_replace('[' . $table . ']', $source, $sql);
            }
        }

        return $sql;
    }

    /**
     * @param string $key
     *
     * @return array
     */
    public function getBind($key = null)
    {
        if ($key !== null) {
            return isset($this->_bind[$key]) ? $this->_bind[$key] : null;
        } else {
            return $this->_bind;
        }
    }

    /**
     * Set default bind parameters
     *
     * @param array $bind
     * @param bool  $merge
     *
     * @return static
     */
    public function setBind($bind, $merge = true)
    {
        $this->_bind = $merge ? array_merge($this->_bind, $bind) : $bind;

        return $this;
    }

    /**
     * @return array
     */
    public function getTables()
    {
        return $this->_tables;
    }

    /**
     * @param int|array $cacheOptions
     *
     * @return array
     * @throws \ManaPHP\Db\Query\Exception
     */
    protected function _getCacheOptions($cacheOptions)
    {
        $_cacheOptions = is_array($cacheOptions) ? $cacheOptions : ['ttl' => $cacheOptions];

        if (isset($this->_tables[0]) && count($this->_tables) === 1) {
            $modelName = $this->_tables[0];
            $prefix = '/' . $this->dispatcher->getModuleName() . '/Models/' . substr($modelName, strrpos($modelName, '\\') + 1);
        } else {
            $prefix = '/' . $this->dispatcher->getModuleName() . '/Queries/' . $this->dispatcher->getControllerName();
        }

        if (isset($_cacheOptions['key'])) {
            if ($_cacheOptions['key'][0] === '/') {
                throw new QueryException('modelsCache `:key` key can not be start with `/`'/**m02053af65daa98380*/, ['key' => $_cacheOptions['key']]);
            }

            $_cacheOptions['key'] = $prefix . '/' . $_cacheOptions['key'];
        } else {
            $_cacheOptions['key'] = $prefix . '/' . md5($this->_sql . serialize($this->_bind));
        }

        return $_cacheOptions;
    }

    /**
     * @param array $rows
     * @param int   $total
     *
     * @return array
     */
    protected function _buildCacheData($rows, $total)
    {
        $from = $this->dispatcher->getModuleName() . ':' . $this->dispatcher->getControllerName() . ':' . $this->dispatcher->getActionName();

        $data = ['time' => date('Y-m-d H:i:s'), 'from' => $from, 'sql' => $this->_sql, 'bind' => $this->_bind, 'total' => $total, 'rows' => $rows];

        return $data;
    }

    /**
     * @param array|int $options
     *
     * @return static
     */
    public function cache($options)
    {
        $this->_cacheOptions = $options;

        return $this;
    }

    /**
     *
     * @return array
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function execute()
    {
        $this->_hiddenParamNumber = 0;

        $this->_sql = $this->_buildSql();

        if ($this->_cacheOptions !== null) {
            $cacheOptions = $this->_getCacheOptions($this->_cacheOptions);

            $data = $this->modelsCache->get($cacheOptions['key']);
            if ($data !== false) {
                return json_decode($data, true)['rows'];
            }
        }

        $result = $this->_db->fetchAll($this->_sql, $this->_bind, \PDO::FETCH_ASSOC, $this->_index);

        if (isset($cacheOptions)) {
            $this->modelsCache->set($cacheOptions['key'],
                json_encode($this->_buildCacheData($result, -1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $cacheOptions['ttl']);
        }

        return $result;
    }

    /**
     * @return int
     * @throws \ManaPHP\Db\Query\Exception
     */
    protected function _getTotalRows()
    {
        if (count($this->_union) !== 0) {
            throw new QueryException('Union query is not support to get total rows'/**m0b24b0f0a54a1227c*/);
        }

        $this->_columns = 'COUNT(*) as [row_count]';
        $this->_limit = 0;
        $this->_offset = 0;
        $this->_order = null;

        $this->_sql = $this->_buildSql();

        if ($this->_group === null) {
            $result = $this->_db->fetchOne($this->_sql, $this->_bind);

            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $rowCount = (int)$result['row_count'];
        } else {
            $result = $this->_db->fetchAll($this->_sql, $this->_bind);
            $rowCount = count($result);
        }

        return $rowCount;
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return \ManaPHP\PaginatorInterface
     * @throws \ManaPHP\Paginator\Exception
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function paginate($size, $page)
    {
        $this->paginator->items = $this->page($size, $page)->executeEx($totalRows);

        return $this->paginator->paginate($totalRows, $size, $page);
    }

    /**
     * build the query and execute it.
     *
     * @param int|string $totalRows
     *
     * @return array
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function executeEx(&$totalRows)
    {
        $this->_hiddenParamNumber = 0;

        $copy = clone $this;

        $this->_sql = $this->_buildSql();

        if ($this->_cacheOptions !== null) {
            $cacheOptions = $this->_getCacheOptions($this->_cacheOptions);

            $result = $this->modelsCache->get($cacheOptions['key']);

            if ($result !== false) {
                $result = json_decode($result, true);
                $totalRows = $result['total'];
                return $result['rows'];
            }
        }

        /** @noinspection SuspiciousAssignmentsInspection */
        $result = $this->_db->fetchAll($this->_sql, $this->_bind, \PDO::FETCH_ASSOC, $this->_index);

        if (!$this->_limit) {
            $totalRows = count($result);
        } else {
            if (count($result) % $this->_limit === 0) {
                $totalRows = $copy->_getTotalRows();
            } else {
                $totalRows = $this->_offset + count($result);
            }
        }

        if (isset($cacheOptions)) {
            $this->modelsCache->set($cacheOptions['key'],
                json_encode($this->_buildCacheData($result, $totalRows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $cacheOptions['ttl']);
        }

        return $result;
    }

    /**
     * @return bool
     * @throws \ManaPHP\Db\Query\Exception
     */
    public function exists()
    {
        $this->_columns = '1 as [stub]';
        $this->_limit = 1;
        $this->_offset = 0;

        $rs = $this->execute();

        return isset($rs[0]);
    }

    /**
     * @param \ManaPHP\Db\QueryInterface[] $queries
     * @param bool                         $distinct
     *
     * @return static
     */
    public function union($queries, $distinct = false)
    {
        if ($this->_db === null) {
            foreach ($queries as $query) {
                if ($query instanceof Query) {
                    $this->_db = $query->_db;
                    break;
                }
            }
        }
        $this->_union = ['type' => 'UNION ' . ($distinct ? 'DISTINCT' : 'ALL'), 'queries' => $queries];

        return $this;
    }
}
