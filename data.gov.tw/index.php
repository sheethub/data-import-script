<?php

function error($message) {
    echo "<html><script>alert(" . json_encode($message) . "); document.location=document.location;</script></html>";
    exit;
}

if ($_POST['url']) {
    if (!filter_var($_POST['url'], FILTER_VALIDATE_URL)) {
        error("不是合法的網址");
        exit;
    }
    include(__DIR__ . '/DataGovTw.php');
    $d = new DataGovTw;

    try {
        $portal_meta = $d->getMetaFromPortal($_POST['url']);
        $config = array('source' => $_POST['url'], 'period' => 0);
        $m = $d->updateOrInsert(str_replace('/', '_', $portal_meta['title']), $config);
    } catch (Exception $e) {
        error("匯入失敗，原因: " . $e->getMessage());
        exit;
    }

    error("匯入完成，" . $m);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>data.gov.tw importer</title>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.min.js"></script>
<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/2.2.2/css/bootstrap.css">
</head>
<body>
<div class="container">
    <form method="post">
        請輸入 data.gov.tw 網址: <input type="text" name="url">
        <button type="submit">匯入</button>
    </form>
</div>
</body>
</html> 
