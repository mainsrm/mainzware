<?php
include_once('edit_this.php');
include_once('environment.php');

echo '<section style="margin:1em;">';
echo '<h1>Start Over</h1>';

if(isset($StartOver)
   and $StartOver=='yup'
   and isset($StartOverCode)
   and isset($PasswordShortCode)
   and $StartOverCode==md5(date('Ymd').$PasswordShortCode)
  ){
  $sql="drop table IF EXISTS ".$This_CMS_Table;
  pg_Exec($database_connection,$sql);
  $sql="create table ".$This_CMS_Table."
             (
               info_id                 varchar(10485760)
              ,info_type               varchar(10485760)
              ,info_page               varchar(10485760)
              ,info_title              varchar(10485760)
              ,info_image              varchar(10485760)
              ,info_location           varchar(10485760)
              ,info_details            varchar(10485760)
              ,info_time               timestamp
              ,info_how_many_hours     varchar(10485760)
              ,info_name               varchar(10485760)
              ,info_hide_it            varchar(10485760)
              ,info_phone              varchar(10485760)
              ,info_email_link         varchar(10485760)
              ,info_webpage_link       varchar(10485760)
              ,info_webpage_link_title varchar(10485760)
              ,info_added_on           timestamp
              ,info_added_by           varchar(10485760)
              ,info_removed_on         timestamp
              ,info_removed_by         varchar(10485760)
             ) with OIDS
       ";
  //echo '$sql=<pre>'.$sql.'</pre><br>';
  pg_Exec($database_connection,$sql);
  //create indexes
  $FieldName='info_id';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_CMS_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='info_type';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_CMS_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='info_page';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_CMS_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='info_title';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_CMS_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='info_location';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_CMS_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);

  echo '<h1>'.$This_CMS_Table.' table deleted and re-created</h1>';

  $sql="drop table IF EXISTS ".$This_Email_Tracker_Table;
  pg_Exec($database_connection,$sql);
  $sql="create table ".$This_Email_Tracker_Table."
             (
               email_address            varchar(10485760)
              ,email_message            varchar(10485760)
              ,email_sent_on            timestamp
              ,email_sent_by            varchar(10485760)
             ) with OIDS
       ";
  //echo '$sql=<pre>'.$sql.'</pre><br>';
  pg_Exec($database_connection,$sql);
  //create indexes
  $FieldName='email_address';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_Email_Tracker_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='email_message';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_Email_Tracker_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);
  $FieldName='email_sent_by';
  $sql="create index ".$This_CMS_Table."_".$FieldName."_index"." on ".$This_Email_Tracker_Table." (".$FieldName.")";
  pg_Exec($database_connection,$sql);

  echo '<h1>'.$This_Email_Tracker_Table.' email tracker table deleted and re-created</h1>';

  //Setup a site admin, this is whoever is going to have access to edit code, not just manage content
  $info_id='100000001';
  $info_type='Admin';
  $info_hide_it=$AdminInitialPassword;
  $info_details=md5($AdminUserNameAndEmail.$info_hide_it);
  $info_email_link=$AdminUserNameAndEmail;
  $info_added_on=$Now;
  $info_added_by='initial database setup';
  $add_it="insert into ".$This_CMS_Table."
                   (
                     info_id
                    ,info_type
                    ,info_hide_it
                    ,info_email_link
                    ,info_added_on
                    ,info_added_by
                   )
                 values
                   (
                     ".pg_escape_literal(trim(convert_bad_microsoft_characters($info_id)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_type)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_details)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_email_link)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_added_on)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_added_by)))."
                   )
                   ";
  echo "\n".'<pre>$add_it='.$add_it.'</pre><br>';
  pg_Exec($database_connection,$add_it);
  //
  //Setup a user to manage content
  $info_id='100000002';
  $info_type='User';
  $info_hide_it=$UserInitialPassword;
  $info_details=md5($UserUserNameAndEmail.$info_hide_it);
  $info_email_link=$UserUserNameAndEmail;
  $info_added_on=$Now;
  $info_added_by='initial database setup';
  $add_it="insert into ".$This_CMS_Table."
                   (
                     info_id
                    ,info_type
                    ,info_hide_it
                    ,info_email_link
                    ,info_added_on
                    ,info_added_by
                   )
                 values
                   (
                     ".pg_escape_literal(trim(convert_bad_microsoft_characters($info_id)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_type)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_details)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_email_link)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_added_on)))."
                    ,".pg_escape_literal(trim(convert_bad_microsoft_characters($info_added_by)))."
                   )
                   ";
  echo "\n".'<pre>$add_it='.$add_it.'</pre><br>';
  pg_Exec($database_connection,$add_it);
  $sql="select *
          from ".strtolower($This_CMS_Table)."
       ";
  RunAndShowIt($database_connection,$sql);
} else {
  echo "\n".'Administrator notified of breakin attempt';
  $to=$AdminUserNameAndEmail;
  $date_time=date("l, m-d-Y g:i:s a");
  $Subject=$SiteURL.' start over breakin attempt on '.$date_time;
  $from_email_name=$EmailFromName;
  $from_email_address=$EmailFromAddress;
  $headers = "From: ".$from_email_name."<".$from_email_address.">";    
  $email_message='Breakin attempt from '.$_SERVER['REMOTE_ADDR'].' on '.$date_time;
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
}
echo '</section>';
?>