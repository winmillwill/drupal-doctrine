<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseMenuCustom extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('menu_custom');
    $this->hasColumn('menu_name', 'string', 32, array (
  'primary' => true,
  'notnull' => true,
));

    $this->hasColumn('title', 'string', 255, array (
  'notnull' => true,
));
    $this->hasColumn('description', 'string', 2147483647, array (
  'notnull' => false,
));
  }


}