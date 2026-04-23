<?php
$input = 'c:\\xampp\\htdocs\\Nuevo-Puerta-drey\\admindashboard.php';
$outfile = 'c:\\xampp\\htdocs\\Nuevo-Puerta-drey\\temp_braces_utf8.txt';
$lines = file($input);
$c=0;$prev=0;$i=0;$out="";
foreach($lines as $ln){
  $i++;
  $c += substr_count($ln, "{") - substr_count($ln, "}");
  if($c !== $prev){
    $out .= sprintf("%6d %3d %s\n", $i, $c, trim(substr($ln,0,200)));
    $prev = $c;
  }
}
file_put_contents($outfile, $out);
echo "Wrote to $outfile\n";
?>
