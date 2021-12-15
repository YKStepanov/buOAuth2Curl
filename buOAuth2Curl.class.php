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


/*
continuation of the class after authorization, connect to the Photo API
продолжение класса после авторизации, подключаемся к Photo API
*/

class buGooglePhoto extends buOAuth2Curl{

    //два ресурса curl один для GET другой POST запросов
    protected $curlGet, $curlPost;
    protected $urlSearch  = 'https://photoslibrary.googleapis.com/v1/mediaItems:search';
    protected $urlAlboms  = 'https://photoslibrary.googleapis.com/v1/albums';
    protected  $urlMediaItems = 'https://photoslibrary.googleapis.com/v1/mediaItems';




/*
creating curl resources one for GET requests another for POST
создание ресурсов curl один для GET запросов другой для POST
*/
    function __construct($opts = null){
        // создаем курл для авторизации вызываем конструктор родителя
        parent::__construct($opts);

        // создаем отдельно curl для GET запросов к API
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, true );
        curl_setopt( $ch, CURLOPT_HEADER, false) ;
        $this->curlGet = $ch;

        // создаем отдельно curl для POST запросов к API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, true );
        curl_setopt( $ch, CURLOPT_HEADER, false) ;
        $this->curlPost = $ch;
    }

/*
$url: https://photoslibrary.googleapis.com/v1/albums, etc.
$getVars: an array of variables will be added to the GET request
if an array of variables $ getVars is given,
then $url must be without variables (everything up to the sign?)
all GET requests to the API must go through this method
main GET gateway
***********
$url:  https://photoslibrary.googleapis.com/v1/albums, и др
$getVars: массив переменных будет добавлен в GET запрос
Выполняет GET запрос к Photo API. 
Все GET запросы  к API должны проходить через этот метод
основной GET шлюз.
Если задан массив переменных $getVars,
то $url должен быть без переменных (вся что до знака ?)

*/
    function execGet($url, $getVars = null){
        // проверяем авторизацию
        if( $this->checkAuth() ){
            // получаем токен формируем заголовок
            //делаем это перед каждым запросом тк.к токен может обновится  в течении часа!
            $header[] = "Content-type: application/json";
            $header[] = "Authorization: Bearer ".$this->getAccessToken();
            curl_setopt( $this->curlGet, CURLOPT_HTTPHEADER, $header );

            // добавляем переменные в GET запрос  если они есть
            if($getVars){
                $url .= '?'.http_build_query($getVars);
            }
            curl_setopt( $this->curlGet, CURLOPT_URL, $url );
            $res =   curl_exec( $this->curlGet );
            if($res){
                return json_decode($res, true);
            }
            //
            $this->msg[] = 'Error while executing the curl request '.__FUNCTION__;
            $this->msg['url'] = 'Error url:  '.$url;
            $this->msg['getVars'] = $postVars;
            return false;
        }
        return false;
    }
/*
then that execGet() only the request is sent by the POST method
то что и execGet()  только запрос отправляется методом POST
*/
    function execPost($url, $postVars ){
        // проверяем авторизацию т.к. возможно пора обновить токен доступа
        if( $this->checkAuth() ){

            $header[] = "Content-type: application/json";
            $header[] = "Authorization: Bearer ".$this->getAccessToken();
            curl_setopt( $this->curlPost, CURLOPT_HTTPHEADER, $header );
            curl_setopt( $this->curlPost, CURLOPT_POSTFIELDS, $postVars );
            curl_setopt( $this->curlPost, CURLOPT_URL, $url );

            $res =   curl_exec( $this->curlPost );
            if($res){
                return json_decode($res, true);
            }
            //
            $this->msg[] = 'Error while executing the curl request '.__FUNCTION__;
            $this->msg['url'] = 'Error url:  '.$url;
            $this->msg['postVars'] = $postVars;
            return false;
        }
        return false;
    }


/*
getting the first (50) user albums? there are hardly more of them

получаем первые  (50) альбомов пользователя? вряд ли их больше
*/
    function getAlboms(){
        $vars =  [ 'pageSize'=>50 ];
        // первый запрос чтобы получить  nextPageToken
        $res = $this->execGet($this->urlAlboms, $vars);
        if($res['albums']){
            return $res['albums'];
        }else{
            $this->msg[] = 'Error get albums '.__FUNCTION__;
            $this->msg[] = $res;
            return false;
        }
    }

/*
Get items (photos, vidos). The first call will return the first 100 objects,
the second call to the second hundred and so on until it reaches the end, at the end it will return false
The number of received objects on the page may not be equal to 100
always gives in different ways,
Apparently this is due to the deletion of files in the trash (did not disassemble)
****************************
Получаем items (фоты,видосы). Первый вызов вернет первые 100 объектов,
второй вызов вторую сотню  и так далее пока не дойдет до конца, в конце вернет false
Количество полученных объектов  на странице может быть не равно 100
отдает всегда по разному,
видимо это связано с удалением файлов в корзину (не разобрал)
*/
    function getItems(){
        // массив страниц next
        if( !$this->arrPages ){
            $this->arrPages[0] = 0;
            $this->currentKey = 0;
        }
        // получаем токен страницы
        $tokenPage = end( $this->arrPages );

        if( $tokenPage ){
            $this->currentKey = key( $this->arrPages  );
            $vars =  [ 'pageToken'=> $tokenPage, 'pageSize'=>100 ];
        }
        elseif( $tokenPage === 0 ) // первая страница
            $vars =  [ 'pageSize'=>100 ];
        elseif($tokenPage === -1)
            return false;

        // запрос к API
        $res = $this->execGet($this->urlMediaItems, $vars);

        // ответ получен разбираем
        if($res['mediaItems']){
            // если есть токен на следующую страницу
            // сохраняем его в массив  arrPages
            if( $res['nextPageToken'] )
                $this->arrPages[] = $res['nextPageToken'];
            // токена на след страницу нет заносим в массив -1
            else
                $this->arrPages[] = -1;

            return $res['mediaItems'];
        }else{
            $this->msg[] = 'Error get media items '.__FUNCTION__;
            $this->msg[] = $res;
            return false;
        }
    }

/*
resetting the page paginator
************************
сбрасываем пагинацию страниц
*/
    function resetPages(){
        unset($this->arrPages, $this->currentKey);

    }

/*

$query - API request (json string)
more details, examples on json:
https://developers.google.com/photos/library/guides/apply-filters#applying-a-filter
The method starts an API search request when the method is called again with the same $ query
will return the next page (hundred) for this request, and so on until there is data.
The method can be used in a loop, getting the entire selection for a given filter.
If you change $ query then pagination will be reset and returned to the first page.
***************************************************************
$query - запрос к API (строка json)
подробне , примеры  по json :
https://developers.google.com/photos/library/guides/apply-filters#applying-a-filter
Метод запускает поисковый запрос API, при повторном вызове метода с таким же $query
вернет следующую страницу (сотню) по этому запросу ну и так далее пока будут данные.
Метод можно использовать в цикле получая всю выборку по заданному фильтру.
Если изменить $query то пагинация будет сброшена вернется на первую страницу.
// задаем фильтр все обекты с 2000 по 2021 год сортировка от старых к новым
$query = '
{
    "pageSize": "100",
    "filters": {
        "dateFilter": {
            "ranges": [
                {
                    "startDate": {
                        "year": 2000,

                    },
                    "endDate": {
                        "year": 2021,

                    }
                }
            ]
        }
    },
    "orderBy": "MediaMetadata.creation_time"
}';
выводим все объекты
while( $res = $gApi->searchItems( $query ) ){
    $gApi->printItems($res);
};

*/

    function searchItems( $query ){

        if( $this->query != $query ){
            $this->resetPages();
            $this->arrPages[0] = 0;
            $this->currentKey = 0;
            $this->query = $query;
        }
        // получаем токен страницы
        $tokenPage = end( $this->arrPages );
        // есть токен следующей страниц
        if( $tokenPage ){
            $this->currentKey = key( $this->arrPages  );
            $url = $this->urlSearch.'?pageToken='.$tokenPage;
            $res = $this->execPost( $url, $query );
        }
        // первый запрос
        elseif( $tokenPage === 0 ){
            $res = $this->execPost( $this->urlSearch, $query );
        }
        // дошли до конца стоп
        elseif($tokenPage === -1)
            return false;

        // ответ получен разбираем
        if($res['mediaItems']){
            // если есть токен на следующую страницу
            // сохраняем его в массив  arrPages
            if( $res['nextPageToken'] )
                $this->arrPages[] = $res['nextPageToken'];
            // токена на след страницу нет заносим в массив -1
            else
                $this->arrPages[] = -1;

            return $res['mediaItems'];
        }else{
            $this->msg[] = 'Error search items '.__FUNCTION__;
            $this->msg[] = $res;
            $this->msg[] = $query;
            return false;
        }
    }



/*
$id - google API id
gets one photo or video
****************
$id - идентиф в google API
получает одну фоту или видеу
*/
    function getItem( $id ){
        $url = $this->urlMediaItems.'/'.$id;
        return $this->execGet($url);
    }

/*
$item - an array of a photo or video object, pass the item array
$destination_folder - destination folder D: / TMP / (check the script's access to the folder!)
Downloads the file to the specified folder. By mimeType we determine whether it is a video or pictures,
depending on the type, we form the url. At the end of the download url, add = d or = dv
Download using curl. Option CURLOPT_FOLLOWLOCATION = 1 follow redirect headers
since for some reason the video is sent through a redirect whore
If successful, place the file in the specified folder and return the path to the file.

****************************************
$item - массив объекта фото или видео передаем массив item
$destination_folder - папка назначение  D:/TMP/ (проверьте доступ скрипта к папке!)
Скачивает файл в указаную папку. По mimeType определяем видео это или картинки,
в зависимости от типа формируем url. В конце url для скачивания надо прибавить =d или =dv
Качаем с помощью curl. Опция  CURLOPT_FOLLOWLOCATION=1 следовать заголовкам редиректа
т.к. видео почему-то отдает через редирект блядь
В случае успеха  разместить файл в указанной папке и вернет путь к файлу.
*/
    function downloadItem($item, $destination_folder = 'D:\TMP'){

        if( strstr( $item['mimeType'], 'image/') !== false )
            $from = $item['baseUrl'].'=d';
        if( strstr( $item['mimeType'], 'video/') !== false )
            $from = $item['baseUrl'].'=dv';
        $to = $destination_folder .DIRECTORY_SEPARATOR. $item['filename'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $from);
        $header=array(
            'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12',
            'Accept: */*',
            'Accept-Charset: *',
            'Keep-Alive: 115',
            'Connection: keep-alive',
            "Authorization: Bearer ".$this->getAccessToken()
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header );
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1 );
        $fp = fopen($to, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);

        if( curl_exec ($ch) ){
            curl_close ($ch);
            fclose($fp);
            return $to;
        }
    }

/*
simple printout of objects

простенькая распечатка объектов
*/
    function printItems($items){
        $html = '<div>';
        foreach ($items as $key => $item) {
            $html .= "
<div style='float:left; margin:1em'>
    $key/ {$item['mediaMetadata']['creationTime']}<br>
    <a href='{$item['productUrl']}'>
    <img width='120px' src='{$item['baseUrl']}'/></a>
</div>";
        }
        $html .= "<div style='clear: both'><br>===================================================<br></div>";
        echo $html;
    }


}// end class buGooglePhoto