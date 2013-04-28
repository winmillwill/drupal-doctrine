<?php

/**
 * @file
 * The SchemaDriver reads the mapping meta-data from Schema API.
 */

namespace Drupal\doctrine\Mapping;

use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;

/**
 * Reads the mapping meta-data from Schema API.
 *
 * The meta-data building process depends on Entity API through this class, as
 * the driver relies on entity info. Using this feature adds a substantial
 * performance hit to schema driver as more meta-data has to be loaded into
 * memory than might actually be necessary. This may not be relevant to scenarios
 * where caching of meta-data is in place, however hits hardly in scenarios where
 * no caching is used.
 *
 * @since 7.x-1.0
 * @author Sylvain Lecoy <sylvain.lecoy@gmail.com>
 */
class SchemaDriver implements MappingDriver {

  /**
   * Array information about the whole database schema.
   *
   * @var array
   *
   * @see drupal_get_schema()
   */
  protected $schemas;

  /**
   * Array of information about the entities.
   *
   * @var array
   *
   * @see entity_get_info()
   */
  protected $entityInfo;

  protected $entityToSchemaMap = array();

  /**
   * Constructs a SchemaDriver.
   *
   * @param array $schemas
   * @param array $entityInfo
   */
  public function __construct(array $schemas, array $entityInfo) {
    // The entity to schema map building process depends on Entity API through
    // this function, as the driver relies on hook_entity_info() meta-data.
    foreach (array_values($entityInfo) as $entity) {
      if (isset($entity['entity class'])) {
        // Only map entities which declares an 'entity class' in their info
        // hook. Entity API requires the 'base table' meta data so the driver
        // can safely assumes it is existing and contains functional value.
        $this->entityToSchemaMap[$entity['entity class']] = $entity['base table'];
      }
    }

    // The schema references building process depends on Schema API through
    // this function, as the driver relies on hook_schema() meta-data.
    foreach ($schemas as $name => $schema) {
      if (isset($schema['foreign keys'])) {
        // Loops over the foreign keys definition to find an eventual relation
        // with the corresponding schema of the entity. Because the name of the
        // constraint does not follow any conventions, the driver cannot rely
        // on the key, but is enforced to compare every keys with the schema.
        foreach (array_values($schema['foreign keys']) as $reference) {
          // Compares the current value with the entity schema.
          if ($reference['table'] == $this->$entityToTableMap[$class]) {
            // When a schema containing a reference to the entity being
            // checked, add its references with the name of the schema as key
            // and foreign keys definition as value.
            $references[$name] = $schema['foreign keys'];
          }
        }
      }
    }
    $this->schemas = $schemas;
    $this->entityInfo = $entityInfo;
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
      foreach ($schema['foreign keys'] as $name => $reference) {
        // What is expressed is: "This entity has a property that is a reference
        // to an instance of another entity". In other words, using a ManyToOne
        // is the way to map OneToOne foreign key associations.
        list($self, $target) = $this->extractForeignKeys($reference);
        $mapping = $this->fieldToArray($self, $schema['fields'][$self]);
        $mapping['targetEntity'] = $this->getTargetEntity($reference['table']);
        $mapping['joinColumns'][] = $this->joinColumnToArray($reference['columns']);
        $mapping['fetch'] = \Doctrine\ORM\Mapping\ClassMetadata::FETCH_LAZY;

        if (isset($schema['unique keys'])) {
          foreach ($schema['unique keys'] as $name => $columns) {
            if (in_array($self, $columns)) {
              // If the schema contains a unique key on the reference being
              // mapped, the relation is a OneToOne association.
              $metadata->mapOneToOne($mapping);
              break;
            }
          }
        }
        else {
          // If the schema does not contains a unique key on the reference being
          // mapped, the relation is then a ManyToOne association.
          $metadata->mapManyToOne($mapping);
        }

        // Properties must be declared only once.
        // TODO: Refactor to loop over fields.
        unset($schema['fields'][$name]);
      }
    }

    foreach ($this->getSchemaReferences($className) as $name => $references) {
      // A foreign key in an external schema can represent either a OneToMany
      // relationship or a ManyToMany relationship (unidirectional).
      $debug = TRUE;
//       list($self, $target) = $this->extractForeignKeys($reference);
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

  private function extractForeignKeys($reference) {
    if (count($reference['columns']) > 1) {
      // Mapping of several columns for a foreign key is not supported and
      // the execution should result into a RuntimeException. However Drupal
      // seems not using compound foreign keys and Doctrine does not
      // recommends the use of surrogate key due to additional PHP code that
      // is necessary to handle this kind of keys.

      // TODO: Add support for surrogate foreign keys.
      throw \UnexpectedValueException(sprintf('Unsupported surrogate foreign key to the schema: %s.', $reference['table']));
    }
    else {
      return array(key($reference['columns']), current($reference['columns']));
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
  protected function getSchema($class) {
    if (empty($this->entityToSchemaMap[$class])) {
      // If entity class has not been found in entity map, throws an unexpected
      // value exception indicating the value does not match with the entity list.
      throw new \UnexpectedValueException(sprintf('Unknown entity type: %s.', $class));
    }

    return $this->schemas[$this->entityToSchemaMap[$class]];
  }

  /**
   * Gets the schema referencing the entity.
   *
   * This function helps finding the toMany relationships, where an external
   * schema is referencing the entity.
   *
   * @param string $class
   *   The name of the entity class.
   *
   * @throws \UnexpectedValueException
   *
   * @return array
   *   An array of 'foreign keys' definition keyed by schema name.
   */
  protected function getSchemaReferences($class) {
    $references = array();

    if (empty(static::$entityToTableMap[$class])) {
      // If entity class has not been found in entity map, throws an unexpected
      // value exception indicating the value does not match with the entity list.
      throw new \UnexpectedValueException(sprintf('Unknown entity type: %s.', $class));
    }

    // The schema references building process depends on Schema API through
    // this function, as the driver relies on hook_schema(). Using this
    // feature adds a substantial performance hit to schema driver as more
    // meta-data has to be loaded into memory than might actually be necessary.
    // This may not be relevant to scenarios where caching of meta-data is in
    // place, however hits hardly in scenarios where no caching is used.
    foreach (drupal_get_schema() as $name => $schema) {
      if (isset($schema['foreign keys'])) {
        // Loops over the foreign keys definition to find an eventual relation
        // with the corresponding schema of the entity. Because the name of the
        // constraint does not follow any conventions, the driver cannot rely
        // on the key, but is enforced to compare every keys with the schema.
        foreach (array_values($schema['foreign keys']) as $reference) {
          // Compares the current value with the entity schema.
          if ($reference['table'] == static::$entityToTableMap[$class]) {
            // When a schema containing a reference to the entity being
            // checked, add its references with the name of the schema as key
            // and foreign keys definition as value.
            $references[$name] = $schema['foreign keys'];
          }
        }
      }
    }

    return $references;
  }

  /**
   * Gets the target entity for a table name.
   *
   * This function mirrors getSchema() as it returns the entity class based on
   * a table name.
   *
   * @param string $table
   *   The name of the table.
   *
   * @throws \UnexpectedValueException
   *
   * @return string
   */
  protected function getTargetEntity($table) {
    static $map = array();

    if (empty($map)) {
      $map = array_flip(static::$entityToTableMap);
    }

    if (empty($map[$table])) {
      // If table name has not been found in reversed entity map, throws an
      // unexpected value exception mirroring the exception in getSchema().
      throw new \UnexpectedValueException(sprintf('Unknown table name: %s.', $table));
    }

    return $map[$table];
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
      foreach (array_keys(static::$entityToTableMap) as $className) {
        if (/* TODO put this back: class_exists($className) && */!$this->isTransient($className)) {
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
