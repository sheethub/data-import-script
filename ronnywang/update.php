<?php

include(__DIR__  . '/../SheetHubTool.php');

$list = array(
    '1998開始歷年每日登革熱確定病例統計' => array('period' => 3600 * 6),
);

foreach ($list as $type => $config) {
    $sheet_info = SheetHubTool::getSheetHubInfo('ronnywang', $type)->sheet;
    $config['period'] = array_key_exists('period', $config) ? $config['period'] : 86400;
    if (time() - strtotime($sheet_info->meta->fetched_time) < $config['period']) {
        continue;
    }
    $config['source'] = $sheet_info->meta->source;

    if (!$config['source']) {
        error_log("找不到更新網址");
        continue;
    }
    list($fp, $filetype) = SheetHubTool::downloadFile($config['source'], $sheet_info->meta->file_type);

    $file = stream_get_meta_data($fp)['uri'];
    $md5 = md5_file($file);
    if ($sheet_info and $sheet_info->meta->file_hash and $md5 == $sheet_info->meta->file_hash) {
        error_log("md5 same, skip {$type}");
        SheetHubTool::setMeta('ronnywang', $type, array('fetched_time' => date('c', time())));
        continue;
    }

    $upload_id = SheetHubTool::uploadToSheetHub($fp, $filetype);
    $ret = SheetHubTool::updateFile('ronnywang', $type, $upload_id, $config);
    $result = " insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete);
    error_log("Type={$type} done, insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete));

    $new_meta = $sheet_info->meta;
    $new_meta->fetched_time = date('c', time());
    $new_meta->file_hash = $md5;
    SheetHubTool::setMeta('ronnywang', $type, $new_meta);
}
