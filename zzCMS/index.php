<?php
session_start();//Start a session to remember things
include('wwwsiteheader.php');
echo '<section>';
//echo '$page='.$page.'<br>';
if(!isset($page)){
  $page='';
}
if($page=='' or $page=='Home'){
  echo '<h1>'.$PrettySiteTitle.'</h1>';
  $page='Home';
} else {
  echo '<h1>'.str_replace('_',' ',$page).'</h1>';
}
$look_for="info_page = '".$page."'";
$sql="select *
        from ".$This_CMS_Table."
       where info_removed_on is NULL
    order by info_type asc, info_page asc, info_title asc
     ";
//RunAndShowIt($database_connection,$sql);
for($current_or_past_loop=1;$current_or_past_loop<=2;$current_or_past_loop++) {
  if($current_or_past_loop==1){
    //show it as current for up to 8 hours
    $Criteria="(
                (info_time >= (now() - interval '8 hours'))
                or info_time is NULL
               )";
    $OrderBy='info_time asc, info_type asc, info_page asc, info_title asc';
  } else {
    $Criteria="info_time < '".$Now."' and info_time is NOT NULL";
    $OrderBy='info_time desc, info_type asc, info_page asc, info_title asc';
  }
  $sql="select *
          from ".$This_CMS_Table."
         where ".$look_for."
               and info_removed_on is NULL
               and ".$Criteria."
      order by ".$OrderBy."
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $show_this='';
  $calendar_info='';
  if(pg_num_rows($gotdata)>0){
    if($current_or_past_loop==1){
      //skip this
    } else {
      echo "\n".'<details class="past_events"><summary>Past (Dated) Events</summary>';
    }
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $info_id=$getarow['info_id'];
      $info_type=$getarow['info_type'];
      $info_page=$getarow['info_page'];
      $info_title=$getarow['info_title'];
      $info_image=$getarow['info_image'];
      $info_location=$getarow['info_location'];
      $info_details=$getarow['info_details'];
      $info_time=$getarow['info_time'];
      $info_how_many_hours=$getarow['info_how_many_hours'];
      $info_name=$getarow['info_name'];
      $info_phone=$getarow['info_phone'];
      $info_email_link=$getarow['info_email_link'];
      $info_webpage_link=$getarow['info_webpage_link'];
      $info_webpage_link_title=$getarow['info_webpage_link_title'];
      $info_added_on=$getarow['info_added_on'];
      $info_added_by=$getarow['info_added_by'];
      $info_removed_on=$getarow['info_removed_on'];
      $info_removed_by=$getarow['info_removed_by'];
            
      $add_to_calendar='';
      echo "\n".'<article>';

      if($info_title<>''){
        echo "\n".'<h2>'.$info_title.'</h2>';
      }
      if($info_image<>''){
        //echo '$info_image='.$info_image.'<br>';
        $UseThisPicture=$info_image;
        $LookInThisSmallFolder='images-Small/SmallManually_Selected/';
        $LookInThisFolder='images/Manually_Selected/';
        if(!file_exists($LookInThisSmallFolder)){
          mkdir($LookInThisSmallFolder, 0777, true);
        }
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
      if($info_location<>''){
        echo "\n".'<div class="show_location"><pre>'.$info_location.'</pre></div>';
      }
      if(pg_num_rows($gotdata)>3){
        //there are more than 3 articles on the page, hide some of the text for long articles
        if($info_details<>''){
          echo "\n".'<div><pre>';
          $show_around_this_many_characters=1000;
          if(strlen($info_details)>$show_around_this_many_characters){
            $beginning_of_the_next_word=strpos($info_details,"\n",$show_around_this_many_characters); //keep the last word in tact
            //echo $beginning_of_the_next_word;
            echo substr($info_details,0,$beginning_of_the_next_word).'...';
            echo '<details aria-label="read about: '.$info_title.'"><summary>...<span style="display:inline;text-decoration: underline;">keep reading</span>...</summary>...'.substr($info_details,$beginning_of_the_next_word).'</details>';
          } else {
            echo $info_details;
          }
          
          echo '</pre></div>';
        }
      } else {
        //only up to 3 articles on the page, show the whole article for all of them
        echo "\n".'<div><pre>'.$info_details.'</pre></div>';
      }
      //figure out if this has passed already or not
      $this_has_passed='';
      if($info_time<>'' and $info_how_many_hours==''){
        $converted_to_time=strtotime($info_time);
        $Ymd_of_event_with_no_duration=date('Ymd',$converted_to_time);
        if($TodayYmd>$Ymd_of_event_with_no_duration){
          $this_has_passed='yup';
        }
      } else if($info_time<>'' and $info_how_many_hours<>''){
        $converted_to_time=strtotime($info_time);
        $converted_end_time=mktime(date('H',$converted_to_time),(date('i',$converted_to_time)+($info_how_many_hours*60)),date('s',$converted_to_time),date('m',$converted_to_time),date('d',$converted_to_time),date('Y',$converted_to_time));
        $converted_end_to_date=date('Ymd',$converted_end_time);
        if($TodayYmd>$converted_end_to_date){
          $this_has_passed='yup';
        }
        //echo '$converted_end_time='.$converted_end_time.'<br>';
      }
      if($this_has_passed==''){
        if($info_time<>''){
          $changed_to_time=strtotime($info_time);
          $start_day_of_the_week=date('l',$changed_to_time).', ';
          $start_date=date('F jS, Y',$changed_to_time);
          $start_hours=date('g',$changed_to_time);
          $start_info_for_countdown=date('M, j Y G:i:s',$changed_to_time);
          $start_info_for_starting_the_countdown=date('Ymd',$changed_to_time);
          if(date('i',$changed_to_time)=='00'){
            $start_minutes='';
          } else {
            $start_minutes=':'.date('i',$changed_to_time);
          }
          $start_am_or_pm=date('a',$changed_to_time);
          if($start_hours.$start_minutes.$start_am_or_pm=='12am'
             and trim($info_how_many_hours)==''
            ){
            echo "\n".'<div style="margin-top:1em;">'.$start_day_of_the_week.$start_date.'<br>(this information will stop being displayed after the date shown above)';
          } else {
            echo "\n".'<div style="margin-top:1em;">'.$start_day_of_the_week.$start_date.' '.$start_hours.$start_minutes.$start_am_or_pm;
          }
          if($info_how_many_hours<>''){          
            $end_time=mktime(date('H',$changed_to_time),(date('i',$changed_to_time)+($info_how_many_hours*60)),date('s',$changed_to_time),date('m',$changed_to_time),date('d',$changed_to_time),date('Y',$changed_to_time));
            if(date('F jS, Y',$end_time)==$start_date){
              $end_date=' - ';
              $end_day_of_the_week='';
              $at_or_not='';
            } else {
              $end_date=date('F jS, Y',$end_time);
              $end_day_of_the_week=' through '.date('l',$end_time).', ';
              $prayer_vigil_date_to_use=date('Ymd',$end_time);
              $at_or_not=' at ';
            }
            $end_hours=$at_or_not.date('g',$end_time);
            if(date('i',$end_time)=='00'){
              $end_minutes='';
            } else {
              $end_minutes=':'.date('i',$end_time);
            }
            $end_am_or_pm=date('a',$end_time);
            $show_this_end_time=$end_day_of_the_week.$end_date.$end_hours.$end_minutes.$end_am_or_pm;
            echo $show_this_end_time;
            //
            $dtstart=date('Ymd',$changed_to_time).'T'.date('His',$changed_to_time);
            $dtend=date('Ymd',$end_time).'T'.date('His',$end_time);
            //
            $location_for_calendar=$info_location;
            $location_for_calendar=str_replace("
",' ',$location_for_calendar); //replace line feeds with spaces
            $location_for_calendar=str_replace("\n",' ',$location_for_calendar);
            $location_for_calendar=str_replace(".",'',$location_for_calendar);
            $location_for_calendar=str_replace(",",'',$location_for_calendar);
            $location_for_calendar=substr($location_for_calendar,strcspn($location_for_calendar,'0123456789'));//start at the first number
            $calendar_info='';
            $calendar_info.='BEGIN:VCALENDAR';
            $calendar_info.="\r\n".'VERSION:2.0';
            $calendar_info.="\r\n".'PRODID:'.$AddToCalendarPRODID;
            $calendar_info.="\r\n".'BEGIN:VEVENT';
            $calendar_info.="\r\n".'UID:'.$AddToCalendarPRODID.'-'.$info_id;
            $calendar_info.="\r\n".'SUMMARY:'.$AddToCalendarEventTitleGroup.' - '.$info_title;
            $calendar_info.="\r\n".'DTSTAMP:'.$dtstart;
            $calendar_info.="\r\n".'DTSTART;TZID='.$AddToCalendarTimeZone.':'.$dtstart;
            $calendar_info.="\r\n".'DTEND;TZID='.$AddToCalendarTimeZone.':'.$dtend;
            $calendar_info.="\r\n".'LOCATION:'.$location_for_calendar;
            $calendar_info.="\r\n".'SEQUENCE:0';
            $calendar_info.="\r\n".'TRANSP:OPAQUE';
            $calendar_info.="\r\n".'CLASS:PUBLIC';
            $calendar_info.="\r\n".'END:VEVENT';
            $calendar_info.="\r\n".'END:VCALENDAR';
            $add_to_calendar_folder='add_to_calendar/';
            $Save_As=$add_to_calendar_folder.$AddToCalendarPRODID.'-'.$info_id.'.ics';
            //echo '$Save_As='.$Save_As.'<br>';
            $file = fopen($Save_As,"w");
            $WriteThis=$calendar_info; //videos/ = end of URL
            fwrite($file,$WriteThis);
            fclose($file);
            $add_to_calendar='<a href="'.$Save_As.'" aria-label="add '.str_replace('"',"'",$info_title).' to your calendar">Add to Calendar</a>';
          }
          $now = time(); // or your date as well
          $your_date = strtotime($start_info_for_starting_the_countdown);
          $datediff = $your_date - $now;
          $rounded_date_difference = round($datediff / (60 * 60 * 24));
          $font_size='1';
          if($rounded_date_difference==7){
            $font_size='1.1';
          } else if($rounded_date_difference==6){
            $font_size='1.2';
          } else if($rounded_date_difference==5){
            $font_size='1.3';
          } else if($rounded_date_difference==4){
            $font_size='1.4';
          } else if($rounded_date_difference==3){
            $font_size='1.5';
          } else if($rounded_date_difference==2){
            $font_size='1.6';
          } else if($rounded_date_difference==1){
            $font_size='1.7';
          } else if($rounded_date_difference==0){
            $font_size='2';
          }
          if($rounded_date_difference<14){
            echo "\n".'
            <div id="countdown_'.$info_id.'" style="font-size:'.$font_size.'em;"></div>
  
            <script>
            // Set the date we are counting down to
            var countDownDate_'.$info_id.' = new Date("'.$start_info_for_countdown.'").getTime();
            
            // Update the count down every 1 second
            var x = setInterval(function() {
            
              // Get today\'s date and time
              var now = new Date().getTime();
                
              // Find the distance between now and the count down date
              var distance = countDownDate_'.$info_id.' - now;
                
              // Time calculations for days, hours, minutes and seconds
              var days = Math.floor(distance / (1000 * 60 * 60 * 24));
              var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
              var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
              var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                
              // Output the result in an element with id="demo"
              document.getElementById("countdown_'.$info_id.'").innerHTML = "Countdown: " + days + " days " + hours + " hours "
              + minutes + " minutes " + seconds + " seconds ";
                
              // If the count down is over, write some text 
              if (distance < 0) {
                clearInterval(x);
                document.getElementById("countdown_'.$info_id.'").innerHTML = "This information will be displayed here for a short while longer";
              }
            }, 1000);
            </script>
            ';
            //end date and/or time
          }
          echo "\n".'</div>';
          echo "\n".'</p>';
        }
        echo "\n".'<ul>';
        if($info_name<>''){
          echo "\n".'<li>Contact: '.$info_name;
        }
        if($info_phone<>''){
          echo "\n".'<li>Phone: '.$info_phone;
        }
        if($info_email_link<>''){
          echo "\n".'<li>email: <a href="mailto:'.$info_email_link.'">'.$info_email_link.'</a>';
        }
        if($info_webpage_link<>''){
          $check_for_youtube=str_replace('.','',$info_webpage_link);
          if(stristr($check_for_youtube,'youtube') and stristr($check_for_youtube,'embed')){
            echo "\n".'<div class="videoWrapper">'.$info_webpage_link.'</div>';
          } else if(stristr($check_for_youtube,'youtube')){
            //just the url was found
            echo "\n".'<div class="videoWrapper"><iframe width="699" height="418" src="'.$info_webpage_link.'" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe></div>';
          } else {
            if(stristr($info_webpage_link,$SiteURL)){
              $target='';
            } else {
              $target=' target="_blank"';
            }
            echo "\n".'<li>More Info: <a href="'.$info_webpage_link.'"'.$target.'>'.$info_webpage_link_title.'</a>';
          }
        }
        if($this_has_passed=='' and $info_time<>'' and $info_how_many_hours<>''){
          echo "\n".'<li>'.$add_to_calendar;    
        }
        echo "\n".'</ul>';
        echo "\n".'</article>';
      }
      //echo 'Session stuff - Logged In: '.$_SESSION[$SessionStuffLoggedI].'<br>';
      if(isset($_SESSION[$SessionStuffLoggedIn])){
        //let administrators edit or remove this article
        echo "\n".'<p>(updated on '.$info_added_on.' by '.$info_added_by.')</p>';
        echo "\n".'<p class="edit_this_information"><a href="login.php?edit='.$info_id.'" title="edit '.$info_title.'">edit this information</a></p>';
        echo "\n".'<p class="remove_this_information"><a href="login.php?remove='.$info_id.'" title="remove '.$info_title.'" onclick="AreYouSure()">remove this information</a></p>';
      }
    }
    if($current_or_past_loop==1){
      //skip this
    } else {
      echo "\n".'</details>';
    }
  }
}
////////////////////////////////////////////////////////////////////////////////////
//Automatically grab images from a respective folder for this specific page
if(is_dir('images/'.$page)){
  $dir = opendir('images/'.$page);
  $LookInThisFolder='images/'.$page.'/';
  $LookInThisSmallFolder='images-Small/'.$page.'/';
  while ($file = readdir($dir)) {
    if(!file_exists($LookInThisSmallFolder)){
      mkdir($LookInThisSmallFolder, 0777, true);
    }
    if(stristr($file,'.jpg') or stristr($file,'.jpeg')){
      $UseThisPicture=$file;
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
    }
  }
}
//////////////////////////////////////////////////////////////////////////////////////////
echo '</section>';
include('wwwsitefooter.php');
?>