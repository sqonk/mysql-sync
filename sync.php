<?php

use sqonk\phext\core\{arrays,strings};
use sqonk\phext\context\context;


// Run a synchronisation using a json config file.
function mysql_sync_with_conf(string $filePath)
{
	if (! $filePath or ! strings::ends_with($filePath, '.json')) {
		println("This script should take only 1 arguement, being a json file containing both the source and destination database information. Examine the database.sample.json file for an example.");
		exit;
	}
	if (! file_exists($filePath)) {
		println("The specified file does not exist.");
		exit;
	}

	mysql_sync(json_decode(file_get_contents($filePath)));
}

/* 
	Run a synchronsisation using an in-memory configuration object.

	The object should be in the same structure as the sample json file.

	An array can also be provided, which will be converted to an object
	internally.
*/
function mysql_sync($config)
{
	if (is_array($config))
		$config = json_decode(json_encode($config));
	
	# --- Connect to both databases.
	println("\n--Source: {$config->source->user}@{$config->source->host}/{$config->source->database}");

	$source = new mysqli($config->source->host, $config->source->user, $config->source->password, $config->source->database);
	if (! $source or $source->connect_error) {
		println("Unable to connect to the source MySQL database.");
		exit;
	}
	defer ($_, function() use ($source) {
		$source->close();
	});

	println("\n--Dest: {$config->dest->user}@{$config->dest->host}/{$config->dest->database}\n");

	$dest = new mysqli($config->dest->host, $config->dest->user, $config->dest->password, $config->dest->database);
	if (! $source or $source->connect_error) {
		println("Unable to connect to the source MySQL database.");
		exit;
	}
	defer ($_, function() use ($dest) {
		$dest->close();
	});
	
	# ---- Run the sync process, first as a dry run to see what has changed and then ask the user if they want to do it for real.
	println("Displaying differences..");

	if (sync($source, $dest, true) > 0) 
	{
		do {
			$r = ask("Do you want deploy the changes? (Y/n)");
		}
		while (! arrays::contains(['Y', 'n'], $r));
	
		if ($r == 'Y')
			context::mysql_transaction($dest)->do(function($dest) use ($source) {
				sync($source, $dest, false);
			});
		else 
			println('changes aborted.');
	}
}

function describe($db) 
{
    $tables = [];
	
	foreach ($db->query("SHOW TABLES") as $row)
	{
		$tableName = arrays::first($row);
        
		if ($r = $db->query("DESCRIBE `$tableName`"))
		{
			$table = [];
	        foreach ($r as $info) 
			{
				$info['Null'] = $info['Null'] == 'YES' ? '' : 'NOT NULL';
                if (! empty($info['Default']))
                    $info['Default'] = 'DEFAULT '.$info['Default'];
				
				$table[$info['Field']] = arrays::implode_only(' ', $info, 
					'Field', 'Type', 'Null', 'Default', 'Extra');
	        }
	        $tables[$tableName] = $table;
		}
    }
    return $tables;
}

function sync($source, $dest, bool $dryRun)
{
	$source_tables = describe($source);
	$dest_tables = describe($dest);

	$new = array_diff_key($source_tables, $dest_tables);
	$dropped = array_diff_key($dest_tables, $source_tables);
	$existing = array_diff_key($source_tables, $new);
	
	$newCount = $existingCount = 0;
	
	// -- new tables
	
	println("\n====== NEW TABLES");
	foreach ($new as $tblName => $cols) 
	{
		$create = $source->query("SHOW CREATE TABLE `$tblName`");
		if ($create && $r = $create->fetch_assoc()) 
		{
			$newCount++;
			$create = $r["Create Table"];
			if (! $dryRun) {
				println("creating $tblName");
				$dest->query($create);
			}
			else
				println("\n$create");
		}
	}
	if ($newCount == 0) 
		println('There are no new tables.');
	
	// -- tables to drop
	
	println("\n===== TABLES TO REMOVE");
	if (count($dropped) > 0)
	{
		foreach ($dropped as $tblName => $cols) 
		{
		    $drop = "DROP TABLE `$tblName`";
			if (! $dryRun) {
				println("dropping $tblName");
				$dest->query($drop);
			}
			else
				println("\n$drop");
		}
	}
	
	else 
		println('There are no tables to drop');
	
	// -- existing tables to modify
	
	println("\n===== EXISTING TABLES");
	foreach ($existing as $tblName => $master_cols) 
	{
	    $slave_cols = $dest_tables[$tblName];
	    // compare columns
	    $newCols = array_diff_key($master_cols, $slave_cols);
	    $droppedCols = array_diff_key($slave_cols, $master_cols);
	    $existingM = array_diff($master_cols, $newCols);
	    $existingS = array_diff($slave_cols, $droppedCols);
	    $changed = array_diff($existingM, $existingS);
	    $alter = [];
		
	    foreach ($newCols as $cmd) 
	       $alter[] = " ADD COLUMN $cmd";
	    
	    foreach ($changed as $cmd) 
	        $alter[] = " MODIFY COLUMN $cmd";
	    
	    foreach ($droppedCols as $colName => $cmd) 
	        $alter[] = " DROP COLUMN $colName";
	    
	    if (count($alter) > 0) 
		{
			$existingCount++;
	        $command = "ALTER TABLE `$tblName` \n".trim(implode(",\n", $alter));
			if (! $dryRun) {
				println("adjusting $tblName\n");
				$dest->query($command);
			}
			else
				println("\n$command");
	    }
	}
	
	if ($existingCount == 0) 
		println('There are no changes between existing tables.');
	
	println();
	return $newCount + count($dropped) + $existingCount;
}

