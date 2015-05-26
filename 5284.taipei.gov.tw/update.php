<?php

/**
 * usage: env SHEETHUB_KEY={key} update.php
 * 5284 文件: http://www.dot.gov.taipei/public/mmo/dot/%E6%88%91%E6%84%9B%E5%B7%B4%E5%A3%AB5284%E8%B3%87%E6%96%99%E5%BA%AB%E4%BB%8B%E6%8E%A5%E8%AA%AA%E6%98%8E%E6%96%87%E4%BB%B6.pdf
 * SheetHub API 文件: https://hackpad.com/SheetHub.com-API-Public-gtQm8vtBXLH
 *
 */
class Updater
{
    /**
     * 從 5284 API 取得某一個 API 資料
     * 
     * @param mixed $type 
     * @access public
     * @return array($fp, 版本時間)
     */
    public function getFileFrom5284($type)
    {
        $bus_domain = getenv('BUS5284_DOMAIN') ?: 'imp.5284.com.tw';

        $fp = tmpfile();

        $curl = curl_init("http://{$bus_domain}/TaipeiBusService/{$type}.aspx?dataFormat=json");
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_exec($curl);
        curl_close($curl);

        fseek($fp, 0);
        $content = fgets($fp, 1024);
        if (!preg_match('#"UpdateTime":"([^"]*)"#', $content, $matches)) {
            throw new Exception('Update Time not found');
        }
        fseek($fp, 0);
        return array($fp, $matches[1]);;
    }

    /**
     * 將 $fp 上傳到 SheetHub 取得 $upload_id 待下一步
     * 
     * @param mixed $fp 
     * @access public
     * @return string $upload_id
     */
    public function uploadToSheetHub($fp)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $file = stream_get_meta_data($fp)['uri'];

        $curl = curl_init("https://{$sheethub_domain}/file/upload?access_token={$sheethub_key}");
        $cfile = curl_file_create($file, 'text/json', 'data.json');
        curl_setopt($curl, CURLOPT_POSTFIELDS, array('file' => $cfile));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        curl_close($curl);

        if (!$upload_id = json_decode($content)->data->upload_id) {
            throw new Exception('upload failed:' . $content);
        }
        return $upload_id;
    }

    /**
     * 從已上傳的 $upload_id 取得經過 SheetHub 解析的資訊
     * 
     * @param mixed $upload_id 
     * @access public
     * @return Obj
     */
    public function getFileInfoFromUpload($upload_id)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $curl = curl_init("https://{$sheethub_domain}/file/getuploadedinfo?upload_id={$upload_id}&access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        curl_close($curl);

        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 只檢查有什麼內容有修改，不變更線上內容
     * 
     * @param string $type 
     * @param string $upload_id 
     * @access public
     * @return Obj
     */
    public function checkUpload($type, $upload_id)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $info = $this->getFileInfoFromUpload($upload_id);
        if ($info->tabs[0] != 'BusInfo') {
            throw new Exception('預期應該有 BusInfo');
        }

        $columns = ($info->tables[0][0]);
        $params = array();
        $params[] = 'upload_id=' . urlencode($upload_id);
        $params[] = 'tab-select=BusInfo';
        $params[] = 'row_begin=1';
        $params[] = 'update_type=1';
        $params[] = 'cols=' . urlencode(json_encode($columns));
        $curl = curl_init("https://{$sheethub_domain}/5284.taipei.gov.tw/{$type}/checkupload?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 用 $upload_id 的內容覆蓋 SheetHub 線上的內容，採用 update_type=1, 當新檔案沒有的東西在 SheetHub 上也會刪除
     * 
     * @param string $type 
     * @param string $upload_id 
     * @access public
     * @return Object result 
     */
    public function updateFile($type, $upload_id)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $info = $this->getFileInfoFromUpload($upload_id);
        if ($info->tabs[0] != 'BusInfo') {
            throw new Exception('預期應該有 BusInfo');
        }

        $columns = ($info->tables[0][0]);
        $params = array();
        $params[] = 'upload_id=' . urlencode($upload_id);
        $params[] = 'tab-select=BusInfo';
        $params[] = 'row_begin=1';
        $params[] = 'update_type=1';
        $params[] = 'cols=' . urlencode(json_encode($columns));
        $curl = curl_init("https://{$sheethub_domain}/5284.taipei.gov.tw/{$type}/updatefile?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 修改 SheetHub 上的 meta data
     * 
     * @param string $type 
     * @param array $values 
     * @access public
     * @return OBj
     */
    public function setMeta($type, $values)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $curl = curl_init("https://{$sheethub_domain}/5284.taipei.gov.tw/{$type}/meta?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'meta=' . urlencode(json_encode($values)));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 取得 $type 這個 Sheet 的資訊
     * 
     * @param string $type 
     * @access public
     * @return Object
     */
    public function getSheetHubInfo($type)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';

        $curl = curl_init("https://{$sheethub_domain}/5284.taipei.gov.tw/{$type}?format=json&without_data=1");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret;
    }

    public function main()
    {
        $list = array(
            'StopLocation' => array('period' => 86400),
            'CarUnusual' => array('period' => 0),
            'Parameter' => array('period' => 86400),
            'IStop' => array('period' => 86400),
            'IStopPath' => array('period' => 86400),
            'Stop' => array('period' => 86400),
            'Provider' => array('period' => 86400),
            'OrgPathAttribute' => array('period' => 86400),
            'EstimateTime' => array('period' => 0),
            'BusEvent' => array('period' => 0),
            'BusData' => array('period' => 0),
            'SemiTimeTable' => array('period' => 86400),
            'TimeTable' => array('period' => 86400),
            'CarInfo' => array('period' => 86400),
            'PathDetail' => array('period' => 86400),
            'Route' => array('period' => 86400),
        );

        foreach ($list as $type => $config) {
            if (!$fetched_time = $this->getSheetHubInfo($type)->sheet->meta->fetched_time) {
                //throw new Exception("找不到原來的更新時間");
            }
            if (time() - strtotime($fetched_time) < $config['period']) {
                continue;
            }

            $time = microtime(true);
            error_log("Type={$type} downloading");
            list($fp, $updatetime) = $this->getFileFrom5284($type);
            $delta = microtime(true) - $time; $time = microtime(true);
            error_log("Type={$type} downloaded, spent {$delta}, uploading");
            $upload_id = $this->uploadToSheetHub($fp);
            $delta = microtime(true) - $time; $time = microtime(true);
            error_log("Type={$type} uploaded, spent {$delta}, updating");
            $ret = $this->updateFile($type, $upload_id);
            $delta = microtime(true) - $time; $time = microtime(true);
            error_log("Type={$type} updated, spent {$delta}, insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete));
            $this->setMeta($type, array(
                'updated_time' => $updatetime,
                'fetched_time' => date('c', time()),
                'period' => $config['period'] == 86400 ? '每日' : '每分鐘',
                'source' => 'http://www.dot.gov.taipei/',
            ));
        }
    }
}

$u = new Updater;
$u->main();
