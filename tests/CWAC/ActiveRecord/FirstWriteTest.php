<?php
require_once 'config.php';
require_once 'PHPUnit/Framework/TestCase.php';

class FirstWriteTest extends PHPUnit_Framework_TestCase
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
    
    /**
     * In earlier versions of CWAC_ActiveRecord, an exception was thrown
     * when the first operation ever done on any ActiveRecord in a given
     * script was a write operation. This test is here to ensure this
     * will never happen again.
     */
    public function testFirstOperationIsWrite()
    {
        require_once 'model/Book.php';
        $book = new Book();
        $this->assertTrue($book instanceof CWAC_ActiveRecord, '$book is not an CWAC_ActiveRecord object');
        $book->title = "Yet Unwritten";
        $book->author = "Markus Wolff";
        $book->publisher_id = 1;
        $stmt = $book->save();
        $this->assertTrue($stmt instanceof PDOStatement, 'save() does not return PDOStatement instance');
        $this->assertTrue(is_numeric($book->id), 'New book ID is not numeric');
    }
}
?>