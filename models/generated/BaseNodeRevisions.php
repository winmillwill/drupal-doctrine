<?php

/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
abstract class BaseNodeRevisions extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('node_revisions');
    $this->hasColumn('nid', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('vid', 'integer', 4, array (
  'primary' => true,
  'autoincrement' => true,
  'notnull' => true,
  'unsigned' => 1,
));

    $this->hasColumn('uid', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
));

    $this->hasColumn('title', 'string', 255, array (
  'notnull' => true,
));

    $this->hasColumn('body', 'blob', 2147483647, array (
  'notnull' => true,
));

    $this->hasColumn('teaser', 'blob', 2147483647, array (
  'notnull' => true,
));

    $this->hasColumn('log', 'string', 2147483647, array (
  'notnull' => true,
));

    $this->hasColumn('timestamp', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
));
    $this->hasColumn('format', 'integer', 4, array (
  'default' => 0,
  'notnull' => true,
));
  }

  public function setUp()
  {
    $this->hasOne('Node', array('local' => 'nid',
                                'foreign' => 'nid'));

    $this->hasOne('Vocabulary', array('local' => 'vid',
                                      'foreign' => 'vid'));

    $this->hasOne('User', array('local' => 'uid',
                                'foreign' => 'uid'));
  }

}