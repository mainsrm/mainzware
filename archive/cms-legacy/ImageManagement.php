<?php
session_start();//Start a session to remember things
include('wwwsiteheader.php');
$SiteFolderName=str_ireplace('.com','',$SiteURL);
if(isset($_SESSION[$SessionStuffLoggedIn])){
  //ok
} else {
  echo '<p style="padding:2em;">login to manage website images</p>';
  include('wwwsitefooter.php');
  exit;
}
if(isset($_REQUEST['ThisFolder'])){ $ThisFolder=$_REQUEST['ThisFolder']; } else { $ThisFolder=''; }
$sub_directory_name='';
$LookInThisFolder='images/';
$ShowThisHTML='';
echo '
<style>
.thumbnail {
 width:300px;
 margin:0px;
}
p {
 margin:0px;
 padding:1em;
}
p:nth-child(odd) {
 background: #efefef;
 color:#000;
}
p:nth-child(even) {
 background: #dedede;
 color:#000;
}
.box_icon {
 width: 100px;
 border: 2px solid #111;
 text-align: center;
 padding-top: 30px;
 padding-bottom: 30px;
 margin: 20px;
 border-radius: 25%;
 background-color: inherit;
 display: block;
 background-color: #fff;
 color: #000;
}
.download {
 background-color:#03053d;
 color:#efefef;
 padding:.2em;
}
.download:hover{
 text-decoration:none;
 color:#fff;
 background-color:#026307;
 padding:.2em;
}
.rename {
 background-color: inherit;
 display: block;
}
.rename_successful {
 border: 2px solid #000;
 text-align: center;
 padding: 2em;
 margin: 20px;
 border-radius: 20px;
 background-color: #494ee3;
 color: #fff;
 font-size: x-large;
 display: block;
}
.delete_successful, .archive_successful {
 border: 2px solid #000;
 text-align: center;
 padding: 2em;
 margin: 20px;
 border-radius: 20px;
 background-color: #f7981b;
 color: #fff;
 font-size: x-large;
 display: block;
}
.archive_not_successful {
 border: 2px solid #000;
 text-align: center;
 padding: 2em;
 margin: 20px;
 border-radius: 20px;
 background-color: #780901;
 color: #fff;
 font-size: x-large;
 display: block;
}
.hide {
 display: none;
}
.videoWrapper {
	 position: relative;
	 padding-bottom: 47%;
	 padding-top: 25px;
	 height: 0;
	 margin: 3px 0px 60px 0px;
}
.videoWrapper iframe {
	 position: absolute;
	 top: 0;
	 left: 0;
	 width: 100%;
	 height: 100%;
}
.videotitle {
	 font-size: 1.05em;
	 color: #00465c;
	 line-height: 1.05em;
	 margin: 10px 0px 4px 0px;
}
.outside_wrapper {
  width: 90%;
  margin-left:5%;
  margin-right:5%;
}
video::cue {
  /* background-image: linear-gradient(to bottom, dimgray, lightgray); */
  /* background-color: #000; */
  color: #fff;
}
video::cue(b) {
  color: #00f;
}
</style>
';
echo "\n".'<div style="padding:2em;">';

echo "\n".'<h1>Image Management</h1>';
echo "\n".'<p>Select a folder to upload images that will automatically be displayed at the bottom of the respective page.</p>';
echo "\n".'<p>Upload <b>jpg</b> images saved as <b>.jpg</b> (.jpeg files will get renamed to .jpg); not png, gif, or HEIC\'s (which are used on iPhones - unfornately the web does NOT support these yet).</p>';
echo "\n".'<p><b>TopImages</b> show at the top of each page. You will want to make them all the same proportions for consistency between page loads. An image will automatically be selected from the TopImages folder for most pages. The system automatically creates a webp (smaller file size) in 2 sizes for quicker image display.</p>';
echo "\n".'<p>The <b>Manually_Selected</b> folder is used in conjunction with the Login/Manage Content page. Images uploaded to the Manually_Selected folder will show up as a selectable option and will be displayed below the title of an article.</p>';

$LookingForFolders='';
$AllowMoreDeleting='';
$file_counter=0;
echo "\n".'<form name="AnotherGreatForm" action="'.$_SERVER["PHP_SELF"].'?ThisFolder='.$ThisFolder.'" METHOD="post" ENCTYPE="multipart/form-data">'."\n";
if($ThisFolder==''){
  //foreach ($_REQUEST as $key => $value) {
  //  $$key=$value;
  //}
  //if(isset($_REQUEST['password'])){ $password=$_REQUEST['password']; } else { $password=''; }
  //if($password==''){
  //  echo "\n".'<label for="password" style="display:none;">Password</label>';
  //  echo "\n".'<input type="password" name="password" id="password" value="" placeholder="Enter password">'."\n";
  //  echo "\n".'<BR><BR><input type="submit" name="submit" value="submit">'."\n";
  //}
  //
  //if($password=='AAAAbbbb'){
    $ShowThisHTML.="\n".'<h1>Folders</h1>';
    if($handle = opendir($LookInThisFolder)) {
      while (false !== ($entry = readdir($handle))) {
        if(substr($entry,0,1) != "." and $entry<>'index.php') {
          $dirFiles[] = $entry; //put each folder into an array
        }
      }
      closedir($handle);
    }
    sort($dirFiles);
    foreach($dirFiles as $file){
      $ShowThisHTML.="\n".'<p>'.'<a href="'.$_SERVER['PHP_SELF'].'?ThisFolder='.$file.'">'.$file.'</a></p>';
    }
  //} else if($password<>''){
  //  if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
  //    $ip = $_SERVER['HTTP_CLIENT_IP'];
  //  } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
  //    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
  //  } else {
  //    $ip = $_SERVER['REMOTE_ADDR'];
  //  }
  //  $to      = 'kirkhopkins0057@gmail.com';
  //  $subject = 'ImageManagement incorrect password';
  //  $message = 'Password entered: "'.$password.'" from '.$ip;
  //  $from    = 'kirkhopkins0057@gmail.com';
  //
  //  mail($to,$subject,$message,'From: '.$from);
  //  echo '<h1>System administrators have been notified of a break-in attempt</h1>';
  //}
} else {
  $ShowThisHTML.="\n".'<h1>'.$ThisFolder.' (folder)</h1>';
  
  if(!file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles')){
    mkdir($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles', 0777, true);
  }
  if(!file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles/images')){
    mkdir($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles/images', 0777, true);
  }
  if(!file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles/images/'.$ThisFolder)){
    mkdir($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/ArchivedFiles/images/'.$ThisFolder, 0777, true);
  }
  
  if(isset($delete_file)){
    //echo 'delete '.$_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.$delete_file.'<br>';
    unlink($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.$delete_file);
    echo '<div class="delete_successful">file deleted</div>';
    $AllowMoreDeleting='yup';
  }
  if(isset($archive_file)){
    //echo 'archive<br>'.$_SERVER['DOCUMENT_ROOT'].'/'.$archive_file.'<br>by moving it to<br>'.$_SERVER['DOCUMENT_ROOT'].'/ArchivedFiles/'.$archive_file.'<br>';
    if(file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.$archive_file)){
      rename($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.$archive_file,$_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.'ArchivedFiles/'.$archive_file);
      if(file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.str_replace('.jpg','.webp',$archive_file))){
        unlink($_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/'.str_replace('.jpg','.webp',$archive_file));
      }
      echo '<div class="archive_successful">file archived</div>';
    } else {
      echo '<div class="archive_not_successful">file may have been previously archived</div>';
    }
  }
  
  //Begin file upload (minimal renaming)
  $target_dir=$_SERVER['DOCUMENT_ROOT'].'/'.$SiteFolderName.'/images/'.$ThisFolder.'/';
  //echo '$target_dir='.$target_dir.'<br>';
  $_FILES[]=''; //added 12-12-2022
  if(isset($_FILES["fileToUpload"]["name"])){
    $filename=basename($_FILES["fileToUpload"]["name"]);
    $target_file=$target_dir.str_ireplace('.jpeg','.jpg',$filename);
    //echo '$target_dir='.$target_dir.'<br>';
    $uploadOk='yes';
    $theFileType=strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
    $Problem='';
    $Success='';
    if(isset($_POST['upload_file'])){
      // Check if file already exists
      if(file_exists($target_file)){
        $Problem.='that file has already been uploaded<br>';
      }
      // Check file size
      if($_FILES["fileToUpload"]["size"] > 500000000){
        $Problem.='your file is too large<br>';
      }
      if($_FILES["fileToUpload"]["size"] <1){
        $Problem.='no file was selected<br>';
      }
      // Allow certain file formats
      //if($theFileType<>'jpg'){
      //  $Problem.='<p>Only JPG files are allowed</p>';
      //}
  
      //echo '<br>';
      //echo 'tmp_name='.$_FILES["fileToUpload"]["tmp_name"].'<br>';
      //echo 'target_file='.$target_file.'<br>';
      
      // Check if $uploadOk is set to 0 by an error
      if($Problem<>''){
        $MainMessage='Oops, ';
      } else {
        //create a folder to store this file in
        if(!file_exists($target_dir)){
          mkdir($target_dir, 0777, true);
        }
        if(move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)){
          $Success.='The file '.str_ireplace('.jpeg','.jpg',basename($_FILES['fileToUpload']['name'])).' has been uploaded<br>';
        } else {
          $Problem.='There was an error uploading your file';
        }
      }
    }
    if($Problem<>''){
      echo "\n".'<div style="background-color:#850920;color:#fff;padding:2em;">'.$MainMessage.$Problem.'</div>';
    }
    if($Success<>''){
      echo "\n".'<div style="background-color:#106906;color:#fff;padding:2em;">'.$Success.'</div>';
    }
  }
  echo "\n".'<input type="hidden" id="ThisFolder" name="ThisFolder" value="'.$ThisFolder.'">';
  //echo "\n".'<h2>Upload a jpg file to this folder</h2>';
  //echo "\n".'<p>iOS devices automatically compress (reduce file size and quality of) photos and video with no way to get around it when uploading to a web page.  If you want full quality, upload these items from another device like a laptop. You can transfer files from device to device using AirDrop, which does not compress these files, but both Apple devices need to be in close proximity to each other.</p>';
  echo "\n".'<p>';
  echo "\n".'<label for="fileToUpload" style="display:none;">Upload A File</label>';
  echo "\n".'<input type="file" name="fileToUpload" id="fileToUpload" title="Select your file to upload" accept="image/jpeg">';
  //echo "\n".'<input type="file" name="fileToUpload" id="fileToUpload" title="Select your file to upload" accept="image/png, image/gif, image/jpeg">';
  echo "\n".'</p>';
  echo "\n".'<p>';
  echo "\n".'<input type="submit" name="upload_file" value="upload file" title="Upload now">';
  echo "\n".'</p>';
  //End file upload
  
  $FoundOne='';
  if($handle = opendir($LookInThisFolder.$ThisFolder)) {
    while (false !== ($entry = readdir($handle))) {
      if(substr($entry,0,1) != "." and $entry<>'index.php') {
        $dirFiles[] = $entry; //put each folder into an array
        $FoundOne='yup';
      }
    }
    closedir($handle);
  }
  if($FoundOne<>''){
    sort($dirFiles);
    foreach($dirFiles as $file){
      $file_counter++;
      $filemtime=@filemtime($LookInThisFolder.$ThisFolder.'/'.$file);
      if(isset($_POST['rename_file'])){
        //echo '<br>rename a file button pressed';
        $check_this='rename_'.$filemtime;
        //$$check_this=$check_this;
        if(isset($$check_this) and $$check_this<>''){
          //echo ' - '.$check_this.' from '.$file.' to '.$$check_this;
          $fileBitsOldName = preg_split("/\./",$file);
          if(sizeof($fileBitsOldName)==2){
            $filebaseOldName = $fileBitsOldName[0];
            $fileextOldName  = strtolower($fileBitsOldName[1]);
          }
          //echo '$filebaseOldName='.$filebaseOldName.'<br>';
          //echo '$fileextOldName='.$fileextOldName.'<br>';
          $fileBitsNewName = preg_split("/\./",$$check_this);
          if(sizeof($fileBitsNewName)==2){
            $filebaseNewName = $fileBitsNewName[0];
          } else {
            $filebaseNewName = $fileBitsNewName[0];
          }
          $fileextNewName  = $fileextOldName;
          if($fileextNewName=='jpeg'){
            $fileextNewName='jpg';
          }
          if($fileextNewName==''){
            $$check_this.='.'.$fileextOldName;
          }
          //
          rename($LookInThisFolder.$ThisFolder.'/'.$file,$LookInThisFolder.$ThisFolder.'/'.$filebaseNewName.'.'.$fileextNewName);
          //delete the webp
          if(file_exists($LookInThisFolder.$ThisFolder.'/'.$filebaseOldName.'.webp')){
            unlink($LookInThisFolder.$ThisFolder.'/'.$filebaseOldName.'.webp');
          }
          $ShowThisHTML.="\n".'<span class="rename_successful">Renamed '.$file.' to '.$filebaseNewName.'.'.$fileextNewName.'</span>';
          $file=$filebaseNewName.'.'.$fileextNewName;
        }
      }
      if(file_exists($LookInThisFolder.$ThisFolder.'/'.$file)){
        $file_size=number_format((filesize($LookInThisFolder.$ThisFolder.'/'.$file))/1000000,2);
      } else {
        $file_size=0;
      }
      $just_the_name=$file;
      $just_the_name=str_replace('images/','',$just_the_name);
      $just_the_name=str_replace($sub_directory_name.'/','',$just_the_name);
      //
      $fileBits = preg_split("/\./",$file);
      if(sizeof($fileBits)==2){
        $filebase = $fileBits[0];
        $fileext  = strtolower($fileBits[1]);
      } else {
        $fileext = "Next";
      }
      //echo '$filebase='.$filebase.'<br>';
      //echo '$fileext='.$fileext.'<br>';
      //
      if($fileext=='jpg'
         or $fileext=='jpeg'
         or $fileext=='png'
         or $fileext=='gif'
        ){
        $display_this='<br><img src="'.$LookInThisFolder.$ThisFolder.'/'.$file.'?'.$filemtime.'" alt="'.str_replace('_',' ',$just_the_name).'" class="thumbnail">';
      } else if($fileext=='mp3'){
        $display_this='
        <br>
        <audio controls>
          <source src="'.$LookInThisFolder.$ThisFolder.'/'.$file.'" type="audio/mpeg">
          Your browser does not support the audio element.
        </audio>
        ';
      } else if($fileext=='ogg'){
        $display_this='
        <br>
        <audio controls>
          <source src="'.$LookInThisFolder.$ThisFolder.'/'.$file.'" type="audio/ogg">
          Your browser does not support the audio element.
        </audio>
        ';
      } else if($fileext=='m4v'){
        $display_this='
        <br>
        <video width="320" height="240" controls>
          <source src="'.$LookInThisFolder.$ThisFolder.'/'.$file.'" type="video/mp4">
          Your browser does not support the video tag.
        </video>
        ';
      } else if($fileext=='webp'){
        //skip this
      } else {
        $display_this='
        <br><span class="box_icon">'.$fileext.'</span>
        ';
      }
      if($fileext=='jpg'
         or $fileext=='jpeg'
         or $fileext=='png'
         or $fileext=='gif'
        ){
        $ShowThisHTML.="\n".'<p>'.$just_the_name.' <a href="'.$LookInThisFolder.$ThisFolder.'/'.$file.'" download="'.$just_the_name.'"><span class="download">download ('.$file_size.'MB)</a>';
        //echo '$LookInThisFolder='.$LookInThisFolder.'<br>';
        if($filemtime<>''){
          $ShowThisHTML.='<span class="rename">
                          <label for="rename_'.$filemtime.'" title="rename" class="hide">rename '.$filemtime.'</label>
                          <br><input type="text" name="rename_'.$filemtime.'" id="rename_'.$filemtime.'" value="" title="enter a new file name with or without the file extension" placeholder="new file name">
                          <input type="submit" name="rename_file" value="rename file" title="rename this file">
                          ';
                          if(stristr($file,'delete') or $AllowMoreDeleting=='yup'){
                            $ShowThisHTML.='<a href="'.$_SERVER['PHP_SELF'].'?ThisFolder='.$ThisFolder.'&delete_file='.$LookInThisFolder.$ThisFolder.'/'.$file.'">delete this file</a>';
                          }
                          $ShowThisHTML.='<a href="'.$_SERVER['PHP_SELF'].'?ThisFolder='.$ThisFolder.'&archive_file='.$LookInThisFolder.$ThisFolder.'/'.$file.'" onclick="return confirm(\'Are you sure?\')">archive this file</a>';
                          $ShowThisHTML.='
                          </span>
                         ';
        }
        $ShowThisHTML.=$display_this.'</span></p>';
      }
    }
  }
  $ShowThisHTML.="\n".'<p style="margin-top:4em;"><a href="'.$_SERVER['PHP_SELF'].'?ThisFolder='.$ThisFolder.'">reload this page</a></p>';  
  $ShowThisHTML.="\n".'<p><a href="'.$_SERVER['PHP_SELF'].'">back to folders</a></p>';  
}
if($ShowThisHTML=='' and $LookingForFolders=='yup'){
  echo "\n".'<h1>Error: no files found</h1>';
} else {
  echo $ShowThisHTML;
}
echo "\n".'</div>';
echo "\n".'</form>';
include('wwwsitefooter.php');
?>