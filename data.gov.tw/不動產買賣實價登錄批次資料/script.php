<?php

$variables = array('TMP_DIR', 'access_token');
foreach ($variables as $v) {
    if (!getenv('TMP_DIR')) {
        throw new Exception("未指定 {$v} env");
    }
}
class Importer
{
    public function main()
    {
        mkdir(getenv('TMP_DIR'));
        chdir(getenv('TMP_DIR'));
        $this->download();
        $this->import();
    }

    public function import()
    {
        $counties = array(
            'C' => '基隆市',
            'A' => '臺北市',
            'F' => '新北市',
            'H' => '桃園縣',
            'O' => '新竹市',
            'J' => '新竹縣',
            'K' => '苗栗縣',
            'B' => '臺中市',
            'M' => '南投縣',
            'N' => '彰化縣',
            'P' => '雲林縣',
            'I' => '嘉義市',
            'Q' => '嘉義縣',
            'D' => '臺南市',
            'E' => '高雄市',
            'T' => '屏東縣',
            'G' => '宜蘭縣',
            'U' => '花蓮縣',
            'V' => '臺東縣',
            'X' => '澎湖縣',
            'W' => '金門縣',
            'Z' => '連江縣',
        );
        // 明細
        $columns = explode(',', '鄉鎮市區,交易標的,土地區段位置或建物區門牌,土地移轉總面積平方公尺,都市土地使用分區,非都市土地使用分區,非都市土地使用編定,交易年月,交易筆棟數,移轉層次,總樓層數,建物型態,主要用途,主要建材,建築完成年月,建物移轉總面積平方公尺,建物現況格局-房,建物現況格局-廳,建物現況格局-衛,建物現況格局-隔間,有無管理組織,總價元,單價每平方公尺,車位類別,車位移轉總面積平方公尺,車位總價元,備註,編號');
        foreach ($counties as $id => $name) {
            $fp = fopen("{$id}_LVR_LAND_A.CSV", "r");
            $rows = $this->getcsv($fp);
            if ($rows != $columns) {
                throw new Exception("格式不對");
            }

            while ($rows = $this->getcsv($fp)) {
                if (!$rows or (count($rows) == 1 and $rows[0] == '')) {
                    continue;
                }
                array_unshift($rows, $name);
                // RPSNMLRJKHKFFAG97CA
                if (count($rows) > 29 and preg_match('#^[A-Z0-9]*$#', $rows[count($rows) - 1])) {
                    $rows = array_merge(array_slice($rows, 0, 27), array(implode(',', array_slice($rows, 27, -1)), $rows[count($rows) - 1]));
                }

                if (!preg_match('#^[A-Z0-9]*$#', $rows[28]) or strlen($rows[28]) != 19) {
                    throw new Exception('格式不正確');

                }
                $this->insertData('/data.gov.tw/不動產買賣實價登錄批次資料-交易明細', $rows, 28);
            }
        }

        // 建物
        $columns = explode(',', '編號,屋齡,建物移轉面積平方公尺,主要用途,主要建材,建築完成日期,總層數,建物分層');
        $no = array();
        foreach ($counties as $id => $name) {
            $fp = fopen("{$id}_LVR_LAND_A_BUILD.CSV", "r");
            if (!$fp) {
                continue;
            }
            $rows = $this->getcsv($fp);
            if ($rows != $columns) {
                throw new Exception("格式不對");
            }

            while ($rows = $this->getcsv($fp)) {
                $rows = array_merge(array($rows[0], ++ $no[$rows[0]]), array_slice($rows, 1));
                $this->insertData('/data.gov.tw/不動產買賣實價登錄批次資料-建物', $rows, '0,1');
            }
        }

        // 土地
        $columns = explode(',', '編號,土地區段位置,土地移轉面積平方公尺,使用分區或編定');
        $no = array();
        foreach ($counties as $id => $name) {
            $fp = fopen("{$id}_LVR_LAND_A_LAND.CSV", "r");
            if (!$fp) {
                continue;
            }
            $rows = $this->getcsv($fp);
            if ($rows != $columns) {
                throw new Exception("格式不對");
            }

            while ($rows = $this->getcsv($fp)) {
                $rows = array_merge(array($rows[0], ++ $no[$rows[0]]), array_slice($rows, 1));
                $this->insertData('/data.gov.tw/不動產買賣實價登錄批次資料-土地', $rows, '0,1');
            }
        }

        // 車位
        $columns = explode(',', '編號,車位類別,車位價格,車位面積平方公尺');
        $no = array();
        foreach ($counties as $id => $name) {
            $fp = fopen("{$id}_LVR_LAND_A_PARK.CSV", "r");
            if (!$fp) {
                continue;
            }
            $rows = $this->getcsv($fp);
            if ($rows != $columns) {
                throw new Exception("格式不對");
            }

            while ($rows = $this->getcsv($fp)) {
                $rows = array_merge(array($rows[0], ++ $no[$rows[0]]), array_slice($rows, 1));
                $this->insertData('/data.gov.tw/不動產買賣實價登錄批次資料-車位', $rows, '0,1');
            }
        }

        $this->commitData();
    }

    public function getcsv($fp)
    {
        $rows = fgetcsv($fp);
        if (!is_array($rows)) {
            return $rows;
        }
        $rows = array_map(function($s) { return iconv('Big5', 'UTF-8', $s); }, $rows);

        return $rows;
    }

    public function download()
    {
        system("wget 'http://lvr.land.moi.gov.tw/opendata/lvr_landAcsv.zip' -O lvr_landAcsv.zip");
        system("unzip lvr_landAcsv.zip");
    }

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

        error_log("commiting {$sheet} {$this->_pending_data[$sheet][0][0]}");

        $curl = curl_init("https://sheethub.com{$sheet}/insert?access_token=" . getenv('access_token'));
        $params = array();
        $params[] = 'data=' . urlencode(json_encode($this->_pending_data[$sheet], JSON_UNESCAPED_UNICODE));
        $params[] = 'unique_columns[]=' . $this->_unique_column_id[$sheet];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $obj = json_decode($content);
        error_log($content);
        if (is_null($obj->data->count)) {
            error_log('count null, retry');
            $this->commitData($sheet);
            return;
        }

        $this->_pending_data[$sheet] = array();
    }

}

$i = new Importer;
$i->main();
