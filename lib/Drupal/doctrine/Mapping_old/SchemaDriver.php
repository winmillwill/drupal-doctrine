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
use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Drupal\doctrine\Schema\SchemaManager;

/**
 * Reads the mapping meta-data from Entity API.
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
   * Conceptual Data Model translated from the Schema API.
   *
   * @var SchemaManager
   */
  protected $schema;

  /**
   * Array of information about the entities.
   *
   * @var array
   *
   * @see entity_get_info()
   */
  protected $entityInfo;

  /**
   * @deprecated Use classToTableNames instead.
   * @var unknown
   */
  protected $entityToSchemaMap = array();

  protected $tables = array();

  /**
   * Class to table name map.
   *
   * @var array
   */
  protected $classToTableNames = array();

  /**
   * Table to class name map.
   *
   * @var array
   */
  protected $classNamesForTables = array();

  /**
   * Many to many tables.
   *
   * @var array
   */
  protected $manyToManyTables = array();

  /**
   * Constructs a SchemaDriver.
   *
   * @param SchemaManager $schema
   * @param array $entityInfo
   */
  public function __construct(SchemaManager $schema, array $entityInfo) {
    // The entity to schema map building process depends on Entity API through
    // this constructor, as the driver relies on hook_entity_info() meta-data.
    foreach (array_values($entityInfo) as $entity) {
      if (isset($entity['entity class'])) {
        // Only map entities which declares an 'entity class' in their info
        // hook. Entity API requires the 'base table' meta data so the driver
        // can safely assumes it is existing and contains functional value.
        $this->classToTableNames[$entity['entity class']] = $entity['base table'];
        $this->classNamesForTables[$entity['base table']] = $entity['entity class'];
      }
    }

    $tables = array();
    // The schema references building process depends on Schema API through
    // this constructor, as the driver relies on hook_schema() meta-data.
    foreach ($schema->listTableNames() as $tableName) {
      $tables[$tableName] = $this->schema->listTableDetails($tableName);
    }

    foreach ($tables as $tableName => $table) {
      /* @var $table \Doctrine\DBAL\Schema\Table */
      $foreignKeys = $table->getForeignKeys();

      $allForeignKeyColumns = array();
      foreach ($foreignKeys as $foreignKey) {
        /* @var $foreignKey \Doctrine\DBAL\Schema\ForeignKeyConstraint */
        $allForeignKeyColumns += $foreignKey->getLocalColumns();
      }

      $pkColumns = $table->getPrimaryKey()->getColumns();
      sort($pkColumns);
      sort($allForeignKeyColumns);

      if ($pkColumns == $allForeignKeyColumns && count($foreignKeys) == 2) {
        // A ManyToMany table is detected if the number of foreign keys is two,
        // and the primary key is composed of those two foreign key constraints.
        $this->manyToManyTables[$tableName] = $table;
      }
      else if (array_key_exists($tableName, $this->classNamesForTables)) {
        // When the table name is mapped to an entity, adds it to the pool.
        $this->tables[$tableName] = $table;
      }
    }


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
      // or a ManyToOne relationship. The relation is treated like a toOne
      // association without the Many part.
      foreach ($schema['foreign keys'] as $name => $reference) {
        // What is expressed is: "This entity has a property that is a reference
        // to an instance of another entity". In other words, using a ManyToOne
        // is the way to map OneToOne foreign key associations.
        $mapping = array();
        $mapping['fieldName'] = $this->getFieldNameForColumn($primaryTable['name'], $name);
        $mapping['targetEntity'] = $this->getTargetEntity($reference['table']);
        $mapping['joinColumns'] = $this->joinColumnToArray($schema['name'], $reference['columns']);
        $mapping['fetch'] = \Doctrine\ORM\Mapping\ClassMetadata::FETCH_LAZY;

        if ($this->isForeignKeyUnique($schema['name'], $name)) {
          // If the schema contains a unique key on the reference being mapped,
          // the association is a OneToOne (unidirectional) relationship and do
          // not require a 'mappedBy' key.
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
      // entity or a ManyToMany relationship if not. It can also represent a
      // OneToOne (bidirectional) relationship.
      foreach ($schema['inversedBy'] as $target => $reference) {
        if ($this->hasTargetEntity($target)) {
          // If the target referencing the entity through a foreign key
          // constraint exists, the nature of the association can be either a
          // OneToMany (bidirectional), or OneToMany (self-referencing).
          $className = $this->getTargetEntity($target);
          $metadata = new ClassMetadataInfo($className);
          $this->loadMetadataForClass($className, $metadata);
          $fieldName = $this->getFieldNameForColumn($target, current($reference));
          $association = $metadata->getAssociationMapping($fieldName);
          $debug = true;
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
//     foreach ($schema['fields'] as $name => $field) {
//       $mapping = $this->fieldToArray($name, $field);
//       // An entity must have an identifier/primary key. Thus the driver assumes
//       // a 'primary key' meta-data exists in the schema definition.
//       if (in_array($name, $schema['primary key'])) {
//         $mapping['id'] = TRUE;
//       }
//       $metadata->mapField($mapping);
//     }

  }

  /**
   * Parses the given Field name as array.
   *
   * @param string $tableName
   *   The schema name.
   * @param string $name
   *   The field name.
   * @return array
   */
  protected function fieldToArray($tableName, $name) {
    $field = $this->schemas[$tableName]['fields'][$name];

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
   * @param string $tableName
   * @param array $columns
   * @return array
   */
  protected function joinColumnToArray($tableName, $columns) {
    $joinColumns = array();

    foreach ($columns as $name => $reference) {
      $joinColumns[] = array(
        'name' => $name,
     /* 'unique' => TODO, */
     /* 'nullable' => TODO, */
     /* 'onDelete' => TODO, */
     /* 'columnDefinition' => $this->fieldToArray($tableName, $name), */
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
  protected function isForeignKeyUnique($tableName, $fkName) {
    if (isset($this->schemas[$tableName]['unique keys'])) {
      foreach ($this->schemas[$tableName]['unique keys'] as $keys) {
        // Checks the difference between the keys composing a foreign key and
        // a unique constraint defined in the schema. An empty difference means
        // the compound (or not) foreign key is unique.
        $columns = $this->schemas[$tableName]['foreign keys'][$fkName]['columns'];
        $diff = array_diff(array_keys($columns), $keys);
        if (empty($diff)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  protected function isForeignKey($tableName, $fkName) {
    return isset($this->schemas[$tableName]['foreign keys'][$fkName]);
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
   * Gets the field name for column.
   *
   * @param string $tableName
   * @param string $columnName
   *
   * @return string
   */
  protected function getFieldNameForColumn($tableName, $columnName) {
    $columnName = strtolower($columnName);

    if ($this->isForeignKey($tableName, $columnName)) {
      // Replaces _id if it is a foreign key column.
      $columnName = str_replace('_id', '', $columnName);
    }

    return Inflector::camelize($columnName);
  }

  protected function getMappedBy($tableName, $foreignKeyName) {
    $foreignTable = $foreignKey['table'];

    $this->getFieldNameForColumn($foreignTable, implode('_', array_values($reference['columns'])));
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
   * @deprecated Use the SchemaManager instead.
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
    return array_keys($this->classToTableNames);
  }

  /**
   * {@inheritDoc}
   */
  public function isTransient($className) {
    return TRUE;
  }
}
