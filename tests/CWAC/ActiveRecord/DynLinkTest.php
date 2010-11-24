<?php
require_once 'config.php';
require_once 'PHPUnit/Framework/TestCase.php';

class DynLinkTest extends PHPUnit_Framework_TestCase
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
    
    public function testGetBooksFromPublisher()
    {
        require_once 'model/Publisher.php';
        $pub = new Publisher(1);
        $this->assertEquals('Some comedy publisher', $pub->name, 'Unexpected publisher name');
        $books = $pub->Book;
        $this->assertTrue($books instanceof CWAC_ActiveRecord, '$books is not an CWAC_ActiveRecord object');
        /**
         * Note: When operating with the PDOStatements directly, you'll have
         * to take care of cleaning up yourself as well. This means: Closing the
         * cursor when finished with an operation, so another object may use a
         * prepared statement again.
         */
        $num = count($books->getCurrentStatement()->fetchAll());
        $this->assertEquals(2, $num, 'Unexpected number of books (using PDOStatement)');
        unset($books); // triggers call to destructor
        
        // Alternate fetch method
        $pub = new Publisher(1);
        $books = $pub->Book;
        $num = 0;
        while($books->fetch()) {
            $num++;
        }
        $this->assertEquals(2, $num, 'Unexpected number of books (using find/fetch)');
    }
}
?>