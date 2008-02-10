<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseAuthmap extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('authmap');
    $this->hasColumn('aid', 'integer', 4, array (
  'primary' => true,
  'autoincrement' => true,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('uid', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
));

    $this->hasColumn('authname', 'string', 128, array (
  'notnull' => true,
));
    $this->hasColumn('module', 'string', 128, array (
  'notnull' => true,
));
  }

  public function setUp()
  {
    $this->hasOne('User', array('local' => 'uid',
                                'foreign' => 'uid'));
  }

}