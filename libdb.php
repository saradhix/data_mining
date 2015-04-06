<?php

function db_connect($hostname, $port, $dbname, $username, $password)
{
  $conn_string = "host=$hostname port=$port dbname=$dbname user=$username
  password=$password";
  $dbconn = pg_connect($conn_string) or die("Connection failed\n");
  return $dbconn;
}

function db_query($query)
{
  $query = trim($query);
  $query  ="_".$query;
  //echo "Running query $query\n";
  $result = pg_query($query);
  if(!$result)
  {
    echo "ERROR:QUERY FAILED::$query ".pg_last_error()."\n";
  }
  return $result;
}
function db_query_tesla($query)
{
  $query = trim($query);
  echo "Running query $query\n";
  $result = pg_query($query);
  if(!$result)
  {
    echo "ERROR:QUERY FAILED::$query ".pg_last_error()."\n";
  }
  return $result;
}
function db_fetch_array($result,$result_type = PGSQL_BOTH )
{
  $row = pg_fetch_array($result, null, $result_type);
  return $row;
}
function db_fetch_row($result)
{
  $row = pg_fetch_row($result);
  return $row;
}

function db_error()
{
  $error = pg_last_error();
  return $error;
}
function db_num_fields($result)
{
  $num = pg_num_fields($result);
  return $num;
}
function db_fetch_field($result,$i)
{
  $name = pg_field_name($result,$i);
  return $name;
}
function db_query_fields($result)
{
  $i=0;
  $myarray=array();
  while ($i < db_num_fields($result))
  {
    $fld = db_fetch_field($result, $i);
    $myarray[]=$fld;
    $i = $i + 1;
  }
  return $myarray;

}

function db_field_type($result, $field_number)
{
  return pg_field_type($result, $field_number);
}

function db_field_name($result, $field_number)
{
  return pg_field_name($result, $field_number);
}

function db_field_num($result,$field_name)
{
  return pg_field_num($result, $field_name);
}
function db_affected_rows($result)
{
  return pg_affected_rows($result);
}
function db_close($resource)
{
  return pg_close($resource);
}
