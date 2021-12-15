<?php
/*
intermediate redirect to domain
If you are using a local websrver like OpenServer
this file needs to be put in localhost and set up a redirect through it because
google allows you to specify only localhost in redirect_uri.
redirect_uri the redirect address must contain target - the final target for the redirect
like this:
'redirect_uri' => 'http: //localhost/redirect.php? target = http: //mydomen/search.php'

*******************************************
промежуточный редирект на домен
Если вы используете локальный websrver типа OpenServer
этот файл нужно положить в localhost и настраивать редирект через него тк.к
google позволяет указать в redirect_uri толкьо адрес localhost.
redirect_uri адресе редиректа должен быть прописан target - коенчная цель для редиректа
например так:
'redirect_uri' => 'http://localhost/redirect.php?target=http://mydomen/search.php'
*/
if( $_REQUEST['target'] ){
    $target = $_REQUEST['target'];
    unset($_REQUEST['target']);
    $query = http_build_query($_REQUEST);
    $url = $target.'?'.$query;
    header("Location: $url");
}else{
    echo "No target for redirect.";
    echo '<pre>$_REQUEST: '; print_r($_REQUEST); echo '</pre>';
}