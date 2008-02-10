<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseTermData extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('term_data');
    $this->hasColumn('tid', 'integer', 4, array (
  'primary' => true,
  'autoincrement' => true,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('vid', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('name', 'string', 255, array (
  'notnull' => true,
));

    $this->hasColumn('description', 'string', 2147483647, array (
  'notnull' => false,
));
    $this->hasColumn('weight', 'integer', 1, array (
  'default' => 0,
  'notnull' => true,
));
  }

  public function setUp()
  {
    $this->hasOne('Vocabulary', array('local' => 'vid',
                                      'foreign' => 'vid'));

    $this->hasMany('TermData as Children', array('refClass' => 'TermHierarchy',
                                                 'local' => 'tid',
                                                 'foreign' => 'parent'));

    $this->hasMany('Node as Nodes', array('refClass' => 'TermNode',
                                          'local' => 'nid',
                                          'foreign' => 'nid'));

    $this->hasMany('TermData as Parent', array('refClass' => 'TermHierarchy',
                                               'local' => 'parent',
                                               'foreign' => 'tid'));

    $this->hasOne('TermHierarchy', array('local' => 'tid',
                                         'foreign' => 'tid'));
  }

}