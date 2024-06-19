<?php
class MongoDB_PDO extends PDO
{
    private $manager;
    private $dbname;

    public function __construct($dsn, $dbname)
    {
        $this->dbname = $dbname;


        $this->manager = new MongoDB\Driver\Manager($dsn);
    }


    public function prepare($statement, $options = []): MongoDB_PDOStatement
    {
        $parser = new SQLToMongoDBParser($statement);
        $result = $parser->parse();

        switch ($result['operation']) {
            case 'SELECT':
                return new MongoDB_PDOStatement($this->manager, $result['table'], $result, $this->dbname);
            case 'INSERT':
            case 'DELETE':
            case 'UPDATE':
                return new MongoDB_PDOStatement($this->manager, $result['table'], $result, $this->dbname);
            default:
                throw new PDOException("Unsupported operation: {$result['operation']}");
        }
    }
}

class MongoDB_PDOStatement extends PDOStatement
{
    private $manager;
    private $table;
    private $parseResult;
    private $dbname;
    private $cursor;
    private $affectedRows;
    private $documents;

    public function __construct($manager, $table, $parseResult, $dbname)
    {

        $this->manager = $manager;
        $this->table = $table;
        $this->parseResult = $parseResult;
        $this->dbname = $dbname;
        $this->affectedRows = 0;
        $this->documents = []; // 初始化为一个空数组
    }

    public function execute($params = null): bool
    {

        if (is_array($params)) {
            // 將命名參數替換為實際值
            foreach ($params as $key => $value) {
                $placeholder = ':' . ltrim($key, ':');
                switch ($this->parseResult['operation']) {
                    case 'SELECT':
                        $this->parseResult['pipeline'] = $this->replacePlaceholder($this->parseResult['pipeline'], $placeholder, $value);

                        break;
                    case 'INSERT':
                        $this->parseResult['document'] = $this->replacePlaceholder($this->parseResult['document'], $placeholder, $value);
                        break;
                    case 'UPDATE':
                        $this->parseResult['filter'] = $this->replacePlaceholder($this->parseResult['filter'], $placeholder, $value);
                        $this->parseResult['update'] = $this->replacePlaceholder($this->parseResult['update'], $placeholder, $value);
                        break;
                    case 'DELETE':
                        $this->parseResult['filter'] = $this->replacePlaceholder($this->parseResult['filter'], $placeholder, $value);
                        break;
                }
            }
        }

        switch ($this->parseResult['operation']) {
            case 'SELECT':
                $command = new MongoDB\Driver\Command([
                    'aggregate' => $this->table,
                    'pipeline' => $this->parseResult['pipeline'],
                    'cursor' => new stdClass,
                ]);
                try {
                    $this->cursor = $this->manager->executeCommand($this->dbname, $command);
                    $tmp = $this->cursor;
                } catch (MongoDB\Driver\Exception\Exception $e) {
                    echo "MongoDB Exception: ", $e->getMessage(), "\n";
                    return false;
                }
                $this->documents = $this->cursor->toArray();
                $this->affectedRows = count($this->documents); // 设置影响的行数为返回的文档数量
                break;
            case 'INSERT':
                $bulkWrite = new MongoDB\Driver\BulkWrite;
                $bulkWrite->insert($this->parseResult['document']);
                $writeConcern = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
                //$this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $result = $this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $this->affectedRows = $result->getInsertedCount();
                break;
            case 'DELETE':
                $bulkWrite = new MongoDB\Driver\BulkWrite;
                $bulkWrite->delete($this->parseResult['filter']);
                $writeConcern = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
                //$this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $result = $this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $this->affectedRows = $result->getInsertedCount();
                break;
            case 'UPDATE':
                $bulkWrite = new MongoDB\Driver\BulkWrite;
                $bulkWrite->update($this->parseResult['filter'], ['$set' => $this->parseResult['update']], ['multi' => true]);
                $writeConcern = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
                //$this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $result = $this->manager->executeBulkWrite($this->dbname . '.' . $this->table, $bulkWrite, $writeConcern);
                $this->affectedRows = $result->getInsertedCount();
                break;
            default:
                throw new PDOException("Unsupported operation: {$this->parseResult['operation']}");
        }

        return true;
    }

    private function replacePlaceholder($value, $placeholder, $actual)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replacePlaceholder($item, $placeholder, $actual);
            }
        } elseif (is_object($value)) {
            if ($value instanceof MongoDB\BSON\Regex) {
                $pattern = $value->getPattern();
                if (strpos($pattern, $placeholder) !== false) {
                    $pattern = str_replace($placeholder, $actual, $pattern);
                    $value = new MongoDB\BSON\Regex($pattern, $value->getFlags());
                }
            } else {
                foreach ($value as $key => $item) {
                    $value->$key = $this->replacePlaceholder($item, $placeholder, $actual);
                }
            }
        } elseif (is_string($value)) {
            if (strpos($value, $placeholder) !== false) {
                $pattern = '/' . preg_quote($placeholder, '/') . '/';
                $value = preg_replace($pattern, $actual, $value);
            }
        }

        return $value;
    }

    public function fetch($mode = null, $cursorOrientation = null, $cursorOffset = null)
    {
        if ($mode === null) {
            $mode = PDO::FETCH_BOTH;
        }
        if ($this->documents && $document = current($this->documents)) {
            next($this->documents);
            switch ($mode) {
                case PDO::FETCH_ASSOC:
                    return (array)$document;
                case PDO::FETCH_NUM:
                    return array_values((array)$document);
                case PDO::FETCH_BOTH:
                    return array_merge((array)$document, array_values((array)$document));
                case PDO::FETCH_CLASS:
                    $class = 'stdClass';
                    return new $class((array)$document);
                case PDO::FETCH_OBJ:
                    return (object)(array)$document;
                default:
                    return (array)$document;
            }
        }
        return false;
    }

    public function fetchAll($mode = PDO::FETCH_CLASS, $class = null, $constructorArgs = null): array
    {
        $documents = [];
        foreach ($this->documents as $document) {
            switch ($mode) {
                case PDO::FETCH_ASSOC:
                    $documents[] = (array)$document;
                    break;
                case PDO::FETCH_NUM:
                    $documents[] = array_values((array)$document);
                    break;
                case PDO::FETCH_BOTH:
                    $documents[] = array_merge((array)$document, array_values((array)$document));
                    break;
                case PDO::FETCH_CLASS:
                    $class = $args[0] ?? 'stdClass';
                    $ctor_args = $args[1] ?? [];
                    $documents[] = new $class((array)$document, ...$ctor_args);
                    break;
                case PDO::FETCH_OBJ:
                    $documents[] = (object)(array)$document;
                    break;
                default:
                    $documents[] = (array)$document;
                    break;
            }
        }
        return $documents;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
class SQLToMongoDBParser
{
    private $sql;
    private $params = [];


    public function __construct($sql)
    {
        $this->sql = $sql;
    }

    public function parse()
    {
        $patterns = [
            'SELECT' => '/^select\s+(.*?)\s+from\s+(.*?)(?:\s+left join\s+(.*?)\s+on\s+(.*?))*(?:\s+where\s+(.*?))?(?:\s+group\s+by\s+(.*?))?(?:\s+order\s+by\s+(.*?))?(?:\s+limit\s+(\d+)(?:\s*,\s*(\d+))?)?$/i',
            'INSERT' => '/^insert\s+into\s+(\w+)\s*\((.*?)\)\s*values\s*\((.*?)\)$/i',
            'DELETE' => '/^delete\s+from\s+(\w+)(?:\s+where\s+(.*?))?$/i',
            'UPDATE' => '/^update\s+(\w+)\s+set\s+(.*?)(?:\s+where\s+(.*?))?$/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $this->sql, $matches)) {
                $result = [
                    'operation' => $type
                ];

                switch ($type) {
                    case 'SELECT':
                        $result += $this->parseSelect($matches);
                        break;
                    case 'INSERT':
                        $result += $this->parseInsert($matches);
                        break;
                    case 'DELETE':
                        $result += $this->parseDelete($matches);
                        break;
                    case 'UPDATE':
                        $result += $this->parseUpdate($matches);
                        break;
                }

                return $result;
            }
        }

        throw new InvalidArgumentException('Unsupported SQL query: ' . $this->sql);
    }
    private function parseSelect($matches)
    {
        $tableInfo = $this->parseTable(trim($matches[2]));
        $table = $tableInfo['table'];
        $tableAlias = isset($tableInfo['alias']) ? $tableInfo['alias'] : $table;

        $filter = $this->parseFilter(isset($matches[5]) ? trim($matches[5]) : '');
        $order = $this->parseOrder(isset($matches[7]) ? trim($matches[7]) : '');

        $offset = (isset($matches[8]) && isset($matches[9])) ? trim($matches[8]) : '';

        $limit = (isset($matches[9]) || $offset == '' && isset($matches[8])) ? (($offset == '' && isset($matches[8])) ? trim($matches[8]) : trim($matches[9])) : '';
        $pipeline = [];

        if (!empty($filter)) {
            $pipeline[] = ['$match' => $filter];
        }

        $joinTableAliases = [];
        if (isset($matches[3]) && isset($matches[4])) {
            $joinClauses = preg_split('/\s+left join\s+/i', $this->sql);
            array_shift($joinClauses); // Remove the main table clause

            foreach ($joinClauses as $joinClause) {
                $joinClause = trim($joinClause);
                $joinParts = preg_split('/\s+on\s+/i', $joinClause);
                $joinTableInfo = $this->parseTable(trim($joinParts[0]));
                $joinTable = $joinTableInfo['table'];
                $joinTableAlias = isset($joinTableInfo['alias']) ? $joinTableInfo['alias'] : $joinTable;
                $joinTableAliases[$joinTableAlias] = $joinTable;
                $joinCondition = trim($joinParts[1]);

                $joinFields = explode('=', $joinCondition);
                $localField = isset($joinFields[1]) ? trim($joinFields[1]) : '';
                $foreignField = isset($joinFields[0]) ? trim($joinFields[0]) : '';

                // Remove table aliases and WHERE clause from local and foreign fields
                $localField = preg_replace('/^.*\.(.*?)(\s+WHERE\s+.*)?$/', '$1', $localField);
                $foreignField = preg_replace('/^.*\./', '', $foreignField);

                $pipeline[] = [
                    '$lookup' => [
                        'from' => $joinTable,
                        'localField' => $localField,
                        'foreignField' => $foreignField,
                        'as' => $joinTableAlias
                    ]
                ];

                $pipeline[] = [
                    '$unwind' => [
                        'path' => '$' . $joinTableAlias,
                        'preserveNullAndEmptyArrays' => true
                    ]
                ];
            }
        }


        $project = $this->parseProject(isset($matches[1]) ? trim($matches[1]) : '*', array_merge([$tableAlias => $table], $joinTableAliases));
        $group = $this->parseGroup(isset($matches[6]) ? trim($matches[6]) : '', $project);

        if (!empty($group)) {
            $pipeline[] = ['$group' => $group];
        }

        if (!empty($project) && $project !== '*') {
            $pipeline[] = ['$project' => $project];
        }

        if (!empty($order)) {
            $pipeline[] = ['$sort' => $order];
        }

        if ($offset !== '') {
            $pipeline[] = ['$skip' => (int) $offset];
        }

        if ($limit !== '') {
            $pipeline[] = ['$limit' => (int) $limit];
        }

        return [
            'table' => $table,
            'tableAlias' => $tableAlias,
            'pipeline' => $pipeline
        ];
    }
    private function parseInsert($matches)
    {
        $table = $matches[1];
        $fields = array_map('trim', explode(',', $matches[2]));
        $values = array_map('trim', explode(',', $matches[3]));

        $document = array_combine($fields, $values);

        // 將值中的命名參數替換為佔位符
        foreach ($document as $field => $value) {
            if (strpos($value, ':') === 0) {
                $paramName = substr($value, 1);
                $document[$field] = ':' . $paramName;
            }
        }

        return [
            'table' => $table,
            'document' => $document,
        ];
    }

    private function parseDelete($matches)
    {
        $table = $matches[1];
        $filter = isset($matches[2]) ? $this->parseFilter($matches[2]) : [];

        return [
            'table' => $table,
            'filter' => $filter,
        ];
    }

    private function parseUpdate($matches)
    {
        $table = $matches[1];
        $update = $this->parseUpdateFields($matches[2]);
        $filter = isset($matches[3]) ? $this->parseFilter($matches[3]) : [];

        return [
            'table' => $table,
            'update' => $update,
            'filter' => $filter,
        ];
    }

    private function parseUpdateFields($fields)
    {
        $update = [];

        $fields = explode(',', $fields);
        foreach ($fields as $field) {
            list($key, $value) = explode('=', trim($field), 2);
            $key = trim($key);
            $value = trim($value);

            if (strpos($value, ':') === 0) {
                $paramName = substr($value, 1);
                if (isset($this->params[$paramName])) {
                    $value = $this->params[$paramName];
                }
            }

            $update[$key] = $value;
        }

        return $update;
    }
    private function parseProject($project, $tableAliases)
    {
        if ($project === '*') {
            return null;
        }

        $fields = explode(',', $project);
        $projection = [];
        foreach ($fields as $field) {
            $field = trim($field);
            if (strpos($field, '(') !== false) {
                $aggregate = $this->parseAggregate($field, $tableAliases);
                $projection = array_merge($projection, $aggregate);
            } else {
                if (strpos($field, ' as ') !== false) {
                    list($field, $alias) = explode(' as ', $field);
                    $field = trim($field);
                    $alias = trim($alias);
                    if (strpos($field, '.') !== false) {
                        list($tableAlias, $fieldName) = explode('.', $field);
                        if ($tableAlias === array_keys($tableAliases)[0]) {
                            $projection[$alias] = '$' . $fieldName;
                        } else {
                            $projection[$alias] = '$' . $tableAlias . '.' . $fieldName;
                        }
                    } else {
                        $projection[$alias] = '$' . $field;
                    }
                } else {
                    if (strpos($field, '.') !== false) {
                        list($tableAlias, $fieldName) = explode('.', $field);
                        if ($tableAlias === array_keys($tableAliases)[0]) {
                            $projection[$fieldName] = '$' . $fieldName;
                        } else {
                            $projection[$fieldName] = '$' . $tableAlias . '.' . $fieldName;
                        }
                    } else {
                        $projection[$field] = '$' . $field;
                    }
                }
            }
        }

        if (!isset($projection['_id'])) {
            $projection['_id'] = 0;
        }

        return $projection;
    }

    private function parseAggregate($field, $tableAliases)
    {
        $aggregate = [];

        if (strpos($field, ' as ') !== false) {
            list($function, $alias) = explode(' as ', $field);
            $alias = trim($alias);
        } else {
            $function = $field;
            $alias = '';
        }

        if (strpos($function, 'count(') !== false) {
            $field = trim(str_replace(['count(', ')'], '', $function));
            if (strpos($field, '.') !== false) {
                list($tableAlias, $fieldName) = explode('.', $field);
                if ($alias !== '') {
                    $aggregate[$alias] = ['$sum' => 1];
                } else {
                    $aggregate['count'] = ['$sum' => 1];
                }
            } else {
                if ($alias !== '') {
                    $aggregate[$alias] = ['$sum' => 1];
                } else {
                    $aggregate['count'] = ['$sum' => 1];
                }
            }
        } elseif (strpos($function, 'sum(') !== false) {
            $field = trim(str_replace(['sum(', ')'], '', $function));
            if (strpos($field, '.') !== false) {
                list($tableAlias, $fieldName) = explode('.', $field);
                if ($alias !== '') {
                    $aggregate[$alias] = ['$sum' => '$' . $tableAlias . '.' . $fieldName];
                } else {
                    $aggregate['sum_' . $fieldName] = ['$sum' => '$' . $tableAlias . '.' . $fieldName];
                }
            } else {
                if ($alias !== '') {
                    $aggregate[$alias] = ['$sum' => '$' . $field];
                } else {
                    $aggregate['sum_' . $field] = ['$sum' => '$' . $field];
                }
            }
        }

        return $aggregate;
    }
    private function parseTable($table)
    {
        if (strpos($table, ' ') !== false) {
            list($table, $alias) = explode(' ', $table);
            return [
                'table' => trim($table),
                'alias' => trim($alias)
            ];
        }
        return ['table' => $table];
    }
    private function parseFilter($filter)
    {
        if ($filter === '') {
            return [];
        }

        $conditions = preg_split('/\s+and\s+/i', $filter);
        $query = [];

        foreach ($conditions as $condition) {
            if (stripos($condition, ' or ') !== false) {
                $orConditions = preg_split('/\s+or\s+/i', $condition);
                $orQuery = [];

                foreach ($orConditions as $orCondition) {
                    $orQuery[] = $this->parseCondition($orCondition);
                }

                $query['$or'] = $orQuery;
            } else {
                $query[] = $this->parseCondition($condition);
            }
        }

        return count($query) === 1 ? $query[0] : ['$and' => $query];
    }

    private function parseCondition($condition)
    {
        if (stripos($condition, ' in ') !== false) {
            list($field, $value) = preg_split('/\s+in\s+/i', $condition);
            $field = trim($field);
            $value = preg_replace('/\(|\)/', '', $value);

            $values = array_map(function ($v) {
                return preg_replace('/^\'|\'$/', '', trim($v));
            }, explode(',', $value));
            return [$field => ['$in' => $values]];
        } elseif (stripos($condition, ' like ') !== false) {
            list($field, $value) = preg_split('/\s+like\s+/i', $condition);
            $field = trim($field);
            $value = preg_replace('/^\'|\'$/', '', trim($value));
            $value = str_replace('%', '.*', $value);
            return [$field => new MongoDB\BSON\Regex($value, 'i')];
        } elseif (stripos($condition, ' not ') !== false) {
            list($field, $value) = preg_split('/\s+not\s+/i', $condition);
            $field = trim($field);
            $value = trim($value, " '");
            return [$field => ['$ne' => $value]];
        } elseif (stripos($condition, ' is null') !== false) {
            $field = trim(str_replace(' is null', '', $condition));
            return [$field => null];
        } elseif (stripos($condition, ' is not null') !== false) {
            $field = trim(str_replace(' is not null', '', $condition));
            return [$field => ['$ne' => null]];
        } else {
            $operators = ['>=', '<=', '>', '<', '<>'];
            foreach ($operators as $operator) {
                if (strpos($condition, $operator) !== false) {
                    list($field, $value) = explode($operator, $condition);
                    $field = trim($field);
                    $value = trim($value, " '");
                    // Check if value is numeric
                    if (is_numeric($value)) {
                        $value = (float) $value;
                    }
                    switch ($operator) {
                        case '>=':
                            return [$field => ['$gte' => $value]];
                        case '<=':
                            return [$field => ['$lte' => $value]];
                        case '>':
                            return [$field => ['$gt' => $value]];
                        case '<':
                            return [$field => ['$lt' => $value]];
                        case '<>':
                            return [$field => ['$ne' => $value]];
                    }
                }
            }
            list($field, $value) = preg_split('/\s*=\s*/', $condition);
            $field = trim($field);
            $value = trim($value);
            $value = preg_replace('/^\'|\'$/', '', trim($value));
            // Check if value is numeric
            if (is_numeric($value)) {
                $value = (float) $value;
            }
            return [$field => $value];
        }
    }

    private function parseGroup($group, $project)
    {
        $groupFields = [];

        if ($group !== '') {
            $fields = explode(',', $group);

            foreach ($fields as $field) {
                $groupFields['_id'][$field] = '$' . $field;
            }
        }

        if (!empty($project) && $project !== '*') {
            foreach ($project as $key => $value) {
                if (is_array($value) && isset($value['$sum'])) {
                    $groupFields[$key] = $value;
                }
            }
        }

        return $groupFields;
    }

    private function parseOrder($order)
    {
        if ($order === '') {
            return [];
        }

        $fields = explode(',', $order);
        $sort = [];

        foreach ($fields as $field) {
            $field = trim($field);
            if (strpos($field, ' desc') !== false) {
                $field = str_replace(' desc', '', $field);
                $sort[$field] = -1;
            } else {
                $sort[$field] = 1;
            }
        }

        return $sort;
    }
}
