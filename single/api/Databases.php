<?php
class Database {
    protected $connection;
    protected $host = "localhost";
    protected $user = "5grc";
    protected $password = "f6eca7806e73cd25";
    protected $database = "5grc";

    public function __construct() {
        $this->connection = new mysqli($this->host, $this->user, $this->password, $this->database);

        if ($this->connection->connect_error) {
            die("连接失败: " . $this->connection->connect_error);
        }
    }

    public function prepare($query) {
        $stmt = $this->connection->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare statement failed: " . $this->connection->error);
        }
        return $stmt;
    }

   public function query($sql, $params = [], $isUpdate = false) {
    $stmt = $this->connection->prepare($sql);
    if (!$stmt) {
        throw new Exception("预处理SQL语句失败: " . $this->connection->error);
    }

    if ($params) {
        $types = $this->getParamTypes($params);

        if (substr_count($sql, '?') !== count($params)) {
            throw new Exception("参数数量与SQL占位符不匹配");
        }

        if (!$stmt->bind_param($types, ...$params)) {
            $stmt->close();
            throw new Exception("参数绑定失败: " . $stmt->error);
        }
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("执行SQL语句失败: " . $stmt->error);
    }
   $result = $stmt->get_result();
    if ($result === false) {
        $stmt->close();
        throw new Exception("获取结果集失败: " . $stmt->error);
    }
    if ($isUpdate) {
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows;
    } else {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        return $data;
    }
}

private function getParamTypes($params) {
    $types = '';
    foreach ($params as $param) {
        if (is_int($param)) {
            $types .= 'i';
        } elseif (is_float($param)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }
    return $types;
}

private function fetchAll($stmt) {
    $result = $stmt->get_result();
    if ($result === false) {
        throw new Exception("获取结果集失败: " . $stmt->error);
    }
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}
    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }

    public function close() {
        $this->connection->close();
    }

    public function getConnection() {
        return $this->connection;
    }
}


