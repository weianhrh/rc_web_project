<?php

require_once __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// 自动加载 .env
if (!isset($_ENV['DB_HOST'])) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}


class Database {
    protected $mysqli;
    protected $inTransaction = false;
    protected $redis;
    protected $cacheTTL = 300;

    protected $config;

    public function __construct($config = []) {
        if (empty($_ENV['DB_HOST'])) {
            $dotenv = Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        }

        $this->config = array_merge([
            'host'       => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'user'       => $_ENV['DB_USER'] ?? 'root',
            'password'   => $_ENV['DB_PASS'] ?? '',
            'database'   => $_ENV['DB_NAME'] ?? '',
            'port'       => $_ENV['DB_PORT'] ?? 3306,
            'redis_host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'redis_port' => $_ENV['REDIS_PORT'] ?? 6379,
        ], $config);

        $this->cacheTTL = $_ENV['REDIS_TTL'] ?? 300;

        $this->connectMySQL();
        $this->connectRedis();
    }
    public function getUserBySessionToken($token) {
        return $this->query("SELECT * FROM admin_users WHERE session_token = ?", [$token])[0] ?? null;
    }

    private function connectMySQL() {
        $this->mysqli = new mysqli(
            $this->config['host'],
            $this->config['user'],
            $this->config['password'],
            $this->config['database'],
            $this->config['port']
        );

        if ($this->mysqli->connect_error) {
            throw new Exception("MySQL连接失败: " . $this->mysqli->connect_error);
        }

        $this->mysqli->set_charset("utf8mb4");
    }

    private function connectRedis() {
        $this->redis = new Redis();
        try {
            $this->redis->connect($this->config['redis_host'], $this->config['redis_port']);
        } catch (RedisException $e) {
            $this->log("Redis连接失败: " . $e->getMessage(), 'ERROR');
        }
    }

    public function find($table, $where = [], $cacheKey = null, $ttl = null) {
        if ($cacheKey && $this->redis) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== false) return json_decode($cached, true);
        }

        $whereSql = $this->buildWhere($where);
        $sql = "SELECT * FROM `$table` $whereSql LIMIT 1";
        $result = $this->query($sql, array_values($where));

        $row = $result[0] ?? null;
        if ($row && $cacheKey && $this->redis) {
            $this->redis->setex($cacheKey, $ttl ?? $this->cacheTTL, json_encode($row));
        }

        return $row;
    }

    public function create($table, $data) {
        $columns = implode(",", array_keys($data));
        $placeholders = implode(",", array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        return $this->execute($sql, array_values($data));
    }

    public function update($table, $data, $where) {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $whereSql = $this->buildWhere($where);
        $sql = "UPDATE `$table` SET $set $whereSql";
        return $this->execute($sql, array_merge(array_values($data), array_values($where)));
    }

    public function delete($table, $where) {
        $whereSql = $this->buildWhere($where);
        $sql = "DELETE FROM `$table` $whereSql";
        return $this->execute($sql, array_values($where));
    }

    public function query($sql, $params = []) {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            $this->log("SQL预处理失败: {$this->mysqli->error}", 'ERROR');
            return false;
        }

        if (!empty($params)) {
            $stmt->bind_param($this->getParamTypes($params), ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $data = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }

        $stmt->close();
        return $data;
    }
    public function fetch($sql, $params = []) {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            $this->log("SQL执行失败: {$this->mysqli->error}", 'ERROR');
            return null;
        }
    
        if (!empty($params)) {
            $stmt->bind_param($this->getParamTypes($params), ...$params);
        }
    
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    
        return $row;
    }

    public function execute($sql, $params = []) {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            $this->log("SQL执行失败: {$this->mysqli->error}", 'ERROR');
            return false;
        }

        if (!empty($params)) {
            $stmt->bind_param($this->getParamTypes($params), ...$params);
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public function beginTransaction() {
        if (!$this->inTransaction) {
            $this->mysqli->begin_transaction();
            $this->inTransaction = true;
        }
    }

    public function commit() {
        if ($this->inTransaction) {
            $this->mysqli->commit();
            $this->inTransaction = false;
        }
    }

    public function rollback() {
        if ($this->inTransaction) {
            $this->mysqli->rollback();
            $this->inTransaction = false;
        }
    }

    private function buildWhere($conditions) {
        if (empty($conditions)) return '';
        $clauses = array_map(fn($key) => "`$key` = ?", array_keys($conditions));
        return 'WHERE ' . implode(' AND ', $clauses);
    }

    private function getParamTypes($params) {
        $types = '';
        foreach ($params as $param) {
            switch (gettype($param)) {
                case 'integer': $types .= 'i'; break;
                case 'double': $types .= 'd'; break;
                case 'string': $types .= 's'; break;
                default:       $types .= 'b'; break;
            }
        }
        return $types;
    }
    public function getRedis() {
        return $this->redis;
    }

    private function log($message, $level = 'INFO') {
        $logDir = __DIR__ . '/log';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = "$logDir/database.log";
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time][$level] $message\n", FILE_APPEND);
    }

    public function close() {
        if ($this->mysqli) $this->mysqli->close();
        if ($this->redis) $this->redis->close();
    }
}