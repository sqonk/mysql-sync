<?php

use sqonk\phext\core\arrays;
use sqonk\phext\core\strings;
use sqonk\phext\context\context;

// Run a synchronisation using a json config file.
function mysql_sync_with_conf(string $filePath): void
{
  if (! $filePath or ! str_ends_with(haystack: $filePath, needle: '.json')) {
    println("This script should take only 1 argument, being a json file containing both the source and destination database information. Examine the database.sample.json file for an example.");
    exit;
  }
  if (! file_exists($filePath)) {
    println("The specified file does not exist.");
    exit;
  }

  mysql_sync(json_decode(file_get_contents($filePath)));
}


/**
 * Run a synchronsisation using an in-memory configuration object. The object should be in 
 * the same structure as the sample json file.
 * 
 * An array can also be provided, which will be converted to an object internally.
 * 
 * @param object|array<mixed> $config
 */
function mysql_sync(object|array $config): void
{
  if (is_array($config)) {
    $config = json_decode(json_encode($config));
  }

  # --- Connect to both databases.
  println("\n--Source: {$config->source->user}@{$config->source->host}/{$config->source->database}");

  $source = new mysqli($config->source->host, $config->source->user, $config->source->password, $config->source->database, $config->source->port);
  if (! $source || $source->connect_error) { // @phpstan-ignore-line
    println("Unable to connect to the source MySQL database.");
    if ($err = $source->connect_error) { // @phpstan-ignore-line
      println($err);
    }
    exit;
  }

  println("\n--Dest: {$config->dest->user}@{$config->dest->host}/{$config->dest->database}\n");

  $dest = new mysqli($config->dest->host, $config->dest->user, $config->dest->password, $config->dest->database, $config->dest->port);
  if (!$source || $source->connect_error) { // @phpstan-ignore-line
    println("Unable to connect to the source MySQL database.");
    if ($err = $source->connect_error) { // @phpstan-ignore-line
      println($err);
    }
    exit;
  }

  $ignoreColumnWidths = (bool)$config->ignoreColumnWidths;

  # ---- Run the sync process, first as a dry run to see what has changed and then ask the user if they want to do it for real.
  println("Displaying differences..");

  $statements = sync($source, $dest, true, $ignoreColumnWidths);
  if (getCounts($statements) > 0) {
    do {
      $r = ask("Do you want to deploy the changes to the destination, dump the SQL modification commands to a file or cancel? [D]eploy to destination, [s]ave to file, [c]ancel");
    } while (! arrays::contains(['D', 's', 'c'], $r));

    if ($r == 'D') {
      context::mysql_transaction($dest)->do(function ($dest) use ($source, $ignoreColumnWidths) {
        sync($source, $dest, false, $ignoreColumnWidths);
      });
    } elseif ($r == 's') {
      $out = implode("\n\n", array_map(fn($set) => implode("\n", $set), $statements));
      $now = date('Y-m-d-h-i');
      file_put_contents(getcwd() . "/database_diff-$now.sql", trim($out));
    }
  }
}

/**
 * @return array<string, mixed> 
 */
function describe(mysqli $db, bool $ignoreColumnWidths): array
{
  $tables = [];

  foreach ($db->query("SHOW TABLES") as $row) {
    $tableName = arrays::first($row) ?? '';
    if (! $tableName) {
      continue;
    }

    if ($r = $db->query("DESCRIBE `$tableName`")) {
      $table = [];
      foreach ($r as $info) {
        $info['Null'] = $info['Null'] == 'YES' ? '' : 'NOT NULL';
        if (isset($info['Default'])) {
          $info['Default'] = 'DEFAULT ' . $info['Default'];
        }

        $type = $info['Type'] ?? '';
        if ($ignoreColumnWidths && $type && str_contains(haystack: $type, needle: '(')) {
          $info['Type'] = preg_replace("/(\(.+?\))/", "", $type);
        }

        $name = $info['Field'];
        $info['FieldQ'] = "`$name`";

        $table[$name] = arrays::implode_only(' ', $info, 'FieldQ', 'Type', 'Null', 'Default', 'Extra');
      }
      $tables[$tableName] = $table;
    }
  }
  return $tables;
}

/**
 * @param array<mixed> $statements
 */
function getCounts(array $statements): int
{
  return array_sum(array_map(fn($arr) => count($arr), $statements));
}

/**
 * @return array{list<string>, list<string>, list<string>}
 */
function sync(mysqli $source, mysqli $dest, bool $dryRun, bool $ignoreColumnWidths): array
{
  $source_tables = describe($source, $ignoreColumnWidths);
  $dest_tables = describe($dest, $ignoreColumnWidths);

  $new = array_diff_key($source_tables, $dest_tables);
  $dropped = array_diff_key($dest_tables, $source_tables);
  $existing = array_diff_key($source_tables, $new);


  $newStatements = [];
  $dropStatements = [];
  $alterStatements = [];

  println("\n====== NEW TABLES");
  foreach ($new as $tblName => $cols) {
    $create = $source->query("SHOW CREATE TABLE `$tblName`");
    if ($create && $r = $create->fetch_assoc()) {
      $create = $r["Create Table"];
      $newStatements[] = $create;
      if (! $dryRun) {
        println("creating $tblName");
        $dest->query($create);
      } else {
        println("\n$create");
      }
    }
  }
  if (count($newStatements) == 0) {
    println('There are no new tables.');
  }

  println("\n===== TABLES TO REMOVE");
  if (count($dropped) > 0) {
    foreach ($dropped as $tblName => $cols) {
      $drop = "DROP TABLE `$tblName`";
      $dropStatements[] = $drop;
      if (! $dryRun) {
        println("dropping $tblName");
        $dest->query($drop);
      } else {
        println("\n$drop");
      }
    }
  } else {
    println('There are no tables to drop');
  }

  println("\n===== EXISTING TABLES");
  foreach ($existing as $tblName => $master_cols) {
    $slave_cols = $dest_tables[$tblName];
    // compare columns
    $newCols = array_diff_key($master_cols, $slave_cols);
    $droppedCols = array_diff_key($slave_cols, $master_cols);
    $existingM = array_diff($master_cols, $newCols);
    $existingS = array_diff($slave_cols, $droppedCols);
    $alter = [];

    foreach ($newCols as $cmd) {
      $st = "ADD COLUMN $cmd";
      if ($dryRun) {
        $st .= ',';
      }
      $alter[] = $st;
    }

    $previousDesc = [];
    foreach ($existingM as $fn => $descrption) {
      $slaveDescription = $existingS[$fn] ?? '';
      if ($descrption != $slaveDescription) {
        $m = "MODIFY COLUMN $descrption";
        if ($dryRun) {
          $m .= ',';
        }
        $alter[] = $m;
        $previousDesc[$m] = $slaveDescription;
      }
    }

    foreach ($droppedCols as $colName => $cmd) {
      $st = "DROP COLUMN $colName";
      if ($dryRun) {
        $st .= ',';
      }
      $alter[] = $st;
    }

    $alterCount = count($alter);
    if ($alterCount > 0) {
      $last = $alter[$alterCount - 1];
      if (str_ends_with(haystack: $last, needle: ',')) {
        $alter[$alterCount - 1] = substr($last, 0, -1);
      }
      if ($dryRun) {
        $alterT = "ALTER TABLE `$tblName`";
        println($alterT);
        $alterStatements[] = $alterT;
        foreach ($alter as $cmd) {
          $alterStatements[] = $cmd;
          println(trim($cmd));
          $d = $previousDesc[$cmd] ?? '';
          if ($d) {
            println("\twas: [$d]");
          }
        }
        println();

        println('-------------');
      } else {
        println("adjusting $tblName\n");
        foreach ($alter as $modify) {
          $cmd = "ALTER TABLE `$tblName` $modify";
          $alterStatements[] = $cmd;
          try {
            $dest->query($cmd);
          } catch (Exception $error) {
            println("Statement failed: [$cmd]", $error->getMessage());
          }
        }
      }
    }
  }

  if (count($alterStatements) == 0) {
    println('There are no changes between existing tables.');
  }

  println();
  return [$newStatements, $dropStatements, $alterStatements];
}
