<?php

// 資料來源是 http://gazetteer.hk
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

    public function getContent($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        curl_close($curl);
        return $content;
    }
}

$i = new Importer;

$data_hk = json_decode($i->getContent('http://gazetteer.hk/locale/zh-HK/generated_ns-translation.json'));
$data_us = json_decode($i->getContent('http://gazetteer.hk/locale/en-US/generated_ns-translation.json'));
$data_cn = json_decode($i->getContent('http://gazetteer.hk/locale/zh-CN/generated_ns-translation.json'));

foreach ($data_hk->district as $id => $name) {
    $i->insertData("/ronnywang/香港行政區劃-分區",
        array(
            strtoupper($id),
            $name,
            $data_cn->district->{$id},
            $data_us->district->{$id},
        ),
        array(0)
    );
}

foreach ($data_hk->area as $id => $name) {
    $i->insertData("/ronnywang/香港行政區劃-選區",
        array(
            strtoupper($id),
            substr($id, 1),
            strtoupper($id[0]),
            $name,
            $data_cn->area->{$id},
            $data_us->area->{$id},
        ),
        array(0)
    );
}
$i->commitData();
