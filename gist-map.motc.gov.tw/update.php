<?php
include('SheetHubTool.php');
$curl = curl_init('https://gist-map.motc.gov.tw/Complex/MapTopic');
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
// wget http://grca.nat.gov.tw/repository/Certs/GRCA2.cer
// openssl x509 -inform DER -in GRCA2.cer -out GRCA2.crt
curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/GRCA2.crt');
$content = curl_exec($curl);
$info = curl_getinfo($curl);

$doc = new DOMDocument;
@$doc->loadHTML($content);
$tr_doms = $doc->getElementsByTagName('tr');
for ($i = 1; $i < $tr_doms->length; $i++) {
    $td_doms = $tr_doms->item($i)->getElementsByTagName('td');
    $title = $td_doms->item(0)->nodeValue;
    if ($title == '國家風景區範圍') {
        continue;
    }
    $description = $td_doms->item(3)->nodeValue;
    if (!$description){
        continue;
    }

    $curl = curl_init("https://gist-map.motc.gov.tw". $td_doms->item(5)->getElementsByTagName('a')->item(0)->getAttribute('href'));
    curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/GRCA2.crt');
    $fp = tmpfile();
    curl_setopt($curl, CURLOPT_FILE, $fp);
    curl_exec($curl);
    curl_close($curl);

    $upload_id = SheetHubTool::uploadToSheetHub($fp, 'zip');
    $new_file = false;
    try {
        $sheet_info = SheetHubTool::getSheetHubInfo('gist-map.motc.gov.tw', $title)->sheet;
    } catch (Exception $e) {
        $sheet_info = SheetHubTool::newFile('gist-map.motc.gov.tw', $title, $upload_id, array())->sheet;
        $new_file = true;
    }
    if (!$new_file) {
        $ret = SheetHubTool::updateFile('gist-map.motc.gov.tw', $title, $upload_id, array());
    }
    SheetHubTool::setDescription('gist-map.motc.gov.tw', $title, $description);

    $file = stream_get_meta_data($fp)['uri'];
    $md5 = md5_file($file);
    $new_meta = $sheet_info->meta;
    $new_meta->file_hash = $md5;
    $new_meta->update_code = 'https://github.com/sheethub/data-import-script/blob/master/gist-map.motc.gov.tw/update.php';
    $new_meta->source = 'https://gist-map.motc.gov.tw/Complex/MapTopic';
    $new_meta->fetched_time = date('c', time());
    SheetHubTool::setMeta('gist-map.motc.gov.tw', $title, $new_meta);
}
