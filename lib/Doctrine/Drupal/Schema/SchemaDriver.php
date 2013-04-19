<?php

/**
 * @file
 * The SchemaDriver reads the mapping meta-data from Schema API.
 */

namespace Doctrine\Drupal\Schema;

use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;

/**
 * Reads the mapping meta-data from Schema API.
 *
 * @since 7.x-1.0
 * @author Sylvain Lecoy <sylvain.lecoy@gmail.com>
 */
class SchemaDriver implements MappingDriver {

  protected static $entityToTableMap = array();

  public function __construct() {
    // The map building process depends on Entity API through this function,
    // as the driver relies on hook_entity_info(). Using this feature adds a
    // substantial performance hit to schema driver as more meta-data has to be
    // loaded into memory than might actually be necessary. This may not be
    // relevant to scenarios where caching of meta-data is in place, however
    // hits in scenarios where no caching is used.
    foreach (entity_get_info() as $name => $entity_info) {
      if (isset($entity_info['entity class'])) {
        // Only map entities which declares an 'entity class' in their info
        // hook. Entity API requires the 'base table' meta data so the driver
        // can safely assumes it is existing and consists functional value.
        static::$entityToTableMap[$entity_info['entity class']] = $entity_info['base table'];
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function loadMetadataForClass($className, ClassMetadata $metadata) {
    $schema = $this->getSchema($className);

    // Evaluates $schema[] structure.
    $primaryTable['name'] = $schema['name'];

    if (isset($schema['indexes'])) {
      // Indexes translates simply as they are already defined with arrays. The
      // Doctrine meta-data model is very similar to Drupal Schema API 'indexes'
      // description.
      foreach ($schema['indexes'] as $name => $columns) {
        $primaryTable['indexes'][$name] = array('columns' => $columns);
      }
    }

    if (isset($schema['unique keys'])) {
      // Unique constraints translates simply as they are already defined with
      // arrays. The Doctrine meta-data model is very similar to Drupal Schema
      // API 'unique keys' description.
      foreach ($schema['unique keys'] as $name => $columns) {
        $primaryTable['uniqueConstraints'][$name] = array('columns' => $columns);
      }
    }

    if (isset($schema['foreign keys'])) {
      // A foreign key in schema can represent either a OneToOne relationship
      // or a ManyToOne relationship (unidirectional). The relation is treated
      // like a toOne association without the Many part.
      foreach ($schema['foreign keys'] as $name => $relation) {
        // What is expressed is: "This entity has a property that is a reference
        // to an instance of another entity". In other words, using a ManyToOne
        // is the way to map OneToOne foreign key associations (which are actually
        // maybe more frequent than shared primary key OneToOne associations).
        $mapping = $this->fieldToArray($name, $schema['fields'][$name]);
        $mapping['targetEntity'] = $this->getTargetEntity($relation['table']);
        $mapping['joinColumns'][] = $this->joinColumnToArray($relation['columns']);
        $mapping['fetch'] = \Doctrine\ORM\Mapping\ClassMetadata::FETCH_LAZY;
        $metadata->mapManyToOne($mapping);

        // Properties must be declared only once.
        unset($schema['fields'][$name]);
      }
    }

    $metadata->setPrimaryTable($primaryTable);

    // Evaluates $schema[fields] structure.
    foreach ($schema['fields'] as $name => $field) {
      $mapping = $this->fieldToArray($name, $field);
      // An entity must have an identifier/primary key. Thus the driver assumes
      // a 'primary key' meta-data exists in the schema definition.
      if (in_array($name, $schema['primary key'])) {
        $mapping['id'] = TRUE;
      }
      $metadata->mapField($mapping);
    }

  }

  /**
   * Parses the given Field as array.
   *
   * @param string $name
   * @param array $field
   * @return array
   */
  protected function fieldToArray($name, $field) {
    $mapping = array(
      'fieldName' => $name,
      'type'      => $this->getFieldType($field['type'], isset($field['size']) ? $field['size'] : 'normal'),
      'scale'     => isset($field['scale']) ? $field['scale'] : NULL,
      'length'    => isset($field['length']) ? $field['length'] : NULL,
   /* 'unique'    => $column['unique'] */
      'nullable'  => isset($field['not null']) ? !$field['not null'] : NULL,
      'precision' => isset($field['precision']) ? $field['precision'] : NULL,
    );

    if (isset($field['options'])) {
      $mapping['options'] = $field['options'];
    }

    return $mapping;
  }

  /**
   * Parses the given join column as array.
   *
   * @param array $joinColumn
   * @return array
   */
  protected function joinColumnToArray($joinColumn) {
    return array(
      'name' => key($joinColumn),
   /* 'unique' => $joinColumn->unique, */
   /* 'nullable' => $joinColumn->nullable, */
   /* 'onDelete' => $joinColumn->onDelete, */
   /* 'columnDefinition' => $joinColumn->columnDefinition, */
      'referencedColumnName' => current($joinColumn),
    );
  }

  /**
   * Gets the schema definition of a table.
   *
   * The returned schema will include any modifications made by any module that
   * implements hook_schema_alter().
   *
   * @param string $class
   *   The name of the entity class.
   *
   * @throws \UnexpectedValueException
   *
   * @return array
   */
  protected function getSchema($className) {
    if (empty(static::$entityToTableMap[$className])) {
      // If entity class has not been found in entity map, throws an unexpected
      // value exception indicating the value does not match with the entity list.
      throw new \UnexpectedValueException(sprintf('Unknown entity type: %s.', $className));
    }

    return drupal_get_schema(static::$entityToTableMap[$className]);
  }

  /**
   * Gets the target entity for a table name.
   *
   * This function mirrors getSchema() as it returns the entity class based on
   * a table name.
   *
   * @param string $tableName
   *   The name of the table.
   *
   * @throws \UnexpectedValueException
   *
   * @return string
   */
  protected function getTargetEntity($tableName) {
    static $map = array();

    if (empty($map)) {
      $map = array_flip(static::$entityToTableMap);
    }

    if (empty($map[$tableName])) {
      // If table name has not been found in reversed entity map, throws an
      // unexpected value exception mirroring the exception in getSchema().
      throw new \UnexpectedValueException(sprintf('Unknown table name: %s.', $tableName));
    }

    return $map[$tableName];
  }

  /**
   * Maps Schema API types back into Doctrine types.
   *
   * @param string $type
   * @param string $size
   * @return string
   *   The Doctrine translated type.
   */
  protected function getFieldType($type, $size = 'normal') {
    // Maps schema types back into doctrine types.
    // $map does not use drupal_static as its value never changes.
    static $map = array(
      'varchar:normal'  => Type::STRING,
      'char:normal'     => Type::STRING,

      'text:tiny'       => Type::TEXT,
      'text:small'      => Type::TEXT,
      'text:medium'     => Type::TEXT,
      'text:big'        => Type::TEXT,
      'text:normal'     => Type::TEXT,

      'serial:tiny'     => Type::BOOLEAN,
      'serial:small'    => Type::SMALLINT,
      'serial:medium'   => Type::INTEGER,
      'serial:big'      => Type::BIGINT,
      'serial:normal'   => Type::INTEGER,

      'int:tiny'        => Type::BOOLEAN,
      'int:small'       => Type::SMALLINT,
      'int:medium'      => Type::INTEGER,
      'int:big'         => Type::BIGINT,
      'int:normal'      => Type::INTEGER,

      'float:tiny'      => Type::FLOAT,
      'float:small'     => Type::FLOAT,
      'float:medium'    => Type::FLOAT,
      'float:big'       => Type::FLOAT,
      'float:normal'    => Type::FLOAT,

      'numeric:normal'  => Type::DECIMAL,

      'blob:big'        => Type::BLOB,
      'blob:normal'     => Type::BLOB,
    );

    return $map["$type:$size"];
  }

  /**
   * {@inheritDoc}
   */
  public function getAllClassNames() {
    static $classes = array();

    if (empty($classes)) {
      foreach (static::$entityToTableMap as $className) {
        if (class_exists($className) && !$this->isTransient($className)) {
          $classes[] = $className;
        }
      }
    }

    return $classes;
  }

  /**
   * {@inheritDoc}
   */
  public function isTransient($className) {
    if (isset(static::$entityToTableMap[$className])) {
      return FALSE;
    }

    return !class_exists($className);
  }
}
