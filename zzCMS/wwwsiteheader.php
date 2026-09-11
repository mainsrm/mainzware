<?php
include_once('environment.php');
echo "\n".'<!DOCTYPE html>';
echo "\n".'<html lang="en">';
echo "\n".'<head>';
echo "\n".'<link rel="icon" type="image/png" href="favicon/favicon.png">';
echo "\n".'<link rel="apple-touch-icon" href="favicon/favicon.png">';
echo "\n".'<meta name="apple-mobile-web-app-title" content="'.$PrettySiteTitle.'">';
echo "\n".'<meta name="viewport" content="initial-scale = 1,width=device-width"/>';
echo "\n".'<meta name="description" content="'.$PrettySiteDescription.'">';
echo "\n".'<meta charset="UTF-8">';

if(!isset($CMS_site_username)){ $CMS_site_username=''; }

$UseThisCSS='CSS3.css';
$FileTime=@filemtime($UseThisCSS);
echo '<link href="'.$UseThisCSS.'?'.$FileTime.'" media="screen" rel="stylesheet" type="text/css"/>';
echo '
<script>
function AreYouSure() {
  confirm("Do you really want to do this?");
}
</script>
<title>'.$PrettySiteTitle.'</title>
</head>
<body>
<header>
';
//Hard-coded navigation menu items
echo '
<section class="top-nav">
<div>
<a href="index.php" title="'.$PrettySiteTitle.' home page"><img src="favicon/favicon.png" alt="home" style="width:30px; margin:0px 0px 0px 0px;"></a>
</div>
<input name="menu-toggle" id="menu-toggle" type="checkbox" />
<label for="menu-toggle" class="menu-button-container">
<div class="menu-button"></div>
<div style="display:none;">Hamburger menu</div>
</label>
<ul class="menu">
';
echo $NavigationMenuItems;
if((isset($CMS_site_username) and $CMS_site_username<>'') or isset($_SESSION[$SessionStuffLoggedIn])){
  //this is nice if you aren't showing a link to the Login page
  echo "\n".'<li><a href="login.php" title="Manage Content">Manage Content</a></li>';
}
echo '
</ul>
</section>
</header>
';
//echo '$CMS_site_username='.$CMS_site_username.'<br>';
//echo '$CMS_site_logged_in='.$_SESSION[$SessionStuffLoggedIn].'<br>';
$LookInThisSmallFolder='images-Small/SmallTopImages/';
if(!isset($LookInThisFolder)){
  $LookInThisFolder='images/TopImages/';
}
if(!file_exists($LookInThisSmallFolder)){
  mkdir($LookInThisSmallFolder, 0777, true);
}
if(is_dir($LookInThisFolder)){
  $HowOldInSeconds=60*60*24*14; //60 seconds * 60 minutes * 24 hours * 14 days
  $UseThisPicture='';
  require("KirksPictureGrabber.php");
  if($UseThisPicture<>''){
    //if(isset($new_image_counter)){
    //  //if I have new pictures, show them in color
    //  $grayscale='';
    //} else if(rand(1,2) % 2 == 0){
    //  $grayscale='';
    //} else {
    //  $grayscale=' -webkit-filter: grayscale(100%); filter: grayscale(100%);';
    //}
    //$grayscale='';
    //$percent=rand(50,100);
    $percent=100;//totally gray
    $percent=0;//full color
    $grayscale=' -webkit-filter: grayscale('.$percent.'%); filter: grayscale('.$percent.'%);';
    //echo '<img src="/'.$LookInThisFolder.$UseThisPicture.'" alt="'.str_replace('_',' ',$UseThisPicture).'" style="width:100%; margin:0px 0px 0px 0px;">';

    ////////////////////////////////////////////////////////////////////////////////////
    echo "\n".'<picture>';
    //setup a small top image for smartphones
    include_once('kirks_photo_resizer.php');
    $small_original=$LookInThisSmallFolder.$UseThisPicture;
    $small_original=str_ireplace('.jpg','-Small.jpg',$small_original);
    $small_original=str_ireplace('.jpeg','-Small.jpg',$small_original);
    $originalfile=$LookInThisFolder.$UseThisPicture;
    $new_small_webp=$LookInThisSmallFolder.$UseThisPicture;
    $new_small_webp=str_ireplace('.jpg','-Small.webp',$new_small_webp);
    $new_small_webp=str_ireplace('.jpeg','-Small.webp',$new_small_webp);
    if(!file_exists($new_small_webp)){
      include_once('convert_image_to_webp.php');
      //create a small version of the original
      list($width, $height) = getimagesize($originalfile);
      $newwidth='1024';
      //echo 'Find dimensions of the original: '.$originalfile.'<br>';
      //echo 'Original width: '.$width.'<br>';
      //echo 'Original height: '.$height.'<br>';
      //echo 'small_original: '.$small_original.'<br>';
      kirks_image_resizer($originalfile, $small_original, $newwidth);
      //create a webp of the small version
      include_once('convert_image_to_webp.php');
      convert_to_webp($small_original,50); //folder and file, compression
      //delete the original small version
      unlink($small_original);
    }
    //echo 'new_small_webp: '.$new_small_webp.'<br>';

    $new_webp=$LookInThisFolder.$UseThisPicture;
    $new_webp=str_ireplace('.jpg','.webp',$new_webp);
    $new_webp=str_ireplace('.jpeg','.webp',$new_webp);
    //echo '$new_webp from wwwsiteheader='.$new_webp.'<br>';
    //$output_file =  $file . '.webp';
    $output_file=$new_webp;
    if(!file_exists($output_file)) {
      include_once('convert_image_to_webp.php');
      convert_to_webp($LookInThisFolder.$UseThisPicture,50); //folder and file, compression
    }
    echo "\n".'<source type="image/webp" media="(min-width: 1024px)" srcset="'.str_replace(' ','%20',$new_webp).'">'; //big
    echo "\n".'<source type="image/webp" media="(min-width: 1px)" srcset="'.str_replace(' ','%20',$new_small_webp).'">'; //to little
    echo "\n".'<img src="/'.$LookInThisFolder.$UseThisPicture.'" alt="" style="width:100%; margin:0px 0px 0px 0px;'.$grayscale.'">'; //setting alt="" is ok for decorative photos //to unsupported webp by browser
    echo "\n".'</picture>';
    ////////////////////////////////////////////////////////////////////////////////////
    $iPhoneTextImage=$LookInThisFolder.$UseThisPicture;
  }
}
?>