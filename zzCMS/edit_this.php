<?php
$StartOver='';
$StartOver='yup';//wipes out and rebuilds the database
$view_all_variables_passed='';
//$view_all_variables_passed='yup';//shows all variables passed (for troubleshooting)

/*
Instructions:
Create your own favicon.png and replace the one in the favicon folder
Edit CSS3.css
edit most of the rest of this file
you'll also want to add your own TopImages jpgs and archive initial_top_image
then have fun logging in and managing content
*/

//set site-wide parameters
$PrettySiteTitle="Kirk's Content Management System Starter Site";
$PrettySiteDescription="Kirk's CMS Starter Site";
$SiteURL="StarterContentManagementSite.com";
$This_CMS_Table='Kirk_CMS_Table';
$This_Email_Tracker_Table='Kirk_CMS_Email_Tracker_Table';
$AddToCalendarPRODID='CMS Starter Site'; //letters, numbers, and spaces only
$AddToCalendarEventTitleGroup='CMS Starter Site';
$AddToCalendarTimeZone='America/New_York';
$SiteTimeZone='US/Eastern';
$PasswordShortCode='abc';
$EmailFromName='CMS Starter Site';
$EmailFromAddress='music.seiemmaus@gmail.com';//make this a real email address and different from $AdminUserNameAndEmail

$AdminUserNameAndEmail='kirkhopkins0057@gmail.com';
$AdminInitialPassword='aaa';
$UserUserNameAndEmail='pamhopkins0057@gmail.com';
$UserInitialPassword='aaa';

$SessionStuffUsername='CMS_Username';
$SessionStuffPassword='CMS_Password';
$SessionStuffLoggedIn='StarterSiteName_logged_in';
$SessionStuffLogout='CMS_Logout';

$NavigationMenuItems='';
$NavigationMenuItems.="\n".'<li><a href="index.php?page=Home" title="Site home page">Home</a></li>';
$NavigationMenuItems.="\n".'<li><a href="index.php?page=Page_01" title="1st Navigation Menu Item">Page 01</a></li>';
$NavigationMenuItems.="\n".'<li><a href="index.php?page=Page_02" title="2nd Navigation Menu Item">Page 02</a></li>';
$NavigationMenuItems.="\n".'<li><a href="index.php?page=Page_03" title="3rd Navigation Menu Item">Page 03</a></li>';
$NavigationMenuItems.="\n".'<li><a href="https://apple.com" title="External Link" target="_blank">Apple</a></li>';
$NavigationMenuItems.="\n".'<li><a href="login.php" title="Content Management">Login</a></li>';

//this should most likely match $NavigationMenuItems
$NavigationItemsForContentManagement='';
$a_page='Home';
$NavigationItemsForContentManagement.="\n".'<option value="'.$a_page.'" title="Selecting '.$a_page.'" selected>'.str_replace('_',' ',$a_page).'</option>';
if(!is_dir('images/'.$a_page)){ mkdir('images/'.$a_page); } //creates a folder in images to display kind of a photo gallery
$a_page='Page_01';
$NavigationItemsForContentManagement.="\n".'<option value="'.$a_page.'" title="Selecting '.$a_page.'">'.str_replace('_',' ',$a_page).'</option>';
if(!is_dir('images/'.$a_page)){ mkdir('images/'.$a_page); } //creates a folder in images to display kind of a photo gallery
$a_page='Page_02';
$NavigationItemsForContentManagement.="\n".'<option value="'.$a_page.'" title="Selecting '.$a_page.'">'.str_replace('_',' ',$a_page).'</option>';
if(!is_dir('images/'.$a_page)){ mkdir('images/'.$a_page); } //creates a folder in images to display kind of a photo gallery
$a_page='Page_03';
$NavigationItemsForContentManagement.="\n".'<option value="'.$a_page.'" title="Selecting '.$a_page.'">'.str_replace('_',' ',$a_page).'</option>';
//if(!is_dir('images/'.$a_page)){ mkdir('images/'.$a_page); } //creates a folder in images to display kind of a photo gallery

//Replace the favicon: located at /favicon/favicon.png

//$footer_content="\n".'<a href="https://www.facebook.com/groups/seiemmaus.org/" target="_blank" >Facebook</a>';
$footer_content="\n".'<div style="color:#fff;">This is the footer</div>';

//connect to Postgres database
//you may have to setup your username and password in Bluehost using a tool called Advanced...phpPgAdmin

require_once("Connect_to_Postgres.php");

/*
$password='enter_your_password_here';
////or create a password with Security by obscurity
//$P1="qioejfaskdnv;adf;qefnr;kasn;fwjefhJ;OI;KJ;OIH;ENF;LKDCNX,EIUH";
//$P2="eoifjqeEFQEFOjeksoj23434545634215677889890jweijfJIJoPOIJ><?MB";
//$P3=";j;lekjfj;lkKLJb@#$%@#^$^&*^*(*&()!~_+$%^&^%$#%$%&^^&*sdgfsfg";
//$P4="oieqf;dskciojf(*&*wn	3r43dphsldahlfiuyosd sdiyowe87dyoDHlsdhd";
//$P5="J:KJ:OIefemflsjc;C/SDmf;oIjOADC<>Cn>ZKChddfOIJwlenf><MCvSADsd";
//$password='';
//$password.=substr($P1,2,1);

echo $password;

$user = 'db_username';
$db = 'db_name';
$host = 'localhost';

$database_connection = pg_connect("host=".$host."
                       dbname=".$db."
                       user=".$user."
                       password=".$password
                     )
           or die('Could not connect: ' . pg_last_error());
*/
//echo $PasswordShortCode;
//exit;
//$StartOverCode=md5(date('Ymd').$PasswordShortCode); //don't change this line

//Use a web browser to navigate to the start_over.php page
//http://kirkhopkins.com/StarterContentManagementSite/start_over.php
?>