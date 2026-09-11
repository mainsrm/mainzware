<?php
function convert_to_webp($file,$compression_quality){
  // check if file exists
  if(!file_exists($file)){
      return false;
  }
  $file_type = exif_imagetype($file);
  //https://www.php.net/manual/en/function.exif-imagetype.php
  //exif_imagetype($file);
  // 1    IMAGETYPE_GIF
  // 2    IMAGETYPE_JPEG
  // 3    IMAGETYPE_PNG
  // 6    IMAGETYPE_BMP
  // 15   IMAGETYPE_WBMP
  // 16   IMAGETYPE_XBM
  $new_name=$file;
  $new_name=str_ireplace('.jpg','.webp',$new_name);
  $new_name=str_ireplace('.jpeg','.webp',$new_name);
  $new_name=str_ireplace('.gif','.webp',$new_name);
  $new_name=str_ireplace('.png','.webp',$new_name);
  $new_name=str_ireplace('.bmp','.webp',$new_name);
  $new_name=str_ireplace('.wbmp','.webp',$new_name);
  $new_name=str_ireplace('.xbm','.webp',$new_name);
  //echo '$new_name='.$new_name.'<br>';
  //$output_file =  $file . '.webp';
  $output_file=$new_name;
  if(file_exists($output_file)){
    return $output_file;
  }
  if(function_exists('imagewebp')){
    switch($file_type){
      case '1': //IMAGETYPE_GIF
          $image = imagecreatefromgif($file);
          break;
      case '2': //IMAGETYPE_JPEG
          $image = imagecreatefromjpeg($file);
          break;
      case '3': //IMAGETYPE_PNG
              $image = imagecreatefrompng($file);
              imagepalettetotruecolor($image);
              imagealphablending($image, true);
              imagesavealpha($image, true);
              break;
      case '6': // IMAGETYPE_BMP
          $image = imagecreatefrombmp($file);
          break;
      case '15': //IMAGETYPE_Webp
         return false;
          break;
      case '16': //IMAGETYPE_XBM
          $image = imagecreatefromxbm($file);
          break;
      default:
      return false;
    }
    // Save the image
    $result = imagewebp($image, $output_file, $compression_quality);
    if(false === $result){
        return false;
    }
    // Free up memory
    imagedestroy($image);
    return $output_file;
  } elseif(class_exists('Imagick')){
    $image = new Imagick();
    $image->readImage($file);
    if($file_type === "3"){
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($compression_quality);
        $image->setOption('webp:lossless', 'true');
    }
    $image->writeImage($output_file);
    return $output_file;
  }
  return false;
}
?>