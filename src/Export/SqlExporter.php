<?php

declare(strict_types=1);

namespace Ldb\Export;

class SqlExporter extends AbstractExporter
{
    public function mimeType(): string
    {
        return 'application/sql';
    }

    public function extension(): string
    {
        return 'sql';
    }

    public static function optionSpecs(): array
    {
        return [
            ['name' => 'structure', 'type' => 'bool', 'label' => 'Structure (CREATE)', 'default' => true],
            ['name' => 'data', 'type' => 'bool', 'label' => 'Data (INSERT)', 'default' => true],
            ['name' => 'drop_table', 'type' => 'bool', 'label' => 'Add DROP TABLE IF EXISTS', 'default' => false],
            ['name' => 'if_not_exists', 'type' => 'bool', 'label' => 'Add IF NOT EXISTS to CREATE TABLE', 'default' => false],
            ['name' => 'objects', 'type' => 'bool', 'label' => 'Include routines, triggers and events', 'default' => false],
            ['name' => 'insert_style', 'type' => 'select', 'label' => 'INSERT style', 'default' => 'insert', 'choices' => [
                ['value' => 'insert', 'label' => 'INSERT'],
                ['value' => 'insert_ignore', 'label' => 'INSERT IGNORE'],
                ['value' => 'replace', 'label' => 'REPLACE'],
            ]],
            ['name' => 'extended_inserts', 'type' => 'text', 'label' => 'Rows per INSERT statement', 'default' => '100'],
            ['name' => 'disable_fk_checks', 'type' => 'bool', 'label' => 'Disable foreign key checks', 'default' => true],
            ['name' => 'transaction', 'type' => 'bool', 'label' => 'Wrap in a transaction', 'default' => false],
            ['name' => 'comments', 'type' => 'bool', 'label' => 'Header comment', 'default' => true],
        ];
    }

    /**
     * Failure trailer for SQL dumps. A comment alone would let a truncated dump import "cleanly", so
     * the trailer also contains a statement that is a syntax error in every supported dialect
     * (an unknown bare keyword): the importer / mysql / psql / sqlite3 report an error instead of
     * silently accepting a partial dump. If the dump broke off mid-statement the statement is
     * invalid as well.
     */
    public function writeFailure($out, string $message): void
    {
        $this->write($out, "\n-- LDB EXPORT FAILED: " . self::failureMessage($message) . "\nLDB_EXPORT_FAILED;\n");
    }

    public function export(array $tables, $out): void
    {
        $id = $this->driverId;
        $structure = $this->flag('structure', true);
        $data = $this->flag('data', true);
        $query = trim((string) $this->opt('query', '')) !== '';
        if ($query) {
            $structure = false;
        }
        $fk = $this->flag('disable_fk_checks', true);
        $tx = $this->flag('transaction', false);

        if ($this->flag('comments', true)) {
            $this->write($out, $this->header());
        }
        if ($id === 'mysql') {
            $this->write($out, "SET NAMES utf8mb4;\n");
            $this->write($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        }
        if ($fk) {
            $this->write($out, $this->fkOff());
        }
        if ($tx) {
            $this->write($out, $this->beginTx() . "\n");
        }

        $types = $this->tableTypes();
        foreach ($this->orderByForeignKeys($this->sources($tables), $types, $structure) as $src) {
            $name = $src['name'];
            $isView = isset($types[$name]) && strpos($types[$name], 'view') !== false;
            if ($structure && $src['table'] !== null) {
                $this->writeStructure($out, $name, $isView);
            }
            if ($data && !$isView) {
                $this->writeData($out, $src, $structure);
            }
        }

        if ($structure && $this->flag('objects', false) && !$query) {
            $this->writeObjects($out);
        }

        if ($tx) {
            $this->write($out, "COMMIT;\n");
        }
        if ($fk) {
            $this->write($out, $this->fkOn());
        }
    }

    private function header(): string
    {
        $ver = defined('LDB_VERSION') ? (string) constant('LDB_VERSION') : 'dev';
        $server = '';
        try {
            $si = $this->db->serverInfo();
            $server = trim((string) ($si['name'] ?? '') . ' ' . (string) ($si['version'] ?? ''));
        } catch (\Throwable $e) {
            $server = $this->driverId;
        }
        $db = '';
        try {
            $db = (string) $this->db->currentDatabase();
        } catch (\Throwable $e) {
        }
        return "-- LDB SQL dump\n-- Version: " . $this->oneLine($ver)
            . "\n-- Server: " . $this->oneLine($server)
            . ($db !== '' ? "\n-- Database: " . $this->oneLine($db) : '')
            . "\n-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
    }

    /**
     * PostgreSQL and SQL Server cannot defer FK creation (no FOREIGN_KEY_CHECKS equivalent in the dump), so
     * referenced tables must be created first. Stable topological order; cycles keep their original order.
     *
     * @param array<int,array> $sources
     * @param array<string,string> $types
     * @return array<int,array>
     */
    private function orderByForeignKeys(array $sources, array $types, bool $structure): array
    {
        if (!$structure || !in_array($this->driverId, ['pgsql', 'sqlsrv'], true) || count($sources) < 2) {
            return $sources;
        }
        $byName = [];
        $deps = [];
        foreach ($sources as $src) {
            if ($src['table'] === null) {
                return $sources;
            }
            $n = $src['name'];
            $byName[$n] = $src;
            $deps[$n] = [];
            if (isset($types[$n]) && strpos($types[$n], 'view') !== false) {
                continue;
            }
            try {
                foreach ((array) ($this->db->tableInfo($n)['foreign_keys'] ?? []) as $fk) {
                    $ref = (string) ($fk['ref_table'] ?? '');
                    if ($ref !== '' && $ref !== $n) {
                        $deps[$n][$ref] = true;
                    }
                }
            } catch (\Throwable $e) {
                return $sources;
            }
        }
        $out = [];
        $done = [];
        $visit = function (string $n) use (&$visit, &$out, &$done, $byName, $deps): void {
            if (isset($done[$n])) {
                return;
            }
            $done[$n] = true; // also marks "in progress", which breaks cycles
            foreach (array_keys($deps[$n]) as $d) {
                if (isset($byName[$d])) {
                    $visit((string) $d);
                }
            }
            $out[] = $byName[$n];
        };
        foreach (array_keys($byName) as $n) {
            $visit((string) $n);
        }
        return $out;
    }

    private function oneLine(string $s): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $s));
    }

    private function fkOff(): string
    {
        switch ($this->driverId) {
            case 'mysql':
                return "SET FOREIGN_KEY_CHECKS=0;\n";
            case 'sqlite':
                return "PRAGMA foreign_keys=OFF;\n";
            case 'pgsql':
                return "SET session_replication_role = 'replica';\n";
        }
        return '';
    }

    private function fkOn(): string
    {
        switch ($this->driverId) {
            case 'mysql':
                return "SET FOREIGN_KEY_CHECKS=1;\n";
            case 'sqlite':
                return "PRAGMA foreign_keys=ON;\n";
            case 'pgsql':
                return "SET session_replication_role = 'origin';\n";
        }
        return '';
    }

    private function beginTx(): string
    {
        switch ($this->driverId) {
            case 'mysql':
                return 'START TRANSACTION;';
            case 'sqlsrv':
                return 'BEGIN TRANSACTION;';
        }
        return 'BEGIN;';
    }

    /** @return array<string,string> lowercase type by table name */
    private function tableTypes(): array
    {
        $map = [];
        try {
            foreach ($this->db->listTables() as $t) {
                if (isset($t['name'])) {
                    $map[(string) $t['name']] = strtolower((string) ($t['type'] ?? 'table'));
                }
            }
        } catch (\Throwable $e) {
        }
        return $map;
    }

    /**
     * Table reference used in dump statements. MySQL must not be database-qualified, otherwise
     * importing the dump into another database would write into (or drop) the source tables.
     */
    private function dumpTable(string $name): string
    {
        return $this->driverId === 'mysql' ? $this->db->quoteIdentifier($name) : $this->db->quoteTable($name);
    }

    /** @param resource $out */
    private function writeStructure($out, string $name, bool $isView): void
    {
        $q = $this->dumpTable($name);
        $this->write($out, "\n-- Structure of " . ($isView ? 'view ' : 'table ') . $this->oneLine($name) . "\n");
        if ($this->flag('drop_table', false)) {
            $this->write($out, 'DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $q . ";\n");
        }
        $ddl = $this->db->createStatement($name, $isView ? 'view' : 'table');
        if (!$isView && $this->flag('if_not_exists', false)) {
            $ddl = preg_replace('/^(\s*CREATE\s+TABLE\s+)(?!IF\s+NOT\s+EXISTS)/i', '$1IF NOT EXISTS ', $ddl, 1) ?? $ddl;
        }
        if ($this->driverId === 'sqlsrv' && !$isView) {
            // table DDL is followed by its triggers in "GO" batches; those belong to the "objects" option
            $ddl = preg_split('/\R^GO\s*$/m', $ddl, 2)[0];
        }
        if ($this->driverId === 'sqlsrv' && $isView) {
            $this->write($out, "GO\n" . rtrim($ddl, "; \t\r\n") . "\nGO\n");
            return;
        }
        $this->write($out, rtrim($ddl, "; \t\r\n") . ";\n");
    }

    /**
     * @param resource $out
     * @param array    $src
     */
    private function writeData($out, array $src, bool $structure): void
    {
        $id = $this->driverId;
        $style = (string) $this->opt('insert_style', 'insert');
        $per = $this->opt('extended_inserts', 100);
        if (is_bool($per)) {
            $per = $per ? 100 : 1;
        }
        $per = max(1, (int) $per);
        if ($id === 'sqlsrv') {
            $per = min($per, 1000);
        }

        $verb = 'INSERT INTO';
        $suffix = '';
        if ($style === 'insert_ignore') {
            if ($id === 'mysql') {
                $verb = 'INSERT IGNORE INTO';
            } elseif ($id === 'sqlite') {
                $verb = 'INSERT OR IGNORE INTO';
            } elseif ($id === 'pgsql') {
                $suffix = ' ON CONFLICT DO NOTHING';
            }
        } elseif ($style === 'replace') {
            if ($id === 'mysql') {
                $verb = 'REPLACE INTO';
            } elseif ($id === 'sqlite') {
                $verb = 'INSERT OR REPLACE INTO';
            }
        }

        $target = $src['table'] !== null ? $this->dumpTable($src['table']) : $this->db->quoteIdentifier('query');
        $identity = false;
        if ($id === 'sqlsrv' && $src['table'] !== null) {
            $info = $this->tableInfoSafe($src['table']);
            foreach (($info['columns'] ?? []) as $c) {
                if (!empty($c['auto_increment'])) {
                    $identity = true;
                }
            }
        }

        $head = '';
        $pending = [];
        $count = 0;
        $flush = function () use (&$pending, &$head, $out, $suffix) {
            if (!$pending) {
                return;
            }
            $this->write($out, $head . implode(",\n", $pending) . $suffix . ";\n");
            $pending = [];
        };

        $started = false;
        $this->stream($src, function (array $cols) use (&$head, $verb, $target, $out, $src, $identity, &$started) {
            $q = array_map(function ($c) {
                return $this->db->quoteIdentifier($c);
            }, $cols);
            $head = $verb . ' ' . $target . ' (' . implode(', ', $q) . ") VALUES\n";
            $this->write($out, "\n-- Data of " . $this->oneLine($src['name']) . "\n");
            if ($identity) {
                $this->write($out, 'SET IDENTITY_INSERT ' . $target . " ON;\n");
            }
            $started = true;
        }, function (array $row) use (&$pending, &$count, $per, $flush) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $this->sqlValue($v);
            }
            $pending[] = '(' . implode(', ', $vals) . ')';
            $count++;
            if (count($pending) >= $per) {
                $flush();
            }
        });
        $flush();
        if ($started && $identity) {
            $this->write($out, 'SET IDENTITY_INSERT ' . $target . " OFF;\n");
        }
    }

    /** @param mixed $v */
    public function sqlValue($v): string
    {
        $v = $this->scalar($v);
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            if ($this->driverId === 'pgsql') {
                return $v ? 'TRUE' : 'FALSE';
            }
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            $s = $this->floatStr($v);
            return $s === '' ? 'NULL' : $s;
        }
        $v = (string) $v;
        if ($v !== '' && (!mb_check_encoding($v, 'UTF-8') || strpos($v, "\0") !== false)) {
            $hex = bin2hex($v);
            switch ($this->driverId) {
                case 'pgsql':
                    return "decode('" . $hex . "', 'hex')";
                case 'sqlite':
                    return "X'" . $hex . "'";
                default:
                    return '0x' . $hex;
            }
        }
        $q = $this->db->pdo()->quote($v);
        if ($q === false) {
            $q = "'" . str_replace("'", "''", $v) . "'";
        }
        // T-SQL: a plain literal is converted via the database code page, so non-ASCII text needs N'…'
        if ($this->driverId === 'sqlsrv' && preg_match('/[^\x00-\x7F]/', $v) && $q !== '' && $q[0] === "'") {
            $q = 'N' . $q;
        }
        return $q;
    }

    /** @param resource $out */
    private function writeObjects($out): void
    {
        $id = $this->driverId;
        $drop = $this->flag('drop_table', false);
        $mysql = $id === 'mysql';

        $emit = function (string $label, string $dropSql, string $name, string $type) use ($out, $mysql, $id) {
            try {
                $sql = rtrim($this->db->createStatement($name, $type), "; \t\r\n");
            } catch (\Throwable $e) {
                $this->write($out, '-- Could not export ' . $label . ' ' . $this->oneLine($name) . ': ' . $this->oneLine($e->getMessage()) . "\n");
                return;
            }
            $this->write($out, "\n-- " . ucfirst($label) . ' ' . $this->oneLine($name) . "\n");
            if ($mysql) {
                $this->write($out, "DELIMITER \$\$\n");
                if ($dropSql !== '') {
                    $this->write($out, $dropSql . "\$\$\n");
                }
                $this->write($out, $sql . "\$\$\nDELIMITER ;\n");
            } elseif ($id === 'sqlsrv') {
                // CREATE VIEW/PROCEDURE/FUNCTION/TRIGGER must be alone in a batch; bodies contain ";"
                $this->write($out, "GO\n" . ($dropSql !== '' ? $dropSql . "\nGO\n" : '') . $sql . "\nGO\n");
            } else {
                if ($dropSql !== '') {
                    $this->write($out, $dropSql . ";\n");
                }
                $this->write($out, $sql . ";\n");
            }
        };

        $caps = [];
        try {
            $caps = $this->db->capabilities();
        } catch (\Throwable $e) {
        }

        if (!empty($caps['routines'])) {
            try {
                foreach ($this->db->listRoutines() as $r) {
                    $type = strtoupper((string) ($r['type'] ?? 'FUNCTION'));
                    $n = (string) $r['name'];
                    $emit(strtolower($type), $drop ? 'DROP ' . $type . ' IF EXISTS ' . $this->db->quoteIdentifier($n) : '', $n, strtolower($type));
                }
            } catch (\Throwable $e) {
                $this->write($out, '-- Could not list routines: ' . $this->oneLine($e->getMessage()) . "\n");
            }
        }
        if (!empty($caps['triggers'])) {
            try {
                foreach ($this->db->listTriggers() as $t) {
                    $n = (string) $t['name'];
                    $emit('trigger', $drop && $mysql ? 'DROP TRIGGER IF EXISTS ' . $this->db->quoteIdentifier($n) : '', $n, 'trigger');
                }
            } catch (\Throwable $e) {
                $this->write($out, '-- Could not list triggers: ' . $this->oneLine($e->getMessage()) . "\n");
            }
        }
        if (!empty($caps['events'])) {
            try {
                foreach ($this->db->listEvents() as $ev) {
                    $n = (string) $ev['name'];
                    $emit('event', $drop ? 'DROP EVENT IF EXISTS ' . $this->db->quoteIdentifier($n) : '', $n, 'event');
                }
            } catch (\Throwable $e) {
                $this->write($out, '-- Could not list events: ' . $this->oneLine($e->getMessage()) . "\n");
            }
        }
    }
}
