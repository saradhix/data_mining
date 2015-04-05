<?php
include "libdb.php";
include "libsplit.php";

$hostname = "localhost";
$username = "saradhix";
$password = "";
$dbname = "postgres";
$port="5432";
$dbcnx = db_connect ($hostname, $port, $dbname, $username, $password);
//drop the existing table

$tablename="wisconsin_data";
$sql="drop table if exists $tablename";
db_query($sql);
$idcol="id";
$target_class="is_malignant";
$howmany_features=9;
$type="numeric";
$mapping=["2"=>"0","4"=>"1"];

$data_file_name="breast-cancer-wisconsin.data";
$create_sql="create table $tablename ( $idcol int,  ";
$fragments=[];
for($i=1;$i<=$howmany_features;$i++)
{
  $colname="x$i";
  $sql="$colname $type";
  $fragments[]=$sql;
}

$col_sql=implode(", ",$fragments);

$sql = "$create_sql $col_sql, $target_class int ) distribute by replication";
echo "Sql=$sql\n";
db_query($sql);
echo "Table $tablename created\n";

db_query("begin");

$cx=0;
$fp=fopen($data_file_name,"r");
while(($s=fgets($fp))!==false)
{
  $s=trim($s);
  echo "s=$s\n";
  $sql="insert into $tablename values($s)";
  db_query($sql);
  $cx++;
}
db_query("commit");
$sql="select count(*) from $tablename";
$result=db_query($sql);
$row=db_fetch_row($result);
$count=$row[0];
echo "Total rows=$cx inserted rows=$count\n";
//update_ids($tablename,$idcol);
update_mappings($tablename,$target_class,$mapping);



split_table($tablename,"id","10");

$training_table=$tablename."_train";
$test_table=$tablename."_test";

//try a logistic regression training

$sql=<<<EOL
select * from logistic_regression(
  {
  "input":"$training_table",
  "target_class":"$target_class",
  "columns":["x1","x2","x3","x4","x5","x6","x7","x8","x9"],
  "output_model":"output_model_breast_cancer",
  "max_iter":100
  })
EOL;
echo "Running sql=$sql\n";
$result=db_query_tesla($sql);
while($row=db_fetch_array($result,PGSQL_ASSOC))
{
  //print_r($row);
}

$sql=<<<EOL
select * from logistic_regression_predict(
{
  "model":"output_model_breast_cancer",
  "test":"$test_table",
  "id":"id",
  "target_table":"prediction_breast_cancer"
})
EOL;
echo "Running sql=$sql\n";
db_query_tesla($sql);

//Now compare the predictions

//Find the number of right predictions

$sql="select * from $test_table";
$result=db_query($sql);
$actual=[];
while($row=db_fetch_array($result))
{
  $id_val=$row["id"];
  $class=$row[$target_class];
  $actual[$id_val]=$class;
}
//print_r($actual);
$predicted=[];
$sql="select * from prediction_breast_cancer";
$result=db_query($sql);
while($row=db_fetch_array($result))
{
  $id_val=$row["id"];
  $class=$row["prediction"];
  $predicted[$id_val]=$class;
}
//print_r($predicted);
//add a column
$correct=0;
foreach($predicted as $id=>$prediction)
{
  if($actual[$id] == $prediction)
  {
    $correct++;
  }
}
$total=count($predicted);
echo "Number correct=$correct out of $total\n";


//Run knn4 now
$sql=<<<EOL
select * from knn(
{
"training_table":"$tablename",
"test_table":"$test_table",
"k":5,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9"]
})
EOL;
db_query_tesla($sql);

db_close($dbcnx);

?>
