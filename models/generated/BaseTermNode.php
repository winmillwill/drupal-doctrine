<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseTermNode extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('term_node');
    $this->hasColumn('nid', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('vid', 'integer', 4, array (
  'primary' => true,
  'default' => 0,
  'notnull' => true,
  'unsigned' => 1,
));
    $this->hasColumn('tid', 'integer', 4, array (
  'primary' => true,
  'default' => 0,
  'notnull' => true,
  'unsigned' => 1,
));
  }

  public function setUp()
  {
    $this->hasOne('TermNode as Term', array('local' => 'tid',
                                            'foreign' => 'tid'));

    $this->hasOne('Vocabulary', array('local' => 'tid',
                                      'foreign' => 'tid'));

    $this->hasOne('Node', array('local' => 'nid',
                                'foreign' => 'nid'));
  }

}