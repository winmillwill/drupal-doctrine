<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseAction extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('actions');
    $this->hasColumn('aid', 'string', 255, array (
  'primary' => true,
  'default' => 0,
  'notnull' => true,
));

    $this->hasColumn('type', 'string', 32, array (
  'notnull' => true,
));

    $this->hasColumn('callback', 'string', 255, array (
  'notnull' => true,
));

    $this->hasColumn('parameters', 'string', 2147483647, array (
  'notnull' => true,
));
    $this->hasColumn('description', 'string', 255, array (
  'default' => 0,
  'notnull' => true,
));
  }

  public function setUp()
  {
    $this->hasOne('ActionAid as Aids', array('local' => 'aid',
                                             'foreign' => 'aid'));
  }

}