<?php

abstract class CWAC_ActiveRecord
{
    /**
     * Name of the database table bound to this class.
     * Will override the default setting which is
     * "lowercase class name" = "table name".
     *
     * @var string
     */
    protected $_tableName = null;
    
    /**
     * Name of the connection to be used by this particular
     * CWAC_ActiveRecord instance. Must be defined in config array.
     *
     * @var string
     */
    protected $_useConnection = 'default';
    
    /**
     * Holds the current statement used in a find() operation
     *
     * @var PDOStatement
     */
    protected $_currentStatement = null;
    
    /**
     * A history of SQL strings sent to the database by this
     * CWAC_ActiveRecord instance
     *
     * @var array
     */
    protected $_queryHistory = array();
    
    /**
     * Original state of the object data right after a fetch.
     * Will be used to build a WHERE condition on object updates.
     *
     * @var array
     */
    protected $_originalState = array();
    
    /**
     * Number of rows to limit query result to
     *
     * @var integer
     */
    protected $_limit = null;
    
    /**
     * Row offset to start query results from (not supported
     * by all databases, e.g. Microsoft SQL Server)
     *
     * @var integer
     */
    protected $_offset = null;
    
    /**
     * Array of fields to order query results by.
     *
     * @var array
     */
    protected $_orderBy = array();
    
    /**
     * Order direction: ASC (default) or DESC
     *
     * @var string
     */
    protected $_orderDirection = 'ASC';
    
    /**
     * Where string added by the user, using whereAdd()
     *
     * @var string
     */
    protected $_userWhere = '';
    
    /**
     * Global connection pool for all CWAC_ActiveRecord instances.
     * Once a connection name from the config array has been
     * used, the corresponding PDO connection object will be
     * stored in this array, having the connection name as key.
     *
     * @var array
     */
    static protected $_connections = array();
    
    /**
     * The global configuration array for all CWAC_ActiveRecord
     * instances as set using CWAC_ActiveRecord::setConfig().
     *
     * @var array
     */
    static protected $_config = array();
    
    /**
     * Array of previously used prepared statement objects,
     * using the generated SQL string as keys. Rumour has it
     * that PDO does something like this internally, so this
     * might go away in the future.
     *
     * @var array
     */
    static protected $_statementCache = array();
    
    /**
     * Due to a bug in PHP 5.1.4 with APC enabled and 5.1.6
     * even without APC, I decided to make usage of the
     * statement cache configurable. It will default to off
     * (false) as long as there isn't a recent PHP version
     * without this bug.
     *
     * @var boolean
     */
    static public $useStatementCache = false;
        
    
    /**
     * Constructor. Sets the tablename and fetches a record
     * from the database if a primary key id has been given.
     * Id can be of type integer or string, depending on how
     * your database tabels are defined.
     *
     * @param mixed $id
     */
    public function __construct($id=null)
    {
        $this->_tableName = $this->_tableName();

        if ($id !== null) {
            $this->_getByPK($id);
        }
    }
    
    /**
     * Gets the PDO connection object for the given connection
     * name (defaults to "default"). If a connection is already
     * present, it will be reused for all Instances.
     *
     * @param string $connectionName
     * @return PDO
     */
    public static function getConnection($connectionName='default')
    {
        if (!empty(self::$_connections[$connectionName])) {
            return self::$_connections[$connectionName];
        }
        if (empty(self::$_config['connections'][$connectionName])) {
            if (!empty(self::$_connections['default'])) {
                // return default connection if desired connection is not configured
                return self::$_connections['default'];
            } elseif (!empty(self::$_config['connections']['default'])) {
                // if "default" is not yet present, there probably hasn't been
                // any database operation yet and the first call was to a
                // nonstandard connection (e.g. "write") - thus, we have to initialize
                // "default" first!
                $connectionName = 'default';
            } else {
                // no connection was configured... AT ALL
                throw new CWAC_ActiveRecordException("No configuration entries for database connections!");
            }
        }
        $dsn = $user = $password = $options = null;
        extract(self::$_config['connections'][$connectionName], EXTR_IF_EXISTS);
        $pdo = new PDO($dsn, $user, $password, $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (stripos($dsn, 'mysql') !== false) {
            if (version_compare(PHP_VERSION, '5.1.3') == -1) {
                trigger_error('MySQL prepared statement emulation not available in your PHP/PDO version - expect errors!', E_USER_WARNING);
            } else {
                /**
                 * This is a neccessary workaround because of this bug:
                 * http://bugs.php.net/bug.php?id=35793
                 * Bug occurs in DynLinkTest::testGetBooksFromPublisher() if
                 * emulation mode is not set.
                 */
                if (defined('PDO::ATTR_EMULATE_PREPARES')) {
                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                } else {
                    trigger_error('PDO version seems to be quite old - update is strongly recommended!', E_USER_NOTICE);
                }
            }
        }
        if (!empty(self::$_config['init_query'])) {
            $pdo->query(self::$_config['init_query']);
        }
        if (!empty(self::$_config['pdo_flags']) && is_array(self::$_config['pdo_flags'])) {
            foreach(self::$_config['pdo_flags'] as $flag => $value) {
                $pdo->setAttribute($flag, $value);
            }
        }
        self::$_connections[$connectionName] = $pdo;
        return $pdo;
    }
    
    /**
     * Can be used to reuse an existing PDO database connection object
     * with CWAC_ActiveRecord. Using setConnection(), you don't need
     * to provide database connection information in the setConfig() 
     * array, ActiveRecord will not try to make a new connection.
     * 
     * If you have two different database connections for read and write
     * operations, set the optional second parameter to 'write' to use
     * the given connection object for all write queries (insert, update,
     * delete).
     *
     * @param PDO $pdo
     * @param string $connectionName
     */
    public static function setConnection(PDO $pdo, $connectionName = 'default') {
        self::$_connections[$connectionName] = $pdo;
    }
    
    /**
     * Retrieves the current PDOStatement object used by the last find()
     * operation or FALSE if find() has not yet been called.
     *
     * @return PDOStatement object or boolean false
     */
    public function getCurrentStatement()
    {
        if ($this->_currentStatement instanceof PDOStatement) {
            return $this->_currentStatement;
        }
        return false;
    }
    
    /**
     * Returns the id field value of the previous insert/update
     * operation, if available.
     * 
     * @TODO Implement compatibility stuff for databases that do not support this
     * @return integer
     */
    protected function _getLastInsertId()
    {
        return $this->getConnection('write')->lastInsertId();
    }
    
    /**
     * Purges all previous data from field properties, sets the id
     * property, generates the SELECT query and populates the object
     * with the data from the first record found.
     * Id value can be integer or string depending on how your database
     * tables are defined.
     *
     * @param mixed $id
     * @return boolean
     */
    protected function _getByPK($id)
    {
        $this->_purgeFields();
        $this->id = $id;
        $stmt = $this->_selectQuery();
        $this->_populate($stmt->fetch(PDO::FETCH_ASSOC));
        $stmt->closeCursor();
        return true;
    }
    
    /**
     * Populates field properties with values from an
     * associative array representing a table row. Will make
     * a copy of the current state in the _originalState property
     * so the user can alter data and use the original data for
     * building WHERE conditions in UPDATE statements.
     *
     * @param array $row
     * @return boolean
     */
    protected function _populate($row)
    {
        if (!is_array($row)) {
            return false;
        }
        $this->_purgeFields(); // ditch old data
        foreach($row as $key => $value) {
            $this->$key = $value;
        }
        $this->_originalState = $this->toArray(); // preserve state
        return true;
    }
    
    /**
     * Purges all previous data from field properties,
     * so the object can be freshly used again.
     */
    protected function _purgeFields()
    {
        $fields = $this->getFieldNames();
        foreach($fields as $field) {
            $this->$field = null;
        }
    }
    
    /**
     * Iterates through all object properties and returns
     * an array of those that are public and do not start
     * with an underscore. All returned fields are considered
     * fields present in the corresponding database table.
     *
     * @return array
     */
    public function getFieldNames()
    {
        $me = (array) $this;
        $fields = array();
        foreach($me as $key => $value) {
            // only public properties!
            if (substr($key, 0, 1) != '_' && substr($key, 0, 1) != "\0") {
                $fields[] = $key;
            }
        }
        return $fields;
    }
    
    /**
     * Generates an SQL SELECT query, and prepares/executes
     * a statement with the currently set values of the
     * object's field properties. Returns the executed
     * PDOStatement object.
     *
     * @return PDOStatement object
     */
    protected function _selectQuery()
    {
        $pdo       = self::getConnection();
        $whereSQL  =  '';
        $selectSQL =  '';
        $bindings  =  array();
        $fields    =  $this->getFieldNames();
        
        // close cursor of previously used statement
        if ($this->_currentStatement instanceof PDOStatement) {
            $this->_currentStatement->closeCursor();
        }
        
        foreach($fields as $key) {
            $value = $this->$key;
            if ($selectSQL != '') {
                $selectSQL .= ', ';
            }
            $selectSQL .= $key;
            if ($value !== null) {
                if ($whereSQL != '') {
                    $whereSQL .= ' AND ';
                }
                if ($value instanceof CWAC_ActiveRecordTypeNull) {
                    $whereSQL .= $key.' IS NULL';
                } else {
                    $whereSQL .= $key.' = :'.$key;
                    $bindings[':'.$key] = $value;
                }
            }
        }
        $whereSQL .= $this->_userWhere;
        
        if (empty(self::$_config['explicitSelects'])) {
            $selectSQL = '*';
        }
        $sql = 'SELECT '.$selectSQL.' FROM '.$this->_tableName;
        if (trim($whereSQL) != '') {
            $sql .= ' WHERE '.$whereSQL;
        }
        error_log($sql."\nBindings: ".print_r($bindings,true));
        $stmt = $this->_executeStatement($sql, $bindings);
        return $stmt;
    }
    
    /**
     * Executes a custom query, bypassing all query-generation.
     * The resultset can afterwards be iterated using the usual
     * methods, given that it contains columns with the same
     * names as the defined object properties.
     * Parameters to the query must be passed via an array in the
     * second parameter, having ":fieldName" as the keys (the same
     * type of placeholder must obviously be used in the query string)
     * 
     * @param string $sql The custom SQL
     * @param array $bindings The parameters for the SQL query
     * @return PDOStatement
     */
    public function executeCustomQuery($sql, $bindings=array()) {
	    // close cursor of previously used statement
        if ($this->_currentStatement instanceof PDOStatement) {
            $this->_currentStatement->closeCursor();
        }
        $stmt = $this->_executeStatement($sql, $bindings);
        $this->_currentStatement = $stmt;
        return $stmt;
    }
    
    /**
     * See _selectQuery, but, well, generates an update query.
     * Will build WHERE condition according to what's found in
     * the _originalState property (see _populate()). If previous
     * and current states are identical, will return boolean false.
     *
     * @param boolean $useNullValues
     * @return PDOStatement object or false if nothing was done
     * @see CWAC_ActiveRecord::_populate()
     */
    protected function _updateQuery($useNullValues=false)
    {
        $whereSQL     =  '';
        $setSQL       =  '';
        $bindings     =  array();
        $fields       =  $this->getFieldNames();
        $updateValues = array();
        
        foreach($fields as $key) {
            $value = $this->$key;
            if ($value != $this->_originalState[$key]) {
                if ($setSQL != '') {
                    $setSQL .= ', ';
                }
                $setSQL .= $key;
                if ($value instanceof CWAC_ActiveRecordTypeNull || $value === null) {
                    $setSQL .= ' = NULL';
                } else {
                    $setSQL .= ' = :'.$key;
                    $bindings[':'.$key] = $value;
                }
            }
        }
        
        // if untouched, do nothing
        if ($setSQL == '') {
            return false;
        }
        
        foreach($this->_originalState as $key => $value) {
            if ($whereSQL != '') {
                $whereSQL .= ' AND ';
            }
            $whereSQL .= $key;
            if ($value instanceof CWAC_ActiveRecordTypeNull || $value === null) {
                $whereSQL .= ' IS NULL';
            } else {
                $whereSQL .= ' = :w_'.$key;
                $bindings[':w_'.$key] = $value;
            }
        }
        $whereSQL .= $this->_userWhere;
        
        $sql = 'UPDATE '.$this->_tableName.' SET '.$setSQL;
        if (trim($whereSQL) != '') {
            $sql .= ' WHERE '.$whereSQL;
        }
        $stmt = $this->_executeStatement($sql, $bindings, 'write');
        return $stmt;
    }
    
    /**
     * Generates and executes an INSERT query using the current
     * object state for field values. Will populate the object's
     * id property with the new record's id.
     * By setting the $forceIdField parameter to true, the insert
     * query will contain the value of the "id" property. Otherwise
     * it will be left out to prevent problems with DBMS not returning
     * auto-increment values (default setting).
     *
     * @param boolean $forceIdField
     * @return PDOStatement object
     */
    protected function _insertQuery($forceIdField=false)
    {
        $insertSQL =  '';
        $valueSQL  =  '';
        $bindings  =  array();
        $fields    =  $this->getFieldNames();
        
        foreach($fields as $key) {
            if ($key == 'id' && !$forceIdField) {
                // not even mention the ID in case some DMBS fails to trigger auto-increment otherwise
                continue;
            }
            $value = $this->$key;
            
            if ($value !== null) {
                if ($insertSQL != '') {
                    $insertSQL .= ', ';
                }
                $insertSQL .= $key;
                if ($valueSQL != '') {
                    $valueSQL .= ', ';
                }
                if ($value instanceof CWAC_ActiveRecordTypeNull) {
                    $valueSQL .= 'NULL';
                } else {
                    $valueSQL .= ':'.$key;
                    $bindings[':'.$key] = $value;
                }
            }
        }
        
        $sql = 'INSERT INTO '.$this->_tableName.' ('.$insertSQL.') VALUES ('.$valueSQL.')';
        $stmt = $this->_executeStatement($sql, $bindings, 'write');
        $this->id = $this->_getLastInsertId();
        return $stmt;
    }
    
    /**
     * Saves the current state of the object to the database.
     * Depending on whether or not the 'id' property is set, the
     * SQL query will either be an INSERT or UPDATE one.
     * Returns the PDOStatement object used for the query (you can,
     * for example, call rowCount() on that to see how many rows
     * have been affected).
     * Under one condition, this method can return false: When calling
     * save() on an unchanged object, no update query is performed and
     * false is returned.
     * When inserting records, the new id will be found in the "id"
     * property of the current object after the insert operation.
     * Note: You can force an "insert" operation even when the "id"
     * property is not null by setting the $forceInsert parameter to
     * true.
     *
     * @param boolean $forceInsert
     * @return PDOStatement object or false on failed update
     */
    public function save($forceInsert=false)
    {
        if ($forceInsert || $this->id === null) {
            return $this->_insertQuery($forceInsert);
        } else {
            return $this->_updateQuery();
        }
    }
    
    /**
     * Builds SQL for a DELETE-query based on the object's
     * current state.
     * Returns the PDOStatement object used for the query (you can,
     * for example, call rowCount() on that to see how many rows
     * have been affected).
     * 
     * @return PDOStatement object
     */
    public function delete()
    {
        $bindings  =  array();
        $fields    =  $this->getFieldNames();
        $sql       = 'DELETE FROM '.$this->_tableName;
        $whereSQL  = null;
        
        foreach($fields as $key) {
            $value = $this->$key;
            if ($value !== null) {
                if ($whereSQL != '') {
                    $whereSQL .= ' AND ';
                }
                $whereSQL .= $key;
                if ($value instanceof CWAC_ActiveRecordTypeNull) {
                    $whereSQL .= ' IS NULL';
                } else {
                    $whereSQL .= '=:'.$key;
                    $bindings[':'.$key] = $value;
                }
            }
        }
        $whereSQL .= $this->_userWhere;
        
        if ($whereSQL != null) {
            $sql .= ' WHERE '.$whereSQL;
        }
        
        $stmt = $this->_executeStatement($sql, $bindings, 'write');
        return $stmt;
    }
    
    /**
     * Adds a condition to the WHERE part of the query. Uses AND operator
     * to concatenate conditions on multiple whereAdd() calls by default.
     * 
     * Usage example:
     * $book->whereAdd("author LIKE '%Adams'");
     * $book->whereAdd("title LIKE 'Hitchhiker%'", 'OR');
     *
     * @param string $condition The SQL condition to use
     * @param string $operator  The SQL operator to use (AND, OR...)
     * @return boolean
     */
    public function whereAdd($condition, $operator='AND') {
    	if (trim($condition) == '') {
    		return false;
    	}
    	if ($this->_userWhere != '') {
    		$this->_userWhere .= ' '.$operator;
    	}
    	$this->_userWhere .= ' '.$condition;
    	return true;
    }
    
    /**
     * Takes an SQL query string along with data bindings
     * and executes it as a stored procedure. Every SQL string
     * will be added to the object's internal _queryHistory property.
     * The global statement cache is being used.
     * Will return the resulting PDOStatement object.
     *
     * @param string $sql
     * @param array $bindings
     * @return PDOStatement object
     */
    protected function _executeStatement($sql, $bindings, $connectionName='default')
    {
        if (!empty($this->_orderBy)) {
            $sql .= ' ORDER BY '.implode(',', $this->_orderBy);
            $sql .= ' '.$this->_orderDirection;
        }
        if ($this->_limit != null) {
        	if ($this->_offset != null) {
        		$sql .= ' LIMIT '.$this->_offset.','.$this->_limit;
            } else {
            	$sql .= ' LIMIT '.$this->_limit;
            }
        }
        $this->_queryHistory[] = $sql;
        //error_log('Executing statement: '.$sql);
        if (self::$useStatementCache && isset(self::$_statementCache[$sql])) {
            // error_log('Reusing existing statement');
            $stmt = self::$_statementCache[$sql];
        } else {
            $pdo = self::getConnection($connectionName);
            // error_log('Preparing new statement');
            $stmt = $pdo->prepare($sql);
            if (self::$useStatementCache) {
                self::$_statementCache[$sql] = $stmt;
            }
        }
        // error_log('Executing with bindings: '.print_r($bindings, true));
        $stmt->execute($bindings);
        return $stmt;
    }
    
    /**
     * Limit the number of rows returned using the LIMIT statement.
     * If supported by the database, you can optionally provide
     * an offset value. Examples for databases supporting LIMIT offset
     * are MySQL and PostgreSQL. Must, of course, be called before
     * find().
     * 
     * Warning: If "offset" is used with a database that does not
     * support it, executing the statement will most probably throw
     * an exception!
     *
     * @param integer $limit
     * @param integer $offset
     */
    public function limit($limit, $offset=false)
    {
        $this->_limit = (int) $limit;
        if ($offset !== false) {
            $this->_offset = (int) $offset;
        }
    }
    
    /**
     * Sets the fields to be used for an ORDER BY statement.
     * Will accept either a single string containing the field
     * name or an array of field names.
     *
     * @param mixed $fieldNames
     */
    public function orderBy($fieldNames, $direction='ASC')
    {
        $validFields = $this->getFieldNames();
        $fieldNames = (array) $fieldNames;
        foreach ($fieldNames as $field) {
        	if (in_array($field, $validFields) && !in_array($field, $this->_orderBy)) {
        	    $this->_orderBy[] = $field;
        	}
        }
        $direction = strtoupper($direction);
        if ($direction == 'ASC' || $direction == 'DESC') {
        	$this->_orderDirection = $direction;
        }
    }
    
    /**
     * Returns an array of SQL queries generated by this object instance
     *
     * @return array
     */
    public function getQueryHistory()
    {
        return $this->_queryHistory;
    }
    
    /**
     * Returns the name of the database table bound to this class.
     * Override in child classes to customize table names.
     * Table names currently default to the lowercase version of
     * the class name - THIS MAY CHANGE IN THE FUTURE !!!!!
     *
     * @return string
     */
    protected function _tableName()
    {
        return strtolower(get_class($this));
    }
    
    /**
     * Globally sets the configuration array used by all
     * CWAC_ActiveRecord instances
     *
     * @param array $config
     * @return boolean
     * @throws CWAC_ActiveRecordException
     * @TODO Properly document all possible configuration options
     */
    public static function setConfig($config)
    {
        if (!in_array('connections', array_keys($config)) || !in_array('model_path', array_keys($config))) {
            throw new CWAC_ActiveRecordException('Configuration array is missing at least one of the mandatory options ("connections", "model_path")');
        }
        self::$_config = $config;
        return true;
    }
    
    /**
     * Retrieves the configuration array used by all CWAC_ActiveRecord instances
     *
     * @return array
     */
    public static function getConfig()
    {
        return self::$_config;
    }
    
    /**
     * Generates the SQL SELECT query needed to find all records
     * that match the current field property values. Returns the
     * PDOStatement object with (potentially) found records.
     * To iterate through the resultset, you can either use the PDOStatement
     * object or use a while() construct with the fetch() method to
     * have all field properties filled with the next rows' values on
     * each iteration. Example:
     * <code>
     * $my_table->find();
     * while($my_table->fetch()) {
     *     echo "Name: ".$my_table->name;
     * }
     * </code>
     *
     * @return PDOStatement object
     */
    public function find()
    {
        $stmt = $this->_selectQuery(); /* @var $stmt PDOStatement */
        $this->_currentStatement = $stmt;
        return $stmt;
    }
    
    /**
     * Fetches the next record from the current resultset and fills
     * all field properties of the current object with the values
     * from the next result row. Returns false if there are no more
     * results, true otherwise.
     * The find() method must be called before using fetch() - otherwise,
     * an exception will be thrown.
     *
     * @return boolean
     */
    public function fetch()
    {
        if (!($this->_currentStatement instanceof PDOStatement)) {
            throw(new CWAC_ActiveRecordException('find() has to be called before fetch()'));
        }
        $stmt = $this->_currentStatement; /* @var $stmt PDOStatement */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt->closeCursor();
            return false;
        }
        $this->_populate($row);
        return true;
    }
    
    /**
     * Returns an associative array containing all
     * field properties of the object instance with their
     * current data.
     *
     * @return array
     */
    public function toArray()
    {
        $fields = $this->getFieldNames();
        $data   = array();
        foreach($fields as $field) {
            $data[$field] = $this->$field;
        }
        return $data;
    }
    
    /**
     * Sets all field properties using the given input array
     * that must provide array keys identical to the field names.
     * Disregards all array keys that have no matching field.
     *
     * @param array $data
     */
    public function setFromArray($data)
    {
        $fields = $this->getFieldNames();
        foreach($data as $key => $value) {
        	if (in_array($key, $fields)) {
            	$this->$key = $value;
        	} else {
        		user_error("Tried to set illegal property '$key' with value '$value' using setFromArray()", E_USER_NOTICE);
        	}
        }
    }
    
    /**
     * Magic method to catch all read operations on undefined
     * properties. Can be used to retrieve data from related tables.
     *
     * @param string $field
     * @return CWAC_ActiveRecord object
     * @TODO Better documentations - this one needs thorough examples!
     */
    public function __GET($field)
    {
        $modelPath = self::$_config['model_path'];
        @include_once $modelPath.$field.'.php';
        if (!class_exists($field)) {
            throw new CWAC_ActiveRecordException("Class '$field' does not exist.");
        }
        // does $this have a link to that class?
        $foreignKeyName = strtolower($field).'_id';
        if (!empty($this->$foreignKeyName)) {
            $obj = new $field($this->$foreignKeyName);
        } else {
            // no, so we assume that class is linked to $this
            $obj = new $field();
            $fieldList = array_keys((array)$obj);
            $foreignKeyName = strtolower($this->_tableName).'_id';
            if (in_array($foreignKeyName, $fieldList)) {
                $obj->$foreignKeyName = $this->id;
            }
            $obj->find();
        }
        return $obj;
    }
    
    /**
     * Disallow setting of undefined properties (would mess up field list)
     *
     * @param string $field
     * @param mixed $value
     */
    public function __SET($field, $value)
    {
        // do nothing
    }
    
    /**
     * Class destructor - will clean up on object destruction, i.e.
     * close cursor on remaining PDOStatement and maybe more in the future.
     * Remember: The destructor is only called on script shutdown *or* when
     * you explicitly destroy your object via unset(). Just overwriting the
     * object variable with another object won't do the trick!
     */
    public function __destruct()
    {
        if ($this->_currentStatement instanceof PDOStatement) {
            $this->_currentStatement->closeCursor();
        }
    }
}

class CWAC_ActiveRecordTypeNull
{
}

class CWAC_ActiveRecordException extends Exception
{
}

?>