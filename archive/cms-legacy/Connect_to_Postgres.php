<?php
//  Program: Connect_to_Postgres.php
//  Section: Utility
//  Purpose: Connect to the database
//  Created: 11-03-2017 ksh

//Security by obscurity
$P1="qioeLJb@#jfaskdnv;adf;qefnr;kasn;fwjefhJ;OI;KJ;OIH;ENF;LKDCNX,EIUHhy";
$P2="eoifjqeEFQEFOjeksoj23434545634efnr;kas215677889890jweijfJIJoPOIJ><?MB";
$P3=";j;lekjfj;lkKLJb@#$%@#^$^&*^*(ciojf(*&**&()!~_+$%^&^%$#%$%&^^&*sdgfsf";
$P4="oiehsldahlqf;dskciojf(*&*wn	3r43dphsldahlfiuyosd sdiyowe87dyoDHlsdhdf";
$P5="J:KJ:OIefemflsjc;C45634ef/SDmf;oIjOADC<>Cn>ZKChddfOIJwlenf><MCvSADsd3";
$password='';
$password.=substr($P1,1,3);
$password.=substr($P2,11,2);
$password.=substr($P3,12,1);
$password.=substr($P4,15,2);
$password.=substr($P5,5,1);
//echo $password.'<br>';
//exit;
$user = 'mains';
$db = 'postgres';
$host = 'localhost';

$database_connection = pg_connect("host=".$host."
                       dbname=".$db."
                       user=".$user."
                       password=".$password
                     )
           or die('Could not connect: ' . pg_last_error());

//echo 'POSTGRES Connected';
?>
