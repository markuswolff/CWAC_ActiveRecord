#!/opt/lampp/bin/php
<?php
/**
 * This is a very quick and simple (read: DIRTY HACK!!!) script
 * to create class files for CWAC_ActiveRecord from an existing
 * database.
 * 
 * It is nearly certain this hack won't work with anything but MySQL!
 */

$usage = "Usage: create_mode.php [hostname] [dbname] [user] [password|destination path] ([destination path])\n";
if (empty($argv[1]) || empty($argv[2]) || empty($argv[3]) || empty($argv[4])) {
    echo "Error: Not enough arguments.\n";
    echo $usage;
    exit(1);
}

$dsn = "mysql:host=$argv[1];dbname=$argv[2]";
$user = $argv[3];
if (empty($argv[5])) {
    $pwd  = null;
    $path = $argv[4];
} else {
    $pwd  = $argv[4];
    $path = $argv[5];
}

$pdo = new PDO($dsn, $user, $pwd);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tpl = '<?php';
$tpl .= <<<EOD

class %s extends CWAC_ActiveRecord
{
    ##### BEGIN AUTO-GENERATION BLOCK #####
%s    #####  END AUTO-GENERATION BLOCK  #####
}

EOD;
$tpl .= "\n";

foreach($pdo->query('SHOW TABLES')->fetchAll() as $row) {
    $fieldBlock = '';
    foreach($pdo->query('DESCRIBE `'.$row[0].'`', PDO::FETCH_OBJ) as $table) {
        /**
         * Content of each $table:
         * stdClass Object
         * (
         *    [Field] => updated_at
         *    [Type] => timestamp
         *    [Null] => YES
         *    [Key] =>
         *    [Default] => 0000-00-00 00:00:00
         *    [Extra] =>
         *  )
         */
        $fieldName = strtolower($table->Field);
        $fieldBlock .= "    public \${$fieldName};\n";
    }
    $className = ucfirst(strtolower($row[0]));
    $fileName  = $path.'/'.$className.'.php';
    if (!file_exists($fileName)) {
        $tableClass = sprintf($tpl, $className, $fieldBlock);
        echo "Writing new file: $fileName\n";
    } else {
        $tableClass = file_get_contents($fileName);
        $replaceRule = "/#####\ BEGIN AUTO-GENERATION BLOCK\ #####.*#####\ \ END AUTO-GENERATION BLOCK\ \ #####/s";
        $replacement = "##### BEGIN AUTO-GENERATION BLOCK #####
$fieldBlock
    #####  END AUTO-GENERATION BLOCK  #####";
        $tableClass = preg_replace($replaceRule, $replacement, $tableClass);
        echo "Updating existing file: $fileName\n";
    }
    file_put_contents($fileName, $tableClass);
}

echo "\nAll done.\n\n";
?>
