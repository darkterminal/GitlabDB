# GitlabDB
A PHP Class that reads JSON file as a database. Use for sample DBs using Gitlab API inspire from [donjajo/php-jsondb](https://github.com/donjajo/php-jsondb)

### Usage
Install package
```bash
composer require darkterminal/GitlabDB
```

#### Initialize
```php
<?php
use GitlabDB\GitlabDB;

$options['personal_access_token']    = "YOUR_GITLAB_ACCESS_TOKEN";
$options['project_id']               = "YOUR_GITLAB_PROJECT_ID";
$options['branch']                   = "YOUR_GITLAB_BRANCH";
$options['cloud_url']                = "YOUR_GITLAB_URL";

$path = 'YOUR_PATH_ON_GITLAB';

$json_db = new GitlabDB( $options, $path ); // Or passing the file path of your json files with no trailing slash, default is the root directory. E.g.  new GitlabDB( $options, 'database' )
```

#### Inserting
Insert into your new JSON file. Using *users.json* as example here

**NB:** *Columns inserted first will be the only allowed column on other inserts*

```php
<?php
$json_db->insert( 'users.json',
	[
		'name' => 'Thomas',
		'state' => 'Nigeria',
		'age' => 22
	]
);
```

#### Get
Get back data, just like MySQL in PHP

##### All columns:
```php
<?php
$users = $json_db->select( '*' )
	->from( 'users.json' )
	->get();
print_r( $users );
```

##### Custom Columns:
```php
<?php
$users = $json_db->select( 'name, state'  )
	->from( 'users.json' )
	->get();
print_r( $users );

```

##### Where Statement:
This WHERE works as AND Operator at the moment or OR
```php
<?php
$users = $json_db->select( 'name, state'  )
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas' ] )
	->get();
print_r( $users );

// Defaults to Thomas OR Nigeria
$users = $json_db->select( 'name, state'  )
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas', 'state' => 'Nigeria' ] )
	->get();
print_r( $users );

// Now is THOMAS AND Nigeria
$users = $json_db->select( 'name, state'  )
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas', 'state' => 'Nigeria' ], 'AND' )
	->get();
print_r( $users );


```
##### Where Statement with regex:
By passing`GitlabDB::regex` to where statement, you can apply regex searching. It can be used for implementing `LIKE` or `REGEXP_LIKE` clause in SQL.

```php
$users = $json_db->select( 'name, state' )
	->from( "users" )
	->where( array( "state" => GitlabDB::regex( "/ria/" )), GitlabDB::AND )
	->get();
print_r( $users );
// Outputs are rows which contains "ria" string in "state" column.
```

##### Order By:
Thanks to [Tarun Shanker](http://in.linkedin.com/in/tarunshankerpandey) for this feature. By passing the `order_by()` method, the result is sorted with 2 arguments of the column name and sort method - `GitlabDB::ASC` and `GitlabDB::DESC`
```php
<?php
$users = $json_db->select( 'name, state'  )
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas' ] )
	->order_by( 'age', GitlabDB::ASC )
	->get();
print_r( $users );
```

#### Updating Row
You can also update same JSON file with these methods
```php
<?php
$json_db->update( [ 'name' => 'Oji', 'age' => 10 ] )
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas' ] )
	->trigger();

```
*Without the **where()** method, it will update all rows*

#### Deleting Row
```php
<?php
$json_db->delete()
	->from( 'users.json' )
	->where( [ 'name' => 'Thomas' ] )
	->trigger();

```
*Without the **where()** method, it will deletes all rows*

#### Exporting to MySQL
You can export the JSON back to SQL file by using this method and providing an output
```php
<?php
$json_db->to_mysql( 'users.json', 'users.sql' );
```
Disable CREATE TABLE
```php
<?php
$json_db->to_mysql( 'users.json', 'users.sql', false );
```

#### Exporting to XML
[Tarun Shanker](http://in.linkedin.com/in/tarunshankerpandey) also provided a feature to export data to an XML file
```php
<?php
if( $json_db->to_xml( 'users.json', 'users.xml' ) ) {
	echo 'Saved!';
}