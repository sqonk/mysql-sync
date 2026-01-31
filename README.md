# MySQL Synchroniser

[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.4-8892BF.svg)](https://php.net/)
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
		"port" : 3306
	},
	"dest" : {
		"host" : "",
		"user" : "",
		"password" : "",
		"database" : "",
		"port" : 3306
	},
}
```
_Note that the above is a baseline config. See the sample json file for a full set of possible options._

Then from your terminal run the following the command:

``` bash
vendor/bin/mysql-sync path/to/my/config-file.json
```

### Method 2: Using in-memory PHP array

Create a new PHP script, load the composer includes and pass your config array accordingly.

``` php
require 'vendor/autoload.php';

mysql_sync([
    "source" => [
        "host" => "",
        "user" => "",
        "password" => "",
        "database" => "",
        "port" => "3306"
    ],
    "dest" => [
        "host" => "",
        "user" => "",
        "password" => "",
        "database" => "",
        "port" => "3306"
    ],
    "ignoreColumnWidths" => false
]);
```

#### Ignoring Column Widths

If you are in a situation in which the configuration of the destination database differs from that of the source environment in such a way that column widths do not match up then you can set the option `ignoreColumnWidths` to true in the top level of your sync configuration.

This will adjust the comparison to ignore column width/length.

#### Dealing with Collation differences on tables

If the source and destination databases have different character encoding sets you can instruct the synchroniser to either remove or substitute encoding sets _when creating new tables in the destination_.

##### Omitting COLLATE syntax entirely

Add a key `omitCollate` to the top level of your json config with a value of `true` or `false`. Setting it to true will remove all `COLLATE=` commands on the end of `CREATE TABLE` lines.

##### Substituting collation

You can also elect to replace occurances of multiple table collations on your source database to another set that is present on the destination database.

To do so, add the following to the top level of your config, replacing the values of 'from' and 'to':

```json
  collateSubstitutions : [
  	{
  	   "from" : "collationOnSource1",
  	   "to": "collationOnDestination1"
  	}
  ]
```

Because the `collateSubstitutions` is an array, you can add as many substiution sets as required.

##### Using both options together

Setting `omitCollate` to `true` and adding substitution sets will function as expected; Substitutions will be replace occurances and any collations not matching one of the sets will be removed.

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

