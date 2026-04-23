<?php
$lines=file('c:\\xampp\\htdocs\\Nuevo-Puerta-drey\\admindashboard.php');
$c=0;$max=0;$maxLine=0;
foreach($lines as $i=>$ln){
    $c+=substr_count($ln,"{")-substr_count($ln,"}");
    if($c>$max){ $max=$c; $maxLine=$i+1; }
}
echo "MAX:".$max." at line ".$maxLine.PHP_EOL;
