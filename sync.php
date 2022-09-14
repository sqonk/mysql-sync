<?php

use sqonk\phext\core\{arrays,strings};
use sqonk\phext\context\context;

function run_later(?SplStack &$context, callable $callback): void
{
    $context ??= new class() extends SplStack {
        public function __destruct()
        {
            while ($this->count() > 0) {
                \call_user_func($this->pop());
            }
        }
    };

    $context->push($callback);
}

 /* Read the user input from the command prompt. Optionally pass a question/prompt to
 * the user, to be printed before input is read.
 * 
 * NOTE: This method is intended for use with the CLI.
 * 
 * -- parameters:
 * @param  $prompt The optional prompt to be displayed to the user prior to reading input.
 * @param  $newLineAfterPrompt If TRUE, add a new line in after the prompt.
 * @param  $allowEmptyReply If TRUE, the prompt will continue to cycle until a non-empty answer is provided by the user. White space is trimmed to prevent pseudo empty answers. This option has no affect when using $allowedResponses.
 * @param $allowedResponses An array of acceptable replies. The prompt will cycle until one of the given replies is received by the user.
 * 
 * @return The response from the user in string format.
 * 
 * Example:
 * 
 * ``` php
 * $name = prompt('What is your name?');
 * // Input your name.. e.g. John
 * println('Hello', $name);
 * // prints 'Hello John' (or whatever you typed into the input).
 * ```
 */
function prompt(string $prompt = '', bool $newLineAfterPrompt = false, bool $allowEmptyReply = true, array $allowedResponses = []): string
{
    $sapi = php_sapi_name();
    if ($sapi != 'cli')
        throw new RuntimeException("Attempt to call prompt() from $sapi. It can only be used when run from the command line.");
    
    if ($prompt) {
        $seperator = $newLineAfterPrompt ? PHP_EOL : ' ';
        if (! str_ends_with(haystack:$prompt, needle:$seperator))
            $prompt .= $seperator;
    }
        
    $an = '';
    $fh = fopen("php://stdin", "r");
    try {
        while (true)
        {
            if ($prompt)
                echo $prompt;
        	$an = trim(fgets($fh));

            if (count($allowedResponses) && in_array(needle:$an, haystack:$allowedResponses))
                break;
            
            else if (count($allowedResponses) == 0 && ($allowEmptyReply || $an))
                break;
            
        } 
    }
    finally {
        fclose($fh);
    }
    
	return $an;
}


// Run a synchronisation using a json config file.
function mysql_sync_with_conf(string $filePath)
{
	if (! $filePath or ! strings::ends_with($filePath, '.json')) {
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
 * Run a synchronisation using an in-memory configuration object.
 * 
 * The object should be in the same structure as the sample json file.
 * 
 * An array can also be provided, which will be converted to an object internally.
 */
function mysql_sync(array|object|null $config): void
{
	if (is_array($config)) // convert from array format to object.
		$config = json_decode(json_encode($config));
	
	# --- Connect to both databases.
	println("\n--Source: {$config->source->user}@{$config->source->host}/{$config->source->database}");

	$source = new mysqli($config->source->host, $config->source->user, $config->source->password, $config->source->database);
	if (! $source or $source->connect_error) {
		println("Unable to connect to the source MySQL database.");
		exit;
	}
	run_later ($_, function() use ($source) {
		if ($source)
            $source->close();
	});

	println("\n--Dest: {$config->dest->user}@{$config->dest->host}/{$config->dest->database}\n");

	$dest = new mysqli($config->dest->host, $config->dest->user, $config->dest->password, $config->dest->database);
	if (! $source or $source->connect_error) {
		println("Unable to connect to the source MySQL database.");
		exit;
	}
	println("\n--Dest: {$config->dest->host}/{$config->dest->database}\n");
    
    $dest = connect($config->dest);
	run_later ($_, function() use ($dest) {
        if ($dest)
    		$dest->close();
	});
	
	# ---- Run the sync process, first as a dry run to see what has changed and then ask the user if they want to do it for real.
	println("Displaying differences..");
    
    $statements = sync($source, $dest, true);
	if (getCounts($statements) > 0) 
	{
		do {
			$r = ask("Do you want to deploy the changes to the destination, dump the SQL modification commands to a file or cancel? [D]eploy to destination, [s]ave to file, [c]ancel");
		}
		while (! arrays::contains(['D', 's', 'c'], $r));
	
		if ($r == 'D') {
			context::mysql_transaction($dest)->do(function($dest) use ($source) {
				sync($source, $dest, false);
			});
        }
        else if ($r == 's') {
            $out = implode("\n\n", array_map(fn($set) => implode("\n\n", $set), $statements));
            $now = date('Y-m-d-h-i');
            file_put_contents(getcwd()."/database_diff-$now.sql", $out);
        }
	}
}

function describe($db): array
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
                $def = $info['Default'] ?? null;
                if ($def !== null)
                    $info['Default'] = "DEFAULT '$def'";
				
				$table[$info['Field']] = arrays::implode_only(' ', $info, 
					'Field', 'Type', 'Null', 'Default', 'Extra');
	        }
	        $tables[$tableName] = $table;
		}
    }
    return $tables;
}

function getCounts(array $statements): int {
    return array_sum(array_map(fn($arr) => count($arr), $statements));
}

function sync($source, $dest, bool $dryRun): array
{
	$source_tables = describe($source);
	$dest_tables = describe($dest);

	$new = array_diff_key($source_tables, $dest_tables);
	$dropped = array_diff_key($dest_tables, $source_tables);
	$existing = array_diff_key($source_tables, $new);
		
	// -- new tables
    
    $newStatements = [];
    $dropStatements = [];
    $alterStatements = [];
    	
	println("\n====== NEW TABLES");
	foreach ($new as $tblName => $cols) 
	{
		$create = $source->query("SHOW CREATE TABLE `$tblName`");
		if ($create && $r = $create->fetch_assoc()) 
		{
			$create = $r["Create Table"];
            $newStatements[] = $create;
			if (! $dryRun) {
				println("creating $tblName");
				$dest->query($create);
			}
			else {
                println("\n$create");
			}
		}
	}
	if (count($newStatements) == 0) 
		println('There are no new tables.');
	
	// -- tables to drop
	
	println("\n===== TABLES TO REMOVE");
	if (count($dropped) > 0)
	{
		foreach ($dropped as $tblName => $cols) 
		{
		    $drop = "DROP TABLE `$tblName`";
            $dropStatements[] = $drop;
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
	        $command = "ALTER TABLE `$tblName` \n".trim(implode(",\n", $alter));
            $alterStatements[] = $command;
			if (! $dryRun) {
				println("adjusting $tblName\n");
				$dest->query($command);
			}
			else
				println("\n$command");
	    }
	}
	
	if (count($alterStatements) == 0) 
		println('There are no changes between existing tables.');
	
	println();
	return [$newStatements, $dropStatements, $alterStatements];
}

