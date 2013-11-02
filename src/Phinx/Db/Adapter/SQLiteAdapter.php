<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2013 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 * 
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

use Phinx\Db\Table,
    Phinx\Db\Table\Column,
    Phinx\Db\Table\Index,
    Phinx\Db\Table\ForeignKey;

/**
 * Phinx SQLite Adapter.
 *
 * @author Rob Morgan <robbym@gmail.com>
 * @author Richard McIntyre <richard.mackstars@gmail.com>
 */
class SQLiteAdapter extends PdoAdapter implements AdapterInterface
{
    protected $definitionsWithLimits = array(
        'CHARACTER',
        'VARCHAR',
        'VARYING CHARACTER',
        'NCHAR',
        'NATIVE CHARACTER',
        'NVARCHAR'
    );

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if (null === $this->connection) {
            if (!class_exists('PDO') || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the PDO_SQLITE extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }
            
            $dsn = '';
            $db = null;
            $options = $this->getOptions();
            
            // if port is specified use it, otherwise use the MySQL default
            if (isset($options['memory'])) {
                $dsn = 'sqlite:memory:';
            } else {
                $dsn = 'sqlite:' . $options['name'] . '.sqlite3';
            }

            $driverOptions = array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION);

            try {
                $db = new \PDO($dsn);
            } catch(\PDOException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $exception->getMessage()
                ));
            }

            $this->setConnection($db);
            
            // Create the schema table if it doesn't already exist
            if (!$this->hasSchemaTable()) {
                $this->createSchemaTable();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasTransactions()
    {
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->execute('START TRANSACTION');
    }
    
    /**
     * {@inheritdoc}
     */
    public function commitTransaction()
    {
        $this->execute('COMMIT');
    }
    
    /**
     * {@inheritdoc}
     */
    public function rollbackTransaction()
    {
        $this->execute('ROLLBACK');
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteTableName($tableName)
    {
        return str_replace('.', '`.`', $this->quoteColumnName($tableName));
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteColumnName($columnName)
    {
        return '`' . str_replace('`', '``', $columnName) . '`';
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasTable($tableName)
    {
        $options = $this->getOptions();
        
        $tables = array();
        $rows = $this->fetchAll(sprintf('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'%s\'', $tableName));
        foreach ($rows as $row) {
            $tables[] = strtolower($row[0]);
        }
        
        return in_array(strtolower($tableName), $tables);
    }
    
    /**
     * {@inheritdoc}
     */
    public function createTable(Table $table)
    {
        $this->startCommandTimer();

        // Add the default primary key
        $columns = $table->getPendingColumns();
        $options = $table->getOptions();
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            $column = new Column();
            $column->setName('id')
                   ->setType('integer')
                   ->setIdentity(true);
            
            array_unshift($columns, $column);
            $options['primary_key'] = 'id';

        } elseif (isset($options['id']) && is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                   ->setType('integer')
                   ->setIdentity(true);

            array_unshift($columns, $column);
            $options['primary_key'] = $options['id'];
        }
        
        
        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($table->getName()) . ' (';
        foreach ($columns as $column) {
            $sql .= $this->quoteColumnName($column->getName()) . ' ' . $this->getColumnSqlDefinition($column) . ', ';
        }
        
        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            $sql .= ' PRIMARY KEY (';
            if (is_string($options['primary_key'])) {       // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } else if (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                // PHP 5.4 will allow access of $this, so we can call quoteColumnName() directly in the anonymous function,
                // but for now just hard-code the adapter quotes
                $sql .= implode(',', array_map(function($v) { return '`' . $v . '`'; }, $options['primary_key']));
            }
            $sql .= ')';
        } else {
            $sql = substr(rtrim($sql), 0, -1);              // no primary keys
        }

        $sql .= ') ';

        // set the foreign keys
        $foreignKeys = $table->getForeignKeys();
        if (!empty($foreignKeys)) {
            foreach ($foreignKeys as $foreignKey) {
                $sql .= ', ' . $this->getForeignKeySqlDefinition($foreignKey);
            }
        }

        $sql = rtrim($sql) . ';';
        // execute the sql
        $this->writeCommand('createTable', array($table->getName()));
        $this->execute($sql);
        $this->endCommandTimer();

        foreach($table->getIndexes() as $index) {
            $this->addIndex($table, $index);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function renameTable($tableName, $newTableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('renameTable', array($tableName, $newTableName));
        $this->execute(sprintf('ALTER TABLE %s RENAME TO %s', $this->quoteTableName($tableName), $this->quoteTableName($newTableName)));
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropTable($tableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropTable', array($tableName));
        $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($tableName)));
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function getColumns($tableName)
    {
        $columns = array();
        $rows = $this->fetchAll(sprintf('pragma table_info(%s)', $tableName));

        foreach ($rows as $columnInfo) {
            $column = new Column();
            $type = strtolower($columnInfo['type']);
            $column->setName($columnInfo['name'])
                   ->setNull($columnInfo['notnull'] != '1')
                   ->setDefault($columnInfo['dflt_value']);

            $phinxType = $this->getPhinxType($type);
            //var_dump($phinxType);
            $column->setType($phinxType['name'])
                   ->setLimit($phinxType['limit']);

            // if ($columnInfo['Extra'] == 'auto_increment') {
            //     $column->setIdentity(true);
            // }

            $columns[] = $column;
        }

        return $columns;
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasColumn($tableName, $columnName)
    {
        $rows = $this->fetchAll(sprintf('pragma table_info(%s)', $tableName));
        foreach ($rows as $column) {
            if (strtolower($column['name']) == strtolower($columnName)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function addColumn(Table $table, Column $column)
    {
        $this->startCommandTimer();

        $sql = sprintf('ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteTableName($table->getName()),
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        );
        
        $this->writeCommand('addColumn', array($table->getName(), $column->getName(), $column->getType()));
        $this->execute($sql);
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function renameColumn($tableName, $columnName, $newColumnName)
    {
        $tmpTableName = 'tmp_' . $tableName;

        $rows = $this->fetchAll('select * from sqlite_master where `type` = \'table\'');

        foreach($rows as $table) {
            if ($table['tbl_name'] == $tableName) {
                $sql = $table['sql'];
            }
        }

        $columns = $this->fetchAll(sprintf('pragma table_info(%s)', $tableName));
        $selectColumns = array();
        $writeColumns = array();
        foreach ($columns as $column) {
            $selectName = $column['name'];
            $writeName = ($selectName == $columnName)? $newColumnName : $selectName;
            $selectColumns[] = $this->quoteColumnName($selectName);
            $writeColumns[] = $this->quoteColumnName($writeName);
        }

        if (!in_array($this->quoteColumnName($columnName), $selectColumns)) {
            throw new \InvalidArgumentException(sprintf(
                'The specified column doesn\'t exist: ' . $columnName
            ));
        }

        $this->execute(sprintf('ALTER TABLE %s RENAME TO %s', $tableName, $tmpTableName));

        $sql = str_replace(
            $this->quoteColumnName($columnName),
            $this->quoteColumnName($newColumnName),
            $sql
        );
        $this->execute($sql);


        $sql = sprintf(
            'INSERT INTO %s(%s) SELECT %s FROM %s',
            $tableName,
            implode(', ', $writeColumns),
            implode(', ', $selectColumns),
            $tmpTableName
        );

        $this->execute($sql);

    }
    
    /**
     * {@inheritdoc}
     */
    public function changeColumn($tableName, $columnName, Column $newColumn)
    {
        $this->startCommandTimer();
        $this->writeCommand('changeColumn', array($tableName, $columnName, $newColumn->getType()));
        $this->execute(
            sprintf('ALTER TABLE %s CHANGE %s %s %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($newColumn->getName()),
                $this->getColumnSqlDefinition($newColumn)
            )
        );
        return $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropColumn($tableName, $columnName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropColumn', array($tableName, $columnName));
        $this->execute(
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName)
            )
        );
        return $this->endCommandTimer();
    }
    
    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getIndexes($tableName)
    {
        $indexes = array();
        $rows = $this->fetchAll(sprintf('pragma index_list(%s)', $tableName));

        foreach ($rows as $row) {
            $indexData = $this->fetchAll(sprintf('pragma index_info(%s)', $row['name']));
            if (!isset($indexes[$tableName])) {
                $indexes[$tableName] = array('columns' => array());
            }
            foreach ($indexData as $indexItem) {
                $indexes[$tableName]['columns'][] = strtolower($indexItem['name']);
            }
        }
        return $indexes;
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasIndex($tableName, $columns)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $columns = array_map('strtolower', $columns);
        $indexes = $this->getIndexes($tableName);
        
        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function addIndex(Table $table, Index $index)
    {
        $this->startCommandTimer();
        $this->writeCommand('addIndex', array($table->getName(), $index->getColumns()));
        $indexName = ''; 
        $indexColumnArray = array();
        foreach ($index->getColumns() as $column) {
            $indexName .= $column . '_';
            $indexColumnArray []= sprintf('`%s` ASC', $column);
        }
        $indexName .= 'index';
        $indexColumns = implode(',', $indexColumnArray);
        $result = $this->execute(
            sprintf('CREATE %s `%s` ON %s (%s)',
                $this->getIndexSqlDefinition($index),
                $indexName,
                $this->quoteTableName($table->getName()),
                $indexColumns
            )
        );
        return $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropIndex($tableName, $columns)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $this->writeCommand('dropIndex', array($tableName, $columns));
        $indexes = $this->getIndexes($tableName);
        $columns = array_map('strtolower', $columns);
        
        foreach ($indexes as $indexName => $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                $this->execute(
                    sprintf('ALTER TABLE %s DROP INDEX %s',
                        $this->quoteTableName($tableName),
                        $this->quoteColumnName($indexName)
                    )
                );
                return $this->endCommandTimer();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasForeignKey($tableName, $columns, $constraint = null)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }
            return false;
        } else {
            foreach ($foreignKeys as $key) {
                $a = array_diff($columns, $key['columns']);
                if (empty($a)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getForeignKeys($tableName)
    {
        $foreignKeys = array();
        $rows = $this->fetchAll(sprintf(
            'SELECT
              CONSTRAINT_NAME,
              TABLE_NAME,
              COLUMN_NAME,
              REFERENCED_TABLE_NAME,
              REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND TABLE_NAME = "%s"
            ORDER BY POSITION_IN_UNIQUE_CONSTRAINT',
            $tableName
        ));
        foreach ($rows as $row) {
            $foreignKeys[$row['CONSTRAINT_NAME']]['table'] = $row['TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return $foreignKeys;
    }

    /**
     * {@inheritdoc}
     */
    public function addForeignKey(Table $table, ForeignKey $foreignKey)
    {
        $this->startCommandTimer();
        $this->writeCommand('addForeignKey', array($table->getName(), $foreignKey->getColumns()));
        $this->execute(
            sprintf('ALTER TABLE %s ADD %s',
                $this->quoteTableName($table->getName()),
                $this->getForeignKeySqlDefinition($foreignKey)
            )
        );
        return $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropForeignKey($tableName, $columns, $constraint = null)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $this->writeCommand('dropForeignKey', array($tableName, $columns));
        
        if ($constraint) {
            $this->execute(
                sprintf('ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->quoteTableName($tableName),
                    $constraint
                )
            );
            return $this->endCommandTimer();
        } else {
            foreach ($columns as $column) {
                $rows = $this->fetchAll(sprintf(
                        'SELECT
                            CONSTRAINT_NAME
                          FROM information_schema.KEY_COLUMN_USAGE
                          WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                            AND TABLE_NAME = "%s"
                            AND COLUMN_NAME = "%s"
                          ORDER BY POSITION_IN_UNIQUE_CONSTRAINT',
                        $tableName,
                        $column
                ));
                foreach ($rows as $row) {
                    $this->dropForeignKey($tableName, $columns, $row['CONSTRAINT_NAME']);
                }
            }
        }
        return $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSqlType($type)
    {
        switch ($type) {
            case 'primary_key':
                return self::DEFAULT_PRIMARY_KEY;
            case 'string':
                return array('name' => 'varchar', 'limit' => 255);
                break;
            case 'text':
                return array('name' => 'text');
                break;
            case 'integer':
                return array('name' => 'int');
                break;
            case 'biginteger':
                return array('name' => 'bigint');
                break;
            case 'float':
                return array('name' => 'float');
                break;
            case 'decimal':
                return array('name' => 'decimal');
                break;
            case 'datetime':
                return array('name' => 'datetime');
                break;
            case 'timestamp':
                return array('name' => 'datetime');
                break;
            case 'time':
                return array('name' => 'time');
                break;
            case 'date':
                return array('name' => 'date');
                break;
            case 'binary':
                return array('name' => 'blob');
                break;
            case 'boolean':
                return array('name' => 'boolean');
                break;
            default:
                throw new \RuntimeException('The type: "' . $type . '" is not supported.');
        }
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param string $sqlType SQL type
     * @returns string Phinx type
     */
    public function getPhinxType($sqlTypeDef)
    {
        if (preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*$/', $sqlTypeDef, $matches) === false) {
            throw new \RuntimeException('Column type ' . $sqlTypeDef . ' is not supported');
        } else {
            $limit = null;
            $precision = null;
            $type = $matches[1];
            if (count($matches) > 2) {
                $limit = $matches[3] ? $matches[3] : null;
            }
            if (count($matches) > 4) {
                $precision = $matches[5];
            }
            switch ($matches[1]) {
                case 'varchar':
                    $type = 'string';
                    if ($limit == 255) {
                        $limit = null;
                    }
                    break;
                case 'int':
                    $type = 'integer';
                    if ($limit == 11) {
                        $limit = null;
                    }
                    break;
                case 'bigint':
                    if ($limit == 11) {
                        $limit = null;
                    }
                    $type = 'biginteger';
                    break;
                case 'blob':
                    $type = 'binary';
                    break;
            }
            if ($type == 'tinyint') {
                if ($matches[3] == 1) {
                    $type = 'boolean';
                    $limit = null;
                }
            }

            $this->getSqlType($type);

            return array(
                'name' => $type,
                'limit' => $limit,
                'precision' => $precision
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDatabase($name, $options = array())
    {
        $this->startCommandTimer();
        $this->writeCommand('createDatabase', array($name));
        $charset = isset($options['charset']) ? $options['charset'] : 'utf8';
        
        if (isset($options['collation'])) {
            $this->execute(sprintf(
                'CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s` COLLATE `%s`', $name, $charset, $options['collation']
            ));
        } else {
            $this->execute(sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s`', $name, $charset));
        }
        return $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasDatabase($name)
    {
        $rows = $this->fetchAll(
            sprintf(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = \'%s\'',
                $name
            )
        );
        
        foreach ($rows as $row) {
            if (!empty($row)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropDatabase($name)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropDatabase', array($name));
        unlink($name . '.sqlite3');
        return $this->endCommandTimer();
    }
    
    /**
     * Gets the SQLite Column Definition for a Column object.
     *
     * @param Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column)
    {
        $sqlType = $this->getSqlType($column->getType());
        $def = '';
        $def .= strtoupper($sqlType['name']);
        if ($column->getPrecision() && $column->getScale()) {
            $def .= '(' . $column->getPrecision() . ',' . $column->getScale() . ')';
        }
        $limitable = in_array(strtoupper($sqlType['name']), $this->definitionsWithLimits);
        if (($column->getLimit() || isset($sqlType['limit'])) && $limitable) {
            $def .= '(' . ($column->getLimit() ? $column->getLimit() : $sqlType['limit']) . ')';
        }
        $default = $column->getDefault();

        $def .= ($column->isNull() || is_null($default)) ? ' NULL' : ' NOT NULL';
        if (is_numeric($default) || $default == 'CURRENT_TIMESTAMP') {
            $def .= ' DEFAULT ' . $column->getDefault();
        } else {
            if(!is_null($default)) {
                $def .= ' DEFAULT '  . $column->getDefault();
            }
        }

        if ($column->getUpdate()) {
            $def .= ' ON UPDATE ' . $column->getUpdate();
        }

        return $def;
    }
    
    /**
     * Gets the SQLite Index Definition for an Index object.
     *
     * @param Index $index Index
     * @return string
     */
    protected function getIndexSqlDefinition(Index $index)
    {
        if ($index->getType() == Index::UNIQUE) {
            return 'UNIQUE INDEX';
        } 
        return 'INDEX';
    }

    /**
     * Gets the SQLite Foreign Key Definition for an ForeignKey object.
     *
     * @param ForeignKey $foreignKey
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey)
    {
        $def = '';
        if ($foreignKey->getConstraint()) {
            $def .= ' CONSTRAINT ' . $this->quoteColumnName($foreignKey->getConstraint());
        } else {
            $columnNames = array();
            foreach ($foreignKey->getColumns() as $column) {
                $columnNames[] = $this->quoteColumnName($column);
            }
            $def .= ' FOREIGN KEY (' . implode(',', $columnNames) . ')';
            $refColumnNames = array();
            foreach ($foreignKey->getReferencedColumns() as $column) {
                $refColumnNames[] = $this->quoteColumnName($column);
            }
            $def .= ' REFERENCES ' . $this->quoteTableName($foreignKey->getReferencedTable()->getName()) . ' (' . implode(',', $refColumnNames) . ')';
            if ($foreignKey->getOnDelete()) {
                $def .= ' ON DELETE ' . $foreignKey->getOnDelete();
            }
            if ($foreignKey->getOnUpdate()) {
                $def .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
            }
        }
        return $def;
    }
}
