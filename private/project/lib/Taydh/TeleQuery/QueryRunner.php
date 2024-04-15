<?php
namespace Taydh\TeleQuery;

use stdClass;

class QueryRunner
{
	const FETCH_ALL = 1;
	const FETCH_ONE = 2;
	const EXEC = 3;
	const READ_FILE = 21;
	const READ_DIR = 22;

	private $clientSettings;
	private $mainEntries;
	private $connPool;
	private $activeConn;
	private $paused;
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
			: current(array_filter($this->mainEntries, fn($e) => ($e->id ?? $e->label ?? null) == $entryOrLabel));

		$types = [
			'fetchAll' => self::FETCH_ALL,
			'fetchOne' => self::FETCH_ONE,
			'exec' => self::EXEC,
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

	public function run($mainEntries) {
	    $this->mainEntries = $mainEntries;
		$this->connPool = [];
		$this->activeConn = null;
		$this->paused = false;
		$this->result = [];
		$this->runMainQuery();

		// remove fields from result by blockFields parameter
		foreach ($this->mainEntries as $entry) {
			if (!property_exists($entry, 'blockFields')) continue;

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

			// determine if entry and after is paused
			if (property_exists($entry, '_pause_')) {
				$this->paused = $entry->_pause_;
			}

			if ($this->paused) continue;

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
		if (!property_exists($entry, 'id') && !property_exists($entry, 'label')) return;

		$isDatahaltActiveConn = get_class($this->activeConn) == \Taydh\TeleQuery\DatahaltConnector::class;
		$mapTo = $entry->id ?? $entry->label;
		$mapKeyCol = $entry->assocKey ?? null;
		$queryType = $this->getEntryQueryType($entry);

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
			$this->result[$mapTo] = $item;
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
		$queryText = $entry->fetchOne;
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
		$queryText = $entry->fetchAll;
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
