<?php

/**
 * SheetHub 上傳工具
 * SheetHub API 文件: https://hackpad.com/SheetHub.com-API-Public-gtQm8vtBXLH
 *
 */
class SheetHubTool
{
    /**
     * 將 $fp 上傳到 SheetHub 取得 $upload_id 待下一步
     * 
     * @param mixed $fp 
     * @access public
     * @return string $upload_id
     */
    public function uploadToSheetHub($fp, $file_type)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        if (is_string($fp)) {
            $file = $fp;
        } else {
            $file = stream_get_meta_data($fp)['uri'];
        }

        $map = array(
            'csv' => array('text/csv', 'data.csv'),
            'zip' => array('text/zip', 'data.zip'),
            'xml' => array('text/xml', 'data.xml'),
            'json' => array('text/json', 'data.json'),
            'xls' => array('text/xls', 'data.xls'),
            'xlsx' => array('text/xlsx', 'data.xlsx'),
        );

        if (!array_key_exists($file_type, $map)) {
            throw new Exception("unknown filetype {$file_type}");
        }

        $curl = curl_init("https://{$sheethub_domain}/file/upload?access_token={$sheethub_key}");
        $cfile = curl_file_create($file, $map[$file_type][0], $map[$file_type][1]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, array('file' => $cfile));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        curl_close($curl);

        if (!$upload_id = json_decode($content)->data->upload_id) {
            throw new Exception('upload failed:' . $content);
        }
        error_log("upload to sheethub done: upload_id = {$upload_id}");
        return $upload_id;
    }

    public function downloadFile($url, $filetype)
    {
        // http://elearning.treif.org.tw/know/html_edition/book_1/csv/台灣地區活動斷層.csv
        $url = preg_replace_callback('#[^a-zA-Z./:_?&;=%]+#', function($m) { return rawurlencode($m[0]); }, $url);
        error_log("[$filetype]$url");
        $curl = curl_init($url);
        $fp = tmpfile();
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Chrome');
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$filetype) {
            if (preg_match('#Content-Disposition: attachment; filename="[^"]*\.([^".]*)"#', $header, $matches)) {
                $t = strtolower($matches[1]);
                if (in_array($t, array('csv', 'zip'))) {
                    $filetype = $t;
                }
            }
            return strlen($header);
        });
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $vars = array('prev_time' => 0, 'prev_size' => -1);
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function($curl, $download_size, $downloaded, $upload_size, $uploaded) use (&$vars) {
            if ($vars['prev_time'] == 0 and $download_size > 10 * 1024 * 1024) {
                throw new Exception("$url is large than 10MB");
            }
            if ($downloaded != $vars['prev_size']) {
                $vars['prev_size'] = $downloaded;
                $vars['prev_time'] = time();
            } else {
                if (time() - $vars['prev_time'] > 10) {
                    return -1;
                    throw new Exception("超過 10 秒沒下載反應");
                }
            }
        });
        curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/GCA.crt');
        curl_exec($curl);
        $info = curl_getinfo($curl);
        curl_close($curl);

        $header = file_get_contents(stream_get_meta_data($fp)['uri'], false, null, 0, 8192);
        if (strpos($header, '<Treifopendata xmlns="http://www.treif.org.tw/">') !== false) {
            if (preg_match('#<file1>(.*)\.([^.]*)</file1>#', $header, $matches)) {
                $url = $matches[1] . '.' . $matches[2];
                $file_type = strtolower($matches[2]);
                return self::downloadFile($url, $file_type);
            }
        }

        if (strpos($url, 'http://statis.moi.gov.tw/micst/stmain.jsp') === 0) {
            if (preg_match('#onload="JavaScript:linkurl\(\'([^\']*)\',0\)#', $header, $matches)) {
                return self::downloadFile('http://statis.moi.gov.tw/micst/' . $matches[1], 'csv');
            }
        }


        // wget http://gca.nat.gov.tw/repository/Certs/GCA.cer
        // openssl x509 -inform der -in GCA.cer -out GCA.crt
        if ($info['http_code'] != 200) {
            if (in_array($info['http_code'], array(302, 301))) {
                if (!$info['redirect_url']) {
                    var_dump($info);
                    throw new Exception("找不到 redirect_url");
                }
                return self::downloadFile($info['redirect_url'], $filetype);
            }
            print_r($info);
            throw new Exception("download {$url} failed: code={$info['http_code']}");
        }

        //   ["content_type"]=>
        //     string(24) "application/vnd.ms-excel"
        //
        switch (strtolower(explode(';', $info['content_type'])[0])) {
        case 'application/json':
            $filetype = 'json';
            break;
        case 'application/vnd.ms-excel':
            $filetype = 'xls';
            break;
        case 'text/csv':
            $filetype = 'csv';
            break;
        case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
            $filetype = 'xlsx';
            break;
        case 'text/xml':
            $filetype = 'xml';
            break;
        case 'application/zip':
        case 'application/x-zip-compressed':
            $filetype = 'zip';
            break;
        case 'text/html':
            if ($filetype == 'json' and in_array($header[0], array('[', '{'))) {
                break;
            }
            echo $header;
            throw new Exception("{$info['content_type']} 不給用的 {$url} (filetype={$filetype})");
        case 'application/pdf':
        case 'application/msword':
            if ($filetype == 'json') {
                break;
            }
            throw new Exception("{$info['content_type']} 不給用的 {$url} (filetype={$filetype})");
        default:
            error_log("unknown {$info['content_type']}, use {$filetype}");
        }
        return array($fp, $filetype);
    }


    /**
     * 從已上傳的 $upload_id 取得經過 SheetHub 解析的資訊
     * 
     * @param mixed $upload_id 
     * @access public
     * @return Obj
     */
    public function getFileInfoFromUpload($upload_id, $encode = 'auto')
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $curl = curl_init("https://{$sheethub_domain}/file/getuploadedinfo?upload_id={$upload_id}&access_token={$sheethub_key}&encode={$encode}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        curl_close($curl);

        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    public function guessRowBegin($tables)
    {
        $row_counts = array();
        foreach ($tables as $id => $rows) {
            for ($j = count($rows) - 1; $j >= 0; $j --) {
                if (strlen(trim($rows[$j]))) {
                    break;
                }
            }
            $row_counts[$id] = $j + 1;
        }
        $max_column_count = max($row_counts);

        for ($i = 0; $i < count($tables); $i ++) {
            if ($row_counts[$i] == $max_column_count) {
                return $i + 1;
            }
        }
        throw new Exception("failed");
    }

    /**
     * 用 $upload_id 的新增 Sheet
     * 
     * @param string $user
     * @param string $param
     * @param string $upload_id 
     * @access public
     * @return Object result 
     */
    public function newFile($user, $sheet, $upload_id, $config)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $info = self::getFileInfoFromUpload($upload_id);

        if (property_exists('tab_id', $config)) {
            $tab_id = $config['tab_id'];
        } else {
            $tab_id = null;
            foreach ($info->tab_ids as $id => $tab) {
                if (!$info->rows[$id]) {
                    continue;
                }
                if (!is_null($tab_id)) {
                    throw new Exception("超過一個檔案或 tab 可選: " . implode(",", $info->tab_ids));
                }
                $tab_id = $tab;
            }

            if (is_null($tab_id)) {
                throw new Exception("沒有 tab 可選: " . implode(",", $info->tab_ids));
            }
            $tab_id = array($tab_id);
        }

        if (array_key_exists('row_begin', $config)) {
            $row_begin = $config['row_begin'];
        } else {
            $row_begin = self::guessRowBegin($info->tables[0]);
        }

        if (array_key_exists('columns', $config)) {
            $columns = $config['columns'];
        } else {
            $columns = ($info->tables[0][max(0, $row_begin - 1)]);
        }

        $columns = array_map(function($s) {return str_replace("\n", "", $s); }, $columns);
        $params = array();
        $params[] = 'sheetuser=' . urlencode($user);
        $params[] = 'sheetname=' . urlencode($sheet);
        $params[] = 'upload_id=' . urlencode($upload_id);
        $params[] = 'tab-select=' . urlencode(json_encode($tab_id));
        $params[] = 'row_begin=' . intval($row_begin);
        $params[] = 'cols=' . urlencode(json_encode($columns));
        if ($config['encode']) {
            $params[] = 'encode=' . urlencode($config['encode']);
        }
        $curl = curl_init("https://{$sheethub_domain}/file/addsheetfromuploaded?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            error_log('table=' . json_encode($info->tables[0], JSON_UNESCAPED_UNICODE));
            error_log('column=' . json_encode($columns, JSON_UNESCAPED_UNICODE));
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    public function setURIColumns($user, $sheet, $column_ids)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $user = urlencode($user);
        $sheet = urlencode($sheet);
        $curl = curl_init("https://{$sheethub_domain}/{$user}/{$sheet}/seturi?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'columns=' . urlencode(json_encode($column_ids)));
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
     * @param string $user
     * @param string $sheet
     * type 
     * @param string $upload_id 
     * @access public
     * @return Object result 
     */
    public function updateFile($user, $sheet, $upload_id, $config = array())
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $info = self::getFileInfoFromUpload($upload_id);

        if (count($info->tab_ids) != 1) {
            throw new Exception("超過一個檔案或 tab 可選");
        }
        $tab_id = $info->tab_ids[0];

        $row_begin = array_key_exists('row_begin', $config) ? $config['row_begin'] : self::guessRowBegin($info->tables[0]);
        $columns = ($info->tables[0][max(0, $row_begin - 1)]);
        if ($config['columns'] and count($config['columns']) == count($columns)) {
            $columns = $config['columns'];
        }
        $columns = array_map(function($s) {return str_replace("\n", "", $s); }, $columns);
        $params = array();
        $params[] = 'upload_id=' . urlencode($upload_id);
        $params[] = 'tab-select=' . urlencode($tab_id);
        $params[] = 'row_begin=' . intval($row_begin);
        $params[] = 'update_type=' . array_key_exists('update_type', $config) ? $config['update_type'] : 0;
        $params[] = 'cols=' . urlencode(json_encode($columns));
        if ($config['encode']) {
            $params[] = 'encode=' . urlencode($config['encode']);
        }
        $user = urlencode($user);
        $sheet = urlencode($sheet);
        $curl = curl_init("https://{$sheethub_domain}/{$user}/{$sheet}/updatefile?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            error_log('table=' . json_encode($info->tables[0], JSON_UNESCAPED_UNICODE));
            error_log('column=' . json_encode($columns, JSON_UNESCAPED_UNICODE));
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 修改 SheetHub 上的 meta data
     * 
     * @param string $user
     * @param string $sheet
     * @param array $values 
     * @access public
     * @return OBj
     */
    public function setMeta($user, $sheet, $values)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $user = urlencode($user);
        $sheet = urlencode($sheet);
        $curl = curl_init("https://{$sheethub_domain}/{$user}/{$sheet}/meta?access_token={$sheethub_key}");
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
     * 修改 SheetHub 上的 description 
     * 
     * @param string $user
     * @param string $sheet
     * @access public
     * @return OBj
     */
    public function setDescription($user, $sheet, $desc)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $user = urlencode($user);
        $sheet = urlencode($sheet);
        $curl = curl_init("https://{$sheethub_domain}/{$user}/{$sheet}/update?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'description=' . urlencode($desc));
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        return $ret->data;
    }

    /**
     * 取得 $sheet 這個 Sheet 的資訊
     * 
     * @param string $user
     * @param string $sheet
     * @access public
     * @return Object
     */
    public function getSheetHubInfo($user, $sheet)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $user = urlencode($user);
        $sheet = urlencode($sheet);

        $curl = curl_init("https://{$sheethub_domain}/{$user}/{$sheet}?format=json&without_data=1");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $ret = json_decode($content);
        if ($ret->error){
            throw new Exception($ret->message);
        }
        if (!$ret) {
            throw new Exception("get {$user}/{$sheet} failed");
        }
        return $ret;
    }
}
