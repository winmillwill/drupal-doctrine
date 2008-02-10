<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseFilter extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('filters');
    $this->hasColumn('fid', 'integer', 4, array (
  'primary' => true,
  'autoincrement' => true,
  'notnull' => true,
));

    $this->hasColumn('format', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
));

    $this->hasColumn('module', 'string', 64, array (
  'notnull' => true,
));

    $this->hasColumn('delta', 'integer', 1, array (
  'default' => 0,
  'notnull' => true,
));
    $this->hasColumn('weight', 'integer', 1, array (
  'default' => 0,
  'notnull' => true,
));
  }

  public function setUp()
  {
    $this->hasOne('FilterFormat', array('local' => 'format',
                                        'foreign' => 'format'));
  }

}