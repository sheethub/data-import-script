<?php

include('DataGovTw.php');

$d = new DataGovTw;
if ($argv = $_SERVER['argv'][1]) {
    if (filter_var($argv, FILTER_VALIDATE_URL)) { // 網址就直接抓下來傳傳看
        $portal_meta = $d->getMetaFromPortal($argv);
        $config = array('source' => $argv);
        if ($_SERVER['argv'][2]) {
            $config['meta_only'] = true;
        }
        $config['force'] = true;
        $d->updateOrInsert(str_replace('/', '_', $portal_meta['title']), $config);
    } else {
        $d->updateOrInsert($argv, array());
    }
} else {
    $url = 'https://sheethub.com/data.gov.tw/?format=json';
    while ($url) {
        $sheets = json_decode(file_get_contents($url));
        foreach ($sheets->data as $sheet_info) {
            if ($sheet_info->meta->update_code != 'https://github.com/sheethub/data-import-script/blob/master/data.gov.tw/update.php') {
                continue;
            }
            $d->updateOrInsert($sheet_info->name, array('sheet_info' => $sheet_info));
        }
        $url = $sheets->next_url;
    }
}
