<?php

include(__DIR__  . '/../SheetHubTool.php');

$list = array(
    '土石流潛勢溪流圖層' => array('period' => 30 * 86400),
    '土壤圖' => array('period' => 30 * 86400),
    '山坡地範圍' => array('period' => 30 * 86400),
    '土石流潛勢溪流影響範圍圖層' => array('period' => 30 * 86400),
    '雨量站基本資料' => array('period' => 86400),
    '觀測站基本資料' => array('period' => 86400),
    '土石流紅黃警戒' => array('period' => 3600),
    '避難處所' => array('period' => 3600),
    '即時雨量' => array('period' => 600),
    '土石流警戒雨量' => array('period' => 3600),
    '醫療處所' => array('period' => 3600),
    '災例最速報' => array('period' => 3600),
    '觀測站影像資料' => array('period' => 3600),
    '災情查報單資料' => array('period' => 3600),
    '應變小組開設資料' => array('period' => 3600),
    '歷年土砂災害照片資料' => array('period' => 3600),
);

foreach ($list as $type => $config) {
    $sheet_info = SheetHubTool::getSheetHubInfo('246.swcb.gov.tw', $type)->sheet;
    $config['period'] = array_key_exists('period', $config) ? $config['period'] : 86400;
    if (time() - strtotime($sheet_info->meta->fetched_time) < $config['period']) {
        continue;
    }
    $config['source'] = $sheet_info->meta->source;

    if (property_exists($sheet_info->meta, 'tab_id')) {
        $config['tab_id'] = $sheet_info->meta->tab_id;
    }
    if (property_exists($sheet_info->meta, 'srs')) {
        $config['srs'] = $sheet_info->meta->srs;
    }
    if (property_exists($sheet_info->meta, 'encode')) {
        $config['encode'] = $sheet_info->meta->encode;
    } elseif (!array_key_exists('encode', $config)) {
        $config['encode'] = 'auto';
    }

    if (!$config['source']) {
        error_log("找不到更新網址");
        continue;
    }
    list($fp, $filetype) = SheetHubTool::downloadFile($config['source'], $sheet_info->meta->file_type);

    $file = stream_get_meta_data($fp)['uri'];
    $md5 = md5_file($file);
    if ($sheet_info and $sheet_info->meta->file_hash and $md5 == $sheet_info->meta->file_hash) {
        error_log("md5 same, skip {$type}");
        SheetHubTool::setMeta('246.swcb.gov.tw', $type, array('fetched_time' => date('c', time())));
        continue;
    }

    $upload_id = SheetHubTool::uploadToSheetHub($fp, $filetype);
    $ret = SheetHubTool::updateFile('246.swcb.gov.tw', $type, $upload_id, $config);
    $result = " insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete);
    error_log("Type={$type} done, insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete));

    $new_meta = $sheet_info->meta;
    $new_meta->fetched_time = date('c', time());
    $new_meta->file_hash = $md5;
    SheetHubTool::setMeta('246.swcb.gov.tw', $type, $new_meta);
}
