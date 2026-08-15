<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2017年8月30日
 *  数据库PDO驱动
 */
namespace core\database;

use core\basic\Config;

class Pdo implements Builder
{

    protected static $pdo;

    protected $master;

    protected $slave;

    protected $begin = false;

    private function __construct()
    {}

    public function __destruct()
    {
        if ($this->begin) {
            $this->commitTransaction();
        }
    }

    public static function getInstance()
    {
        if (! self::$pdo) {
            self::$pdo = new self();
        }
        return self::$pdo;
    }

    public function conn($cfg)
    {
        if (get_db_type() == 'sqlite' && ! extension_loaded('pdo_sqlite')) {
            if (extension_loaded('SQLite3')) {
                error('未检测到您服务器环境的pdo_sqlite数据库扩展，请检查php.ini中是否已经开启该扩展！<br>另外，检测到您服务器支持sqlite3扩展，您也可以修改数据库配置连接驱动为sqlite试试！');
            } else {
                error('未检测到您服务器环境的pdo_sqlite数据库扩展，请检查php.ini中是否已经开启对应的数据库扩展！');
            }
        } elseif (get_db_type() == 'mysql' && ! extension_loaded('pdo_mysql')) {
            if (extension_loaded('mysqli')) {
                error('未检测到您服务器环境的pdo_mysqli数据库扩展，请检查php.ini中是否已经开启该扩展！<br>另外，检测到您服务器支持mysqli扩展，您也可以修改数据库配置连接驱动为mysqli试试！');
            } else {
                error('未检测到您服务器环境的pdo_mysqli数据库扩展，请检查php.ini中是否已经开启对应的数据库扩展！');
            }
        }
        
        $charset = Config::get('database.charset') ?: 'utf8';
        switch (Config::get('database.type')) {
            case 'pdo_mysql':
                $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['dbname'] . ';charset=' . $charset;
                try {
                    $conn = new \PDO($dsn, $cfg['user'], $cfg['passwd']);
                } catch (\PDOException $e) {
                    error('PDO方式连接MySQL数据库错误：' . iconv('gbk', 'utf-8', $e->getMessage()));
                }
                break;
            case 'pdo_sqlite':
                $dsn = 'sqlite:' . ROOT_PATH . $cfg['dbname'];
                try {
                    $conn = new \PDO($dsn);
                } catch (\PDOException $e) {
                    error('PDO方式连接Sqlite数据库错误：' . iconv('gbk', 'utf-8', $e->getMessage()));
                }
                break;
            case 'pdo_pgsql':
                $dsn = 'pgsql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['dbname'];
                try {
                    $conn = new \PDO($dsn, $cfg['user'], $cfg['passwd']);
                } catch (\PDOException $e) {
                    error('PDO方式连接Pgsql数据库错误：' . iconv('gbk', 'utf-8', $e->getMessage()));
                }
                break;
            default:
                $dsn = Config::get('database.dsn');
                try {
                    $conn = new \PDO($dsn, $cfg['user'], $cfg['passwd']);
                } catch (\PDOException $e) {
                    error('PDO方式连接数据库错误：' . iconv('gbk', 'utf-8', $e->getMessage()));
                }
                break;
        }
        return $conn;
    }

    public function beginTransaction()
    {
        if (! $this->master) {
            $cfg = Config::get('database');
            $this->master = $this->conn($cfg);
            if ($cfg['type'] == 'pdo_mysql') {
                $this->master->exec("SET sql_mode='NO_ENGINE_SUBSTITUTION'");
            }
        }
        if (! $this->begin) {
            $this->master->beginTransaction();
            $this->begin = true;
        }
    }

    public function commitTransaction()
    {
        if ($this->begin) {
            $this->master->commit();
            $this->begin = false;
        }
    }

    public function query($sql, $type = 'master', $params = array())
    {
        $time_s = microtime(true);

        //修复：定义 $cfg，PHP8+不报错
        $cfg = Config::get('database');

        switch ($type) {
            case 'master':
                if (! $this->master) {
                    $this->master = $this->conn($cfg);
                    if ($cfg['type'] == 'pdo_mysql') {
                        $this->master->exec("SET sql_mode='NO_ENGINE_SUBSTITUTION'");
                    }
                }
                
                if ($cfg['type'] == 'pdo_sqlite' && ! $this->begin) {
                    $this->beginTransaction();
                } elseif ($cfg['type'] == 'pdo_mysql' && Config::get('database.transaction') && ! $this->begin) {
                    $this->beginTransaction();
                }
                
                if (!empty($params)) {
                    $stmt = $this->master->prepare($sql);
                    if ($stmt === false) {
                        $this->error($sql, 'master');
                    }
                    $exec = $stmt->execute($params);
                    if ($exec === false) {
                        $this->error($sql, 'master', $stmt);
                    }
                    $result = $stmt;
                } else {
                    $result = $this->master->query($sql);
                    if ($result === false) {
                        $this->error($sql, 'master');
                    }
                }
                break;
            case 'slave':
                if (! $this->slave) {
                    if (! $cfg = Config::get('database.slave')) {
                        $cfg = Config::get('database');
                    } else {
                        if (is_multi_array($cfg)) {
                            $count = count($cfg);
                            $cfg = $cfg['slave' . mt_rand(1, $count)];
                        }
                    }
                    $this->slave = $this->conn($cfg);
                }
                if (!empty($params)) {
                    $stmt = $this->slave->prepare($sql);
                    if ($stmt === false) {
                        $this->error($sql, 'slave');
                    }
                    $exec = $stmt->execute($params);
                    if ($exec === false) {
                        $this->error($sql, 'slave', $stmt);
                    }
                    $result = $stmt;
                } else {
                    $result = $this->slave->query($sql) or $this->error($sql, 'slave');
                }
                break;
        }
        return $result;
    }

    public function isExist($sql)
    {
        $result = $this->query($sql, 'slave');
        if ($result->fetch()) {
            return true;
        } else {
            return false;
        }
    }

    public function rows($table)
    {
        $sql = "SELECT count(*) FROM $table";
        $result = $this->query($sql, 'slave');
        if (! ! $row = $result->fetch(\PDO::FETCH_NUM)) {
            return $row[0];
        } else {
            return 0;
        }
    }

    public function fields($table)
    {
        $sql = "SELECT * FROM $table LIMIT 1";
        $result = $this->query($sql, 'slave');
        if ($result) {
            return $result->columnCount();
        } else {
            return false;
        }
    }

    public function tableFields($table)
    {
        $rows = array();
        switch (Config::get('database.type')) {
            case 'pdo_mysql':
                $sql = "describe $table";
                $result = $this->query($sql, 'slave');
                while (! ! $row = $result->fetchObject()) {
                    $rows[] = $row->Field;
                }
                break;
            case 'pdo_sqlite':
                $sql = "pragma table_info($table)";
                $result = $this->query($sql, 'slave');
                while (! ! $row = $result->fetchObject()) {
                    $rows[] = $row->name;
                }
                break;
            case 'pdo_pgsql':
                $sql = "SELECT column_name FROM information_schema.columns WHERE table_name ='$table'";
                $result = $this->query($sql, 'slave');
                while (! ! $row = $result->fetchObject()) {
                    $rows[] = $row->column_name;
                }
                break;
            default:
                return array();
        }
        return $rows;
    }

    public function one($sql, $type = null, $params = array())
    {
        $result = $this->query($sql, 'slave', $params);
        $row = array();
        if ($type) {
            $type ++;
            $row = $result->fetch($type);
        } else {
            $row = $result->fetchObject();
        }
        return $row;
    }

    public function all($sql, $type = null, $params = array())
    {
        $result = $this->query($sql, 'slave', $params);
        $rows = array();
        if ($type) {
            $type ++;
            $rows = $result->fetchAll($type);
        } else {
            while (! ! $row = $result->fetchObject()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function amd($sql, $params = array())
    {
        $result = $this->query($sql, 'master', $params);
        //修复：保持旧版兼容
        return $result;
    }

    public function insertId()
    {
        return $this->master->lastInsertId();
    }

    public function multi($sql)
    {
        $sqls = explode(';', $sql);
        foreach ($sqls as $key => $value) {
            $result = $this->query($value, 'master');
        }
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    protected function error($sql, $conn, $stmt = null)
    {
        $source = $stmt ?: $this->$conn;
        $errs = $source->errorInfo();

        $err = '错误：' . (isset($errs[2]) ? $errs[2] : '未知错误');
        if (isset($errs[0]) && $errs[0]) {
            $err .= ' [SQLSTATE:' . $errs[0] . ']';
        }

        if (preg_match('/XPATH/i', $err)) {
            $err = '';
        }

        //修复：删除多余的 inTransaction()
        if ($this->begin) {
            $this->$conn->rollBack();
            $this->begin = false;
        }
        
        error('执行SQL发生错误！' . $err);
    }

    public function fetchQuery($obj){
        return $obj->fetchAll();
    }
}