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
		$result = [
			'fetch' => new \stdClass(), 
			'exec' => new \stdClass()];
		self::runFollow($pdo, $result, 0, $entry, null, null);
		
		return $result;
	}
	
	private function fetchAll($pdo, &$result, $entry, $parentItems, $label, $hasLabel) {
		// map must exists
		$mapTo = $entry->map->to;
		$mapKeyCol = $entry->map->keyCol ?? null;
		
		$queryText = $entry->query->text;		
		$allParamValues = [];
		
		if (property_exists($entry->query, 'params')) {
			// convert to question marks statement template
			foreach ($entry->query->params as $param) {
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
				$queryText = str_replace(':'.$param->param, $marks, $queryText);
				$allParamValues = array_merge($allParamValues, $paramValues);
			}
		}
		
		$stm = $pdo->prepare($queryText);
		$stm->execute($allParamValues);
		$rows = $stm->fetchAll(\PDO::FETCH_ASSOC);

		if ($hasLabel) $this->queryResultPool[$label] = $rows;
		if (!$mapKeyCol) { // as array
			$result['fetch']->$mapTo = $rows;
		}
		else { // as object
			$mapItem = new \stdClass();
			$result['fetch']->$mapTo = $mapItem;

			foreach ($rows as $row) {
				$keyValue = $row[$mapKeyCol];
				$mapItem->$keyValue = $row;
			}
		}
		
		// run next follow
		if (property_exists($entry, 'follows')) {
			foreach ($entry->follows as $nextFollowIndex => $nextFollowEntry) {
				self::runFollow($pdo, $result, $nextFollowIndex, $nextFollowEntry, $rows, $label);
			}
		}
	}
	
	private function runFollow($pdo, &$result, $index, $entry, $parentItems, $parentLabel) {
		$followType = $entry->followType ?? 'once';
		$hasLabel = property_exists($entry, 'label');
		$label = $hasLabel ? $entry->label : $parentLabel . '_' . $index;
		
		// set default to fetch
		$queryType = property_exists($entry, 'query') ? ($entry->query->type ?? 'fetch') : null;
		//echo print_r($sql, true) . PHP_EOL;
		
		if ($queryType == 'fetch') {
			if ($followType == 'once') {
				self::fetchAll($pdo, $result, $entry, $parentItems, $label, $hasLabel);
			}
			else if ($followType == 'each') {
				foreach ($parentItems as $idx => $item) {
					$labelEach = $label . ':' . $idx;
					self::fetchAll($pdo, $result, $entry, [$item], $labelEach, $hasLabel);
				}
			}
		}
		else if($queryType == 'exec') {
			$row = ['affectedRecords' => $pdo->exec($sql)];
			
			if (($entry->lastInsertId ?? 0) != 0) {
				$row['lastInsertId'] = $pdo->lastInsertId();
			}
			
			$result['exec']->$mapTo = $row;
			
			// run next follow
			if (property_exists($entry, 'follows')) {
				foreach ($entry->follows as $nextFollowIndex => $nextFollowEntry) {
					self::runFollow($pdo, $result, $nextFollowIndex, $nextFollowEntry, [$row], $label);
				}
			}
		}
	}
}
