<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         1.3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console\Command\Task;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

/**
 * Task class for creating and updating fixtures files.
 */
class FixtureTask extends BakeTask {

/**
 * Tasks to be loaded by this Task
 *
 * @var array
 */
	public $tasks = ['DbConfig', 'Model', 'Template'];

/**
 * path to fixtures directory
 *
 * @var string
 */
	public $path = null;

/**
 * Override initialize
 *
 * @param ConsoleOutput $stdout A ConsoleOutput object for stdout.
 * @param ConsoleOutput $stderr A ConsoleOutput object for stderr.
 * @param ConsoleInput $stdin A ConsoleInput object for stdin.
 */
	public function __construct($stdout = null, $stderr = null, $stdin = null) {
		parent::__construct($stdout, $stderr, $stdin);
		$this->path = APP . 'Test/Fixture/';
	}

/**
 * Gets the option parser instance and configures it.
 *
 * @return ConsoleOptionParser
 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();

		$parser->description(
			__d('cake_console', 'Generate fixtures for use with the test suite. You can use `bake fixture all` to bake all fixtures.')
		)->addArgument('name', [
			'help' => __d('cake_console', 'Name of the fixture to bake. Can use Plugin.name to bake plugin fixtures.')
		])->addOption('connection', [
			'help' => __d('cake_console', 'Which database configuration to use for baking.'),
			'short' => 'c',
			'default' => 'default'
		])->addOption('table', [
			'help' => __d('cake_console', 'The table name if it does not follow conventions.'),
		])->addOption('plugin', [
			'help' => __d('cake_console', 'CamelCased name of the plugin to bake fixtures for.'),
			'short' => 'p',
		])->addOption('theme', [
			'short' => 't',
			'help' => __d('cake_console', 'Theme to use when baking code.')
		])->addOption('force', [
			'short' => 'f',
			'help' => __d('cake_console', 'Force overwriting existing files without prompting.')
		])->addOption('count', [
			'help' => __d('cake_console', 'When using generated data, the number of records to include in the fixture(s).'),
			'short' => 'n',
			'default' => 10
		])->addOption('schema', [
			'help' => __d('cake_console', 'Create a fixture that imports schema, instead of dumping a schema snapshot into the fixture.'),
			'short' => 's',
			'boolean' => true
		])->addOption('records', [
			'help' => __d('cake_console', 'Used with --count and <name>/all commands to pull [n] records from the live tables, where [n] is either --count or the default of 10.'),
			'short' => 'r',
			'boolean' => true
		]);

		return $parser;
	}

/**
 * Execution method always used for tasks
 * Handles dispatching to interactive, named, or all processes.
 *
 * @return void
 */
	public function execute() {
		parent::execute();
		if (empty($this->args)) {
			$this->_interactive();
		}

		if (isset($this->args[0])) {
			$this->interactive = false;
			if (!isset($this->connection)) {
				$this->connection = 'default';
			}
			if (strtolower($this->args[0]) === 'all') {
				return $this->all();
			}
			$model = $this->_modelName($this->args[0]);
			$this->bake($model);
		}
	}

/**
 * Bake All the Fixtures at once. Will only bake fixtures for models that exist.
 *
 * @return void
 */
	public function all() {
		$tables = $this->Model->listAll($this->connection, false);

		foreach ($tables as $table) {
			$model = $this->_modelName($table);
			$importOptions = [];
			if (!empty($this->params['schema'])) {
				$importOptions['schema'] = $model;
			}
			$this->bake($model, false, $importOptions);
		}
	}

/**
 * Interactive baking function
 *
 * @return void
 */
	protected function _interactive() {
		$this->DbConfig->interactive = $this->Model->interactive = $this->interactive = true;
		$this->hr();
		$this->out(__d('cake_console', "Bake Fixture\nPath: %s", $this->getPath()));
		$this->hr();

		if (!isset($this->connection)) {
			$this->connection = $this->DbConfig->getConfig();
		}
		$modelName = $this->Model->getName($this->connection);
		$useTable = $this->Model->getTable($modelName, $this->connection);
		$importOptions = $this->importOptions($modelName);
		$this->bake($modelName, $useTable, $importOptions);
	}

/**
 * Interacts with the User to setup an array of import options. For a fixture.
 *
 * @param string $modelName Name of model you are dealing with.
 * @return array Array of import options.
 */
	public function importOptions($modelName) {
		$options = [];

		if (!empty($this->params['schema'])) {
			$options['schema'] = $modelName;
		}
		if (!empty($this->params['records'])) {
			$options['records'] = true;
			$options['fromTable'] = true;
		}
		return $options;
	}

/**
 * Assembles and writes a Fixture file
 *
 * @param string $model Name of model to bake.
 * @param string $useTable Name of table to use.
 * @param array $importOptions Options for public $import
 * @return string Baked fixture content
 * @throws RuntimeException
 */
	public function bake($model, $useTable = false, $importOptions = []) {
		$table = $schema = $records = $import = $modelImport = null;
		$importBits = [];

		if (!$useTable) {
			$useTable = Inflector::tableize($model);
		} elseif ($useTable != Inflector::tableize($model)) {
			$table = $useTable;
		}

		if (!empty($importOptions)) {
			if (isset($importOptions['schema'])) {
				$modelImport = true;
				$importBits[] = "'model' => '{$importOptions['schema']}'";
			}
			if (isset($importOptions['records'])) {
				$importBits[] = "'records' => true";
			}
			if ($this->connection !== 'default') {
				$importBits[] .= "'connection' => '{$this->connection}'";
			}
			if (!empty($importBits)) {
				$import = sprintf("[%s]", implode(', ', $importBits));
			}
		}

		$connection = ConnectionManager::get($this->connection);
		if (!method_exists($connection, 'schemaCollection')) {
			throw new \RuntimeException(
				'Cannot generate fixtures for connections that do not implement schemaCollection()'
			);
		}
		$schemaCollection = $connection->schemaCollection();
		$data = $schemaCollection->describe($useTable);

		if ($modelImport === null) {
			$schema = $this->_generateSchema($data);
		}

		if (empty($importOptions['records']) && !isset($importOptions['fromTable'])) {
			$recordCount = 1;
			if (isset($this->params['count'])) {
				$recordCount = $this->params['count'];
			}
			$records = $this->_makeRecordString($this->_generateRecords($data, $recordCount));
		}
		if (!empty($this->params['records']) || isset($importOptions['fromTable'])) {
			$records = $this->_makeRecordString($this->_getRecordsFromTable($model, $useTable));
		}
		$out = $this->generateFixtureFile($model, compact('records', 'table', 'schema', 'import'));
		return $out;
	}

/**
 * Generate the fixture file, and write to disk
 *
 * @param string $model name of the model being generated
 * @param string $otherVars Contents of the fixture file.
 * @return string Content saved into fixture file.
 */
	public function generateFixtureFile($model, $otherVars) {
		$defaults = [
			'table' => null,
			'schema' => null,
			'records' => null,
			'import' => null,
			'fields' => null,
			'namespace' => Configure::read('App.namespace')
		];
		if ($this->plugin) {
			$defaults['namespace'] = $this->plugin;
		}
		$vars = array_merge($defaults, $otherVars);

		$path = $this->getPath();
		$filename = Inflector::camelize($model) . 'Fixture.php';

		$this->Template->set('model', $model);
		$this->Template->set($vars);
		$content = $this->Template->generate('classes', 'fixture');

		$this->out("\n" . __d('cake_console', 'Baking test fixture for %s...', $model), 1, Shell::QUIET);
		$this->createFile($path . $filename, $content);
		return $content;
	}

/**
 * Get the path to the fixtures.
 *
 * @return string Path for the fixtures
 */
	public function getPath() {
		$path = $this->path;
		if (isset($this->plugin)) {
			$path = $this->_pluginPath($this->plugin) . 'Test/Fixture/';
		}
		return $path;
	}

/**
 * Generates a string representation of a schema.
 *
 * @param array $tableInfo Table schema array
 * @return string fields definitions
 */
	protected function _generateSchema($table) {
		$cols = $indexes = $constraints = [];
		foreach ($table->columns() as $field) {
			$fieldData = $table->column($field);
			$properties = implode(', ', $this->_values($fieldData));
			$cols[] = "\t\t'$field' => [$properties],";
		}
		foreach ($table->indexes() as $index) {
			$fieldData = $table->index($index);
			$properties = implode(', ', $this->_values($fieldData));
			$indexes[] = "\t\t\t'$index' => [$properties],";
		}
		foreach ($table->constraints() as $index) {
			$fieldData = $table->constraint($index);
			$properties = implode(', ', $this->_values($fieldData));
			$contraints[] = "\t\t\t'$index' => [$properties],";
		}
		$options = $this->_values($table->options());

		$content = implode("\n", $cols) . "\n";
		if (!empty($indexes)) {
			$content .= "\t\t'_indexes' => [" . implode("\n", $indexes) . "],\n";
		}
		if (!empty($constraints)) {
			$content .= "\t\t'_constraints' => [" . implode("\n", $constraints) . "],\n";
		}
		if (!empty($options)) {
			$content .= "\t\t'_options' => [" . implode(', ', $options) . "],\n";
		}
		return "[\n$content]";
	}

/**
 * Formats Schema columns from Model Object
 *
 * @param array $values options keys(type, null, default, key, length, extra)
 * @return array Formatted values
 */
	protected function _values($values) {
		$vals = array();
		if (is_array($values)) {
			foreach ($values as $key => $val) {
				if (is_array($val)) {
					$vals[] = "'{$key}' => array(" . implode(", ", $this->_values($val)) . ")";
				} else {
					$val = var_export($val, true);
					if ($val === 'NULL') {
						$val = 'null';
					}
					if (!is_numeric($key)) {
						$vals[] = "'{$key}' => {$val}";
					} else {
						$vals[] = "{$val}";
					}
				}
			}
		}
		return $vals;
	}

/**
 * Generate String representation of Records
 *
 * @param array $tableInfo Table schema array
 * @param integer $recordCount
 * @return array Array of records to use in the fixture.
 */
	protected function _generateRecords($table, $recordCount = 1) {
		$records = [];
		for ($i = 0; $i < $recordCount; $i++) {
			$record = [];
			foreach ($table->columns() as $field) {
				$fieldInfo = $table->column($field);
				$insert = '';
				switch ($fieldInfo['type']) {
					case 'integer':
					case 'float':
						$insert = $i + 1;
						break;
					case 'string':
					case 'binary':
						$isPrimary = in_array($field, $table->primaryKey());
						if ($isPrimary) {
							$insert = String::uuid();
						} else {
							$insert = "Lorem ipsum dolor sit amet";
							if (!empty($fieldInfo['length'])) {
								$insert = substr($insert, 0, (int)$fieldInfo['length'] - 2);
							}
						}
						break;
					case 'timestamp':
						$insert = time();
						break;
					case 'datetime':
						$insert = date('Y-m-d H:i:s');
						break;
					case 'date':
						$insert = date('Y-m-d');
						break;
					case 'time':
						$insert = date('H:i:s');
						break;
					case 'boolean':
						$insert = 1;
						break;
					case 'text':
						$insert = "Lorem ipsum dolor sit amet, aliquet feugiat.";
						$insert .= " Convallis morbi fringilla gravida,";
						$insert .= " phasellus feugiat dapibus velit nunc, pulvinar eget sollicitudin";
						$insert .= " venenatis cum nullam, vivamus ut a sed, mollitia lectus. Nulla";
						$insert .= " vestibulum massa neque ut et, id hendrerit sit,";
						$insert .= " feugiat in taciti enim proin nibh, tempor dignissim, rhoncus";
						$insert .= " duis vestibulum nunc mattis convallis.";
						break;
				}
				$record[$field] = $insert;
			}
			$records[] = $record;
		}
		return $records;
	}

/**
 * Convert a $records array into a a string.
 *
 * @param array $records Array of records to be converted to string
 * @return string A string value of the $records array.
 */
	protected function _makeRecordString($records) {
		$out = "[\n";
		foreach ($records as $record) {
			$values = [];
			foreach ($record as $field => $value) {
				$val = var_export($value, true);
				if ($val === 'NULL') {
					$val = 'null';
				}
				$values[] = "\t\t\t'$field' => $val";
			}
			$out .= "\t\t[\n";
			$out .= implode(",\n", $values);
			$out .= "\n\t\t],\n";
		}
		$out .= "\t]";
		return $out;
	}

/**
 * Interact with the user to get a custom SQL condition and use that to extract data
 * to build a fixture.
 *
 * @param string $modelName name of the model to take records from.
 * @param string $useTable Name of table to use.
 * @return array Array of records.
 */
	protected function _getRecordsFromTable($modelName, $useTable = null) {
		if ($this->interactive) {
			$condition = null;
			$prompt = __d('cake_console', "Please provide a SQL fragment to use as conditions\nExample: WHERE 1=1");
			while (!$condition) {
				$condition = $this->in($prompt, null, 'WHERE 1=1');
			}
			$prompt = __d('cake_console', "How many records do you want to import?");
			$recordCount = $this->in($prompt, null, 10);
		} else {
			$condition = 'WHERE 1=1';
			$recordCount = (isset($this->params['count']) ? $this->params['count'] : 10);
		}
		$model = TableRegistry::get($modelName, [
			'table' => $useTable,
			'connection' => ConnectionManager::get($this->connection)
		]);
		$records = $model->find('all', [
			'conditions' => $condition,
			'recursive' => -1,
			'limit' => $recordCount
		]);

		$schema = $model->schema();
		$alias = $model->alias();
		$out = [];
		foreach ($records as $record) {
			$row = [];
			foreach ($record[$model->alias] as $field => $value) {
				if ($schema->columnType($field) === 'boolean') {
					$value = (int)(bool)$value;
				}
				$row[$field] = $value;
			}
			$out[] = $row;
		}
		return $out;
	}

}