<?php

/**
 * Translates the Postgres schema files in database/sql/*.sql into MySQL 8 files in database/sql/mysql/.
 * Run from the project root after adding a new schema file:  php database/tools/pg2mysql.php
 *
 *   uuid → CHAR(36) (DEFAULT (uuid()))    timestamptz → DATETIME(3) (UTC session)    boolean → TINYINT(1)
 *   jsonb → JSON                          enum types → inline ENUM(...)              numeric → DECIMAL
 */
$root = dirname(__DIR__, 2);
$enums = [];
foreach (glob("{$root}/database/sql/*.sql") as $file) {
    $out = [];
    foreach (preg_split('/-->\s*statement-breakpoint/', file_get_contents($file)) as $stmt) {
        $s = trim($stmt);
        if ($s === '') {
            continue;
        }
        if (preg_match('/^CREATE TYPE "public"\."(\w+)" AS ENUM\((.*)\);$/s', $s, $m)) {
            $enums[$m[1]] = $m[2];

            continue;
        }
        $type = function (string $t) use ($enums): string {
            $t = trim($t);
            if (preg_match('/^"?(\w+)"?$/', $t, $m) && isset($enums[$m[1]])) {
                return 'ENUM('.$enums[$m[1]].')';
            }

            return match (true) {
                $t === 'uuid' => 'CHAR(36)',
                $t === 'timestamp with time zone' => 'DATETIME(3)',
                $t === 'boolean' => 'TINYINT(1)',
                $t === 'jsonb' => 'JSON',
                $t === 'text' => 'TEXT',
                $t === 'integer' => 'INT',
                $t === 'bigint' => 'BIGINT',
                $t === 'date' => 'DATE',
                $t === 'time' => 'TIME',
                (bool) preg_match('/^numeric\((\d+),\s*(\d+)\)$/', $t, $n) => "DECIMAL({$n[1]},{$n[2]})",
                (bool) preg_match('/^varchar\(\d+\)$/', $t) => strtoupper($t),
                default => throw new RuntimeException("unmapped type {$t}"),
            };
        };
        $default = function (string $d, string $t): string {
            $d = trim($d);

            return match (true) {
                $d === 'gen_random_uuid()' => '(uuid())',
                $d === 'now()' => 'CURRENT_TIMESTAMP(3)',
                $d === 'false' => '0',
                $d === 'true' => '1',
                (bool) preg_match("/^'(.*)'::jsonb$/s", $d, $m) => "(_utf8mb4'".$m[1]."')",
                $t === 'TEXT' => "({$d})",
                default => $d,
            };
        };
        $column = function (string $line) use ($type, $default): string {
            if (! preg_match('/^"(\w+)" (.+?)( PRIMARY KEY)?( DEFAULT (.+?))?( NOT NULL)?$/s', trim($line), $m)) {
                throw new RuntimeException("unparsed column {$line}");
            }
            $t = $type($m[2]);

            return "`{$m[1]}` {$t}".(! empty($m[6]) ? ' NOT NULL' : ' NULL').(isset($m[5]) && $m[5] !== '' ? ' DEFAULT '.$default($m[5], $t) : '').(! empty($m[3]) ? ' PRIMARY KEY' : '');
        };
        if (preg_match('/^CREATE TABLE "(\w+)" \((.*)\);$/s', $s, $m)) {
            $cols = array_map($column, preg_split('/,\n/', trim($m[2])));
            $out[] = "CREATE TABLE `{$m[1]}` (\n\t".implode(",\n\t", $cols)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
        } elseif (preg_match('/^CREATE (UNIQUE )?INDEX "(\w+)" ON "(\w+)" USING btree \((.+)\);$/', $s, $m)) {
            $out[] = "CREATE {$m[1]}INDEX `{$m[2]}` ON `{$m[3]}` (".str_replace('"', '`', $m[4]).');';
        } elseif (preg_match('/^ALTER TABLE "(\w+)" ADD CONSTRAINT "(\w+)" FOREIGN KEY \("(\w+)"\) REFERENCES "public"\."(\w+)"\("(\w+)"\) ON DELETE (cascade|set null|no action|restrict) ON UPDATE no action;$/', $s, $m)) {
            $out[] = "ALTER TABLE `{$m[1]}` ADD CONSTRAINT `".substr($m[2], 0, 64)."` FOREIGN KEY (`{$m[3]}`) REFERENCES `{$m[4]}`(`{$m[5]}`) ON DELETE ".strtoupper($m[6]).';';
        } elseif (preg_match('/^ALTER TABLE "(\w+)" ADD COLUMN (.+);$/s', $s, $m)) {
            $out[] = "ALTER TABLE `{$m[1]}` ADD COLUMN ".$column($m[2]).';';
        } elseif (preg_match('/^ALTER TABLE "(\w+)" ALTER COLUMN "(\w+)" DROP NOT NULL;$/', $s, $m)) {
            $out[] = "ALTER TABLE `{$m[1]}` MODIFY `{$m[2]}` CHAR(36) NULL;";
        } else {
            throw new RuntimeException('unhandled statement in '.basename($file).":\n{$s}");
        }
    }
    file_put_contents("{$root}/database/sql/mysql/".basename($file), '-- Generated from ../'.basename($file)." by database/tools/pg2mysql.php — do not edit by hand.\n".implode("\n--> statement-breakpoint\n", $out)."\n");
    echo basename($file).': '.count($out)." statements\n";
}
