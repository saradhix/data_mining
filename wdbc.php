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

$tablename="wdbc_data";
$sql="drop table if exists $tablename";
db_query($sql);
$idcol="id";
$target_class="is_malignant";
$howmany_features=30;
$type="numeric";
$mapping=["'B'"=>"0","'M'"=>"1"];
$lr_result="lr_result";
$nb_result="nb_result";
$knn1_result="knn1_result";
$knn5_result="knn5_result";

$data_file_name="wdbc.data";
$create_sql="create table $tablename ( $idcol int, $target_class int,  ";
$fragments=[];
for($i=1;$i<=$howmany_features;$i++)
{
  $colname="x$i";
  $sql="$colname $type";
  $fragments[]=$sql;
}

$col_sql=implode(", ",$fragments);

$sql = "$create_sql $col_sql ) distribute by replication";
echo "Sql=$sql\n";
db_query($sql);
echo "Table $tablename created\n";

//db_query("begin");
$cx=0;
$fp=fopen($data_file_name,"r");
while(($s=fgets($fp))!==false)
{
  $s=trim($s);
  $s=str_replace("M","1",$s);
  $s=str_replace("B","0",$s);
  $sql="insert into $tablename values($s)";
  db_query($sql);
  $cx++;
  if($cx % 100 == 0)
  {
    echo "Inserted $cx rows\n";
  }
}
db_query("commit");
$sql="select count(*) from $tablename";
$result=db_query($sql);
$row=db_fetch_row($result);
$count=$row[0];
echo "Total rows=$cx inserted rows=$count\n";
update_ids($tablename,$idcol);
//update_mappings($tablename,$target_class,$mapping);



split_table($tablename,"id","10");

$training_table=$tablename."_train";
$test_table=$tablename."_test";

//try a logistic regression training

$sql=<<<EOL
select * from logistic_regression(
  {
  "input":"$training_table",
  "target_class":"$target_class",
  "columns":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30"],
  "output_model":"output_model_breast_cancer",
  "max_iter":100
  })
EOL;
echo "Running sql=$sql\n";
$result=db_query_tesla($sql);
/*
while($row=db_fetch_array($result,PGSQL_ASSOC))
{
  //print_r($row);
}
*/


$sql=<<<EOL
select * from logistic_regression_predict(
{
  "model":"output_model_breast_cancer",
  "test":"$test_table",
  "id":"id",
  "target_table":"$lr_result"
})
EOL;
db_query_tesla($sql);

$sql="alter table $test_table add column lr_predict int";
db_query($sql);
echo "lr_predict column added to $test_table\n";
join_table($test_table,$lr_result,"id","lr_predict","prediction");
echo "Calculating accuracy\n";

$sql="select count(*) from $test_table";
$result=db_query($sql);
$row=db_fetch_row($result);
$test_count=$row[0];

$sql="select count(*) from $test_table where lr_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "LR:correct=$right_count total=$test_count\n";



$sql="alter table $test_table add column knn1_predict int";
db_query($sql);
echo "knn1_predict column added to $test_table\n";
//Run knn5 now

$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":1,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set knn1_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where knn1_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "KNN1:correct=$right_count total=$test_count\n";


$sql="alter table $test_table add column knn5_predict int";
db_query($sql);
echo "knn5_predict column added to $test_table\n";
//Run knn5 now

$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":5,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set knn5_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where knn5_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "KNN5:correct=$right_count total=$test_count\n";

//Now do the ensemble classification
//1. Run the lr predict on train data. Add the column to the training table
//2. Now use lr_predict on test and train to do knn classification

/*Add lr_predict for the training table*/
$sql="alter table $training_table add column lr_predict int";
db_query($sql);
echo "lr_predict column added to $training_table\n";
$sql=<<<EOL
select * from logistic_regression_predict(
{
  "model":"output_model_breast_cancer",
  "test":"$training_table",
  "id":"id",
  "target_table":"$lr_result"
})
EOL;
$result=db_query_tesla($sql);
echo "Done executing lr_predict on training\n";
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['prediction'];
  $sql="update $training_table set lr_predict=$prediction where id=$idval";
  db_query($sql);
}

$sql="alter table $test_table add column ensemble_predict int";
db_query($sql);
echo "ensemble_predict column added to $test_table\n";
//Run knn5 now


$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":1,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30","lr_predict"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set ensemble_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where ensemble_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "LR-KNN1-ENS:correct=$right_count total=$test_count\n";
$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":5,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30","lr_predict"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set ensemble_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where ensemble_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "LR-KNN5-ENS:correct=$right_count total=$test_count\n";


//Naive bayes begins
//Try naive bayes
$sql=<<<EOL
select * from naive_bayes({"input":"$training_table",
"target_class":"$target_class",
"numeric_columns":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30"],
"partition_by":"id","output_model":"bayes_model_wisconsin"});
EOL;
db_query_tesla($sql);


//Add nb_predict for naive_bayes_prediction
$sql="alter table $test_table add column nb_predict int";
db_query($sql);
echo "nb_predict column added to $test_table\n";
$sql=<<<EOL
select * from naive_bayes_predict(
{
"input":"$test_table",
"model":"bayes_model_wisconsin",
"id":"id"
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['prediction'];
  $sql="update $test_table set nb_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where nb_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "NB:correct=$right_count total=$test_count\n";

//Add nb_predict to training table
$sql="alter table $training_table add column nb_predict int";
db_query($sql);
echo "nb_predict column added to $training_table\n";
$sql=<<<EOL
select * from naive_bayes_predict(
{
"input":"$training_table",
"model":"bayes_model_wisconsin",
"id":"id"
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['prediction'];
  $sql="update $training_table set nb_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $training_table where nb_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
//echo "NB:correct=$right_count total=$test_count\n";

//-----------------------------------End of naive bayes*/

//Run knn5 now


$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":1,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30","nb_predict"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set ensemble_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where ensemble_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "NB-KNN1-ENS:correct=$right_count total=$test_count\n";
$sql=<<<EOL
select * from knn(
{
"training_table":"$training_table",
"test_table":"$test_table",
"k":5,
"id":"id",
"output_table":"nn_output",
"target_class":"is_malignant",
"features":["x1","x2","x3","x4","x5","x6","x7","x8","x9","x10","x11","x12","x13","x14","x15",
    "x16","x17","x18","x19","x20","x21","x22","x23","x24","x25","x26","x27","x28","x29","x30","nb_predict"]
})
EOL;
$result=db_query_tesla($sql);
while($row=db_fetch_array($result))
{
  $idval=$row['id'];
  $prediction=$row['class'];
  $sql="update $test_table set ensemble_predict=$prediction where id=$idval";
  db_query($sql);
}
$sql="select count(*) from $test_table where ensemble_predict=is_malignant";
$result=db_query($sql);
$row=db_fetch_row($result);
$right_count=$row[0];
echo "NB-KNN5-ENS:correct=$right_count total=$test_count\n";

db_close($dbcnx);

?>
