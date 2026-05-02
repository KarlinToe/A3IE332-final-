<?php
// db_compat.php — PDO-compatible wrapper using mysqli
// Lets existing PDO-style code work without full rewrite

include_once __DIR__ . '/db.php';

class StmtCompat {
    private $conn;
    private $sql;
    private $result;
    private $params;

    public function __construct($conn, $sql) {
        $this->conn = $conn;
        $this->sql  = $sql;
    }

    public function execute($params = array()) {
        $sql = $this->sql;
        // Replace ? placeholders with escaped values
        foreach ($params as $p) {
            $escaped = is_null($p) ? 'NULL' : "'" . mysqli_real_escape_string($this->conn, $p) . "'";
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr_replace($sql, $escaped, $pos, 1);
            }
        }
        $this->result = mysqli_query($this->conn, $sql);
        if ($this->result === false) {
            die("Query error: " . mysqli_error($this->conn) . "<br>SQL: " . htmlspecialchars($sql));
        }
        return true;
    }

    public function fetch() {
        if (!$this->result || !is_object($this->result)) return false;
        $row = mysqli_fetch_assoc($this->result);
        return $row ? $row : false;
    }

    public function fetchAll() {
        if (!$this->result || !is_object($this->result)) return array();
        $rows = [];
        while ($row = mysqli_fetch_assoc($this->result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetchColumn() {
        if (!$this->result || !is_object($this->result)) return false;
        $row = mysqli_fetch_row($this->result);
        return $row ? $row[0] : false;
    }

    public function rowCount() {
        return mysqli_affected_rows($this->conn);
    }
}

class PdoCompat {
    public $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function prepare($sql) {
        return new StmtCompat($this->conn, $sql);
    }

    public function query($sql) {
        $stmt = new StmtCompat($this->conn, $sql);
        $stmt->execute(array());
        return $stmt;
    }
}

// Create $pdo as a compatibility object
$pdo = new PdoCompat($conn);
