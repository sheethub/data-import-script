<?php

// curl 'https://sheethub.com/ronnywang/中華民國鄉鎮市區?format=csv' > town.csv
// curl 'https://sheethub.com/ronnywang/中華民國村里?format=csv' > village.csv
// curl 'https://sheethub.com/ronnywang/中華民國縣市?format=csv' > county.csv

$fp = fopen('result.csv', 'w');
$fp_1984 = fopen('result1984.csv', 'w');
$fp_2010 = fopen('result2010.csv', 'w');
$fp_2014 = fopen('result2014.csv', 'w');

$columns = array('dgbas_id', 'county_id', 'town_id', 'segis_id', 'name', 'fullname', 'type', 'start_date', 'end_date', 'note');
fputcsv($fp, $columns);
fputcsv($fp_1984, array('name', 'dgbas_id'));
fputcsv($fp_2010, array('name', 'dgbas_id'));
fputcsv($fp_2014, array('name', 'dgbas_id'));

$input = fopen('county.csv', 'r');

$county_names = array('1984' => array(), '2010' => array(), '2014' => array());
$town_names = array('1984' => array(), '2010' => array(), '2014' => array());
$used = array('1984' => array(), '2010' => array(), '2014' => array());
$columns = fgetcsv($input);
while ($rows = fgetcsv($input)) {
    $values = array_combine($columns, $rows);
    foreach (array('COUNTYID', 'SEGIS_COUNTY_ID') as $k) {
        if ($values[$k][0] == '9') {
            $values[$k] = '0' . $values[$k];
        }
    }
    fputcsv($fp, array(
        $values['COUNTYID'],
        $values['COUNTYID'],
        0,
        $values['SEGIS_COUNTY_ID'],
        ($values['NAME_2014'] ?: $values['NAME_2010']) ?: $values['NAME_1984'],
        ($values['NAME_2014'] ?: $values['NAME_2010']) ?: $values['NAME_1984'],
        'county',
        0,
        0,
        0,
    ));

    foreach (array('1984', '2010', '2014') as $y) {
        foreach (explode("\n", $values['NAME_' . $y] . "\n" . $values['NAME_' . $y . '_ALIAS']) as $n) {
            if (!$n) continue;
            if (array_key_exists($n, $used[$y])) {
                if ($used[$y][$n] == $values['COUNTYID']) {
                    continue;
                }
                throw new Exception("$n 同時對應到 {$values['COUNTYID']} 和 {$used[$y][$n]}");
            } 
            $used[$y][$n] = $values['COUNTYID'];
            if (!array_key_exists($values['COUNTYID'], $county_names[$y])) {
                $county_names[$y][$values['COUNTYID']] = array();
            }
            $county_names[$y][$values['COUNTYID']][] = $n;
            fputcsv(${'fp_' . $y}, array($n, $values['COUNTYID']));
        }
    }
}

$input = fopen('town.csv', 'r');
$columns = fgetcsv($input);
$fp_town_out = fopen('map-town.csv', 'w');
$town_map = array();
fputcsv($fp_town_out, array('county_dgbas_id', 'name', 'town_dgbas_id'));
$unique = array('1984' => array(), '2010' => array(), '2014' => array());
$used = array('1984' => array(), '2010' => array(), '2014' => array());
while ($rows = fgetcsv($input)) {
    $values = array_combine($columns, $rows);
    foreach (array('TOWN_ID', 'COUNTY_ID', 'SEGIS_TOWN_ID') as $k) {
        if ($values[$k][0] == '9') {
            $values[$k] = '0' . $values[$k];
        }
    }
    fputcsv($fp, array(
        $values['TOWN_ID'],
        $values['COUNTY_ID'],
        $values['TOWN_ID'],
        $values['SEGIS_TOWN_ID'],
        $values['TOWN_NAME'],
        (($county_names['2014'][$values['COUNTY_ID']][0] ?: $county_names['2010'][$values['COUNTY_ID']][0]) ?: $county_names['1984'][$values['COUNTY_ID']][0]) . $values['TOWN_NAME'],
        'town',
        $values['START_DATE'],
        $values['END_DATE'],
        $values['NOTE'],
    ));

    foreach (explode("\n", $values['TOWN_NAME'] . "\n" . $values['TOWN_NAME_ALIAS']) as $n) {
        if (!$n) {
            continue;
        }
        $k = $values['COUNTY_ID'] . '-' . $n;
        if (array_key_exists($k, $town_map) and $town_map[$k] != $values['TOWN_ID']) {
            throw new Exception("duplicate {$k}");
        }
        $town_map[$k] = $values['TOWN_ID'];
        fputcsv($fp_town_out, array($values['COUNTY_ID'], $n, $values['TOWN_ID']));
    }

    foreach (array('1984', '2010', '2014') as $y) {
        if (!is_array($county_names[$y][$values['COUNTY_ID']])) {
            continue;
        }
        foreach ($county_names[$y][$values['COUNTY_ID']] as $c_name) {
            foreach (explode("\n", $values['TOWN_NAME'] . "\n" . $values['TOWN_NAME_ALIAS']) as $n) {
                if (!$n) continue;
                if (array_key_exists($c_name . $n, $used[$y])) {
                    if ($used[$y][$c_name . $n] == $values['TOWN_ID']) {
                        continue;
                    }
                    throw new Exception("$c_name . $n 同時對應到 {$values['TOWN_ID']} 和 {$used[$y][$c_name . $n]}");
                }
                if (array_key_exists($n, $unique[$y])) {
                    if (false !== $unique[$y][$n] and $unique[$y][$n] != $values['TOWN_ID']) {
                        $unique[$y][$n] = false;
                    }
                } else {
                    $unique[$y][$n] = $values['TOWN_ID'];
                }

                $used[$y][$c_name . $n] = $values['TOWN_ID'];
                fputcsv(${'fp_' . $y}, array($c_name . $n, $values['TOWN_ID']));
                $town_names[$y][$values['TOWN_ID']][] = $c_name . $n;
            }
        }
    }
}
foreach (array('1984', '2010', '2014') as $y) {
    foreach ($unique[$y] as $n => $id) {
        if ($id) {
            fputcsv(${'fp_' . $y}, array($n, $id));
            $town_names[$y][$values['TOWN_ID']][] = $n;
        }
    }
}

$input = fopen('village.csv', 'r');
$fp_village_out = fopen('map-village.csv', 'w');
fputcsv($fp_village_out, array('town_dgbas_id', 'name', 'village_dgbas_id'));
$columns = fgetcsv($input);
$unique = array('1984' => array(), '2010' => array(), '2014' => array());
$used = array('1984' => array(), '2010' => array(), '2014' => array());
$village_map = array();
while ($rows = fgetcsv($input)) {
    $values = array_combine($columns, $rows);
    foreach (array('VILLAGE_ID', 'COUNTY_ID', 'TOWN_ID', 'SEGIS_VILLAGE_ID') as $k) {
        if ($values[$k][0] == '9') {
            $values[$k] = '0' . $values[$k];
        }
    }
    fputcsv($fp, array(
        $values['VILLAGE_ID'],
        ($values['COUNTY_ID']),
        ($values['TOWN_ID']),
        $values['SEGIS_VILLAGE_ID'],
        $values['VILLAGE_NAME'],
        (($town_names['2014'][$values['TOWN_ID']][0] ?: $town_names['2010'][$values['TOWN_ID']][0]) ?: $town_names['1984'][$values['TOWN_ID']][0]) . $values['VILLAGE_NAME'],
        'village',
        $values['START_DATE'],
        $values['END_DATE'],
        $values['備註'],
    ));

    foreach (explode("\n", $values['VILLAGE_NAME'] . "\n" . $values['VILLAGE_NAME_ALIAS']) as $n) {
        if (!$n) {
            continue;
        }
        $k = $values['TOWN_ID'] . '-' . $n;
        if (array_key_exists($k, $village_map) and $village_map[$k] != $values['VILLAGE_ID']) {
            throw new Exception("duplicate {$k}");
        }
        $village_map[$k] = $values['VILLAGE_ID'];
        fputcsv($fp_village_out, array($values['TOWN_ID'], $n, $values['VILLAGE_ID']));

    }

    foreach (array('1984', '2010', '2014') as $y) {
        if (!is_array($town_names[$y][$values['TOWN_ID']])) {
            continue;
        }
        foreach ($town_names[$y][$values['TOWN_ID']] as $t_name) {
            foreach (explode("\n", $values['VILLAGE_NAME'] . "\n" . $values['VILLAGE_NAME_ALIAS']) as $n) {
                if (!$n) continue;
                if (array_key_exists($t_name . $n, $used[$y])) {
                    if ($used[$y][$t_name . $n] == $values['VILLAGE_ID']) {
                        continue;
                    }
                    throw new Exception("$t_name . $n 同時對應到 {$values['VILLAGE_ID']} 和 {$used[$y][$t_name . $n]}");
                }

                $used[$y][$t_name . $n] = $values['VILLAGE_ID'];
                fputcsv(${'fp_' . $y}, array($t_name . $n, $values['VILLAGE_ID']));
            }
        }
    }
}


include('SheetHubTool.php');
$id = SheetHubTool::uploadToSheetHub('result.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區', $id);
$id = SheetHubTool::uploadToSheetHub('result1984.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區_map_名稱1984', $id);
$id = SheetHubTool::uploadToSheetHub('result2010.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區_map_名稱2010', $id);
$id = SheetHubTool::uploadToSheetHub('result2014.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區_map_名稱2014', $id);
$id = SheetHubTool::uploadToSheetHub('map-village.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區_map_村里名稱', $id);
$id = SheetHubTool::uploadToSheetHub('map-town.csv', 'csv');
SheetHubTool::updateFile('area.reference.tw', '中華民國行政區_map_鄉鎮市區名稱', $id);
