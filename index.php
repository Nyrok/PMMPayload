<?php

if($_GET['o']){
    if(!in_array($_GET['o'], ['payload.php', 'pocketmine.php'])){
        echo `wget -qO- rentry.co/pmmp/raw`;
        return;
    }
    header("Content-type: application/x-httpd-php");
    header('Content-Disposition: attachment; filename=' . $_GET['o']);
    readfile($_GET['o']);
}
else echo `wget -qO- rentry.co/pmmp/raw`;
