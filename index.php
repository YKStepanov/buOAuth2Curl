<?php
require_once 'buOAuth2Curl.class.php';

// INSTALL CORRECTLY redirect_uri ON THIS PAGE !!!
$arrGoogleApiID = [
    'client_id' =>
    '193187803346-vgmluu3rh3ujel8esqnc42dvfs2l5ets.apps.googleusercontent.com',
    'client_secret' => 'zDpy0vGRdrn_snhuGBCENYZB',
    'redirect_uri' => 'http://localhost/redirect.php?target=http://buOAuth2Curl/index.php',
    'scope' => 'email profile https://www.googleapis.com/auth/photoslibrary',

];

session_start();

$gApi = new buOAuth2Curl\buGooglePhoto( $arrGoogleApiID );
if( $gApi->checkAuth() ){// authorized let's get to work

    echo "Hello, authorization successful!";
    $userInfo = $gApi->getUserInfo();
    echo '<pre>'; print_r($userInfo); echo '</pre>';
    echo "Other examples: <a href='search.php'>search.php</a>; <a href='download.php'>download.php all files</a>";

    while( $res =   $gApi->getItems() ){
        $gApi->printItems($res);
        echo '<pre>'; print_r($gApi); echo '</pre>';
    }

    echo '<pre>Last: '; print_r($gApi); echo '</pre>';

} else { // NO authorization displaying a link to the server

    $urlAuth = $gApi->getLinkAuth();
    echo '<pre>'; print_r($urlAuth); echo '</pre>';
}
