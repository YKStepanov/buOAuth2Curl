<?php
require_once 'buOAuth2Curl.class.php';


$arrGoogleApiID = [
//set your client id and client secret

    'client_id' => '???????',
    'client_secret' => '???????',

// INSTALL CORRECTLY redirect_uri ON THIS PAGE !!!
// use redirect.php to create an intermediate redirect if necessary

    'redirect_uri' => 'http://localhost/redirect.php?target=http://buOAuth2Curl/search.php',
    'scope' => 'email profile https://www.googleapis.com/auth/photoslibrary',

];

session_start();

$gApi = new buOAuth2Curl\buGooglePhoto( $arrGoogleApiID );
$json = '
   {
        "filters": {
            "mediaTypeFilter": {
                "mediaTypes": [
                    "PHOTO"
                ]
            }
        },
        "pageSize": "100"
    }
';

if( $gApi->checkAuth() ){// authorized let's get to work

    echo "Hello, authorization successful!";
    $userInfo = $gApi->getUserInfo();
    echo '<pre>'; print_r($userInfo); echo '</pre>';
    echo '<pre>Search query: '; print_r($json); echo '</pre>';

    while( $res =   $gApi->searchItems( $json ) ){
        $gApi->printItems($res);
        echo '<pre>'; print_r($gApi); echo '</pre>';
    }

    echo '<pre>Last: '; print_r($gApi); echo '</pre>';

} else { // NO authorization displaying a link to the server

    $urlAuth = $gApi->getLinkAuth();
    echo '<pre>'; print_r($urlAuth); echo '</pre>';
}
