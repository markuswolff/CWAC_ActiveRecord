<?php
require_once 'config.php';
require_once 'PHPUnit/Framework/TestCase.php';

class BasicTest extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        exec(MYSQL_BIN_PATH.'mysqladmin create test_books');
        exec(MYSQL_BIN_PATH.'mysql -u root test_books < sql/mysql_create.sql');
        exec(MYSQL_BIN_PATH.'mysql -u root test_books < sql/common_data.sql');
    }
    
    protected function tearDown()
    {
        exec(MYSQL_BIN_PATH.'mysqladmin -f drop test_books');
    }
    
    public function testSetFaultyConfig()
    {
        /*
         The configuration array should contain at least the keys
         'connections' and 'model_path'.
        */
        $bullconfig = array('not' => 'what', 'is' => 'expected');
        $ex = null;
        try {
            CWAC_ActiveRecord::setConfig($bullconfig);
        } catch (CWAC_ActiveRecordException $ex) {
        }
        $this->assertTrue($ex instanceof CWAC_ActiveRecordException, 'Setting faulty configuration does not cause CWAC_ActiveRecordException.');
    }
    
    public function testNewInstance()
    {
        require_once('model/Book.php');
        $book = new Book();
        $this->assertTrue(($book instanceof Book), '$book is not an instance of Book - require failed?');
        $this->assertTrue(($book instanceof CWAC_ActiveRecord), '$book is not an instance of CWAC_ActiveRecord - check inheritance!');
    }
    
    public function testNoConnectionUntilFirstQuery()
    {
        require_once('model/Book.php');
        $book = new Book();
        $ref = new ReflectionObject($book);
        $prop = $ref->getProperty('_connections');
        $conn = $prop->getValue();
        $this->assertFalse(($conn instanceof PDO), 'There already is a connection just after creating the instance.');
    }
    
    public function testGetConnection()
    {
        $pdo = CWAC_ActiveRecord::getConnection();
        $this->assertTrue(($pdo instanceof PDO), 'getConnection() does not return instance of PDO');
    }
    
    public function testSetConnection()
    {
        $config = CWAC_ActiveRecord::getConfig();
        $dsn = $user = $password = $options = null;
        extract($config['connections']['default'], EXTR_IF_EXISTS);
        $pdo = new PDO($dsn, $user, $password, $options);
        CWAC_ActiveRecord::setConnection($pdo);
        require_once('model/Book.php');
        $book = new Book();
        $book->find();
        $pdo2 = $book->getConnection();
        $this->assertTrue(($pdo === $pdo2), 'Connection instance after find() is not identical to the one passed to setConnection()');
    }
    
    public function testGetCurrentStatement()
    {
        require_once('model/Book.php');
        $book = new Book();
        $book->find();
        $stmt =& $book->getCurrentStatement();
        $this->assertTrue(($stmt instanceof PDOStatement), '$stmt is not a PDOStatement object');
        $stmt->closeCursor();
    }
    
    public function testSelectRecordById()
    {
        require_once('model/Book.php');
        $book = new Book(1);
        $this->assertEquals('1', $book->id, "\$book->id is '{$book->id}', expected: 1");
        $this->assertEquals('The Hitchhiker\'s Guide To The Galaxy',
                            $book->title,
                            "\$book->title is '{$book->title}', expected: 'The Hitchhiker's Guide To The Galaxy'");
        $this->assertEquals('Douglas Adams',
                            $book->author,
                            "\$book->author is '{$book->author}', expected: 'Douglas Adams'");
    }
        
    public function testSelectAllRecords()
    {
        require_once('model/Book.php');
        $book = new Book();
        $stmt = $book->find();
        $this->assertTrue(($stmt instanceof PDOStatement), '$stmt is not a PDOStatement object');
        $num = count($stmt->fetchAll());
        $this->assertEquals(7, $num, "Row count is '$num', expected 7 from test data.");
    }
    
    public function testFetchAllRecords()
    {
        require_once('model/Book.php');
        $book = new Book();
        $book->find();
        $i = 0;
        while($book->fetch()) {
            $i++;
        }
        $this->assertEquals(7, $i, "Row count is '$i', expected 7 from test data.");
        $this->assertEquals('7', $book->id, 'wrong $book->id');
        $this->assertEquals('Neuromancer', $book->title, 'wrong $book->title');
        $this->assertEquals('William Gibson', $book->author, 'wrong $book->author');
    }
    
    public function testInsertRecord()
    {
        require_once('model/Book.php');
        $book = new Book();
        $book->title = 'The Greatest Book Ever Written';
        $book->author = 'Markus Wolff';
        $stmt = $book->save();
        $this->assertTrue($stmt instanceof PDOStatement, 'save() does not return PDOStatement instance');
        $this->assertTrue(is_numeric($book->id), 'New book ID is not numeric');
        $control = new Book($book->id);
        $this->assertTrue(is_numeric($control->id), 'Control book ID is not numeric');
        $this->assertEquals('Markus Wolff', $control->author, 'Unexpected book author');
        $this->assertEquals('The Greatest Book Ever Written', $control->title, 'Unexpected book title');
    }
    
    public function testUpdateRecord()
    {
        require_once('model/Book.php');
        $book = new Book(2);
        $book->title = 'Hogfather';
        $stmt = $book->save();
        $this->assertTrue($stmt instanceof PDOStatement, 'save() does not return PDOStatement instance');
        $this->assertEquals(1, $stmt->rowCount(), 'Unexpected number of affected rows');
        $control = new Book(2);
        $this->assertEquals('2', $control->id, 'Unexpected book id after update');
        $this->assertEquals('Terry Pratchett', $control->author, 'Unexpected book author');
        $this->assertEquals('Hogfather', $control->title, 'Unexpected book title');
    }
    
    public function testDeleteRecord()
    {
        require_once('model/Book.php');
        $book = new Book(3);
        $this->assertEquals('3', $book->id, 'Unexpected book id after instance creation');
        $this->assertEquals('Isaac Asimov', $book->author, 'Unexpected book author');
        $this->assertEquals('The Complete Robot', $book->title, 'Unexpected book title');
        $stmt = $book->delete();
        $this->assertTrue($stmt instanceof PDOStatement, 'delete() does not return PDOStatement instance');
        $this->assertEquals(1, $stmt->rowCount(), 'Unexpected number of affected rows');
        // the information itself should still be left untouched
        $this->assertEquals('3', $book->id, 'Unexpected book id after delete');
        $this->assertEquals('Isaac Asimov', $book->author, 'Unexpected book author after delete');
        $this->assertEquals('The Complete Robot', $book->title, 'Unexpected book title after delete');
        // let's check if it's really deleted
        $control = new Book(3);
        $this->assertEquals(null, $control->author, 'Author is not null');
        $this->assertEquals(null, $control->title, 'Title is not null');
        // ok, but we haven't deleted EVERYTHING, have we?!?!??
        $control = new Book();
        $num = count($control->find()->fetchAll());
        $this->assertEquals(6, $num, "Row count is '$num', expected 6 from test data after deleting one record.");
        // now, can I just re-insert the old record?
        $stmt = $book->save(true);
        $this->assertTrue($stmt instanceof PDOStatement, 'save() does not return PDOStatement instance');
        $this->assertEquals(1, $stmt->rowCount(), 'Unexpected number of affected rows');
        // the information itself should still be left untouched
        $this->assertEquals('3', $book->id, 'Unexpected book id after re-insert');
        $this->assertEquals('Isaac Asimov', $book->author, 'Unexpected book author after re-insert');
        $this->assertEquals('The Complete Robot', $book->title, 'Unexpected book title after re-insert');
    }
    
    public function testNonExistingRecord()
    {
        require_once('model/Book.php');
        $book = new Book(134895);
        $this->assertEquals(134895, $book->id, "Unexpected book id");
        $this->assertNull($book->title, "Book title non-empty");
        $this->assertNull($book->author, "Book author non-empty");
        /**
         * @TODO Do some thinking: Wouldn't it be good here to simply throw an Exception if the
         *       record is not found? What's the better option here... a try-catch block or a
         *       clumsy method call, like: if ($obj->getCurrentStatement()->rowCount() != 0) ??
         *       Other possibilities: Introduce factory method returning possible errors (like DO),
         *       or make an own $obj->selectSuccess() method???? Or some kind of rowCount property,
         *       like $obj->N in DO?
         */
    }
    
    public function testCallFetchBeforeFind()
    {
        require_once('model/Book.php');
        $book = new Book();
        $ex = false;
        try {
            $book->fetch();
        } catch (Exception $ex) {
        }
        $this->assertType('CWAC_ActiveRecordException',$ex, 'Calling fetch() before find() should throw CWAC_ActiveRecordException');
    }
    
    public function testLimit()
    {
        require_once('model/Book.php');
        $book = new Book();
        $book->limit(2);
        $res = $book->find()->fetchAll();
        $this->assertEquals(2, count($res), 'Unexpected number of books using limit()');
    }
    
    public function testOrderBy()
    {
        require_once('model/Book.php');
        $book = new Book();
        $book->orderBy(array('author','name'));
        $res = $book->find()->fetchAll();
        $authors = array('Clive Barker', 'Douglas Adams', 'Isaac Asimov', 'J.R.R. Tolkien', 
                         'R.A. Salvatore', 'Terry Pratchett', 'William Gibson');
        
        $this->assertEquals(7, count($res), 'Unexpected number of results');
        for($i=0; $i < 7; $i++) {
            $this->assertEquals($res[$i]['author'], $authors[$i], "Unexpected sort result at index $i");
        }
    }
    
    public function testWhereAdd() {
    	require_once('model/Book.php');
        $book = new Book();
        $book->whereAdd("author LIKE '%Adams'");
        $res = $book->find()->fetchAll();
        $this->assertEquals(1, count($res), 'Unexpected number of results');
    }
    
    public function testLikeQuery() {
    	/*
    	require_once('model/Book.php');
        $book = new Book();
        $book = CWAC_ActiveRecord_SQL::LIKE('%adams');
        $res = $book->find()->fetchAll();
        $this->assertEquals(1, count($res), 'Unexpected number of results');
        */
    }
    
    public function testCustomQuery() {
    	require_once('model/Book.php');
    	$sql = "SELECT b.* FROM book b
                INNER JOIN publisher p ON b.publisher_id=p.id
				WHERE p.name=:publisher
                ORDER BY b.author ASC";
    	$bindings = array(':publisher' => 'Some comedy publisher');
        $book = new Book();
        $book->executeCustomQuery($sql, $bindings);
        $book->fetch();
        $this->assertEquals('The Hitchhiker\'s Guide To The Galaxy', $book->title, 'Wrong title');
        $this->assertEquals('Douglas Adams', $book->author, 'Wrong author');
        $book = new Book();
        $book->executeCustomQuery($sql, $bindings);
        $results = $book->getCurrentStatement()->fetchAll();
        $this->assertEquals(2, count($results), 'Unexpected number of results');
    }
    
    public function testFromArray() {
    	$data = array('title'        => 'Some Book',
    	              'author'       => 'Some Dude',
    	              'publisher_id' => 1,
    	              'illegal'      => 'should not appear');
		require_once('model/Book.php');
		$book = new Book();
		$book->setFromArray($data);
		$this->assertFalse(isset($book->illegal));
		$this->assertEquals('Some Book', $book->title);
		$this->assertEquals('Some Dude', $book->author);
		$this->assertEquals(1, $book->publisher_id);
		$book->save();
		$this->assertNotNull($book->id);
		$book->delete();
    }
    
}
?>