<?php
/*
buOAuth2Curl - OAuth2 authorization class
tested and configured on Google Photo api
Version 1.0.0

buOAuth2Curl - OAuth2 authorization class tested on google
buGooglePhoto - access to google photo API
no additional libraries, nothing more!

the authorization process is broken down into steps
1) the user is not authorized, we give him an authorization link
2) the user gave the go-ahead on the server and returned with a time code
3) change the temporary code to access tokens
4) the user is authorized

If there is less than updateTime left until the end of the token's life (15 minutes)
the token is automatically updated.
If you have started a long process of 30 minutes or more, then in the middle of the process
you must periodically call $ obj-> checkAuth () to update the token.

********************************** RU

buOAuth2Curl - класс авторизации по протоколу OAuth2 испытан на google
buGooglePhoto - доступ к google photo API
без дополнительных библиотек, ничего лишнего!

процесс авторизации разбит на шаги
1) пользователь не авторизован,  выдаем ему ссылку на авторизацию
2) пользователь дал добро на сервере и вернулся с временным кодом
3) меняем временный код на токены доступа
4) пользователь авторизован

Если до конца жизни токена осталось менее updateTime (15 минут)
автоматически происходит обновление токена.
Если у вас запущен долгий процесс 30 минут и более то в середине процесса
надо периодически вызывать $obj->checkAuth() , чтобы произошло обновление токена.

redirect_uri = urn:ietf:wg:oauth:2.0:oob  -способ без редиректа, код скопировать и вставить вручную
redirect_uri = urn:ietf:wg:oauth:2.0:oob:auto    -способ автоматическое извлечение ХЗ

*/
namespace  buOAuth2Curl;
class buOAuth2Curl{
     var $arrOpt = array(
        'client_id' =>  '',
        'client_secret' => '',
        'redirect_uri' => '', // страница редиректа
        'state' => 'auth_ok',
        'scope' => 'email profile', // запрашиваемый список разрешений
        'grant_type' => 'authorization_code', // хер его знает

    // начальная страница авторизации
        'authUrl' =>  'https://accounts.google.com/o/oauth2/auth',

    // страница обмена и обновлдения токенов token_url
        'tokenUrl' => 'https://accounts.google.com/o/oauth2/token',
        'linkText' => 'Auth in Server Api',  // текст с ссылке на сервер авторизации
        'nameSave' => '', // имя опций в БД
        // время до конца действия токена в секундах когда надо обновлять токен
        'updateTime' => 900
    );
    // массив ошибок и сообщений
    var $msg = [];
    protected $curlAuth;    // curl resurce

/*
$opts - an array of options and settings
creating curl resource
method of sending POST

$opts - массив опци и настроек
создание ресурса curl
метод отправки POST
*/
    function __construct($opts = null){
        if($opts)
            $this->arrOpt = array_merge($this->arrOpt, $opts);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $this->curlAuth = $ch;
    }

/*
$url - where to send the request
$postVars - array of variables
the method sends requests to the server
unpacks the response from json will return it in a regular array
******************************************
$url  - куда посылать запрос
$postVars - массив переменных
метод посылает запросы на сервер
распаковывает ответ из json вернет  в обычном  массиве
*/
    protected function execAuth($url, $postVars = null){
        curl_setopt($this->curlAuth, CURLOPT_URL, $url);
        curl_setopt($this->curlAuth, CURLOPT_POSTFIELDS, $postVars );

        $res = curl_exec($this->curlAuth);
        if($res){
            return json_decode($res, true);
        }
        $this->msg[] = 'Error in execAuth request '.__FUNCTION__;
        return false;
    }

/*

The main method of authorization and authorization verification.
Returns ture if the user is already logged in and false is not logged in.
Checks a valid token by its refresh time,
if there is an updateTime min before the end of the period, the token is updated.
**************************************
Основной метод авторизации и проверки авторизации.
Вернет ture если пользователь уже авторизован и false - не авторизован.
Проверяет действующий токен по времени обновления,
если осталось updateTime мин до конца срока обновляет токен.
*/
    function checkAuth(){
/* 4) the token has already been received, we check its validity period,
it may need to be renewed
***************
токен уже получен проверяем его срок действия может надо обновить */
        $arrToken = $this->getArrToken();
        if( $arrToken['access_token'] ){
            // проверяем что токен еще не просрочен
            if( $arrToken['timeEnd'] > time() ){
                //it may be necessary to update the token if it is in the last 15 minutes
                // проверяем возможно надо обновить токен если это последние 15 минут
                if( ($arrToken['timeEnd'] - time() ) < $this->arrOpt['updateTime'] )
                   return  $this->refreshToken();

                // обновлять не надо все ок работаем
                return true;
            }
            else{ // время токена истекло
                $this->unsetArrToken(); // сбрасываем токен
                $this->msg[] = "Token expired ".__Function__;
                return false;
            }

        }
        elseif( $_REQUEST['code'] ){
/* step 2)
the user gave the go-ahead on the server page it is sent back with the code
which must be exchanged for a token,
change the code to an access token and a refresh token
***********
пользователь дал добро на странице сервера его отправляют назад с кодом
который надо обменять на токен, меняем code на токен доступа и токен обновления
*/
            return $this->codeChangeToken( $_REQUEST['code'] );
        }
        // если токена и кода нет и требуется авторизация вернем false
        $this->msg[] = "Authorization required ".__Function__;
        return false;
    }


/*
the method updates the access_token
saves a new token
will return false or true
**********************
метод обновляет токен доступа access_token
сохраняет новый токен
вернет false или true
*/
    function refreshToken(){
        // получаем старые доступы   и берем токен обновления refresh_token
        $arrOldToken = $this->getArrToken();

        if(!$arrOldToken['refresh_token']) {
            $this->error[] =  "No refresh_token ".__Function__;
            return false;
        }

        // формируем параметры запроса для обновления токена
        $params = array(
            "refresh_token" => $arrOldToken['refresh_token'],
            "client_id" => $this->arrOpt['client_id'],
            "client_secret" => $this->arrOpt['client_secret'],
            "grant_type" => "refresh_token"
        );

        // запрос на сервер
        $arrToken = $this->execAuth($this->arrOpt['tokenUrl'], $params);


        // если новый токен получе
        if($arrToken['access_token']){
            $arrToken['timeEnd'] = time() + $arrToken['expires_in'];
            // сохраняем новый токен
            $this->setArrToken($arrToken);

            return true;
        }else{
            // если не получилось запишем ошибку вернем false
            $this->msg[] = "Refresh token fail ".__Function__;
            return false;
        }

    }
/* step 3)
change temporary code to access token
меняем временный code на токен доступа
*/
    function codeChangeToken( $code ){

        // готовим параметры обменять код на токен
        $params = array(
            'code' => $code,
            'client_id' => $this->arrOpt['client_id'],
            'client_secret' => $this->arrOpt['client_secret'],
            'redirect_uri' => $this->arrOpt['redirect_uri'],
            'grant_type' => $this->arrOpt['grant_type']
        );

        // выполняем запрос
        $arrToken = $this->execAuth($this->arrOpt['tokenUrl'], $params);
        // если все ок
        if($arrToken['access_token']){
             // устанавливаем время окончания токена минус
            $arrToken['timeEnd'] = time() + $arrToken['expires_in'];
            $this->setArrToken($arrToken);
            return true;
        }else{
            // ошибку запишем в масив и вернем false
            $this->msg[] = "Token not recived ".__Function__;
            $this->msg[] = $arrToken;
            return false;
        }
    }

/* step 1)
user is not authorized there are no tokens or they are expired
this method will return url to authorization server
***************
пользователь не авторизован токенов нет или они просрочены
этот метод вернет URL на сервер авторизации
*/
    function getUrlAuth(){
        // Build URL
        $params  =  [
            'client_id' => $this->arrOpt['client_id'],
            'redirect_uri' => $this->arrOpt['redirect_uri'],
            'state' => $this->arrOpt['stat'],
            'response_type' => 'code',
            'scope' => $this->arrOpt['scope'],
        ];
        return $this->arrOpt['authUrl'].'?'.http_build_query($params);
    }

/*
this method will return a link to the authorization server
*********************
этот метод вернет ссылку на сервер авторизации
*/
    function getLinkAuth(){
        $authUrl = $this->getUrlAuth();
        $linkText = $this->arrOpt['linkText'];
        return  "<a href='$authUrl'>$linkText</a>";
    }


/*
gets an array where data token and other data are stored
**********
получает массив где хранятся данные токен и другие данные
*/
    function getArrToken(){
        if( $_SESSION['arrToken'] )
            return $_SESSION['arrToken'];
        else return false;
    }
/*
saves array token and other data to session
****************
сохраняет массив токен и другие данные в сессию
*/
    function setArrToken($arr){

        if($arr){
            $_SESSION['arrToken'] = $arr;
        }
    }

/*
unset saved tokens
сбрасывает сохраненые токены
*/
    function unsetArrToken(){
        unset( $_SESSION['arrToken'] );
    }

/*
return access_token
вернет access_token  
*/
    function getAccessToken(){
        return $_SESSION['arrToken']['access_token'];
    }


/*
short bonus gets info about user email id ....
короткая бонус получает инфу про пользователя емаил id ....
*/
    function getUserInfo(){
        $url = 'https://www.googleapis.com/oauth2/v1/userinfo';
        $data = $this->getArrToken();
        $params = array(
            'access_token' => $data['access_token'],
            'id_token'     => $data['id_token'],
            'token_type'   => 'Bearer',
        );
        $url .= '?'.urldecode(http_build_query($params));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $res = curl_exec( $ch );
        curl_close($ch);
        if( $res ){
            return json_decode( $res, true );
        }
        $this->msg[] = 'Error in get Info User request '.__FUNCTION__;
        return false;

    }

} // end class buOAuth2Curl


