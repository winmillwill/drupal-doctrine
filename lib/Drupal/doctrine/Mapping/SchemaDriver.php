<?php

/**
 * @file
 * The SchemaDriver reads the mapping meta-data from Schema API.
 */

namespace Drupal\doctrine\Mapping;

use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;

use Inflect;

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
    // this constructor, as the driver relies on hook_entity_info() meta-data.
    foreach (array_values($entityInfo) as $entity) {
      if (isset($entity['entity class'])) {
        // Only map entities which declares an 'entity class' in their info
        // hook. Entity API requires the 'base table' meta data so the driver
        // can safely assumes it is existing and contains functional value.
        $this->entityToSchemaMap[$entity['entity class']] = $entity['base table'];
      }
    }

    // The schema references building process depends on Schema API through
    // this constructor, as the driver relies on hook_schema() meta-data.
    foreach ($schemas as $name => $schema) {
      if (isset($schema['foreign keys'])) {
        // Loops over foreign keys definition to build a map of relationships
        // with the corresponding schema. The name of the constraint does not
        // follow any conventions so the driver cannot rely on the key.
        foreach (array_values($schema['foreign keys']) as $reference) {
          // Enhances the schema referenced through this foreign key with an
          // association meta-data bound to the schema referencing it.
          // The columns referenced eventually produces ToMany relationships.
          $schemas[$reference['table']]['inversedBy'][$name] = array_flip($reference['columns']);
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
        $mapping = array();
        $mapping['fieldName'] = $name;
        $mapping['targetEntity'] = $this->getTargetEntity($reference['table']);
        $mapping['joinColumns'] = $this->joinColumnToArray($schema['name'], $reference['columns']);
        $mapping['fetch'] = \Doctrine\ORM\Mapping\ClassMetadata::FETCH_LAZY;

        if ($this->isForeignKeyUnique($schema['name'], $name)) {
          // If the schema contains a unique key on the reference being
          // mapped, the association is a OneToOne relationship.
          $metadata->mapOneToOne($mapping);
        }
        else {
          // If the schema does not contains a unique key on the reference
          // being mapped, the association is then a ManyToOne relationship.
          $metadata->mapManyToOne($mapping);
        }

        // Properties must be declared only once.
        $schema['fields'] = array_diff_key($schema['fields'], $reference['columns']);
      }
    }

    if (isset($schema['inversedBy'])) {
      // A reference in schema can represent either a OneToMany relationship if
      // the join schema is composed of a unique field referencing the foreign
      // entity or a ManyToMany relationship if not.
      foreach ($schema['inversedBy'] as $target => $reference) {
        if ($this->hasTargetEntity($target)) {
          // If the target referencing the entity through a foreign key
          // constraint exists, the nature of the association can be either a
          // OneToMany (bidirectional), or OneToMany (self-referencing).
        }
        else {
          // If the target does not exists, the nature of the association can be
          // a OneToMany (unidirectional) when a unique constraint is set, or a
          // ManyToMany otherwise. When the target entity does not exists, it
          // usually mean the relationship is done via a join table.
          if ($target == 'users_roles') {
          if (count($this->schemas[$target]['foreign keys']) != 2) {
            // The physical data model (PDM) of ManyToMany and OneToMany
            // (unidirectional) relationship is composed of two foreign keys.
            // Throws an exception if the expected value is not met.
            throw new \UnexpectedValueException(sprintf('Join table must have exactly 2 foreign keys: %s.', $target));
          }

          // Target becomes the join table in this if-branch.
          list($self, $joinTable, $target, $targetTable) = $this->getAssociation($schema['name'], $target);

          $mapping = array();
          $mapping['joinTable'] = $this->joinTableToArray($self, $joinTable, $target);
          $mapping['targetEntity'] = $this->getTargetEntity($targetTable);
//           Inflect\Inflect::pluralize($string);

          if ($this->isForeignKeyUnique($joinTable, $target)) {
            // If the join table defines a unique key on the target entity, the
            // association is a OneToMany (unidirectional) relationship.
            $metadata->mapOneToMany($mapping);
          }
          else {
            // If the join table does not defines a unique key on the target
            // entity, the association is then a ManyToMany relationship.
            $metadata->mapManyToMany($mapping);
          }

          $debug = true;
          }
        }
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
   * Parses the given Field name as array.
   *
   * @param string $schema
   *   The schema name.
   * @param string $name
   *   The field name.
   * @return array
   */
  protected function fieldToArray($schema, $name) {
    $field = $this->schemas[$schema]['fields'][$name];

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
   * @param string $schema
   * @param array $columns
   * @return array
   */
  protected function joinColumnToArray($schema, $columns) {
    $joinColumns = array();

    foreach ($columns as $name => $reference) {
      $joinColumns[] = array(
        'name' => $name,
     /* 'unique' => TODO, */
     /* 'nullable' => TODO, */
     /* 'onDelete' => TODO, */
     /* 'columnDefinition' => $this->fieldToArray($schema, $name), */
        'referencedColumnName' => $reference
      );
    }

    return $joinColumns;
  }

  /**
   * Parses the given join table as array.
   *
   * @param string $name
   * @return array
   */
  protected function joinTableToArray($self, $joinTable, $target) {
    $self = $this->schemas[$joinTable]['foreign keys'][$self];
    $target = $this->schemas[$joinTable]['foreign keys'][$target];

    return array(
      'name' => $joinTable,
      'schema' => $joinTable,
      'joinColumns' => $this->joinColumnToArray($self['table'], $self['columns']),
      'inverseJoinColumns' => $this->joinColumnToArray($target['table'], $target['columns'])
    );
  }

  /**
   * Whether or not a foreign key is unique.
   *
   * @return boolean
   */
  protected function isForeignKeyUnique($schema, $key) {
    if (isset($this->schemas[$schema]['unique keys'])) {
      foreach ($this->schemas[$schema]['unique keys'] as $keys) {
        // Checks the difference between the keys composing a foreign key and
        // a unique constraint defined in the schema. An empty difference means
        // the compound (or not) foreign key is unique.
        $columns = $this->schemas[$schema]['foreign keys'][$key]['columns'];
        $diff = array_diff(array_keys($columns), $keys);
        if (empty($diff)) {
          return TRUE;
        }
      }
    }

    return FALSE;
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
   * Whether or not a target entity exists for a table name.
   *
   * @param string $table
   *   The name of the table.
   *
   * @return boolean
   */
  protected function hasTargetEntity($table) {
    return in_array($table, array_values($this->entityToSchemaMap));
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
    $map = array_flip($this->entityToSchemaMap);

    if (empty($map[$table])) {
      // If table name has not been found in reversed entity map, throws an
      // unexpected value exception mirroring the exception in getSchema().
      throw new \UnexpectedValueException(sprintf('Table is not mapped to entity: %s.', $table));
    }

    return $map[$table];
  }

  /**
   * Gets the association for the current entity defined by a join table.
   *
   * @param string $self
   *   Entity table.
   * @param string $joinTable
   *   Join table.
   *
   * @return array
   *   An indexed array whose values are:
   *   - string: name of the foreign key referencing the current entity.
   *   - string: name of the join table.
   *   - string: name of the foreign key referencing the target entity.
   *   - string: name of the table defining the target entity.
   */
  protected function getAssociation($self, $joinTable) {
    foreach ($this->schemas[$joinTable]['foreign keys'] as $name => $key) {
      if ($key['table'] == $self) {
        $self = $name;
      }
      else {
        $target = $name;
        $targetTable = $key['table'];
      }
    }

    // Since the function is not called when the join table does not defines a
    // relationship with the entity, we can safely assumes self and target are set.
    return array($self, $joinTable, $target, $targetTable);
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
      foreach (array_keys($this->entityToSchemaMap) as $className) {
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
    if (isset($this->entityToSchemaMap[$className])) {
      return FALSE;
    }

    return !class_exists($className);
  }
}
