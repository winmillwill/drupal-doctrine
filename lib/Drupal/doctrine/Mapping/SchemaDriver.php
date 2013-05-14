<?php

namespace Drupal\doctrine\Mapping;

use Doctrine\ORM\Mapping\Driver\DatabaseDriver;

class SchemaDriver extends DatabaseDriver {

  /**
   * Array of information about the entities.
   *
   * @var array
   *
   * @see entity_get_info()
   */
  protected $entityInfo;

  public function __construct($schemaManager, array $entityInfo) {
    $this->entityInfo = $entityInfo;
    parent::__construct($schemaManager);
  }

  public function getAllClassNames() {

  }
}
