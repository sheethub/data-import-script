<?php

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

$i = new Importer;
$meta = array(
    'license' => 'CC0',
    'source' => 'http://ecolife.epa.gov.tw/ https://github.com/ronnywang/ecolife.epa.gov.tw',
    'period' => 'monthly',
    '處理程式' => 'https://github.com/sheethub/data-import-script/tree/master/ecolife.epa.gov.tw',
);

for ($year = date('Y'); $year <= date('Y'); $year ++) {
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
                        array($month, $county_id, $county_name, $town_id, $town_name, $total),
                        array(0, 3)
                    );
                } elseif ('總計' == $town_name) {
                    $i->insertData("/ecolife.epa.gov.tw/{$year}-縣市用電量", 
                        array($month, $county_id, $county_name, $total),
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
                        array($month, $county_id, $county_name, $town_id, $town_name, $village_name, $total),
                        array(0,3,5)
                    );
                }
            }
        }
    }
    $i->commitData();
}
$i->commitData();
