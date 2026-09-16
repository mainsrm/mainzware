<?php
function kirks_image_resizer($source_image_path, $Smaller_image_path, $Smaller_image_width){
  //Read the exif(i.e. meta data)to see if we need to rotate the image
  //echo 'In resize_image function, use: '.$source_image_path."<br>\n";
  //echo 'to create: <b>'.$Smaller_image_path."</b><br>\n";
  $exif = exif_read_data($source_image_path, 0, true);
  //echo $source_image_path.":<br />\n";
  $rotate_amount='0';
  foreach($exif as $key => $section){
    foreach($section as $name => $val){
      //echo "<hr>"."$key.$name: $val<br />\n";
      if($name=='Orientation'){
        if($val=='3'){
          $rotate_amount='180';
        } else if($val=='6'){
          $rotate_amount='-90';
        } else if($val=='8'){
          $rotate_amount='90';
        }
      }
    }
  }
  //Rotate and resize it
  list($source_image_width, $source_image_height, $source_image_type)= getimagesize($source_image_path);
  switch($source_image_type){
    case IMAGETYPE_GIF:
      $temp_source_gd_image = imagecreatefromgif($source_image_path);
      //$source_gd_image = imagerotate($temp_source_gd_image, $rotate_amount, 0);
      break;
    case IMAGETYPE_JPEG:
      $temp_source_gd_image = imagecreatefromjpeg($source_image_path);
      //$source_gd_image = imagerotate($temp_source_gd_image, $rotate_amount, 0);
      break;
    case IMAGETYPE_PNG:
      $temp_source_gd_image = imagecreatefrompng($source_image_path);
      //$source_gd_image = imagerotate($temp_source_gd_image, $rotate_amount, 0);
      break;
  }
  $source_gd_image = $temp_source_gd_image;
  if($source_gd_image === false){
    return false;
  }
  //Make it "$Smaller_image_width"px wide
  $resize_calculation=$source_image_width/$Smaller_image_width;
  if($rotate_amount=='90' or $rotate_amount=='-90'){
    //portrait
    $Small_image_height=$Smaller_image_width*$source_image_width/$source_image_height;
    $new_source_image_width=$source_image_height;
    $new_source_image_height=$source_image_width;
  } else {
    //landscape
    $Small_image_height=$source_image_height/$resize_calculation;
    $new_source_image_width=$source_image_width;
    $new_source_image_height=$source_image_height;
  }
  //echo '<hr>$source_image_width='.$source_image_width.'<br>';
  //echo '<hr>$source_image_height='.$source_image_height.'<br>';
  //echo '<hr>$new_source_image_width='.$new_source_image_width.'<br>';
  //echo '<hr>$new_source_image_height='.$new_source_image_height.'<br>';
  //echo '<hr>$Smaller_image_width='.$Smaller_image_width.'<br>';
  //echo '<hr>$Small_image_height='.$Small_image_height.'<br>';
  //echo '<hr>$Small_aspect_ratio='.$Small_aspect_ratio.'<br>';
  $Small_gd_image = imagecreatetruecolor($Smaller_image_width, $Small_image_height);
  $source_gd_image = imagerotate($source_gd_image, $rotate_amount, 0);
  imagecopyresampled($Small_gd_image, $source_gd_image, 0, 0, 0, 0, $Smaller_image_width, $Small_image_height, $new_source_image_width, $new_source_image_height);
  imagejpeg($Small_gd_image, $Smaller_image_path, 90);
  imagedestroy($source_gd_image);
  imagedestroy($Small_gd_image);
  return true;
}
?>