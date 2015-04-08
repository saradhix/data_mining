<?php

function split_table($tablename, $id,  $percentage)
{
  $sql="select id from $tablename";
  $result=db_query($sql);
  $ids=[];
  while($row=db_fetch_row($result))
  {
    $ids[]=$row[0];
  }

  //print_r($ids);
  $num_rows=count($ids);
  echo "Number of rows=$num_rows";

  $test_count = round($percentage*$num_rows/100);

  //now start swapping
  for($i=0;$i<$num_rows/2;$i++)
  {
    $first=mt_rand(0,$num_rows-1);
    $second=mt_rand(0,$num_rows-1);
    $temp=$ids[$first];
    $ids[$first]=$ids[$second];
    $ids[$second]=$temp;
  }
  $test_ids=array_slice($ids,0,$test_count);
  //print_r($test_ids);
  $size=count($test_ids);
  echo "Size of test=$size\n";
  $id_str=implode(",",$test_ids);
  $sql="drop table if exists $tablename"."_test";
  db_query($sql);
  $sql="drop table if exists $tablename"."_train";
  db_query($sql);
  $sql="create table $tablename"."_test as select * from $tablename where id in
    ($id_str)";
  db_query($sql);
  $sql="create table $tablename"."_train as select * from $tablename where id 
    not in ($id_str)";
  db_query($sql);
}

function update_ids($tablename, $id)
{
  $temp="temp";
  $sql="drop table if exists $temp";
  db_query($sql);
  $sql="create table $temp as select * from $tablename";
  db_query($sql);
  $sql="delete from $temp";
  db_query($sql);
  //db_query("begin");
  $sql="select * from $tablename";
  $result=db_query($sql);
  $count=0;
  while($row=db_fetch_row($result))
  {
    $count++;
    $id_val=$row[0];
    $row[0]=$count;
    $insert_sql=implode(",",$row);
    $sql="insert into $temp values($insert_sql)";
    db_query($sql);
  }
  //db_query("commit");
  $sql="drop table $tablename";
  db_query($sql);
  $sql="alter table $temp rename to $tablename";
  db_query($sql);
}


function update_mappings($tablename, $column, $mapping)
{
  foreach($mapping as $key=>$value)
  {
    $sql="update $tablename set $column=$value where $column=$key";
    db_query($sql);
  }
}

function join_table($table1, $table2, $common_key, $table1_colname, $table2_colname)
{

  $sql="select $common_key, $table2_colname from $table2";
  $result=db_query($sql);
  while($row=db_fetch_array($result))
  {
    $id=$row[$common_key];
    $val=$row[$table2_colname];
    $sql="update $table1 set $table1_colname=$val where $common_key=$id";
    db_query($sql);
  }
}
