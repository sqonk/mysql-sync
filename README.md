# MySQL Synchroniser

[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.3-8892BF.svg)](https://php.net/)
[![License](https://sqonk.com/opensource/license.svg)](license.txt)

The MySQL Synchroniser is a simple script written in PHP that can assist and automate the synchronisation of differences in table structures between two database servers.

Synchronisation is performed between a source database and a destination.


## Install

Via Composer

``` bash
$ composer require sqonk/mysql-sync
```

## Disclaimer - (Common Sense)

Always backup the destination database prior to making any changes, this should go without saying.

## Usage

### Method 1: Using a JSON config file

First duplicate the sample json sync file provided the conf folder, call it something meanginful and enter the database details for both the source and destination databases.

``` json
{
	"source" : {
		"host" : "",
		"user" : "",
		"password" : "",
		"database" : "",
		"port" : "3306"
	},
	"dest" : {
		"host" : "",
		"user" : "",
		"password" : "",
		"database" : "",
		"port" : "3306"
	}
}
```

Then from your terminal run the following the command:

``` bash
vendor/bin/mysql-sync path/to/my/config-file.json
```

### Method 2: Using in-memory PHP array

Create a new PHP script, load the composer includes and pass your config array accordingly.

``` php
require 'vendor/autoload.php'

mysql_sync([
	"source" => [
		"host" => "",
		"user" => "",
		"password" => "",
		"database" => "",
		"port" : "3306"
	],
	"dest" => [
		"host" => "",
		"user" => "",
		"password" => "",
		"database" => "",
		"port" : "3306"
	]
]);
```

### Process

A dry-run will first be performed and any differences will be displayed, including:

* New tables to create in the destination.
* Old tables to drop no longer present on the source.
* Tables present in both but with differing columns (including new, old and modified)

Once done, you will be prompted if you wish to apply the changes for real.

## Credits

* Theo Howell
* Oliver Jacobs

## License

The MIT License (MIT). Please see [License File](license.txt) for more information.

