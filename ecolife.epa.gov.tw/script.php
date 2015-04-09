<?php

// 資料來源是: https://github.com/ronnywang/ecolife.epa.gov.tw
// github 上的資料是完全從 ecolife.epa.gov.tw 無修改的匯出版
// 但是 ecolife.epa.gov.tw 上面資料本身就有兩個問題:
//   1. 2010 年以前臺北縣的 xx村 都被改名成 xx里 了(因為升格為新北市之後，連舊資料都變成里)
//   2. 臺北縣升格為新北市時，縣市代號也從
//   2010 高雄縣 10012 => 高雄市 64000
//   2010 臺北縣 10001 => 新北市 65000
//   2010 臺中市 10019 => 臺中市 66000
//   2010 臺中縣 10006 => 臺中市 66000 + 08
//   2010 臺南縣 10011 => 臺南市 67000
//   2010 臺南市 10021 => 臺南市 67000 + 31
//   2014 桃園縣 10003 => 桃園市 68000
//   3. 彰化縣埔鹽鄉瓦廍村 嘉義縣梅山鄉瑞双村 臺南縣西港鄉廍林村 三者皆不存在 ，目前推測是打錯字
//   正確分別應該是 埔鹽鄉瓦磘村, 梅山鄉瑞峯村, 西港鄉檨林村
class Importer
{
    protected $_pending_data = array();
    protected $_unique_column_id = array();

    public function insertData($sheet, $values, $unique_column_id)
    {
        if (!$this->_pending_data[$sheet]) {
            $this->_pending_data[$sheet] = array();
        }
        $this->_unique_column_id[$sheet] = $unique_column_id;

        $this->_pending_data[$sheet][] = $values;

        if (count($this->_pending_data[$sheet]) > 10000) {
            $this->commitData($sheet);
        }
    }

    public function commitData($sheet = null)
    {
        if (is_null($sheet)) {
            foreach (array_keys($this->_pending_data) as $sheet) {
                $this->commitData($sheet);
            }
            return;
        }

        if (!$this->_pending_data[$sheet]) {
            return;
        }
        error_log("commiting {$sheet} {$this->_pending_data[$sheet][0][0]}");

        $curl = curl_init("https://sheethub.com{$sheet}/insert?access_token=" . getenv('access_token'));
        $params = array();
        $params[] = 'data=' . urlencode(json_encode($this->_pending_data[$sheet], JSON_UNESCAPED_UNICODE));
        $params[] = 'unique_columns=' . urlencode(json_encode($this->_unique_column_id[$sheet]));

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $obj = json_decode($content);
        error_log($content);
        if (is_null($obj->data->count)) {
            error_log('count null, retry: ' . json_encode($obj));
            $this->commitData($sheet);
            return;
        }

        $this->_pending_data[$sheet] = array();
    }

    public function newSheet($sheet, $columns, $meta = array())
    {
        list($user, $sheet_name) = explode('/', trim($sheet, '/'));
        $curl = curl_init("https://sheethub.com/new/sheet?access_token=" . getenv('access_token'));
        $params = array();
        $params[] = 'name=' . urlencode($sheet_name);
        $params[] = 'sheetuser=' . urlencode($user);
        $params[] = 'cols=' . urlencode(json_encode($columns));
        $params[] = 'meta=' . urlencode(json_encode($meta));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $obj = json_decode($content);
        if ($obj->error) {
            //throw new Exception($obj->message);
        }
    }
}

function convertCountyId($old_county_id, $year)
{
//   2010 高雄縣 10012 => 高雄市 64000
//   2010 臺北縣 10001 => 新北市 65000
//   2010 臺中市 10019 => 臺中市 66000
//   2010 臺中縣 10006 => 臺中市 66000 + 08
//   2010 臺南縣 10011 => 臺南市 67000
//   2010 臺南市 10021 => 臺南市 67000 + 31
//   2014 桃園縣 10003 => 桃園市 68000
    $map = array();
    $map[2010] = array(
        10012 => 64000,
        10001 => 65000,
        10019 => 66000,
        10006 => 66000,
        10011 => 67000,
        10021 => 67000,
    );
    $map[2014] = array(
        10003 => 68000,
    );

    if ($year > 2014 and $map[2014][$old_county_id]) {
        return $map[2014][$old_county_id];
    }
    if ($year > 2010 and $map[2010][$old_county_id]) {
        return $map[2010][$old_county_id];
    }
    return $old_county_id;
}

function convertTownId($old_town_id, $year)
{
//   2010 高雄縣 10012 => 高雄市 64000
//   2010 臺北縣 10001 => 新北市 65000
//   2010 臺中市 10019 => 臺中市 66000
//   2010 臺中縣 10006 => 臺中市 66000 + 08
//   2010 臺南縣 10011 => 臺南市 67000
//   2010 臺南市 10021 => 臺南市 67000 + 31
//   2014 桃園縣 10003 => 桃園市 68000
    $map = array();
    $map[2010] = array(
        10012 => array(64, 11),
        10001 => array(65, 0),
        10019 => array(66, 0),
        10006 => array(66, 8),
        10011 => array(67, 0),
        10021 => array(67, 31),
    );
    $map[2014] = array(
        10003 => array(68, 0),
    );

    $old_county_id = substr($old_town_id, 0, 5);
    $town_id = substr($old_town_id, 5, 2);
    if ($year > 2014 and $map[2014][$old_county_id]) {
        return sprintf("%2d0%02d00", $map[2014][$old_county_id][0], $map[2014][$old_county_id][1] + $town_id);
    }
    if ($year > 2010 and $map[2010][$old_county_id]) {
        $tainan = array(
            1002104 => 6703400,
            1002106 => 6703500,
            1002107 => 6703600,
            1002108 => 6703700,
        );
        if ($tainan[$old_town_id]) {
            return $tainan[$old_town_id];
        }
        return sprintf("%2d0%02d00", $map[2010][$old_county_id][0], $map[2010][$old_county_id][1] + $town_id);
    }
    return $old_town_id;
}

// wget 'https://sheethub.com/ronnywang/中華民國村里?format=csv' -O village.csv
if (!file_exists('village.csv')) {
    throw new Exception("需要 village.csv, Ex: wget 'https://sheethub.com/ronnywang/中華民國村里?format=csv' -O village.csv ");
}
$fp = fopen('village.csv', 'r');
$columns = fgetcsv($fp);
$village_name_map = array();
while ($rows = fgetcsv($fp)) {
    $rows = array_combine($columns, $rows);
    // 只需要處理有被升格的縣市
    if (!in_array($rows['COUNTY_ID'], array(10012, 10001, 10019, 10006, 10011, 10021, 10003))) {
        continue;
    }
    $names = array($rows['VILLAGE_NAME']);
    if (trim($rows['VILLAGE_NAME_ALIAS'])) {
        $names = array_merge($names, explode("\n", $rows['VILLAGE_NAME_ALIAS']));
    }

    foreach ($names as $name) {
        if (preg_match('#村$#', $name)) {
            if (!$village_name_map[$rows['TOWN_ID']]) {
                $village_name_map[$rows['TOWN_ID']] = array();
            }
            $village_name_map[$rows['TOWN_ID']][preg_replace('#村$#', '里', $name)] = $name;
        }
    }
}

// wget 'http://sheethub.com/ronnywang/中華民國鄉鎮市區?format=csv' -O town.csv
if (!file_exists('town.csv')) {
    throw new Exception("需要 town.csv, Ex: wget 'https://sheethub.com/ronnywang/中華民國鄉鎮市區?format=csv' -O town.csv ");
}
$fp = fopen('town.csv', 'r');
$columns = fgetcsv($fp);
$town_name_map = array();
while ($rows = fgetcsv($fp)) {
    $rows = array_combine($columns, $rows);
    // 只需要處理有被升格的縣市
    if (!in_array($rows['COUNTY_ID'], array(10012, 10001, 10019, 10006, 10011, 10021, 10003))) {
        continue;
    }
    $names = array($rows['TOWN_NAME']);
    if (trim($rows['TOWN_NAME_ALIAS'])) {
        $names = array_merge($names, explode("\n", $rows['TOWN_NAME_ALIAS']));
    }

    foreach ($names as $name) {
        if (preg_match('#(鄉|鎮|市)$#', $name)) {
            if (!$town_name_map[$rows['COUNTY_ID']]) {
                $town_name_map[$rows['COUNTY_ID']] = array();
            }
            $town_name_map[$rows['COUNTY_ID']][preg_replace('#(鄉|鎮|市)$#', '區', $name)] = $name;
        }
    }
}

function convertVillageName($town_id, $year, $village_name)
{
    global $village_name_map;

    $town_id = convertTownId($town_id, $year);
    if ($village_name_map[$town_id][$village_name]) {
        return $village_name_map[$town_id][$village_name];
    }
    //   3. 彰化縣埔鹽鄉瓦廍村 嘉義縣梅山鄉瑞双村 臺南縣西港鄉廍林村 三者皆不存在 ，目前推測是打錯字
    //   正確分別應該是 埔鹽鄉瓦磘村, 梅山鄉瑞峯村, 西港鄉檨林村
    $map = array(
        '1000714-瓦廍村' => '瓦磘村',
        '1001015-瑞双村' => '瑞峯村',
        '1001114-廍林村' => '檨林村',
        '6701400-廍林里' => '檨林里',
    );

    if ($map[$town_id . '-' . $village_name]) {
        return $map[$town_id . '-' . $village_name];
    }
    return $village_name;
}

function convertTownName($county_id, $year, $town_name)
{
    global $town_name_map;

    $county_id = convertCountyId($county_id, $year);
    if ($town_name_map[$county_id][$town_name]) {
        return $town_name_map[$county_id][$town_name];
    }
    return $town_name;
}

$i = new Importer;
$meta = array(
    'license' => 'CC0',
    'source' => 'http://ecolife.epa.gov.tw/ https://github.com/ronnywang/ecolife.epa.gov.tw',
    'period' => 'monthly',
    '處理程式' => 'https://github.com/sheethub/data-import-script/tree/master/ecolife.epa.gov.tw',
);

foreach (array(2015) as $year) {
    $i->newSheet("/ecolife.epa.gov.tw/{$year}-縣市用電量",
        array('月份', '縣市代碼', '縣市名稱', '用電量'),
        $meta
    );
    $i->newSheet("/ecolife.epa.gov.tw/{$year}-鄉鎮用電量",
        array('月份', '縣市代碼', '縣市名稱', '鄉鎮代碼', '鄉鎮名稱', '用電量'),
        $meta
    );
    $i->newSheet("/ecolife.epa.gov.tw/{$year}-村里用電量",
        array('月份', '縣市代碼', '縣市名稱', '鄉鎮代碼', '鄉鎮名稱', '村里名稱', '用電量'),
        $meta
    );


    for ($month = 1; $month <= 12; $month ++) {
        error_log("{$year}/{$month}");

        $curl = curl_init("https://raw.githubusercontent.com/ronnywang/ecolife.epa.gov.tw/master/outputs/town/{$year}-{$month}.csv");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        if (200 == $info['http_code']) {
            foreach (explode("\n", trim($content)) as $line) {
                list($county_id, $county_name, $town_id, $town_name, , $total) = explode(',', trim($line));
                if ($town_id) {
                    $i->insertData("/ecolife.epa.gov.tw/{$year}-鄉鎮用電量", 
                        array(
                            $month,
                            convertCountyId($county_id, $year),
                            $county_name,
                            convertTownId($town_id, $year),
                            convertTownName($county_id, $year, $town_name),
                            $total
                        ),
                        array(0, 3)
                    );
                } elseif ('總計' == $town_name) {
                    $i->insertData("/ecolife.epa.gov.tw/{$year}-縣市用電量", 
                        array(
                            $month,
                            convertCountyId($county_id, $year),
                            $county_name,
                            $total
                        ),
                        array(0,1)
                    );
                }
            }
        }

        $curl = curl_init("https://raw.githubusercontent.com/ronnywang/ecolife.epa.gov.tw/master/outputs/village/{$year}-{$month}.csv");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        if (200 == $info['http_code']) {
            foreach (explode("\n", trim($content)) as $line) {
                list($county_id, $county_name, $town_id, $town_name, $village_name, $total) = explode(',', trim($line));
                if ($village_name != '總計') {
                    $i->insertData("/ecolife.epa.gov.tw/{$year}-村里用電量", 
                        array(
                            $month,
                            convertCountyId($county_id, $year),
                            $county_name,
                            convertTownId($town_id, $year),
                            convertTownName($county_id, $year, $town_name),
                            convertVillageName($town_id, $year, $village_name),
                            $total
                        ),
                        array(0,3,5)
                    );
                }
            }
        }
    }
    $i->commitData();
}
$i->commitData();
