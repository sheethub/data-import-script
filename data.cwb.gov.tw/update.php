<?php

include(__DIR__ . '/CWB.php');
include(__DIR__ . '/SheetHubTool.php');

// 抓 30 天內每小時資料, 因為每日 11:30 才會新增昨天資料，因此等到中午十二點再抓新的
$sheet_info = SheetHubTool::getSheetHubInfo('opendata.cwb.gov.tw', '測站觀測資料');
$data_time = strtotime($sheet_info->sheet->meta->updated_time);
for ($data_time += 86400; $data_time < time() - 36 * 3600; $data_time += 86400) {
    $fp = tmpfile();
    $column_added = false;
    foreach (CWB::getStops() as $stop_id => $stop_name) {
        list($columns, $values) = CWB::getInfoByStopAndTime($stop_id, $stop_name, date('Ymd', $data_time));
        if (!$column_added) {
            fputcsv($fp, $columns);
            $column_added = true;
        }
        foreach ($values as $v) {
            fputcsv($fp, $v);
        }
    }
    $upload_id = SheetHubTool::uploadToSheetHub($fp, 'csv');
    $ret = SheetHubTool::updateFile('opendata.cwb.gov.tw', '測站觀測資料', $upload_id);
    $new_meta = $sheet_info->sheet->meta;
    $new_meta->updated_time = date('Y/m/d', $data_time);
    SheetHubTool::setMeta('opendata.cwb.gov.tw', '測站觀測資料', $new_meta);
}

// 每分鐘抓當下 15 分鐘資料
list($columns, $values) = CWB::getAll15mInfo();
$fp = tmpfile();
fputcsv($fp, $columns);
foreach ($values as $v) {
    fputcsv($fp, $v);
}
$upload_id = SheetHubTool::uploadToSheetHub($fp, 'csv');

$sheet = '測站觀測資料_15分鐘_' . date('Y_m');
try {
    $sheet_info = SheetHubTool::getSheetHubInfo('opendata.cwb.gov.tw', $sheet);
    $ret = SheetHubTool::updateFile('opendata.cwb.gov.tw', $sheet, $upload_id);
    error_log("done, insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete));
} catch (Exception $e) {
    SheetHubTool::newFile('opendata.cwb.gov.tw', $sheet, $upload_id, array());
    SheetHubTool::setURIColumns('opendata.cwb.gov.tw', $sheet, array(0, 2));
}
SheetHubTool::setMeta('opendata.cwb.gov.tw', $sheet, array(
    'fetched_time' => date('Y/m/d H:i:s'),
    'period' => '15分鐘',
    'source' => 'http://www.cwb.gov.tw/V7/observe/real/ALLData.htm',
    'update_code' => 'https://github.com/sheethub/data-import-script/blob/master/opendata.cwb.gov.tw/update.php',
));
