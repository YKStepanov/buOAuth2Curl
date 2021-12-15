<?php
require_once 'buOAuth2Curl.class.php';

// INSTALL CORRECTLY redirect_uri ON THIS PAGE !!!
$arrGoogleApiID = [
    'client_id' =>
    '193187803346-vgmluu3rh3ujel8esqnc42dvfs2l5ets.apps.googleusercontent.com',
    'client_secret' => 'zDpy0vGRdrn_snhuGBCENYZB',
    'redirect_uri' => 'http://localhost/redirect.php?target=http://buOAuth2Curl/download.php',
    'scope' => 'email profile https://www.googleapis.com/auth/photoslibrary',

];

session_start();

$gApi = new buOAuth2Curl\buGooglePhoto( $arrGoogleApiID );

if( $gApi->checkAuth() ){// authorized let's get to work

    $userInfo = $gApi->getUserInfo();
    echo '<pre>'; print_r($userInfo); echo '</pre>';
    echo "Hello, authorization successful! Start download! <br> ";
    flush();

    set_time_limit(0); // отключаем лемит времени
    while( $res =   $gApi->getItems( ) ){
        foreach ($res as $key => $item) {
            $path = $gApi->downloadItem( $item, 'D:\TMP'  );
            if( file_exists($path) ){
                echo "Download OK!  = $path<br>";
                echo '<pre>'; print_r($item); echo '</pre>';
            }
            else{
                echo "<span style='color: #CC0000'>Download Error!</span>  = $path<br>";
                echo '<pre>'; print_r($gApi->msg); echo '</pre>';
            }
            flush();
             // check the connection if the connection is broken, exit
            if ( connection_status()!=0 ) break 2;
        }
        if( !$gApi->checkAuth() ) break;


    }
} else { // NO authorization displaying a link to the server
    $urlAuth = $gApi->getLinkAuth();
    echo '<pre>'; print_r($urlAuth); echo '</pre>';
}