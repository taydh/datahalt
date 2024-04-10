<?php
namespace Taydh\TeleQuery;

class QueryRunner
{
	private $clientSettings;
	private $queryResultPool = [];
	
	public function __construct($clientSettings) {
		$this->clientSettings = $clientSettings;
	}
	
	public function run($mainEntry) {
	    $result = null;
	    $pdo = null;
	    
	    switch($this->clientSettings['db.type']) {
	        case 'sqlite': $pdo = $this->createSqliteConnection(); break;
	        case 'mysql': $pdo = $this->createMysqlConnection(); break;
	    }
		
		if ($pdo) $result = self::runMainQuery($pdo, $mainEntry);
		else throw new \Exception('Invalid database settings');
		
		return $result;
	}
	
	/* PRIVATE METHODS */
	
	private function createSqliteConnection() {
	    $filepath = $this->clientSettings['db.path'];
		
		$vars = ['projectDir' => $_ENV['project_dir']];
		
		foreach ($vars as $var => $val) {
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
	
	private function runMainQuery($pdo, $entry) {
		/* 20240410 no more separate map container 
		$result = [
			'fetch' => new \stdClass(), 
			'exec' => new \stdClass()];
		*/

		$result = new \stdClass();

		self::runNextEntries($pdo, $result, 0, $entry, null, null);
		
		return $result;
	}
	
	private function fetchAll($pdo, &$result, $entry, $parentItems, $label, $hasLabel) {
		// map must exists
		$mapTo = $entry->mapTo;
		$mapKeyCol = $entry->mapKeyCol ?? null;
		
		$queryText = $entry->fetch;		
		$allParamValues = [];
		
		if (property_exists($entry, 'params')) {
			// convert to question marks statement template
			foreach ($entry->params as $param) {
				$isValueObject = is_object($param->value);
				$type = $param->value->type ?? 'STR';
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
				if ($isValueObject) {
					$hasRange = property_exists($param->value, 'range');
					$paramValues = array_unique(array_column($parentItems, $param->value->column));
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
		}
		
		$stm = $pdo->prepare($queryText);
		$stm->execute($allParamValues);
		$rows = $stm->fetchAll(\PDO::FETCH_ASSOC);

		if ($hasLabel) $this->queryResultPool[$label] = $rows;
		if (!$mapKeyCol) { // as array
			// $result['fetch']->$mapTo = $rows;
			$result->$mapTo = $rows;
		}
		else { // as object
			$mapItem = new \stdClass();
			// $result['fetch']->$mapTo = $mapItem;
			$result->$mapTo = $mapItem;

			foreach ($rows as $row) {
				$keyValue = $row[$mapKeyCol];
				$mapItem->$keyValue = $row;
			}
		}
		
		// run next entries
		if (property_exists($entry, 'next')) {
			foreach ($entry->next as $nextIndex => $nextEntry) {
				self::runNextEntries($pdo, $result, $nextIndex, $nextEntry, $rows, $label);
			}
		}
	}
	
	private function runNextEntries($pdo, &$result, $index, $entry, $parentItems, $parentLabel) {
		$nextFetchType = $entry->nextFetchType ?? 'once';
		$hasLabel = property_exists($entry, 'label');
		$label = $hasLabel ? $entry->label : $parentLabel . '_' . $index;
		
		// set default to fetch
		// $queryType = property_exists($entry, 'query') ? ($entry->query->type ?? 'fetch') : null;
		$queryType = property_exists($entry, 'fetch') ? 'fetch' : (property_exists($entry, 'exec') ? 'exec' : null );
		//echo print_r($sql, true) . PHP_EOL;
		
		if ($queryType == 'fetch') {
			if ($nextFetchType == 'once') {
				self::fetchAll($pdo, $result, $entry, $parentItems, $label, $hasLabel);
			}
			else if ($nextFetchType == 'each') {
				foreach ($parentItems as $idx => $item) {
					$labelEach = $label . ':' . $idx;
					self::fetchAll($pdo, $result, $entry, [$item], $labelEach, $hasLabel);
				}
			}
		}
		else if($queryType == 'exec') {
			$mapTo = $entry->mapTo;
			$sql = $entry->exec;
			$row = ['affectedRecords' => $pdo->exec($sql)];
			
			if (($entry->lastInsertId ?? 0) != 0) {
				$row['lastInsertId'] = $pdo->lastInsertId();
			}
			
			// $result['exec']->$mapTo = $row;
			$result->$mapTo = $row;
			
			// run next follow
			if (property_exists($entry, 'follows')) {
				foreach ($entry->next as $nextIndex => $nextEntry) {
					self::runNextEntries($pdo, $result, $nextIndex, $nextEntry, [$row], $label);
				}
			}
		}
	}
}
