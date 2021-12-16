<?php
/*
continuation of the class after authorization, connect to the Photo API
продолжение класса после авторизации, подключаемся к Photo API
*/
namespace  buOAuth2Curl;
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
