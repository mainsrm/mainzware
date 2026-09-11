<?php
session_start();//Start a session to remember things
//$_SESSION['expire'] = $_SESSION['start'] + (60 * 60* 24); //make a session last 24 hours

include('wwwsiteheader.php');
echo '<section>';

if(isset($_REQUEST['reset_password_for_CMS_site_username'])
   and $_REQUEST['reset_password_for_CMS_site_username']<>''
  ){ 
  $look_for_this_user=$_REQUEST['reset_password_for_CMS_site_username'];
  //echo '$look_for_this_user='.$look_for_this_user.'<br>';
  //echo '$This_CMS_Table='.$This_CMS_Table.'<br>';
  
  $sql="select *
          from ".$This_CMS_Table."
         where (info_type = 'Admin' or info_type = 'User')
               and info_email_link = '".trim(addslashes($look_for_this_user))."'
               and info_removed_on is NULL
       ";
  //RunAndShowIt($database_connection,$sql);
  //echo '<pre>$sql='.$sql.'</pre><br>';
  $gotdata=pg_Exec($database_connection,$sql);
  if(pg_num_rows($gotdata)>0){
    $new_password=$PasswordShortCode.rand(10000,99999);
    $save_this_password=md5($look_for_this_user.$new_password);
    //echo $new_password;
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $info_id=$getarow['info_id'];
      RemoveRecord( $database_connection
                   ,$This_CMS_Table
                   ,$info_id
                   ,$Now
                   ,$look_for_this_user
                  );
      $insert_id=NextRecordNumber($database_connection,$This_CMS_Table);
      $insert_type=$getarow['info_type'];
      $insert_title=$getarow['info_title'];
      $insert_hide_it=$save_this_password;
      $insert_name=$getarow['info_name'];
      $insert_phone=$getarow['info_phone'];
      $insert_email_link=$getarow['info_email_link'];
      $insert_added_on=$Now;
      $insert_added_by=$look_for_this_user;
      InsertUser( $database_connection
                    ,$This_CMS_Table
                    ,$insert_id
                    ,$insert_type
                    ,$insert_title
                    ,$insert_hide_it
                    ,$insert_name
                    ,$insert_phone
                    ,$insert_email_link
                    ,$insert_added_on
                    ,$insert_added_by
                   );
    }
    //echo $new_password;
    //exit;
    $to=$look_for_this_user;
    //$to='kirkhopkins0057@gmail.com';
    $date_time=date("l, m-d-Y g:i:s a");
    $Subject='Your '.$SiteURL.' password was reset on '.$date_time;
    $from_email_name=$EmailFromName;
    $from_email_address=$EmailFromAddress;
    $headers = "From: ".$from_email_name."<".$from_email_address.">";    
    $email_message='Your '.$_SERVER['HTTP_REFERER'].' password has been reset to '.$new_password;
    //mail($to, $Subject, $email_message, $headers);
    EmailIt( $database_connection
            ,$This_Email_Tracker_Table
            ,$to
            ,$Subject
            ,$email_message
            ,$headers
            ,$Now
            ,$from_email_address
           );
    //echo '<h1>New Password: '.$new_password.'</h1>';
    echo '<div class="success">Your new password has been sent to '.$look_for_this_user.'</div>';
    //$to='kirkhopkins0057@gmail.com';
    //EmailIt( $database_connection
    //        ,$This_Email_Tracker_Table
    //        ,$to
    //        ,$Subject
    //        ,$email_message
    //        ,$headers
    //        ,$Now
    //        ,$from_email_address
    //       );
  } else {
    echo "\n".'<div class="error">'.$look_for_this_user.' not found</div>';
  }
}

//echo '<pre>$CMS_site_username='.$CMS_site_username.'</pre><br>';
//echo '<pre>$CMS_site_password='.$CMS_site_password.'</pre><br>';
//echo '<pre>$SessionStuffLoggedIn='.$SessionStuffLoggedIn.'</pre><br>';

//if(isset($_REQUEST[$SessionStuffUsername])){ $CMS_site_username=$_REQUEST[$SessionStuffUsername]; } else { $CMS_site_username=''; }
//if(isset($_REQUEST[$SessionStuffPassword])){ $CMS_site_password=$_REQUEST[$SessionStuffPassword]; } else { $CMS_site_password=''; }
if(isset($_REQUEST[$SessionStuffLogout])){ $CMS_site_logout=$_REQUEST[$SessionStuffLogout]; } else { $CMS_site_logout=''; }

//echo '<pre>$CMS_site_username='.$CMS_site_username.'</pre><br>';
//echo '<pre>$CMS_site_password='.$CMS_site_password.'</pre><br>';
//echo '<pre>$SessionStuffLoggedIn='.$SessionStuffLoggedIn.'</pre><br>';

//Trying to login
if($CMS_site_username<>'' and $CMS_site_password<>'' and !isset($_SESSION[$SessionStuffLoggedIn])){
  $CheckThis_CMS_site_password=md5($CMS_site_username.$CMS_site_password);
  //echo '<pre>$CheckThis_CMS_site_password='.$CheckThis_CMS_site_password.'</pre><br>';
  $sqlLogin="select *
               from ".$This_CMS_Table."
              where (info_type = 'Admin' or info_type = 'User')
                    and info_email_link = '".trim(addslashes($CMS_site_username))."'
                    and info_hide_it = '".trim(addslashes($CheckThis_CMS_site_password))."'
                    and info_removed_on is NULL
            ";
  //RunAndShowIt($database_connection,$sqlLogin);
  //echo '<pre>$sqlLogin='.$sqlLogin.'</pre><br>';
  $gotdataLogin=pg_Exec($database_connection,$sqlLogin);
  if(pg_num_rows($gotdataLogin)>0){
    $_SESSION[$SessionStuffLoggedIn] = $CMS_site_username;
  } else {
    echo '<div class="error">Invalid username or wrong password, try again</div>';
  }
}
if(isset($_REQUEST['CMS_site_logout']) and $_REQUEST['CMS_site_logout']<>''){
  // remove all session variables
  session_unset();
  // destroy the session
  session_destroy();
  $CMS_site_logged_in='';
  $CMS_site_username='';
  echo "\n".'<p>Logged out</p>';
  //exit;
}

echo "\n".'<form name="ThisFormOfCourse" action="'.$_SERVER['PHP_SELF'].'" METHOD="post" ENCTYPE="application/x-www-form-urlencoded">';
echo "\n";
//echo '$CMS_site_username='.$CMS_site_username.'<br>';
if(isset($_SESSION[$SessionStuffLoggedIn])==''){
  echo "\n".'<h1>Content Manager Login Page</h1>';
  echo "\n".'<br><label for="CMS_site_username" class="hide">username</label>';
  echo "\n".'<br><input type="text" name="CMS_site_username" id="CMS_site_username" value="" placeholder="username" style="width:90%;">';
  echo "\n".'<br><label for="CMS_site_password" class="hide">password</label>';
  echo "\n".'<br><input type="password" name="CMS_site_password" id="CMS_site_password" value="" placeholder="password" style="width:90%;">';
  echo "\n".'<div style="margin-top:1em"><input type="submit" value="Submit"></div>';
  echo "\n".'<br><input type="hidden" name="CMS_site_logout" value="">';
  echo "\n".'<details><summary>I forgot my password</summary>';
  echo "\n".'<p>"I forgot my password" emails will be sent from <b>'.$EmailFromAddress.'</b>. You may want to add that address as a contact so emails from this address don\'t end up in your junk or spam folder.</p>';
  echo "\n".'<br><label for="reset_password_for_CMS_site_username" class="hide">username to reset the password for</label>';
  echo "\n".'<br><input type="text" name="reset_password_for_CMS_site_username" id="reset_password_for_CMS_site_username" value="" placeholder="username to reset the password for" style="width:90%;">';
  echo "\n".'<div style="margin-top:1em;margin-bottom:4em;"><input type="submit" value="email me a new password" style="width:90%;"></div>';
} else {
  echo "\n".'<h1>Manage Content</h1>';
  echo "\n".'<p>You are logged in. <a href="'.$_SERVER['PHP_SELF'].'?CMS_site_logout=logout">Logout</a></p>';
  $CMS_site_username=$_SESSION[$SessionStuffLoggedIn];
  if(isset($_REQUEST['this_info_type'])){
    $this_info_type=$_REQUEST['this_info_type'];
    echo "\n".'<input type="hidden" id="this_info_type" name="this_info_type" value="'.$this_info_type.'">';
  } else {
    $this_info_type='';
  }
  
  //set_new_password
  if(isset($_REQUEST['set_new_password'])
     and $_REQUEST['set_new_password']<>''
     and isset($_REQUEST['new_password_submit_button'])
     and $_REQUEST['new_password_submit_button']<>''
    ){ 
    $sql="select *
            from ".$This_CMS_Table."
           where (info_type = 'Admin' or info_type = 'User')
                 and info_email_link = '".trim(addslashes($CMS_site_username))."'
                 and info_removed_on is NULL
         ";
    //RunAndShowIt($database_connection,$sql);
    //echo '<pre>$sql='.$sql.'</pre><br>';
    $gotdata=pg_Exec($database_connection,$sql);
    if(pg_num_rows($gotdata)>0){
      $set_new_password=$_REQUEST['set_new_password'];
      $save_this_password=md5($CMS_site_username.$set_new_password);
      //echo $set_new_password;
      for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
        $getarow=pg_Fetch_Array($gotdata,$results_loop);
        $info_id=$getarow['info_id'];
        RemoveRecord( $database_connection
                     ,$This_CMS_Table
                     ,$info_id
                     ,$Now
                     ,$CMS_site_username
                    );
        $insert_id=NextRecordNumber($database_connection,$This_CMS_Table);
        $insert_type=$getarow['info_type'];
        $insert_title=$getarow['info_title'];
        $insert_hide_it=$save_this_password;
        $insert_name=$getarow['info_name'];
        $insert_phone=$getarow['info_phone'];
        $insert_email_link=$getarow['info_email_link'];
        $insert_added_on=$Now;
        $insert_added_by=$CMS_site_username;
        InsertUser( $database_connection
                   ,$This_CMS_Table
                   ,$insert_id
                   ,$insert_type
                   ,$insert_title
                   ,$insert_hide_it
                   ,$insert_name
                   ,$insert_phone
                   ,$insert_email_link
                   ,$insert_added_on
                   ,$insert_added_by
                  );
      }
      echo "\n".'<div class="success">Your new password has been saved</div>';
    } else {
      echo "\n".'<div class="error">'.$CMS_site_username.' not found</div>';
    }
  } else {
    //not setting a new password
    if(isset($_REQUEST['add_event'])){ $add_event=$_REQUEST['add_event']; } else { $add_event=''; }
    
    if(
       (isset($_REQUEST['new_info_title'])
        and $_REQUEST['new_info_title']<>''
       )
       or
       (isset($_REQUEST['brand_new_info_title'])
        and $_REQUEST['brand_new_info_title']<>''
       )
      ){
      //info_id
      if(isset($_REQUEST['update_id'])){
        $insert_id=$_REQUEST['update_id'];
        //remove the old data
        $info_id=$_REQUEST['update_id']; 
        $info_removed_on=$Now;
        $info_removed_by=$CMS_site_username;
        RemoveRecord( $database_connection
                     ,$This_CMS_Table
                     ,$info_id
                     ,$info_removed_on
                     ,$info_removed_by
                    );
      } else {
        $insert_id=NextRecordNumber($database_connection,$This_CMS_Table);
      }
      //info_type
      $insert_type=str_replace('_',' ',$this_info_type); //this is where we started
      //info_page
      if(isset($_REQUEST['brand_new_info_page']) and $_REQUEST['brand_new_info_page']<>''){
        $insert_page=$_REQUEST['brand_new_info_page'];
      } else if(isset($_REQUEST['new_info_page']) and $_REQUEST['new_info_page']<>''){
        $insert_page=$_REQUEST['new_info_page'];
      } else {
        $insert_page='';
      }
      //info_title
      if(isset($_REQUEST['brand_new_info_title']) and $_REQUEST['brand_new_info_title']<>''){
        $insert_title=$_REQUEST['brand_new_info_title'];
      } else if(isset($_REQUEST['new_info_title']) and $_REQUEST['new_info_title']<>''){
        $insert_title=$_REQUEST['new_info_title'];
      } else {
        $insert_title='';
      }
      //info_image
      //if(isset($_REQUEST['brand_new_info_image']) and $_REQUEST['brand_new_info_image']<>''){
      //  $insert_image=$_REQUEST['brand_new_info_image'];
      //} else 
      if(isset($_REQUEST['new_info_image']) and $_REQUEST['new_info_image']<>''){
        $insert_image=$_REQUEST['new_info_image'];
      } else {
        $insert_image='';
      }
      //info_location
      if(isset($_REQUEST['brand_new_info_location']) and $_REQUEST['brand_new_info_location']<>''){
        $insert_location=$_REQUEST['brand_new_info_location'];
      } else if(isset($_REQUEST['new_info_location']) and $_REQUEST['new_info_location']<>''){
        $insert_location=$_REQUEST['new_info_location'];
      } else {
        $insert_location='';
      }
      //info_details
      if(isset($_REQUEST['brand_new_info_details']) and $_REQUEST['brand_new_info_details']<>''){
        $insert_details=$_REQUEST['brand_new_info_details'];
      } else if(isset($_REQUEST['new_info_details']) and $_REQUEST['new_info_details']<>''){
        $insert_details=$_REQUEST['new_info_details'];
      } else {
        $insert_details='';
      }
      ////Combine 2 fields
      //info_date
      if(isset($_REQUEST['brand_new_info_date']) and $_REQUEST['brand_new_info_date']<>''){
        $insert_date=$_REQUEST['brand_new_info_date'];
      } else if(isset($_REQUEST['new_info_date']) and $_REQUEST['new_info_date']<>''){
        $insert_date=$_REQUEST['new_info_date'];
      } else {
        $insert_date='';
      }
      //info_time
      if($insert_date<>''){
        if(isset($_REQUEST['brand_new_info_time']) and $_REQUEST['brand_new_info_time']<>''){
          if(trim($_REQUEST['brand_new_info_time'])==''){
            $add_this_time='00:00:00';
          } else {
            $add_this_time=$_REQUEST['brand_new_info_time'];
          }
        } else if(isset($_REQUEST['new_info_time']) and $_REQUEST['new_info_time']<>''){
          if(trim($_REQUEST['new_info_time'])==''){
            $add_this_time='00:00:00';
          } else {
            $add_this_time=$_REQUEST['new_info_time'];
          }
        } else {
          $add_this_time='00:00:00';
        }
        $insert_time=$insert_date.' '.$add_this_time;
      } else {
        $insert_time='';
      }
      //info_how_many_hours
      if(isset($_REQUEST['brand_new_info_how_many_hours']) and $_REQUEST['brand_new_info_how_many_hours']<>''){
        $insert_how_many_hours=$_REQUEST['brand_new_info_how_many_hours'];
      } else if(isset($_REQUEST['new_info_how_many_hours']) and $_REQUEST['new_info_how_many_hours']<>''){
        $insert_how_many_hours=$_REQUEST['new_info_how_many_hours'];
      } else {
        $insert_how_many_hours='';
      }
      //info_name
      if(isset($_REQUEST['brand_new_info_name']) and $_REQUEST['brand_new_info_name']<>''){
        $insert_name=$_REQUEST['brand_new_info_name'];
      } else if(isset($_REQUEST['new_info_name']) and $_REQUEST['new_info_name']<>''){
        $insert_name=$_REQUEST['new_info_name'];
      } else {
        $insert_name='';
      }
      //info_phone
      if(isset($_REQUEST['brand_new_info_phone']) and $_REQUEST['brand_new_info_phone']<>''){
        $insert_phone=$_REQUEST['brand_new_info_phone'];
      } else if(isset($_REQUEST['new_info_phone']) and $_REQUEST['new_info_phone']<>''){
        $insert_phone=$_REQUEST['new_info_phone'];
      } else {
        $insert_phone='';
      }
      //info_email_link
      if(isset($_REQUEST['brand_new_info_email_link']) and $_REQUEST['brand_new_info_email_link']<>''){
        $insert_email_link=$_REQUEST['brand_new_info_email_link'];
      } else if(isset($_REQUEST['new_info_email_link']) and $_REQUEST['new_info_email_link']<>''){
        $insert_email_link=$_REQUEST['new_info_email_link'];
      } else {
        $insert_email_link='';
      }
      //temporary link
      
      //info_webpage_link
      if(isset($_REQUEST['brand_new_info_webpage_link']) and $_REQUEST['brand_new_info_webpage_link']<>''){
        $insert_webpage_link=$_REQUEST['brand_new_info_webpage_link'];
      } else if(isset($_REQUEST['new_info_webpage_link']) and $_REQUEST['new_info_webpage_link']<>''){
        $insert_webpage_link=$_REQUEST['new_info_webpage_link'];
      } else {
        $insert_webpage_link='';
      }
      //info_webpage_link_title
      if(isset($_REQUEST['brand_new_info_webpage_link_title']) and $_REQUEST['brand_new_info_webpage_link_title']<>''){
        $insert_webpage_link_title=$_REQUEST['brand_new_info_webpage_link_title'];
      } else if(isset($_REQUEST['new_info_webpage_link']) and $_REQUEST['new_info_webpage_link']<>''){
        $insert_webpage_link_title=$_REQUEST['new_info_webpage_link_title'];
      } else {
        $insert_webpage_link_title='';
      }
      if($insert_webpage_link==''){
        $insert_webpage_link_title='';
      } else if($insert_webpage_link<>'' and $insert_webpage_link_title<>''){
        //already got this above
      } else if($insert_webpage_link<>'' and $insert_webpage_link_title==''){
        $insert_webpage_link_title='web link'; //generic title
      }
      //info_added_on
      $insert_added_on=$Now;
      //info_added_by
      $insert_added_by=$CMS_site_username;
      
      if(trim($insert_title)==''){
        $insert_title='Untitled Information added by '.$insert_added_by.' on '.$AmericanDateAndTime;
      }
      InsertRecord( $database_connection
                   ,$This_CMS_Table
                   ,$insert_id
                   ,$insert_type
                   ,$insert_page
                   ,$insert_title
                   ,$insert_image
                   ,$insert_location
                   ,$insert_details
                   ,$insert_time
                   ,$insert_how_many_hours
                   ,$insert_name
                   ,$insert_phone
                   ,$insert_email_link
                   ,$insert_webpage_link
                   ,$insert_webpage_link_title
                   ,$insert_added_on
                   ,$insert_added_by
                  );
    }
    
    if($this_info_type=='' and !isset($_REQUEST['edit'])){
      if(isset($_REQUEST['remove'])){ 
        $info_id=$_REQUEST['remove']; 
        $info_removed_on=$Now;
        $info_removed_by=$CMS_site_username;
        RemoveRecord( $database_connection
                     ,$This_CMS_Table
                     ,$info_id
                     ,$info_removed_on
                     ,$info_removed_by
                    );
      }
    }
  }
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////    
  echo "\n".'<div style="margin-top:1em;">';
  echo "\n".'<details><summary>Password Change</summary>';
  echo "\n".'<p><label for="set_new_password">New Password</label>';
  echo "\n".'<br><input type="password" name="set_new_password" id="set_new_password" placeholder="enter new password" value=""></p>';
  echo "\n".'<div style="margin-top:1em;"><input type="submit" name="new_password_submit_button" value="save it"></div>';
  echo "\n".'</details>';
  echo "\n".'</div>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////    
  echo "\n".'<div style="margin-top:1em;">';
  echo "\n".'<a href="ImageManagement.php">Image Management</a>';
  echo "\n".'</div>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////    
  echo "\n".'<details><summary>View/Edit Live Records</summary>';
  for($current_or_past_loop=1;$current_or_past_loop<=2;$current_or_past_loop++) {
    if($current_or_past_loop==1){
      $Criteria="(info_time >= '".$Now."' or info_time is NULL)";
      $OrderBy='info_time asc, info_type asc, info_page asc, info_title asc';
    } else {
      $Criteria="info_time < '".$Now."' and info_time is NOT NULL";
      $OrderBy='info_time desc, info_type asc, info_page asc, info_title asc';
    }
    $sql="select *
            from ".$This_CMS_Table."
           where info_removed_on is NULL
                 and ".$Criteria."
                 and info_type<>'Admin'
        order by ".$OrderBy."
         ";
    //RunAndShowIt($database_connection,$sql);
    $gotdata=pg_Exec($database_connection,$sql);
    $show_this='';
    $calendar_info='';
    if(pg_num_rows($gotdata)>0){
      if($current_or_past_loop==1){
        echo "\n".'<h2>Current information that can be removed</h2>';
        echo "\n".'<p>This information is currently being displayed on the site. Some of the information is used to populate lists to create new information.</p>';
      } else {
        echo "\n".'<h2>Past information that has not been manually removed</h2>';
        echo "\n".'<p>The date and time for this information is has passed so the information has automatically been hidden on the site. Some of the information is still used to populate lists to create new information.  You only need to remove it if there is something incorrect about it.</p>';
        echo "\n".'<details><summary>Show old info</summary>';
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
  
        echo "\n".'<div class="odd_or_even">';
        if($info_title<>''){
          echo "\n".'<h2 class="h2_odd_or_even">'.$info_title.'</h2>';
        }
        echo "\n".'<table>';
        echo "\n".'<tr><td>Info Type</td><td><pre>'.$info_type.'</pre></td></tr>';
        echo "\n".'<tr><td>Page</td><td><pre>'.$info_page.'</pre></td></tr>';
        echo "\n".'<tr><td>Contact Name</td><td><pre>'.$info_name.'</pre></td></tr>';
        echo "\n".'<tr><td>Image</td><td><pre>'.$info_image.'</pre></td></tr>';
        echo "\n".'<tr><td>Location</td><td><pre>'.$info_location.'</pre></td></tr>';
        echo "\n".'<tr><td>Details</td><td><pre>'.$info_details.'</pre></td></tr>';
        echo "\n".'<tr><td>Time</td><td><pre>'.$info_time.'</pre></td></tr>';
        echo "\n".'<tr><td>Duration</td><td><pre>'.$info_how_many_hours.'</pre></td></tr>';
        echo "\n".'<tr><td>Contact</td><td><pre>'.$info_name.'</pre></td></tr>';
        echo "\n".'<tr><td>Phone</td><td><pre>'.$info_phone.'</pre></td></tr>';
        echo "\n".'<tr><td>Email</td><td><pre>'.$info_email_link.'</pre></td></tr>';
        $converted_info_webpage_link=str_replace('<','&lt;',$info_webpage_link);
        $converted_info_webpage_link=str_replace('>','&gt;',$converted_info_webpage_link);
        $converted_info_webpage_link=str_replace("\n",'<br>',$converted_info_webpage_link);
        echo "\n".'<tr><td>Web Link</td><td><pre>'.$converted_info_webpage_link.'</pre></td></tr>';
        echo "\n".'<tr><td>Web Link Title</td><td><pre>'.$info_webpage_link_title.'</pre></td></tr>';
        echo "\n".'<tr><td>Added On</td><td><pre>'.$info_added_on.'</pre></td></tr>';
        echo "\n".'<tr><td>Added By</td><td><pre>'.$info_added_by.'</pre></td></tr>';
        if($info_removed_on<>''){
          echo "\n".'<tr><td>Removed On</td><td><pre>'.$info_removed_on.'</pre></td></tr>';
          echo "\n".'<tr><td>Removed By</td><td><pre>'.$info_removed_by.'</pre></td></tr>';
        }
        echo "\n".'</table>';
        echo "\n".'<p class="edit_this_information"><a href="?edit='.$info_id.'" title="edit '.$info_title.'">edit this information</a></p>';
        echo "\n".'<p class="remove_this_information"><a href="?remove='.$info_id.'" onclick="AreYouSure()" title="remove '.$info_title.'">remove this information</a></p>';
        echo "\n".'</div>'; // class="odd_or_even"
      }
    }
    if($current_or_past_loop==1){
      //skip this
    } else {
      echo "\n".'</details>';
    }
  }
  echo "\n".'</details>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  echo "\n".'<p style="margin-top:1em;">If there are more than 3 articles on a page, articles with more than 1000 characters will only show the first paragraph and will have an option to expand the article.<br>';
  echo "\n".'Things added with a date/time and a duration will automatically create an "Add to Calendar" link</p>';
  
  if(isset($_REQUEST['edit'])){
    echo "\n".'<h1 style="display:block;padding:1em;background:#fffde6;">Editing Content</h1>';
    echo "\n".'<div style="display:block;padding:2em;background:#fffde6;">';
    $sql9="select *
                  ,to_date(to_char(info_time, 'MM/DD/YYYY'), 'MM/DD/YYYY') as info_just_the_date
                  ,extract(hour from info_time) as info_just_the_hour
                  ,extract(minutes from info_time) as info_just_the_minutes
             from ".$This_CMS_Table."
            where info_removed_on is NULL
                  and info_id = '".$edit."'
          ";
    //echo '$sql9=<pre>'.$sql9.'</pre>';
    //RunAndShowIt($database_connection,$sql9);
    $gotdata9=pg_Exec($database_connection,$sql9);
    $show_this='';
    if(pg_num_rows($gotdata9)>0){
      for($results_loop9=0;$results_loop9<pg_num_rows($gotdata9);$results_loop9++) {
        $getarow9=pg_Fetch_Array($gotdata9,$results_loop9);
        $previous_id=$getarow9['info_id'];
        $previous_type=$getarow9['info_type'];
        $previous_page=$getarow9['info_page'];
        $previous_title=$getarow9['info_title'];
        $previous_image=$getarow9['info_image'];
        $previous_location=$getarow9['info_location'];
        $previous_details=$getarow9['info_details'];
        $previous_time=$getarow9['info_time'];
        //
        $previous_date=$getarow9['info_just_the_date'];
        if($getarow9['info_just_the_hour']==''){
          $previous_time='';
        } else {
          $previous_time=$getarow9['info_just_the_hour'].':'.$getarow9['info_just_the_minutes'].':00';
        }
        //
        $previous_how_many_hours=$getarow9['info_how_many_hours'];
        $previous_name=$getarow9['info_name'];
        $previous_phone=$getarow9['info_phone'];
        $previous_email_link=$getarow9['info_email_link'];
        $previous_webpage_link=$getarow9['info_webpage_link'];
        $previous_webpage_link_title=$getarow9['info_webpage_link_title'];
        $previous_added_on=$getarow9['info_added_on'];
        $previous_added_by=$getarow9['info_added_by'];
        $previous_removed_on=$getarow9['info_removed_on'];
        $previous_removed_by=$getarow9['info_removed_by'];
        //
        $this_info_type=$previous_type;
        echo "\n".'<input type="hidden" id="this_info_type" name="this_info_type" value="'.$this_info_type.'">';
      }
    }
  } else {
    //Work on adding a new record
    echo "\n".'<h1>Add a New Record Below</h1>';
    echo "\n".'<div>';
    $previous_id='';
    $previous_type='';
    $previous_page='';
    $previous_title='';
    $previous_image='';
    $previous_location='';
    $previous_details='';
    $previous_time='';
    //
    $previous_date='';
    //
    $previous_how_many_hours='';
    $previous_name='';
    $previous_phone='';
    $previous_email_link='';
    $previous_webpage_link='';
    $previous_webpage_link_title='';
    $previous_added_on='';
    $previous_added_by='';
    $previous_removed_on='';
    $previous_removed_by='';
    //////////////////////////////////////////////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////////////////
  }
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Page to display it on
  $this_form_thing='info_page';
  $this_form_title='select the page that you want to this information on';
  
  //Hard-coded dropdown list for Navigation Menu Items/Folders (mostly found in edit_this.php)
  $new_list='';
  echo '
  <div style="display:block;padding-top:2em;padding-bottom:2em;">
  <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'">'.$this_form_title.'</label>
  <br><select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'">
  ';
  echo '$this_info_type='.$this_info_type.'<br>';
  echo "\n".'<option value="" title="'.$this_form_title.'">select an option</option>';
  echo $NavigationItemsForContentManagement;
  if($previous_page<>''){
    $new_list.="\n".'<option value="'.$previous_page.'" title="Keep '.$previous_page.'" selected>'.str_replace('_',' ',$previous_page).' (previously saved)</option>';
  }
  echo $new_list;
  echo '
  </select>
  </div>
  ';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Get a list of names for this event type (example: Spring Post-Walk Gathering)
  $blank_or_or='';
  $this_form_thing='info_title';
  $this_form_title='select a title';
  $this_form_title.=' (a title is required)';
  $this_new_form_title='enter a new title';
  $more_criteria="and (info_type = '".$this_info_type."' or info_type = '".str_replace('_',' ',$this_info_type)."')";
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               ".$more_criteria."
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  $found_a_walk='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'"';
      $new_list.='>';
      $new_list.=$found_in_the_database.'</option>';
    }
    if($previous_title<>''){
      $new_list.="\n".'<option value="'.$previous_title.'" title="Keep '.$previous_title.'" selected>'.str_replace('_',' ',$previous_title).' (previously saved)</option>';
    }
    //Build a dropdown list
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" ';
  echo 'value=""';
  echo ' placeholder="'.$blank_or_or.$this_new_form_title.'"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //image - grab images from the "Manually_Selected" folder
  $blank_or_or='';
  $this_form_thing='info_image';
  $this_form_title='use image in the Manually_Selected image folder';
  $new_list='';
  
  $LookInThisFolder='images/';
  $ThisFolder='Manually_Selected';
  $FoundOne='';
  $sub_directory_name='';
  $file_counter=0;
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
      $file_size=number_format((filesize($LookInThisFolder.$ThisFolder.'/'.$file))/1000000,2);
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
        $new_list.="\n".'<div style="margin-bottom:1em;border: 3px solid #232323;padding:1em;margin:1em;">';
        $new_list.="\n".'<input type="radio" name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" value="'.$file.'" title="'.$this_form_title.'" style="display:inline;background-color:#cdcdcd;"';
        if(trim($previous_image<>'') and $previous_image==$LookInThisFolder.$ThisFolder.'/'.$file){
          $new_list.=' checked';
        }
        $new_list.='>';
        $new_list.="\n".'<label for="new_'.$this_form_thing.'" title="'.$this_form_title.'">'.$file;
        $new_list.="\n".'<br><img src="'.$LookInThisFolder.$ThisFolder.'/'.$file.'?'.$filemtime.'" alt="'.str_replace('_',' ',$just_the_name).'" class="thumbnail">';
        if(trim($previous_image<>'') and $previous_image==$LookInThisFolder.$ThisFolder.'/'.$file){
          $new_list.="\n".'<br>(previously saved)';
        }
        $new_list.="\n".'</label>';
        $new_list.="\n".'</div>';
      }
    }
  }
  echo "\n".'<fieldset>';
  echo "\n".'<legend>'.$this_form_title.'</legend>';
  echo $new_list;
  echo "\n".'</fieldset>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Location - Get a list of all locations for this event type (ex. Osgood ...)
  $blank_or_or='';
  $this_form_thing='info_location';
  $this_form_title='select a location';
  $this_new_form_title='enter a new location';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  $found_a_walk='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database<>'')){
        $new_list.="\n".'<div style="margin-bottom:1em;">';
        $new_list.="\n".'<input type="radio" name="new_'.$this_form_thing.'" id="new_'.$found_in_the_database.'" value="'.$found_in_the_database.'" title="'.$this_form_title.'" class="create_info"';
        if(str_replace('_',' ',$this_info_type)=='Mens Walk' or str_replace('_',' ',$this_info_type)=='Womens Walk'){
          if(stristr($found_in_the_database,'Southeastern Baptist Youth Camp')){
            $new_list.=' checked';
            $found_a_walk='yup';
          }
        }
        $new_list.='>';
        $new_list.="\n".'<label for="new_'.$found_in_the_database.'" title="'.$this_form_title.'" class="create_info">';
        $new_list.="\n".'<pre style="margin-top:-1.5em;margin-left:1.5em;">'.$found_in_the_database.'</pre>';
        $new_list.="\n".'</label>';
        $new_list.="\n".'</div>';
      }
    }
    if(trim($previous_location<>'')){
      $new_list.="\n".'<div style="margin-bottom:1em;">';
      $new_list.="\n".'<input type="radio" name="new_'.$this_form_thing.'" id="new_'.$previous_location.'" value="'.$previous_location.'" title="'.$this_form_title.'" class="create_info"';
      $new_list.=' checked>';
      $new_list.="\n".'<label for="new_'.$previous_location.'" title="'.$this_form_title.'" class="create_info">';
      $new_list.="\n".'<pre style="margin-top:-1.5em;margin-left:1.5em;">'.$previous_location.'</pre>';
      $new_list.="\n".'(previously saved)';
      $new_list.="\n".'</label>';
      $new_list.="\n".'</div>';
    }
    echo "\n".'<fieldset>';
    echo "\n".'<legend>'.$this_form_title.'</legend>';
    echo $new_list;
    echo "\n".'</fieldset>';
    
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title;
  echo "\n".'<br><textarea name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" rows="5" class="create_info">';
  if((str_replace('_',' ',$this_info_type)=='Mens Walk' or str_replace('_',' ',$this_info_type)=='Womens Walk') and $found_a_walk==''){
    echo 'Southeastern Baptist Youth Camp';
    echo "\n".'3127 W County Road 800 S';
    echo "\n".'Greensburg, IN  47240';
  }
  echo '</textarea></label></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  $blank_or_or='';
  $this_form_thing='info_details';
  $this_new_form_title='enter details';
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title;
  echo "\n".'<br><textarea name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" rows="5" class="create_info">';
  if($previous_details<>''){
    echo "\n".$previous_details;
  }
  echo "\n".'</textarea></label></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Make a dropdown list with dates in it
  $blank_or_or='';
  $this_form_thing='info_date';
  $this_form_title='select a date';
  $new_list='';
  $more_dates='';
  
  $begin = new DateTime(date('Y-m-d'));
  $end = new DateTime(date('Y-m-d', strtotime(' + 400 days')));
  $interval = DateInterval::createFromDateString('1 day');
  $period = new DatePeriod($begin, $interval, $end);
  if($previous_date<>''){
    $new_list.="\n".'<option value="'.$previous_date.'" title="Keep '.$previous_date.'" selected>'.str_replace('_',' ',$previous_date).' (previously saved)</option>';
  }
  //show all dates
  foreach ($period as $dt) {
    $show_this=$dt->format("m-d-Y (l)");
    $store_this=$dt->format("m-d-Y");
    $more_dates.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_this.'">'.$show_this.'</option>';
  }
  //Build a dropdown list
  echo '
  <div style="display:block;padding-top:2em;">
  <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
  <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
  <option value="" title="'.$this_form_title.'">select an option</option>
  <optgroup label="Suggested Dates" title="Suggested Dates">
  '.$new_list.'
  </optgroup>
  <optgroup label="Full List of Dates" title="Full List of Dates">
  '.$more_dates.'
  </optgroup>
  </select>
  </div>
  ';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Make a dropdown list with times in it
  $blank_or_or='';
  $this_form_thing='info_time';
  $this_form_title='select a time';
  $new_list='';
  $more_times='';
  
  $begin = new DateTime(date('Y-m-d'));
  $end = new DateTime(date('Y-m-d', strtotime(' + 1 day')));
  $interval = DateInterval::createFromDateString('30 minutes');
  $period = new DatePeriod($begin, $interval, $end);
  
  foreach ($period as $dt) {
    $show_this=$dt->format("ga");
    $show_half_hour=$dt->format("g:ia");
    $check_half_hour=$dt->format("i");
    $store_this=$dt->format("H:i:s");
    if(str_replace('_',' ',$this_info_type)=='Mens Walk'
       or str_replace('_',' ',$this_info_type)=='Womens Walk'
       or str_replace('_',' ',$this_info_type)=='Prayer Vigil Signup People List'
      ){
      if($dt->format("H")==19 and $check_half_hour<>30){
        $new_list.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_this.'" selected>'.$show_this.'</option>';
      }
    } else {
      if($this_info_type=='Team_Meeting'){
        if($dt->format("H")>=8 and $dt->format("H")<=10 and $check_half_hour<>30){
          $new_list.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_this.'">'.$show_this.'</option>';
        } else if($dt->format("H")>=8 and $dt->format("H")<=10 and $check_half_hour==30){
          $new_list.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_half_hour.'">'.$show_half_hour.'</option>';
        }
      } else if($dt->format("H")>=17 and $dt->format("H")<=22 and $check_half_hour<>30){
        $new_list.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_this.'">'.$show_this.'</option>';
      } else if($dt->format("H")>=17 and $dt->format("H")<=22 and $check_half_hour==30){
        $new_list.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_half_hour.'">'.$show_half_hour.'</option>';
      }
    }
  }
  foreach ($period as $dt) {
    $show_this=$dt->format("ga");
    $show_half_hour=$dt->format("g:ia");
    $check_half_hour=$dt->format("i");
    $store_this=$dt->format("H:i:s");
    if($check_half_hour<>30){
      $more_times.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_this.'">'.$show_this.'</option>';
    } else if($check_half_hour==30){
      $more_times.="\n".'<option value="'.$store_this.'" title="Selecting '.$show_half_hour.'">'.$show_half_hour.'</option>';
    }
  }
  //Build a dropdown list
  if($previous_time<>''){
    $new_list.="\n".'<option value="'.$previous_time.'" title="Keep '.$previous_time.'" selected>'.str_replace('_',' ',$previous_time).' (previously saved)</option>';
  }
  echo "\n".'<div style="display:block;padding-top:2em;">';
  echo "\n".'<label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>';
  echo "\n".'<select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">';
  echo "\n".'<option value="" title="'.$this_form_title.'">select an option</option>';
  echo "\n".'<optgroup label="Suggested Times" title="Suggested Times">';
  echo $new_list;
  echo "\n".'</optgroup>';
  if(str_replace('_',' ',$this_info_type)=='Mens Walk' or str_replace('_',' ',$this_info_type)=='Womens Walk'){
    //skip this
  } else {
    echo "\n".'<optgroup label="Full List of Times" title="Full List of Times">';
    echo $more_times;
    echo "\n".'</optgroup>';
  }
  echo "\n".'</select>';
  echo "\n".'</div>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //Make a dropdown list with duration in it
  $blank_or_or='';
  $this_form_thing='info_how_many_hours';
  $this_form_title='select a duration (how many hours will this last?)';
  $new_list='';
  $more_times='';
  for ($hours = 1; $hours <= 8; $hours++) {
    $new_list.="\n".'<option value="'.$hours.'" title="Selecting '.$hours.'">'.$hours.'</option>';
  }
  for ($hours = 1; $hours <= 24; $hours++) {
    $more_times.="\n".'<option value="'.$hours.'" title="Selecting '.$hours.'">'.$hours.'</option>';
    $more_times.="\n".'<option value="'.($hours+.5).'" title="Selecting '.($hours+.5).'">'.($hours+.5).'</option>';
  }
  //Build a dropdown list
  if($previous_how_many_hours<>''){
    $new_list.="\n".'<option value="'.$previous_how_many_hours.'" title="Keep '.$previous_how_many_hours.'" selected>'.str_replace('_',' ',$previous_how_many_hours).' (previously saved)</option>';
  }
  echo "\n".'<div style="display:block;padding-top:2em;">';
  echo "\n".'<label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>';
  echo "\n".'<select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">';
  echo "\n".'<option value="" title="'.$this_form_title.'">select an option</option>';
  echo "\n".'<optgroup label="Suggested Hours" title="Suggested Hours">';
  echo $new_list;
  echo "\n".'</optgroup>';
  echo "\n".'<optgroup label="Full List of Hours" title="Full List of Hours">';
  echo $more_times;
  echo "\n".'</optgroup>';
  echo "\n".'</select>';
  echo "\n".'</div>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //name
  $blank_or_or='';
  $this_form_thing='info_name';
  $this_form_title='select a contact name';
  $this_new_form_title='enter a new name';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database)<>''){
        $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'">'.$found_in_the_database.'</option>';
      }
    }
    //Build a dropdown list
    if($previous_name<>''){
      $new_list.="\n".'<option value="'.$previous_name.'" title="Keep '.$previous_name.'" selected>'.str_replace('_',' ',$previous_name).' (previously saved)</option>';
    }
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" class="create_info"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //phone
  $blank_or_or='';
  $this_form_thing='info_phone';
  $this_form_title='select a phone';
  $this_new_form_title='enter a new phone';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database)<>''){
        $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'">'.$found_in_the_database.'</option>';
      }
    }
    //Build a dropdown list
    if($previous_phone<>''){
      $new_list.="\n".'<option value="'.$previous_phone.'" title="Keep '.$previous_phone.'" selected>'.str_replace('_',' ',$previous_phone).' (previously saved)</option>';
    }
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" class="create_info"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //email link
  $blank_or_or='';
  $this_form_thing='info_email_link';
  $this_form_title='select a email link';
  $this_new_form_title='enter a new email link';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database)<>''){
        $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'">'.$found_in_the_database.'</option>';
      }
    }
    //Build a dropdown list
    if($previous_email_link<>''){
      $new_list.="\n".'<option value="'.$previous_email_link.'" title="Keep '.$previous_email_link.'" selected>'.str_replace('_',' ',$previous_email_link).' (previously saved)</option>';
    }
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" class="create_info"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //web page link
  $blank_or_or='';
  $this_form_thing='info_webpage_link';
  $this_form_title='select a webpage link';
  $this_new_form_title='enter a new webpage link';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and info_removed_on is NULL
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database)<>''){
        if(stristr($found_in_the_database,'"')){
          //skip it (YouTube probably)
        } else {
          $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'">'.$found_in_the_database.'</option>';
        }
      }
    }
    //Build a dropdown list
    if($previous_webpage_link<>''){
      $new_list.="\n".'<option value="'.$previous_webpage_link.'" title="Keep '.$previous_webpage_link.'" selected>'.str_replace('_',' ',$previous_webpage_link).' (previously saved)</option>';
    }
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" class="create_info"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////
  //web page link title
  $blank_or_or='';
  $this_form_thing='info_webpage_link_title';
  $this_form_title='select a webpage link title';
  $this_new_form_title='enter a new webpage link title';
  $sql="select distinct ".$this_form_thing."
          from ".$This_CMS_Table."
         where coalesce(".$this_form_thing.") is NOT NULL
               and (info_type = '".$this_info_type."' or info_type = '".str_replace('_',' ',$this_info_type)."')
      order by ".$this_form_thing." asc
       ";
  //RunAndShowIt($database_connection,$sql);
  $gotdata=pg_Exec($database_connection,$sql);
  $new_list='';
  if(pg_num_rows($gotdata)>0){
    for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
      $getarow=pg_Fetch_Array($gotdata,$results_loop);
      $found_in_the_database=$getarow[$this_form_thing];
      if(trim($found_in_the_database)<>''){
        $new_list.="\n".'<option value="'.$found_in_the_database.'" title="Selecting '.$found_in_the_database.'">'.$found_in_the_database.'</option>';
      }
    }
    //Build a dropdown list
    if($previous_webpage_link_title<>''){
      $new_list.="\n".'<option value="'.$previous_webpage_link_title.'" title="Keep '.$previous_webpage_link_title.'" selected>'.str_replace('_',' ',$previous_webpage_link_title).' (previously saved)</option>';
    }
    echo '
    <div style="display:block;padding-top:2em;">
    <label for="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">'.$this_form_title.'</label>
    <select name="new_'.$this_form_thing.'" id="new_'.$this_form_thing.'" title="'.$this_form_title.'" class="create_info">
    <option value="" title="'.$this_form_title.'">select an option</option>
    '.$new_list.'
    </select>
    </div>
    ';
    $blank_or_or=' or ';
  }
  echo "\n".'<p><label for="brand_new_'.$this_form_thing.'" class="create_info">'.$blank_or_or.$this_new_form_title.'</label>';
  echo "\n".'<br><input type="text" name="brand_new_'.$this_form_thing.'" id="brand_new_'.$this_form_thing.'" value="" placeholder="'.$blank_or_or.$this_new_form_title.'" class="create_info"></p>';
  //////////////////////////////////////////////////////////////////////////////////////
  if($previous_id<>''){
    echo "\n".'<br><input type="hidden" name="update_id" value="'.$previous_id.'">';
    echo "\n".'<p><input type="submit" value="update"></p>';
  } else {
    echo "\n".'<p><input type="submit" value="save"></p>';
  }
  echo "\n".'<div style="margin-top:10em;"><a href="'.$_SERVER['PHP_SELF'].'?start_over=yup">clear this and start over</a></div>';
  echo "\n".'</div>';
}
echo "\n".'</form>';
echo '</section>';

include('wwwsitefooter.php');
?>