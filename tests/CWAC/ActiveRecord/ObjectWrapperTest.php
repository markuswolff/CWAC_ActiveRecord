<?php
require_once 'config.php';
require_once 'PHPUnit/Framework/TestCase.php';

class ObjectWrapperTest extends PHPUnit_Framework_TestCase
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
    
    public function testInsertWithLinkedObject()
    {
        require_once 'model/Publisher.php';
        require_once 'model/Book.php';
        $pub = new Publisher();
        $pub->name = "New Publisher";
        $book = new Book();
        $book->title = "New Book";
        $book->publisher_id = $pub;
        $book->save();
        
        $this->markTestIncomplete(
          "This doesn't do a whole lot yet..."
        );
    }
    
    public function testUpdateWithLinkedObject()
    {
        require_once 'model/Publisher.php';
        require_once 'model/Book.php';
        $pub = new Publisher();
        $pub->name = "New Publisher";
        $book = new Book(1);
        $book->publisher_id = $pub;
        $book->save();
        
        $this->markTestIncomplete(
          "This doesn't do a whole lot yet..."
        );
    }
}
?>