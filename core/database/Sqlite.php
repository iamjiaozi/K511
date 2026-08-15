<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2017年8月23日
 * 数据库Sqlite驱动 ,写入数据时自动启用事务
 */
namespace core\database;

use core\basic\Config;

class Sqlite implements Builder
{

    protected static $sqlite;

    protected $master;

    protected $slave;

    private $begin = false;
    private $manual_transaction = false; // 仅标记是否手动事务，不新增逻辑

    private function __construct()
    {}

    public function __destruct()
    {
        // 自动事务才提交，手动事务不处理（原版行为）
        if ($this->begin && !$this->manual_transaction) {
            $this->master->exec('commit;');
            $this->begin = false;
        }
    }

    // 开启显式事务
    public function beginTransaction()
    {
        if (! $this->master) {
            $cfg = ROOT_PATH . Config::get('database.dbname');
            $conn = $this->conn($cfg);
            $this->master = $conn;
            $this->slave = $conn;
        }
        if (!$this->begin) {
            $this->master->exec('begin;');
            $this->begin = true;
            $this->manual_transaction = true;
        }
    }

    // 提交事务
    public function commitTransaction()
    {
        if ($this->begin && $this->manual_transaction) {
            $this->master->exec('commit;');
            $this->begin = false;
            $this->manual_transaction = false;
        }
    }

    // 回滚事务（修复原版缺失，官方标准行为）
    public function rollbackTransaction()
    {
        if ($this->begin && $this->manual_transaction) {
            $this->master->exec('rollback;');
            $this->begin = false;
            $this->manual_transaction = false;
        }
    }

    // 获取单一实例，使用单一实例数据库连接类
    public static function getInstance()
    {
        if (! self::$sqlite) {
            self::$sqlite = new self();
        }
        return self::$sqlite;
    }

    // 连接数据库
    public function conn($cfg)
    {
        if (extension_loaded('SQLite3')) {
            try {
                $conn = new \SQLite3($cfg);
                $conn->busyTimeout(15 * 1000);
            } catch (\Exception $e) {
                error("读取数据库文件失败：" . iconv('gbk', 'utf-8', $e->getMessage()));
            }
        } else {
            if (extension_loaded('pdo_sqlite')) {
                error('未检测到您服务器环境的sqlite3数据库扩展，请检查php.ini中是否已经开启该扩展！<br>另外，检测到您服务器支持pdo_sqlite扩展，您也可以修改数据库配置连接驱动为pdo_sqlite试试！');
            } else {
                error('未检测到您服务器环境的sqlite3数据库扩展！');
            }
        }
        return $conn;
    }

    // 执行SQL语句
    public function query($sql, $type = 'master', $params = array())
    {
        $time_s = microtime(true);
        if (! $this->master || ! $this->slave) {
            $cfg = ROOT_PATH . Config::get('database.dbname');
            $conn = $this->conn($cfg);
            $this->master = $conn;
            $this->slave = $conn;
        }
        if (!empty($params)) {
            $sql = $this->bindParams($sql, $params);
        }
        switch ($type) {
            case 'master':
                // 自动事务：仅未开启任何事务时自动开启
                if (! $this->begin) {
                    $this->master->exec('begin;');
                    $this->begin = true;
                    $this->manual_transaction = false;
                }
                $result = $this->master->exec($sql) or $this->error($sql, 'master');
                break;
            case 'slave':
                $result = $this->slave->query($sql) or $this->error($sql, 'slave');
                break;
        }
        return $result;
    }

    // 参数绑定（完全原版）
    private function bindParams($sql, $params)
    {
        $offset = 0;
        foreach ($params as $param) {
            $pos = strpos($sql, '?', $offset);
            if ($pos !== false) {
                if ($param === null) {
                    $replacement = 'NULL';
                } elseif (is_int($param) || is_float($param)) {
                    $replacement = $param;
                } else {
                    $replacement = "'" . $this->master->escapeString($param) . "'";
                }
                $sql = substr_replace($sql, $replacement, $pos, 1);
                $offset = $pos + strlen($replacement);
            }
        }
        return $sql;
    }

    // 数据是否存在
    public function isExist($sql)
    {
        $result = $this->query($sql, 'slave');
        if ($result->fetchArray()) {
            $result->finalize();
            return true;
        } else {
            return false;
        }
    }

    // 获取记录总量
    public function rows($table)
    {
        $sql = "SELECT count(*) FROM $table";
        $result = $this->query($sql, 'slave');
        if (!! $row = $result->fetchArray(2)) {
            $result->finalize();
            return $row[0];
        } else {
            return 0;
        }
    }

    // 读取字段数量
    public function fields($table)
    {
        $sql = "SELECT * FROM $table LIMIT 1";
        $result = $this->query($sql, 'slave');
        if ($result) {
            return $result->numColumns();
        } else {
            return false;
        }
    }

    // 获取表字段
    public function tableFields($table)
    {
        $sql = "pragma table_info($table)";
        $result = $this->query($sql, 'slave');
        $rows = array();
        while (!! $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row['name'];
        }
        $result->finalize();
        return $rows;
    }

    // 查询一条数据
    public function one($sql, $type = null, $params = array())
    {
        if (! $type) {
            $my_type = SQLITE3_ASSOC;
        } else {
            $my_type = $type;
        }
        $row = array();
        $result = $this->query($sql, 'slave', $params);
        if (!! $row = $result->fetchArray($my_type)) {
            if (! $type && $row) {
                $out = new \stdClass();
                foreach ($row as $key => $value) {
                    $out->$key = $value;
                }
                $row = $out;
            }
            $result->finalize();
        }
        return $row;
    }

    // 查询多条数据
    public function all($sql, $type = null, $params = array())
    {
        if (! $type) {
            $my_type = SQLITE3_ASSOC;
        } else {
            $my_type = $type;
        }
        $result = $this->query($sql, 'slave', $params);
        $rows = array();
        while (!! $row = $result->fetchArray($my_type)) {
            if (! $type && $row) {
                $out = new \stdClass();
                foreach ($row as $key => $value) {
                    $out->$key = $value;
                }
                $row = $out;
            }
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }

    // 数据增、删、改
    public function amd($sql, $params = array())
    {
        $result = $this->query($sql, 'master', $params);
        if ($result) {
            return $result;
        } else {
            return 0;
        }
    }

    // 最近一次插入ID
    public function insertId()
    {
        return $this->master->lastInsertRowID();
    }

    // 执行多条SQL
    public function multi($sql)
    {
        $sqls = explode(';', $sql);
        $result = false;
        foreach ($sqls as $value) {
            if(trim($value)){
                $result = $this->query($value, 'master');
            }
        }
        return $result ? true : false;
    }

    // 错误处理
    protected function error($sql, $conn)
    {
        $err = '错误：' . $this->$conn->lastErrorMsg();
        if ($this->begin) {
            $this->master->exec('rollback;');
            $this->begin = false;
            $this->manual_transaction = false;
        }
        error('执行SQL发生错误！' . $err);
    }
}