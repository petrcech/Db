<?php
/**
 * Copyright 2007 Maintainable Software, LLC
 * Copyright 2008-2010 The Horde Project (http://www.horde.org/)
 *
 * @author     Mike Naberezny <mike@maintainable.com>
 * @author     Derek DeVries <derek@maintainable.com>
 * @author     Chuck Hagenbuch <chuck@horde.org>
 * @license    http://opensource.org/licenses/bsd-license.php
 * @category   Horde
 * @package    Horde_Db
 * @subpackage UnitTests
 */

/**
 * @author     Mike Naberezny <mike@maintainable.com>
 * @author     Derek DeVries <derek@maintainable.com>
 * @author     Chuck Hagenbuch <chuck@horde.org>
 * @license    http://opensource.org/licenses/bsd-license.php
 * @group      horde_db
 * @category   Horde
 * @package    Horde_Db
 * @subpackage UnitTests
 */
class Horde_Db_Migration_MigratorTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->_logger = new Horde_Log_Logger(new Horde_Log_Handler_Null());

        $this->_conn = Horde_Db_Adapter::factory(array(
            'adapter' => 'pdo_sqlite',
            'dbname' => ':memory:',
        ));

        /*
CREATE TABLE users (
  id         int(11) auto_increment,
  company_id int(11),
  name       varchar(255) default '',
  first_name varchar(40) default '',
  approved   tinyint(1) default '1',
  type       varchar(255) default '',
  created_at datetime default '0000-00-00 00:00:00',
  created_on date default '0000-00-00',
  updated_at datetime default '0000-00-00 00:00:00',
  updated_on date default '0000-00-00',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        */
        $table = $this->_conn->createTable('users');
          $table->column('company_id',  'integer',  array('limit' => 11));
          $table->column('name',        'string',   array('limit' => 255, 'default' => ''));
          $table->column('first_name',  'string',   array('limit' => 40, 'default' => ''));
          $table->column('approved',    'boolean',  array('default' => true));
          $table->column('type',        'string',   array('limit' => 255, 'default' => ''));
          $table->column('created_at',  'datetime', array('default' => '0000-00-00 00:00:00'));
          $table->column('created_on',  'date',     array('default' => '0000-00-00'));
          $table->column('updated_at',  'datetime', array('default' => '0000-00-00 00:00:00'));
          $table->column('updated_on',  'date',     array('default' => '0000-00-00'));
        $table->end();
        /*
mike:
  id:         1
  company_id: 1
  name:       Mike Naberezny
  first_name: Mike
  approved:   1
  type:       User
  created_at: '2008-01-01 12:20:00'
  created_on: '2008-01-01'
  updated_at: '2008-01-01 12:20:00'
  updated_on: '2008-01-01'

derek:
  id:         2
  company_id: 1
  name:       Derek DeVries
  first_name: Derek
  approved:   1
  type:       User
  created_at: '<?php echo date("Y-m-d H:i:s", strtotime("-1 day")) ?>'
  created_on: '<?php echo date("Y-m-d",       strtotime("-1 day")) ?>'
  updated_at: '<?php echo date("Y-m-d H:i:s", strtotime("-1 day")) ?>'
  updated_on: '<?php echo date("Y-m-d",       strtotime("-1 day")) ?>'

client:
  id:         3
  company_id: 1
  name:       Extreme
  first_name: Engineer
  approved:   1
  type:       Client
  created_at: '2008-01-01 12:20:00'
  created_on: '2008-01-01'
  updated_at: '2008-01-01 12:20:00'
  updated_on: '2008-01-01'
        */
    }

    public function testInitializeSchemaInformation()
    {
        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));

        $sql = "SELECT version FROM schema_info";
        $this->assertEquals(0, $this->_conn->selectValue($sql));
    }

    public function testMigrator()
    {
        $columns = $this->_columnNames('users');
        $this->assertFalse(in_array('last_name', $columns));

        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);

        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));
        $migrator->up();
        $this->assertEquals(3, $migrator->getCurrentVersion());

        $columns = $this->_columnNames('users');
        $this->assertTrue(in_array('last_name', $columns));

        $this->_conn->insert("INSERT INTO reminders (content, remind_at) VALUES ('hello world', '2005-01-01 02:22:23')");
        $reminder = (object)$this->_conn->selectOne('SELECT * FROM reminders');
        $this->assertEquals('hello world', $reminder->content);

        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));
        $migrator->down();
        $this->assertEquals(0, $migrator->getCurrentVersion());

        $columns = $this->_columnNames('users');
        $this->assertFalse(in_array('last_name', $columns));

        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);
    }

    public function testOneUp()
    {
        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);

        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));
        $migrator->up(1);
        $this->assertEquals(1, $migrator->getCurrentVersion());

        $columns = $this->_columnNames('users');
        $this->assertTrue(in_array('last_name', $columns));

        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);

        $migrator->up(2);
        $this->assertEquals(2, $migrator->getCurrentVersion());

        $this->_conn->insert("INSERT INTO reminders (content, remind_at) VALUES ('hello world', '2005-01-01 02:22:23')");
        $reminder = (object)$this->_conn->selectOne('SELECT * FROM reminders');
        $this->assertEquals('hello world', $reminder->content);
    }

    public function testOneDown()
    {
        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));

        $migrator->up();
        $migrator->down(1);

        $columns = $this->_columnNames('users');
        $this->assertTrue(in_array('last_name', $columns));
    }

    public function testOneUpOneDown()
    {
        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));

        $migrator->up(1);
        $migrator->down(0);

        $columns = $this->_columnNames('users');
        $this->assertFalse(in_array('last_name', $columns));
    }

    public function testMigratorGoingDownDueToVersionTarget()
    {
        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));

        $migrator->up(1);
        $migrator->down(0);

        $columns = $this->_columnNames('users');
        $this->assertFalse(in_array('last_name', $columns));

        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);

        $migrator->up();

        $columns = $this->_columnNames('users');
        $this->assertTrue(in_array('last_name', $columns));

        $this->_conn->insert("INSERT INTO reminders (content, remind_at) VALUES ('hello world', '2005-01-01 02:22:23')");
        $reminder = (object)$this->_conn->selectOne('SELECT * FROM reminders');
        $this->assertEquals('hello world', $reminder->content);
    }

    public function testWithDuplicates()
    {
        try {
            $dir = dirname(dirname(__FILE__)).'/fixtures/migrations_with_duplicate/';
            $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));
            $migrator->up();
        } catch (Exception $e) { return; }
        $this->fail('Expected exception wasn\'t raised');
    }

    public function testWithMissingVersionNumbers()
    {
        $dir = dirname(dirname(__FILE__)).'/fixtures/migrations_with_missing_versions/';
        $migrator = new Horde_Db_Migration_Migrator($this->_conn, $this->_logger, array('migrationsPath' => $dir));
        $migrator->migrate(500);
        $this->assertEquals(4, $migrator->getCurrentVersion());

        $migrator->migrate(2);
        $this->assertEquals(2, $migrator->getCurrentVersion());

        $e = null;
        try {
            $this->_conn->selectValues("SELECT * FROM reminders");
        } catch (Exception $e) {}
        $this->assertType('Horde_Db_Exception', $e);

        $columns = $this->_columnNames('users');
        $this->assertTrue(in_array('last_name', $columns));
    }


    protected function _columnNames($tableName)
    {
        $columns = array();
        foreach ($this->_conn->columns($tableName) as $c) {
            $columns[] = $c->getName();
        }
        return $columns;
    }
}
