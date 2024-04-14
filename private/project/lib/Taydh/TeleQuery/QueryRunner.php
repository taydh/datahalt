<?php
namespace Taydh\TeleQuery;

use stdClass;

class QueryRunner
{
	const FETCH_ALL = 1;
	const FETCH_ONE = 2;
	const EXEC = 3;

	private $clientSettings;
	private $pdo;
	private $mainEntries;
	private $result;
	
	public function __construct($clientSettings)
	{
		$this->clientSettings = $clientSettings;
	}

	private static function isKeyExists($var, $key)
	{
		return is_object($var) ? property_exists($var, $key) : array_key_exists($key, $var);
	}

	private static function isUpdateOrDeleteQuery($lowercaseSQL)
	{
		$lowercaseSQL = trim($lowercaseSQL);
		return strpos($lowercaseSQL, 'update') === 0 || strpos($lowercaseSQL, 'delete') === 0;
	}

	private function getEntryQueryType ( $entryOrLabel )
	{
		$entry = is_object($entryOrLabel)
			? $entryOrLabel 
			: current(array_filter($this->mainEntries, fn($e) => $e->label == $entryOrLabel));

		return property_exists($entry, 'fetchAll')
		? self::FETCH_ALL
		: (property_exists($entry, 'fetchOne') 
			? self::FETCH_ONE
			: (property_exists($entry, 'exec') 
				? self::EXEC
				: null ));
	}

	public function run($mainEntries) {
	    $this->mainEntries = $mainEntries;
	    
	    switch($this->clientSettings['db.type']) {
	        case 'sqlite': $this->pdo = $this->createSqliteConnection(); break;
	        case 'mysql': $this->pdo = $this->createMysqlConnection(); break;
	    }
		
		if (!$this->pdo) {
			throw new \Exception('Invalid database settings');
		}
		else {
			$this->runMainQuery();

			// remove fields from result by blockFields parameter
			foreach ($this->mainEntries as $entry) {
				if (!property_exists($entry, 'blockFields')) continue;

				$queryType = $this->getEntryQueryType($entry);

				switch ($queryType) {
					case self::FETCH_ALL:
						foreach ($this->result[$entry->label] as &$r) {
							foreach ($entry->blockFields as $field) {
								if (array_key_exists($field, $r)) {
									unset($r[$field]);
								}
							}
						}
						break;
					case self::FETCH_ONE:
						$r = &$this->result[$entry->label];

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
	
	private function createSqliteConnection() {
	    $filepath = $this->clientSettings['db.path'];
		
		$whitelistVars = [
			'projectDir' => $_ENV['project_dir'],
			'dataDir' => $_ENV['data_dir'],
		];
		
		foreach ($whitelistVars as $var => $val) {
			$filepath = str_replace('{'.$var.'}', $val, $filepath);
		}
		
		$pdo = new \PDO('sqlite:' . $filepath);
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		
		return $pdo;
	}
	
	private function createMysqlConnection() {
	    $host = $this->clientSettings['db.host'];
		$port = $this->clientSettings['db.port'] ?? null;
		$dsn = 'mysql:host='.$host.';' . ($port ? 'port='.$port.';' : '' );
		$pdo = new \PDO($dsn, $this->clientSettings['db.username'], $this->clientSettings['db.password']);
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		
		return $pdo;
	}
	
	private function runMainQuery () {
		/* 20240410 no more separate map container 
		$result = [
			'fetch' => new \stdClass(), 
			'exec' => new \stdClass()];
		*/

		$this->result = [];

		foreach ($this->mainEntries as $entryIdx => $entry) {
			self::runEntry($entry);
		}
	}
	
	private function runEntry($entry)
	{
		$mapTo = $entry->label;
		$mapKeyCol = $entry->assocKey ?? null;
		$queryType = $this->getEntryQueryType($entry);

		switch ($queryType) {
		case self::FETCH_ALL:
			$items = self::fetchAll($entry);
			$this->result[$mapTo] = !$mapKeyCol ? $items : array_column($items, null, $mapKeyCol);
			break;
		case self::FETCH_ONE:
			$item = self::fetchOne($entry);
			$this->result[$mapTo] = $item;
			break;
		case self::EXEC:
			$item = self::exec($entry);
			$this->result[$mapTo] = $item;
			break;
		}
	}

	private function fetchOne ( $entry )
	{
		// define queryText and allParamValues
		$queryText = $entry->fetchOne;
		$allParamValues = [];

		// replace if params exists
		if (property_exists($entry, 'params')) {
			list($queryText, $allParamValues) = $this->composeParameterValues($queryText, $entry->params);
		}
		
		if ($queryText) {
			$stm = $this->pdo->prepare($queryText);
			$stm->execute($allParamValues);
			$row = $stm->fetch(\PDO::FETCH_ASSOC);
		}

		return $row === false ? null : $row;
	}

	private function fetchAll ( $entry )
	{
		// define queryText and allParamValues
		$queryText = $entry->fetchAll;
		$allParamValues = [];
		$rows = false;

		// replace if params exists
		if (property_exists($entry, 'params')) {
			list($queryText, $allParamValues) = $this->composeParameterValues($queryText, $entry->params);
		}
		
		if ($queryText) {
			$stm = $this->pdo->prepare($queryText);
			$stm->execute($allParamValues);
			$rows = $stm->fetchAll(\PDO::FETCH_ASSOC);
		}

		return $rows === false ? [] : $rows;
	}

	private function exec($entry)
	{
		// define queryText and allParamValues
		$queryText = $entry->exec;
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
			$stm = $this->pdo->prepare($queryText);
			
			if ($stm->execute($allParamValues)) {
				$row = [];

				if (in_array('lastInsertId', $entry->props ?? [])) {
					$row['lastInsertId'] = $this->pdo->lastInsertId();
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
			$from = $param->from ?? null;
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

			// determine values for parameter in array type
			if ($from) {
				$fromQueryType = $this->getEntryQueryType($from);
				$referencedItems = in_array($fromQueryType, [self::FETCH_ONE, self::EXEC])
					? ($this->result[$from] != null ? [$this->result[$from]] : [])
					: $this->result[$from];

				// fail this parameters
				if (count($referencedItems) == 0) return [false, false];

				$paramValues = array_unique(array_column($referencedItems, $param->field));
			}
			else if (is_array($param->value)) {
				$paramValues = $param->value;
			}
			else {
				$paramValues = [$param->value];
			}
			
			$marks = str_repeat('?,', count($paramValues) - 1) . '?';
			$queryText = str_replace(':'.$param->name, $marks, $queryText);
			$allParamValues = array_merge($allParamValues, $paramValues);
		}

		//print_r($queryText); print_r($allParamValues);
		return [$queryText, $allParamValues];
	}
}
