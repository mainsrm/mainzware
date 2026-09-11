<?php
//KirksPictureGrabber.php
//Randomly grab an image for $LookInThisFolder
//echo 'PictureGrabber_$LookForAnImageNameThatContainsThis='.$LookForAnImageNameThatContainsThis.'<br>';
$UseThisPicture='';
$dir = opendir($LookInThisFolder);
srand((double)microtime()*1000000);
$adcnt=0;
if(isset($_REQUEST['LookForThis'])){ $LookForThis=$_REQUEST['LookForThis']; } else { $LookForThis=''; }
while ($file = readdir($dir)) {
  $fileBits = preg_split("/\./",$file);
  if (sizeof($fileBits) == 2) {
    $filebase = $fileBits[0];
    $fileext  = strtoupper($fileBits[1]);
  } else {
    $fileext = "Next";
  }
  if(strtoupper($fileext) == "GIF" or strtoupper($fileext) == "JPG" or strtoupper($fileext) == "PNG") {
    //echo '$file='.$file.'<br>';
    if($LookForThis<>''){
      if(stristr($file,$LookForThis)){
        $adcnt=$adcnt+1;
        $ads[$adcnt]=$file; //only get these
      }
    } else if($LookForThis==''){
      $adcnt=$adcnt+1;
      $ads[$adcnt]=$file;
    }
  }
}
if($ads[$adcnt]<>''){
  $UseThisPicture=$ads[rand(1,$adcnt)];
}
//echo '$UseThisPicture='.$UseThisPicture.'<br>';
?>