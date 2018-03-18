<?php

/*
 * libasynql_v3
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace poggit\libasynql\sqlite3;

use Exception;
use InvalidArgumentException;
use poggit\libasynql\base\QueryRecvQueue;
use poggit\libasynql\base\QuerySendQueue;
use poggit\libasynql\base\SqlSlaveThread;
use poggit\libasynql\result\SqlChangeResult;
use poggit\libasynql\result\SqlColumnInfo;
use poggit\libasynql\result\SqlInsertResult;
use poggit\libasynql\result\SqlSelectResult;
use poggit\libasynql\SqlError;
use poggit\libasynql\SqlResult;
use poggit\libasynql\SqlThread;
use SQLite3;
use function assert;
use function is_array;
use const SQLITE3_ASSOC;
use const SQLITE3_BLOB;
use const SQLITE3_FLOAT;
use const SQLITE3_INTEGER;
use const SQLITE3_NULL;
use const SQLITE3_TEXT;
use function var_dump;

class Sqlite3Thread extends SqlSlaveThread{
	/** @var string */
	private $path;

	public function __construct(string $path, QuerySendQueue $send = null, QueryRecvQueue $recv = null){
		parent::__construct($send, $recv);
		$this->path = $path;
	}

	protected function createConn(&$sqlite) : ?string{
		try{
			$sqlite = new SQLite3($this->path);
			return null;
		}catch(Exception $e){
			return $e->getMessage();
		}
	}

	protected function executeQuery(&$sqlite, int $mode, string $query, array $params) : SqlResult{
		assert($sqlite instanceof SQLite3);
		echo "Executing query: $query\n";
		$stmt = $sqlite->prepare($query);
		if($stmt === false){
			throw new SqlError(SqlError::STAGE_PREPARE, $sqlite->lastErrorMsg(), $query, $params);
		}
		foreach($params as $paramName => $param){
			$bind = $stmt->bindValue($paramName, $param);
			if(!$bind){
				throw new SqlError(SqlError::STAGE_PREPARE, "when binding $paramName: " . $sqlite->lastErrorMsg(), $query, $params);
			}
		}
		$result = $stmt->execute();
		if($result === false){
			throw new SqlError(SqlError::STAGE_EXECUTE, $sqlite->lastErrorMsg(), $query, $params);
		}
		switch($mode){
			case SqlThread::MODE_GENERIC:
				$ret = new SqlResult();
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_CHANGE:
				$ret = new SqlChangeResult($sqlite->changes());
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_INSERT:
				$ret = new SqlInsertResult($sqlite->changes(), $sqlite->lastInsertRowID());
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_SELECT:
				$colInfo = [];
				for($i = 0, $iMax = $result->numColumns(); $i < $iMax; ++$i){
					static $columnTypeMap = [
						SQLITE3_INTEGER => SqlColumnInfo::TYPE_INT,
						SQLITE3_FLOAT => SqlColumnInfo::TYPE_FLOAT,
						SQLITE3_TEXT => SqlColumnInfo::TYPE_STRING,
						SQLITE3_BLOB => SqlColumnInfo::TYPE_STRING,
						SQLITE3_NULL => SqlColumnInfo::TYPE_NULL,
					];
					$colInfo[] = new SqlColumnInfo($result->columnName($i), $columnTypeMap[$result->columnType($i)]);
				}
				$rows = [];
				while(is_array($row = $result->fetchArray(SQLITE3_ASSOC))){
					$rows[] = $row;
				}
				$ret = new SqlSelectResult($colInfo, $rows);
				$result->finalize();
				$stmt->close();
				return $ret;
		}

		throw new InvalidArgumentException("Unknown mode $mode");
	}

	protected function close(&$resource) : void{
		assert($resource instanceof SQLite3);
		$resource->close();
	}
}
