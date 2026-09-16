<?php
session_start();//Start a session to remember things
//$_SESSION['expire'] = $_SESSION['start'] + (60 * 60* 24); //make a session last 24 hours

include('wwwsiteheader.php');

////manually set passwords for accounts with no password
//$sql="select *
//          from ".$EmmausTable."
//         where info_email_link like '%seiemmaus@gmail.com'
//               and info_removed_on is NULL
//               and info_hide_it is NULL
//       ";
////RunAndShowIt($database_connection,$sql);
//$gotdata=pg_Exec($database_connection,$sql);
//if(pg_num_rows($gotdata)>0){
//  for($results_loop=0;$results_loop<pg_num_rows($gotdata);$results_loop=$results_loop+1) {
//    $getarow=pg_Fetch_Array($gotdata,$results_loop);
//    $info_email_link=$getarow['info_email_link'];
//    $new_password='sei';
//    $save_this_password=md5($info_email_link.$new_password);
//    $update_it="update ".$EmmausTable."
//                   set info_hide_it = ".pg_escape_literal(trim(convert_bad_microsoft_characters($save_this_password)))."
//                 where info_email_link = '".$info_email_link."'
//                       and info_email_link like '%seiemmaus@gmail.com'
//                       and info_removed_on is NULL
//                       and info_hide_it is NULL
//               ";
//    echo "\n".'<pre>$update_it='.$update_it.'</pre><br>';
//    //pg_Exec($database_connection,$update_it);
//  }
//}

////manually update a password
//$this_username_for_now='clergy1.seiemmaus@gmail.com';
//$this_temp_password_for_now='sei';
//$this_password_for_now=md5($this_username_for_now.$this_temp_password_for_now);
//$sql="select *
//          from ".$EmmausTable."
//         where info_email_link = '".$this_username_for_now."'
//               and info_removed_on is NULL
//       ";
////RunAndShowIt($database_connection,$sql);
//$update_it="update ".$EmmausTable."
//               set info_removed_on = NULL
//                   ,info_removed_by = NULL
//                   ,info_hide_it = ".pg_escape_literal(trim(convert_bad_microsoft_characters($this_password_for_now)))."
//             where info_email_link = '".$this_username_for_now."'
//                   and info_removed_on is NULL
//           ";
////echo "\n".'$update_it='.$update_it.'<br>';
////pg_Exec($database_connection,$update_it);


////manually bulk remove prayer vigil signups for one person
////a bot came along and took every available time slot, then Louise Griggs somehow did a similar thing for the remaining 94 slots
//$this_problem="prayer_email = 'lgriggs2@icloud.com'
//               and prayer_time >= '2023-04-20'
//               and prayer_time <= '2023-04-24'";
//
//$this_problem="(prayer_name = 'Johanna Johnson' or prayer_name = 'Vicki Ballard')
//               and prayer_time >= '2023-04-20'
//               and prayer_time <= '2023-04-24'";
//
//$sql="select *
//          from ".$EmmausPrayerVigilTable."
//         where ".$this_problem."
//               and prayer_replaced_on is NULL
//       ";
//RunAndShowIt($database_connection,$sql);
//$update_it="update ".$EmmausPrayerVigilTable."
//               set prayer_name = NULL
//                   ,prayer_email = NULL
//                   ,prayer_ip_address = NULL
//                   ,prayer_added_on = NULL
//             where ".$this_problem."
//                   and prayer_replaced_on is NULL
//           ";
//echo "\n".'<pre>$update_it='.$update_it.'</pre><br>';
//pg_Exec($database_connection,$update_it);


//<script>
//function copyText(element) {
//  var range, selection, worked;
//
//  if (document.body.createTextRange) {
//    range = document.body.createTextRange();
//    range.moveToElementText(element);
//    range.select();
//  } else if (window.getSelection) {
//    selection = window.getSelection();        
//    range = document.createRange();
//    range.selectNodeContents(element);
//    selection.removeAllRanges();
//    selection.addRange(range);
//  }
//  
//  try {
//    document.execCommand('copy');
//    alert('link copied, now paste it into a message to a friend');
//  }
//  catch (err) {
//    alert('unable to copy text');
//  }
//}
//</script>
//<details><summary>share</summary>
//Copy and paste the following link to email it or text it to a friend <span id="display" onClick="copyText(this)" style="text-decoration:underline;">https://apple.com</span> 
//</details>
//phpinfo();

echo '<section>';

//$sql='ALTER TABLE '.$EmmausTable.' ADD COLUMN "info_image" character varying(10485760)';
//RunAndShowIt($database_connection,$sql);
//exit;

//echo "\n".'<h1>removed records</h1>';
//$sql="select *
//        from ".$EmmausTable."
//       where info_removed_on is NOT NULL
//     ";

/*
echo "\n".'<h1>live records</h1>';
$sql="select *
        from ".$EmmausTable."
       where info_removed_on is NULL
     ";
RunAndShowIt($database_connection,$sql);
*/
$this_logic="info_title ilike 'Job Description%'
             and info_removed_on is NULL";
$this_logic="info_type in ('Mens Walk','Womens Walk')
             and info_time <= (now() + interval '12 weeks')
             and info_removed_on is NULL
            ";
$xthis_logic="info_title in ('Testing')
             and info_removed_on is NULL
            ";
$this_logic="(info_time >= (now() - interval '3 days')
              and info_how_many_hours is NOT NULL
             )
             and info_removed_on is NULL
            ";
$this_logic="info_removed_on > '05-16-2023'
            ";
$this_logic="info_email_link = 'communication.seiemmaus@gmail.com'
             and info_added_on = '2023-05-16 20:47:49'
            ";
//echo "\n".'<h1>live record research</h1>';
$sql="select *
        from ".$EmmausTable."
       where ".$this_logic."
    order by info_added_on
     ";
RunAndShowIt($database_connection,$sql);
exit;
$sql="update ".$EmmausTable."
         set info_removed_on=NULL
             ,info_removed_by=NULL
       where ".$this_logic."
     ";
RunAndShowIt($database_connection,$sql);
exit;

$sql="update ".$EmmausTable."
         set info_time='01-29-2023'
       where ".$this_logic."
     ";
//RunAndShowIt($database_connection,$sql);

$sql="delete from ".$EmmausTable."
            where ".$this_logic."
       ";
//RunAndShowIt($database_connection,$sql);


echo '</section>';

include('wwwsitefooter.php');
?>