<?php
$lines=file('c:\xampp\htdocs\Nuevo-Puerta-drey\admindashboard.php');
$c=0;$prev=0;$i=0;
foreach($lines as $ln){
  $i++;
  $c += substr_count($ln, "{") - substr_count($ln, "}");
  if($c !== $prev){
    $out = sprintf("%6d %3d %s", $i, $c, trim(substr($ln,0,200)));
    echo $out . PHP_EOL;
    $prev = $c;
  }
}
?>
