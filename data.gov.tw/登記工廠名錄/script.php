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
        //mkdir(getenv('TMP_DIR'));
        chdir(getenv('TMP_DIR'));
        $this->download();
        $this->import();
    }

    public function import()
    {
        // TODO: 把資料更新日期丟進 meta
        $content = file_get_contents('1031231.xml');
        $content = str_replace('MS950', 'CP950', $content);
        $content = iconv('CP950', 'CP950//IGNORE', $content);
        $doc = new DOMDocument;
        $doc->loadXML($content);

        $columns = array('工廠名稱', '工廠登記編號', '工廠設立許可案號', '工廠地址', '工廠市鎮鄉村里', '工廠負責人姓名', '營利事業統一編號', '工廠組織型態', '工廠設立核准日期', '工廠登記核准日期', '工廠登記狀態', '產業類別', '主要產品');
        $columns = array_combine($columns, range(0, count($columns) - 1));

        foreach ($doc->getElementsByTagName('ROW') as $row_dom) {
            $value = array_fill(0, count($columns), '');
            foreach ($row_dom->getElementsByTagName('COLUMN') as $column_dom) {
                $c = $column_dom->getAttribute('NAME');
                if (!array_key_exists($c, $columns)) {
                    throw new Exception($c);
                }
                $value[$columns[$c]] = $column_dom->nodeValue;
            }
            $this->insertData('/data.gov.tw/登記工廠名錄', $value, 1);
        }
        $this->commitData();
    }

    public function download()
    {
        system("wget 'http://data.gov.tw/iisi/logaccess?dataUrl=http://www.cto.moea.gov.tw/04/factory.zip&ndctype=XML&ndcnid=6569' -O factory.zip");
        system("unzip factory.zip");
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
