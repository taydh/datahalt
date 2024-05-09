<?php
namespace Taydh\Telequery;

use stdClass;

class QueryRunner
{
	const FETCH_ALL = 1;
	const FETCH_ONE = 2;
	const EXEC = 3;
	const CALL = 4;
	const COMPOSE = 5;

	const READ_FILE = 21;
	const READ_DIR = 22;

	const SINGLE = 1;
	const MULTIPLE = 2;

	private $clientSettings;
	private $connPool;
	private $activeConn;
	private $source;

	private $skipped;
	private $blocked;
	private $result;

	private $variables;
	private $forerunner;
	private $mainEntries;
	
	public function __construct($clientSettings)
	{
		$this->clientSettings = $clientSettings;
	}

	private static function validateVariableValues ( $variables, $acceptArray=true )
	{
		foreach ($variables as $key => $value) {
			$valid = true;

			if (is_array($value)) {
				$valid = $acceptArray;
				
				if ($valid) {
					self::validateVariableValues($value, false);
				}
			}
			else {
				$valid = !is_object($value); 
			}

			if (!$valid) throw new \Exception("Invalid variable value for $key");
		}
	}

	private function resolveVariables () {
		foreach ($this->variables as $key => $value) {
			$resolvedValue = $value;

			if (is_object($value)) {
				if (property_exists($value, 'src') && array_key_exists($value->src, $this->source)) {
					$resolvedValue = $this->source[$value->src];
				} else if (property_exists($value, 'default')) {
					$resolvedValue = $value->default;
				} else {
					$resolvedValue = null;
				}
			}

			// double check
			self::validateVariableValues([$resolvedValue]);

			$this->variables->$key = $resolvedValue;
		}
	}

	private static function isKeyExists($var, $key)
	{
		return is_object($var) ? property_exists($var, $key) : array_key_exists($key, $var);
	}

	private static function resolveString ( $input )
	{
		if (is_string($input)) {
			return $input;
		}
		else if (is_array($input)) {
			return implode('', $input);
		}

		return '';
	}

	private static function isUpdateOrDeleteQuery($lowercaseSQL)
	{
		$lowercaseSQL = trim($lowercaseSQL);
		return strpos($lowercaseSQL, 'update') === 0 || strpos($lowercaseSQL, 'delete') === 0;
	}

	private function getEntryDataDimension ( $entryOrLabel )
	{
		$entry = is_object($entryOrLabel)
			? $entryOrLabel 
			: current(array_filter($this->mainEntries, fn($e) => ($e->id ?? $e->label ?? null) == $entryOrLabel));
		
		if (!$entry) {
			$entry = is_object($entryOrLabel)
				? $entryOrLabel 
				: current(array_filter($this->forerunner, fn($e) => ($e->id ?? $e->label ?? null) == $entryOrLabel));		
		}

		$single = false;
		$propExists = property_exists($entry, 'single');

		if ($propExists) {
			$single = $entry->single ?? false;
		}
		else {
			$queryType = $this->getEntryQueryType( $entry );

			if (in_array($queryType, [self::FETCH_ONE, self::EXEC, self::CALL, self::READ_FILE])) {
				$single = true;
			}
		}

		return $single ? self::SINGLE : self::MULTIPLE;
	}

	private function getEntryQueryType ( $entryOrLabel )
	{
		$entry = is_object($entryOrLabel)
			? $entryOrLabel 
			: current(array_filter($this->mainEntries, fn($e) => ($e->id ?? $e->label ?? null) == $entryOrLabel));

		$types = [
			'fetchAll' => self::FETCH_ALL,
			'fetchOne' => self::FETCH_ONE,
			'exec' => self::EXEC,
			'call' => self::CALL,
			'compose' => self::COMPOSE,
			'readFile' => self::READ_FILE,
			'readDir' => self::READ_DIR,
		];

		$result = null;

		foreach ($types as $key => $type) {
			if (property_exists($entry, $key)) {
				$result = $type;
				break;
			}
		}

		return $result;
	}

	public function run($query, $source=[]) {
		$this->variables = $query->variables ?? [];
		$this->forerunner = $query->forerunner ?? [];
	    $this->mainEntries = $query->entries;
		$this->connPool = [];
		$this->activeConn = null;
		$this->skipped = false;
		$this->blocked = false;
		$this->source = $source;
		$this->result = [];

		// validate source values
		self::validateVariableValues($this->source);
		$this->resolveVariables();

		// copy forerunner labels to result
		foreach ($this->forerunner as $entry) {
			$this->result[$entry->label] = $entry->data;
		}

		// RUN MAIN QUERY
		$this->runMainQuery();

		// remove forerunner labels from result
		foreach ($this->forerunner as $entry) {
			unset($this->result[$entry->label]);
		}

		foreach ($this->mainEntries as $entry) {
			// remove blocked entry
			if (property_exists($entry, 'label') && property_exists($entry, 'blockLabel') && $entry->blockLabel) {
				unset($this->result[$entry->label]);
			}

			// remove fields from result by blockFields parameter
			if (property_exists($entry, 'blockFields')) {
				$queryType = $this->getEntryQueryType($entry);

				switch ($queryType) {
					case self::FETCH_ALL:
						foreach ($this->result[$entry->id ?? $entry->label] as &$r) {
							foreach ($entry->blockFields as $field) {
								if (array_key_exists($field, $r)) {
									unset($r[$field]);
								}
							}
						}
						break;
					case self::FETCH_ONE:
						$r = &$this->result[$entry->id ??$entry->label];
	
						if ($r) {
							foreach ($entry->blockFields as $field) {
								if (array_key_exists($field, $r)) {
									unset($r[$field]);
								}
							}
						}
						break;
				}
			}
		}
		
		return $this->result;
	}

	public function validateEntries () 
	{
		
	}
	
	/* PRIVATE METHODS */

	private function findConnectionSettings ( $connName)
	{
		foreach ($this->clientSettings as $key => $value) {
			if (is_array($value) && substr_count($key, ':') == 1)
			{
				$parts = explode(':', $key);

				if ($parts[0] == 'conn' && $parts[1] == $connName )
				{
					return $value;
				}
			}
		}
	}

	private function createSqliteConnection ( $settings )
	{
	    $filepath = $settings['path'];
		
		$whitelistVars = [
			'projectDir' => $_ENV['datahalt.project_dir'],
			'dataDir' => $_ENV['datahalt.data_dir'],
		];
		
		foreach ($whitelistVars as $var => $val) {
			$filepath = str_replace('{'.$var.'}', $val, $filepath);
		}
		
		$pdo = new \PDO('sqlite:' . $filepath);
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		
		return $pdo;
	}
	
	private function createMysqlConnection( $settings ) {
	    $host = $settings['host'];
		$port = $settings['port'] ?? null;
		$dsn = 'mysql:host='.$host.';' . ($port ? 'port='.$port.';' : '' );
		$pdo = new \PDO($dsn, $settings['username'], $settings['password']);
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		
		return $pdo;
	}
	
	private function runMainQuery () {
		$skipCount = 0;

		foreach ($this->mainEntries as $entryIdx => $entry) {
			if (property_exists($entry, '_skip_')) {
				$skipCount = $entry->_skip_;
			}

			if ($skipCount-- > 0) continue;

			// determine if entry and after is skipped
			if (property_exists($entry, '_skipped_')) {
				$this->skipped = $entry->_skip_;
			}

			if ($this->skipped) continue;

			// determine if entry label will be blocked from result
			if (property_exists($entry, '_block_')) {
				$this->blocked = $entry->_block_;
			}

			 if(!property_exists($entry, 'blockLabel')) {
			 	$entry->blockLabel = $this->blocked;
			 }

			// determine which connection to use and after
			if (property_exists($entry, '_connect_')) {
				if (array_key_exists($entry->_connect_, $this->connPool)) {
					$this->activeConn = $this->connPool[$entry->_connect_];
				}
				else {
					$settings = $this->findConnectionSettings($entry->_connect_);
					$conn = null;
					
					switch ($settings['type']) {
						case 'sqlite':
							$conn = $this->createSqliteConnection($settings);
							break;
						case 'mysql':
							$conn = $this->createMysqlConnection($settings);
							break;
						case 'fs':
							$conn = new FileSystemConnector($settings['base_dir']);
							break;
						case 'dhconnect':
							$conn = new DatahaltConnector([
								'clientId' => $settings['client_id'],
								'baseUrl' => $settings['base_url'],
								'otpKey' => $settings['otp_key'],
								'connect' => $settings['connect'],
							]);
							break;
					}

					$this->connPool[$entry->_connect_] = $this->activeConn = $conn;
				}
			}

			self::runEntry($entry);
		}
	}
	
	private function runEntry($entry)
	{
		$queryType = $this->getEntryQueryType($entry);
		$labelExists = property_exists($entry, 'id') || property_exists($entry, 'label');

		if (!$labelExists && !in_array($queryType, [self::EXEC, self::CALL])) return;

		$isDatahaltActiveConn = $this->activeConn && get_class($this->activeConn) == \Taydh\TeleQuery\DatahaltConnector::class;
		$mapTo = $entry->id ?? $entry->label ?? null;
		$mapKeyCol = $entry->assocKey ?? null;

		switch ($queryType) {
		case self::FETCH_ALL:
			$items = !$isDatahaltActiveConn ? self::fetchAll($entry) : $this->activeConn->query($entry);
			$this->result[$mapTo] = !$mapKeyCol ? $items : array_column($items, null, $mapKeyCol);

			break;
		case self::FETCH_ONE:
			$item = !$isDatahaltActiveConn ? self::fetchOne($entry) : $this->activeConn->query($entry);
			$this->result[$mapTo] = $item;

			break;
		case self::EXEC:
			$item = !$isDatahaltActiveConn ? self::exec($entry) : $this->activeConn->query($entry);

			if ($mapTo) {
				$this->result[$mapTo] = $item;
			}

			break;
		case self::COMPOSE:
			if ($mapTo) {
				$this->result[$mapTo] = $this->composeParameterArgs($entry->compose);
			}

			break;
		case self::CALL:
			$runner = (object) [
				'activeConn' => $this->activeConn,
				'variables' => &$this->variables,
				'result' => &$this->result,
				// 'bearerToken' => Common::getBearerToken(),
			];
			$args = property_exists($entry, 'params')
				? $this->composeParameterArgs($entry->params)
				: [];

			$fnRealpath = realpath("{$_ENV['datahalt.function_dir']}/{$entry->call}.fn.php");
			$isFnValid = $fnRealpath && strpos($fnRealpath, $_ENV['datahalt.function_dir']) === 0;

			if (!$isFnValid) {
				
			}

			$fn = include($fnRealpath);
			$result = $fn($args, $runner);

			if ($mapTo) {
				$this->result[$mapTo] = $result;
			}

			break;
		case self::READ_FILE:
			$item = !$isDatahaltActiveConn 
				? $this->activeConn->readFile($entry->readFile, $entry->props ?? [])
				: $this->activeConn->query($entry);
			$this->result[$mapTo] = $item === false ? null : $item;
			break;
		case self::READ_DIR:
			$item = !$isDatahaltActiveConn 
				? $this->activeConn->readDir($entry->readDir, $entry->props ?? [])
				: $this->activeConn->query($entry);
			$this->result[$mapTo] = $item;

			break;
		}
	}

	private function fetchOne ( $entry )
	{
		// define queryText and allParamValues
		$queryText = $this->resolveString($entry->fetchOne);
		$allParamValues = [];

		// replace if params exists
		if (property_exists($entry, 'params')) {
			list($queryText, $allParamValues) = $this->composeParameterValues($queryText, $entry->params);
		}
		
		if ($queryText) {
			$stm = $this->activeConn->prepare($queryText);
			$stm->execute($allParamValues);
			$row = $stm->fetch(\PDO::FETCH_ASSOC);
		}

		return $row === false ? null : $row;
	}

	private function fetchAll ( $entry )
	{
		// define queryText and allParamValues
		$queryText = $this->resolveString($entry->fetchAll);
		$allParamValues = [];
		$rows = false;

		// replace if params exists
		if (property_exists($entry, 'params')) {
			list($queryText, $allParamValues) = $this->composeParameterValues($queryText, $entry->params);
		}
		
		if ($queryText) {
			$stm = $this->activeConn->prepare($queryText);
			$stm->execute($allParamValues);
			$rows = $stm->fetchAll(\PDO::FETCH_ASSOC);
		}

		return $rows === false ? [] : $rows;
	}

	private function exec($entry)
	{
		// define queryText and allParamValues
		$queryText = $this->resolveString($entry->exec);
		$allParamValues = [];
		$row = false;

		// replace if params exists
		if (property_exists($entry, 'params')) {
			list($queryText, $allParamValues) = $this->composeParameterValues($queryText, $entry->params);
		}

		// block update or delete without condition (where)
		if ($queryText) {
			$lcQueryText = strtolower($queryText);
			
			if (self::isUpdateOrDeleteQuery($lcQueryText)) {
				if (strpos($lcQueryText, 'where') === false) {
					$queryText = false;
					$row = ['error' => 'At least one condition required for update or delete'];
				}
			}
		}

		if ($queryText) {
			$stm = $this->activeConn->prepare($queryText);
			
			if ($stm->execute($allParamValues)) {
				$row = [];

				if (in_array('lastInsertId', $entry->props ?? [])) {
					$row['lastInsertId'] = $this->activeConn->lastInsertId();
				}
				if (in_array('affectedRows', $entry->props ?? [])) {
					$row['affectedRows'] = $stm->rowCount();
				}
			}
		}
		
		return $row === false ? null : $row;
	}

	private function composeParameterValues ( $queryText, $entryParams )
	{
		$allParamValues = [];

		// convert to question marks statement template
		foreach ($entryParams as $param) {
			$name = $param->name;
			$type = $param->type ?? 'STR';
			$validType = in_array($type, ['STR', 'NUM', 'INT', 'BOOL', 'NULL', 'ARR']);
			$type = $validType ? $type : 'STR';
			$pdoParamType = $type == 'INT'
				? \PDO::PARAM_INT
				: ($type == 'BOOL'
					?  \PDO::PARAM_BOOL
					: ($type == 'NULL'
						? \PDO::PARAM_NULL
						: \PDO::PARAM_STR));

			$from = $param->from ?? null;
			$var = $param->var ?? null;

			// determine values for parameter in array type
			if ($from) {
				$fromDimensionType = $this->getEntryDataDimension($from);
				$referencedItems = ($fromDimensionType == self::SINGLE)
					? ($this->result[$from] != null ? [$name => $this->result[$from]] : [])
					: $this->result[$from];

				// fail this parameters
				if (count($referencedItems) == 0) return [false, false];

				$paramValues = array_unique(array_column($referencedItems, $param->field));
			}
			else if ($var) {
				if (property_exists($this->variables, $var)) {
					$val = $this->variables->$var;

					if ($type == 'ARR' && !is_array($val)) $paramValues = [];
					else $paramValues = is_array($val) ? $val : [$name => $val];
				}
				else { // fail this parameters
					return [false, false];
				}
			}
			else if (property_exists($param, 'value')) {
				$paramValues = is_array($param->value) ? $param->value : [$name => $param->value];
			}

			foreach ($paramValues as $paramName => $value) {
				if (($c = count($paramValues)) > 0 && strpos($queryText, '{$'.$name.'}') !== false) {
					$expander = '';

					for ($i=0; $i<$c; $i++) {
						$expander .= ':'.$name.$i.',';
					}

					$expander = rtrim($expander, ',');
					$paramValues2 = $paramValues;
					$paramValues = [];

					//var_dump($paramValues2);

					for ($i=0; $i<$c; $i++) {
						$paramValues[':'.$name.$i] = $paramValues2[$i];
					}

					$queryText = str_replace('{$'.$name.'}', $expander, $queryText);
				}
			}

			$allParamValues = array_merge($allParamValues, $paramValues);
		}

		//print_r($queryText); print_r($allParamValues); die();
		return [$queryText, $allParamValues];
	}

	private function composeParameterArgs ( $entryParams )
	{
		$args = [];

		// convert to question marks statement template
		foreach ($entryParams as $param) {
			$type = $param->type ?? 'STR';
			$validType = in_array($type, ['STR', 'NUM', 'INT', 'BOOL', 'NULL']);
			$type = $validType ? $type : 'STR';
			$pdoParamType = $type == 'INT'
				? \PDO::PARAM_INT
				: ($type == 'BOOL'
					?  \PDO::PARAM_BOOL
					: ($type == 'NULL'
						? \PDO::PARAM_NULL
						: \PDO::PARAM_STR));

			$from = $param->from ?? null;
			$var = $param->var ?? null;

			// determine values for parameter in array type
			if ($from) {
				$fromDimensionType = $this->getEntryDataDimension($from);
				$referencedItems = ($fromDimensionType == self::SINGLE)
					? ($this->result[$from] != null ? [$this->result[$from]] : [])
					: $this->result[$from];

				// fail this parameters
				if (count($referencedItems) == 0) return false;

				$values = array_unique(array_column($referencedItems, $param->field));

				$arg = [
					$param->name => ($fromDimensionType == self::SINGLE) ? $values[0] : $values,
				];
			}
			else if ($var) {
				if (property_exists($this->variables, $var)) {
					$arg = [
						$param->name => $this->variables->$var
					];
				}
				else { // fail this parameters
					return false;
				}
			}
			else if (property_exists($param, 'value')) {
				$arg = [
					$param->name => $param->value
				];
			}
			
			$args = array_merge($args, $arg);
		}

		return $args;
	}
}
