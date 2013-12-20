<?php
/**
 * Copyright 2013 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/bsd
 * @category   Horde
 * @package    Db
 * @subpackage Adapter
 */

/**
 * Class for Oracle-specific managing of database schemes and handling of SQL
 * dialects and quoting.
 *
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/bsd
 * @category   Horde
 * @package    Db
 * @subpackage Adapter
 */
class Horde_Db_Adapter_Oracle_Schema extends Horde_Db_Adapter_Base_Schema
{
    /*##########################################################################
    # Object factories
    ##########################################################################*/

    /**
     * Factory for Column objects.
     *
     * @param string $name        Column name, such as "supplier_id" in
     *                            "supplier_id int(11)".
     * @param string $default     Type-casted default value, such as "new"
     *                            in "sales_stage varchar(20) default 'new'".
     * @param string $sqlType     Column type.
     * @param boolean $null       Whether this column allows NULL values.
     * @param integer $length     Column width.
     * @param integer $precision  Precision for NUMBER and FLOAT columns.
     * @param integer $scale      Number of digits to the right of the decimal
     *                            point in a number.
     *
     * @return Horde_Db_Adapter_Base_Column  A column object.
     */
    public function makeColumn($name, $default, $sqlType = null, $null = true,
                               $length = null, $precision = null, $scale = null)
    {
        return new Horde_Db_Adapter_Oracle_Column(
            $name, $default, $sqlType, $null,
            $length, $precision, $scale
        );
    }

    /**
     * Factory for TableDefinition objects.
     *
     * @return Horde_Db_Adapter_Base_TableDefinition  A table definition object.
     */
    public function makeTableDefinition($name, $base, $options = array())
    {
        return new Horde_Db_Adapter_Oracle_TableDefinition($name, $base, $options);
    }


    /*##########################################################################
    # Quoting
    ##########################################################################*/

    /**
     * Returns a quoted form of the column name.
     *
     * With Oracle, if using quoted identifiers, you need to use them
     * everywhere. 'SELECT * FROM "tablename"' is NOT the same as 'SELECT *
     * FROM tablename'. Thus we cannot blindly quote table or column names,
     * unless we know that in subsequent queries they will be used too.
     *
     * @param string $name  A column name.
     *
     * @return string  The quoted column name.
     */
    public function quoteColumnName($name)
    {
        return $name;
    }

    /**
     * Returns a quoted binary value.
     *
     * @param mixed  A binary value.
     *
     * @return string  The quoted binary value.
     */
    public function quoteBinary($value)
    {
        return "'" . bin2hex($value) . "'";
    }


    /*##########################################################################
    # Schema Statements
    ##########################################################################*/

    /**
     * Returns a hash of mappings from the abstract data types to the native
     * database types.
     *
     * See TableDefinition::column() for details on the recognized abstract
     * data types.
     *
     * @see TableDefinition::column()
     *
     * @return array  A database type map.
     */
    public function nativeDatabaseTypes()
    {
        return array(
            'autoincrementKey' => 'number NOT NULL PRIMARY KEY',
            'string'           => array('name' => 'varchar2',
                                        'limit' => 255),
            'text'             => array('name' => 'clob',
                                        'limit' => null),
            'mediumtext'       => array('name' => 'clob',
                                        'limit' => null),
            'longtext'         => array('name' => 'clob',
                                        'limit' => null),
            'integer'          => array('name' => 'number',
                                        'limit' => null),
            'float'            => array('name' => 'float',
                                        'limit' => null),
            'decimal'          => array('name' => 'number',
                                        'limit' => null),
            'datetime'         => array('name' => 'date',
                                        'limit' => null),
            'timestamp'        => array('name' => 'date',
                                        'limit' => null),
            'time'             => array('name' => 'varchar2',
                                        'limit' => 8),
            'date'             => array('name' => 'date',
                                        'limit' => null),
            'binary'           => array('name' => 'blob',
                                        'limit' => null),
            'boolean'          => array('name' => 'number',
                                        'precision' => 1,
                                        'scale' => 0),
        );
    }

    /**
     * Returns the maximum length a table alias can have.
     *
     * @return integer  The maximum table alias length.
     */
    public function tableAliasLength()
    {
        return 30;
    }

    /**
     * Returns a list of all tables of the current database.
     *
     * @return array  A table list.
     */
    public function tables()
    {
        return array_map(
            array('Horde_String', 'lower'),
            $this->selectValues('SELECT table_name FROM USER_TABLES')
        );
    }

    /**
     * Returns a table's primary key.
     *
     * @param string $tableName  A table name.
     * @param string $name       (can be removed?)
     *
     * @return Horde_Db_Adapter_Base_Index  The primary key index object.
     */
    public function primaryKey($tableName, $name = null)
    {
        $pk = $this->makeIndex(
            $tableName,
            'PRIMARY',
            true,
            true,
            array()
        );

        $rows = @unserialize($this->_cache->get("tables/primarykeys/$tableName", 0));

        if (!$rows) {
            $constraint = $this->selectOne(
                'SELECT CONSTRAINT_NAME FROM USER_CONSTRAINTS WHERE TABLE_NAME = ? AND CONSTRAINT_TYPE = \'P\'',
                array(Horde_String::upper($tableName)),
                $name
            );
            if ($constraint['constraint_name']) {
                $pk->name = $constraint['constraint_name'];
                $rows = $this->selectValues(
                    'SELECT DISTINCT COLUMN_NAME FROM USER_CONS_COLUMNS WHERE CONSTRAINT_NAME = ?',
                    array($constraint['constraint_name'])
                );
                $rows = array_map(array('Horde_String', 'lower'), $rows);
                $this->_cache->set("tables/primarykeys/$tableName", serialize($rows));
            } else {
                $rows = array();
            }
        }

        $pk->columns = $rows;

        return $pk;
    }

    /**
     * Returns a list of tables indexes.
     *
     * @param string $tableName  A table name.
     * @param string $name       (can be removed?)
     *
     * @return array  A list of Horde_Db_Adapter_Base_Index objects.
     */
    public function indexes($tableName, $name = null)
    {
        $rows = @unserialize($this->_cache->get("tables/indexes/$tableName", 0));

        if (!$rows) {
            $rows = $this->selectAll(
                'SELECT INDEX_NAME, UNIQUENESS FROM USER_INDEXES WHERE TABLE_NAME = ? AND INDEX_NAME NOT IN (SELECT INDEX_NAME FROM USER_LOBS)',
                array(Horde_String::upper($tableName)),
                $name
            );

            $this->_cache->set("tables/indexes/$tableName", serialize($rows));
        }

        $indexes = array();
        $primary = $this->primaryKey($tableName);

        foreach ($rows as $row) {
            if ($row['index_name'] == $primary->name) {
                continue;
            }
            $columns = $this->selectValues(
                'SELECT DISTINCT COLUMN_NAME FROM USER_IND_COLUMNS WHERE INDEX_NAME = ?',
                array($row['index_name'])
            );
            $indexes[] = $this->makeIndex(
                $tableName,
                Horde_String::lower($row['index_name']),
                false,
                $row['uniqueness'] == 'UNIQUE',
                array_map(array('Horde_String', 'lower'), $columns)
            );
        }

        return $indexes;
    }

    /**
     * Returns a list of table columns.
     *
     * @param string $tableName  A table name.
     * @param string $name       (can be removed?)
     *
     * @return array  A list of Horde_Db_Adapter_Base_Column objects.
     */
    public function columns($tableName, $name = null)
    {
        $rows = @unserialize($this->_cache->get("tables/columns/$tableName", 0));

        if (!$rows) {
            $rows = $this->selectAll(
                'SELECT COLUMN_NAME, DATA_DEFAULT, DATA_TYPE, NULLABLE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE FROM USER_TAB_COLUMNS WHERE TABLE_NAME = ?',
                array(Horde_String::upper($tableName)),
                $name
            );

            $this->_cache->set("tables/columns/$tableName", serialize($rows));
        }

        // Create columns from rows.
        $columns = array();
        foreach ($rows as $row) {
            $column = Horde_String::lower($row['column_name']);
            $columns[$column] = $this->makeColumn(
                $column,
                $row['data_default'],
                $row['data_type'],
                $row['nullable'] != 'N',
                $row['data_length'],
                $row['data_precision'],
                $row['data_scale']
            );
        }

        return $columns;
    }

    /**
     * Renames a table.
     *
     * @param string $name     A table name.
     * @param string $newName  The new table name.
     */
    public function renameTable($name, $newName)
    {
        $this->_clearTableCache($name);
        return $this->execute(
            sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteTableName($name),
                $this->quoteTableName($newName)
            )
        );
    }

    /**
     * Drops a table from the database.
     *
     * @param string $name  A table name.
     */
    public function dropTable($name)
    {
        $pk = $this->primaryKey($name);
        if (count($pk->columns) == 1) {
            $prefix = $name . '_' . $pk->columns[0];
            try {
                $this->execute(sprintf(
                    'DROP SEQUENCE %s',
                    $this->quoteColumnName($prefix . '_seq')
                ));
            } catch (Horde_Db_Exception $e) {
            }
            try {
                $this->execute(sprintf(
                    'DROP TRIGGER %s',
                    $this->quoteColumnName($prefix . '_trig')
                ));
            } catch (Horde_Db_Exception $e) {
            }
        }
        parent::dropTable($name);
    }

    /**
     * Adds a new column to a table.
     *
     * @param string $tableName   A table name.
     * @param string $columnName  A column name.
     * @param string $type        A data type.
     * @param array $options      Column options. See
     *                            Horde_Db_Adapter_Base_TableDefinition#column()
     *                            for details.
     */
    public function addColumn($tableName, $columnName, $type,
                              $options = array())
    {
        $this->_clearTableCache($tableName);

        $options = array_merge(
            array('limit'     => null,
                  'precision' => null,
                  'scale'     => null,
                  'unsigned'  => null),
            $options);

        $sql = $this->quoteColumnName($columnName)
            . ' '
            . $this->typeToSql(
                $type,
                $options['limit'],
                $options['precision'],
                $options['scale'],
                $options['unsigned']
            );
        $sql = $this->addColumnOptions($sql, $options);
        $sql = sprintf(
            'ALTER TABLE %s ADD (%s)',
            $this->quoteTableName($tableName),
            $sql
        );

        return $this->execute($sql);
    }

    /**
     * Removes a column from a table.
     *
     * @param string $tableName   A table name.
     * @param string $columnName  A column name.
     */
    public function removeColumn($tableName, $columnName)
    {
        $this->_clearTableCache($tableName);
        $sql = sprintf('ALTER TABLE %s DROP COLUMN %s',
                       $this->quoteTableName($tableName),
                       $this->quoteColumnName($columnName));
        return $this->execute($sql);
    }

    /**
     * Changes an existing column's definition.
     *
     * @param string $tableName   A table name.
     * @param string $columnName  A column name.
     * @param string $type        A data type.
     * @param array $options      Column options. See
     *                            Horde_Db_Adapter_Base_TableDefinition#column()
     *                            for details.
     */
    public function changeColumn($tableName, $columnName, $type, $options = array())
    {
        $this->_clearTableCache($tableName);

        $options = array_merge(
            array(
                'limit'     => null,
                'precision' => null,
                'scale'     => null,
                'unsigned'  => null
            ),
            $options
        );

        if ($type == 'binary') {
            $this->beginDbTransaction();
            $this->addColumn($tableName, $columnName . '_tmp', $type, $options);
            $this->update(sprintf(
                'UPDATE %s SET %s = utl_raw.cast_to_raw(%s)',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName . '_tmp'),
                $this->quoteColumnName($columnName)
            ));
            $this->removeColumn($tableName, $columnName);
            $this->renameColumn($tableName, $columnName . '_tmp', $columnName);
            $this->commitDbTransaction();
            return;
        }

        $sql = sprintf('ALTER TABLE %s MODIFY (%s %s)',
                       $this->quoteTableName($tableName),
                       $this->quoteColumnName($columnName),
                       $this->typeToSql($type,
                                        $options['limit'],
                                        $options['precision'],
                                        $options['scale'],
                                        $options['unsigned']));
        $sql = $this->addColumnOptions($sql, $options);

        return $this->execute($sql);
    }

    /**
     * Sets a new default value for a column.
     *
     * If you want to set the default value to NULL, you are out of luck. You
     * need to execute the apppropriate SQL statement yourself.
     *
     * @param string $tableName   A table name.
     * @param string $columnName  A column name.
     * @param mixed $default      The new default value.
     */
    public function changeColumnDefault($tableName, $columnName, $default)
    {
        $this->_clearTableCache($tableName);
        $sql = sprintf('ALTER TABLE %s MODIFY (%s DEFAULT %s)',
                       $this->quoteTableName($tableName),
                       $this->quoteColumnName($columnName),
                       $this->quote($default));
        return $this->execute($sql);
    }

    /**
     * Renames a column.
     *
     * @param string $tableName      A table name.
     * @param string $columnName     A column name.
     * @param string $newColumnName  The new column name.
     */
    public function renameColumn($tableName, $columnName, $newColumnName)
    {
        $this->_clearTableCache($tableName);
        $sql = sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s',
                       $this->quoteTableName($tableName),
                       $this->quoteColumnName($columnName),
                       $this->quoteColumnName($newColumnName));
        return $this->execute($sql);
    }

    /**
     * Removes a primary key from a table.
     *
     * @param string $tableName  A table name.
     *
     * @throws Horde_Db_Exception
     */
    public function removePrimaryKey($tableName)
    {
        $this->_clearTableCache($tableName);
        $sql = sprintf('ALTER TABLE %s DROP PRIMARY KEY',
                       $this->quoteTableName($tableName));
        return $this->execute($sql);
    }

    /**
     * Removes an index from a table.
     *
     * @param string $tableName      A table name.
     * @param string|array $options  Either a column name or index options:
     *                               - name: (string) the index name.
     *                               - column: (string|array) column name(s).
     */
    public function removeIndex($tableName, $options = array())
    {
        $this->_clearTableCache($tableName);

        $index = $this->indexName($tableName, $options);
        $sql = sprintf('DROP INDEX %s',
                       $this->quoteColumnName($index));

        return $this->execute($sql);
    }

    /**
     * Builds the name for an index.
     *
     * Cuts the index name to the maximum length of 30 characters limited by
     * Oracle.
     *
     * @param string $tableName      A table name.
     * @param string|array $options  Either a column name or index options:
     *                               - column: (string|array) column name(s).
     *                               - name: (string) the index name to fall
     *                                 back to if no column names specified.
     */
    public function indexName($tableName, $options = array())
    {
        return substr(parent::indexName($tableName, $options), 0, 30);
    }

    /**
     * Creates a database.
     *
     * @param string $name    A database name.
     * @param array $options  Database options.
     */
    public function createDatabase($name, $options = array())
    {
        return $this->execute(sprintf('CREATE DATABASE %s', $this->quoteTableName($name)));
    }

    /**
     * Drops a database.
     *
     * @param string $name  A database name.
     */
    public function dropDatabase($name)
    {
        if ($this->currentDatabase() != $name) {
            throw new Horde_Db_Exception('Oracle can only drop the current database');
        }
        return $this->execute('DROP DATABASE');
    }

    /**
     * Returns the name of the currently selected database.
     *
     * @return string  The database name.
     */
    public function currentDatabase()
    {
        return $this->selectValue("SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') FROM DUAL");
    }

    /**
     * Adds default/null options to column SQL definitions.
     *
     * @param string $sql     Existing SQL definition for a column.
     * @param array $options  Column options:
     *                        - column: (Horde_Db_Adapter_Base_ColumnDefinition
     *                          The column definition class.
     *                        - null: (boolean) Whether to allow NULL values.
     *                        - default: (mixed) Default column value.
     *                        - autoincrement: (boolean) Whether the column is
     *                          an autoincrement column. Driver depedendent.
     *
     * @return string  The manipulated SQL definition.
     */
    public function addColumnOptions($sql, $options)
    {
        /* 'autoincrement' is not handled here - it varies too much between
         * DBs. Do autoincrement-specific handling in the driver. */

        if (isset($options['default'])) {
            $default = $options['default'];
            $column  = isset($options['column']) ? $options['column'] : null;
            $sql .= ' DEFAULT ' . $this->quote($default, $column);
        }

        if (isset($options['null']) && $options['null'] === false &&
            (!isset($options['column']) ||
             ($options['column']->getType() != 'text' &&
              $options['column']->getType() != 'binary'))) {
            $sql .= ' NOT NULL';
        }

        return $sql;
    }


    /*##########################################################################
    # Protected
    ##########################################################################*/

    /**
     * Clears the cache for tables when altering them.
     *
     * @param string $tableName  A table name.
     */
    protected function _clearTableCache($tableName)
    {
        parent::_clearTableCache($tableName);
        $this->_cache->set('tables/primarykeys/' . $tableName, '');
    }
}
