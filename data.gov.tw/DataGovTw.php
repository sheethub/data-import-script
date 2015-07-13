<?php

include('SheetHubTool.php');

/**
 * usage: env SHEETHUB_KEY={key} update.php
 * SheetHub API 文件: https://hackpad.com/SheetHub.com-API-Public-gtQm8vtBXLH
 *
 */
class DataGovTw 
{
    /**
     * 從 data.gov.tw 取得某一個資料的 meta data
     * 
     * @access public
     * @return array($fp, 版本時間)
     */
    public function getMetaFromPortal($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        if ($info['http_code'] != 200) {
            throw new Exception("找不到這資料, $url (code={$info['http_code']})");
        }
        curl_close($curl);

        $doc = new DOMDocument;
        @$doc->loadHTML($content);


        $ret = array();
        $ret['title'] = trim($doc->getElementsByTagName('h1')->item(0)->nodeValue);
        foreach ($doc->getElementsByTagName('th') as $th_dom) {
            $name = $th_dom->nodeValue;
            $value_dom = $th_dom->nextSibling;
            while ($value_dom) {
                if ($value_dom->nodeType == XML_ELEMENT_NODE) {
                    break;
                }
                $value_dom = $value_dom->nextSibling;
            }
            if (!$value_dom) {
                continue;
            }

            if ($name == '下載') {
                $ret[$name] = array();
                foreach ($value_dom->getElementsByTagName('a') as $a_dom) {
                    if (in_array(strtolower($a_dom->nodeValue), array('doc', 'word', 'pdf', 'webservice'))) {
                        continue;
                    }
                    $ret[$name][] = array(
                        'type' => strtolower($a_dom->nodeValue),
                        'url' => $a_dom->getAttribute('href'),
                    );
                }
            } elseif ($name == '資料集評分') {
                continue;
            } else {
                $ret[$name] = trim($value_dom->nodeValue);
            }
        }
        return $ret;
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
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $vars = array('prev_time' => 0, 'prev_size' => -1);
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function($curl, $download_size, $downloaded, $upload_size, $uploaded) use (&$vars) {
            if ($vars['prev_time'] == 0 and $download_size > 10 * 1024 * 1024) {
                throw new Exception("$url is large than 10MB");
            }
            if ($download_size != $vars['prev_size']) {
                $vars['prev_size'] = $download_size;
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
                $type = strtolower($matches[2]);
                return self::downloadFile($url, $type);
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

    public function updateStatus($type, $time, $status)
    {
        $sheethub_domain = getenv('SHEETHUB_DOMAIN') ?: 'sheethub.com';
        $sheethub_key = getenv('SHEETHUB_KEY');

        $curl = curl_init("https://{$sheethub_domain}/data.gov.tw/更新記錄/insert?access_token={$sheethub_key}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $params = array();
        $params[] = 'data=' . urlencode(json_encode(array(array($type, $time, $status))));
        $params[] = 'unique_columns=' . urlencode(json_encode(array(0)));
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));

        $content = json_decode(curl_exec($curl));
        curl_close($curl);

        if (!$content or $content->error) {
            throw new Exception('update final failed:' . $content->message);
        }
    }

    public function updateOrInsert($type, $config)
    {
        try {
            if ($config['sheet_info']) {
                $sheet_info = $config['sheet_info'];
            } else {
                $sheet_info = SheetHubTool::getSheetHubInfo('data.gov.tw', $type)->sheet;
            }
            $config['period'] = array_key_exists('period', $config) ? $config['period'] : 86400;
            if (!$config['force'] and time() - strtotime($sheet_info->meta->fetched_time) < $config['period']) {
                return "距離上次更新時間過短";
            }
            error_log("updating {$type}");
        } catch (Exception $e) {
            error_log("adding {$type} (because {$e->getMessage()}");
            $sheet_info = null;
        }

        $time = microtime(true);
        try {
            if (!$config['source']) {
                $config['source'] = $sheet_info->meta->source;
            }
            if (!$config['source']) {
                throw new Exception("找不到更新網址");
            }
            $portal_meta = $this->getMetaFromPortal($config['source']);
        } catch (Exception $e) {
            $this->error($type, $e);
            return "更新失敗，原因: " . $e->getMessage();
        }

        if (!$config['meta_only']) {
            $files = array();
            $download_url = null;
            if (count($portal_meta['下載']) == 1) {
                $download_url = $portal_meta['下載'][0]['url'];
                $filetype = $portal_meta['下載'][0]['type'];
            }
            if (is_null($download_url) and $config['choose_file']) {
                foreach ($portal_meta['下載'] as $info) {
                    if ($info['type'] == $config['choose_file']) {
                        $download_url = $info['url'];
                        $filetype = $info['type'];
                        break;
                    }
                }
            }

            if (is_null($download_url)) {
                $files = array();
                foreach ($portal_meta['下載'] as $info) {
                    $files[$info['type']] = $info['url'];
                }
                foreach (array('json', 'csv', 'excel', 'xml') as $t) { 
                    if ($files[$t]) {
                        $download_url = $files[$t];
                        $filetype = $t;
                        break;
                    }
                }
            }

            if (is_null($download_url)) {
                try {
                    throw new Exception("超過一個檔可以下載，不知道要用哪個: " . implode(',', array_map(function($i) {return $i['type']; }, $portal_meta['下載'])));
                } catch (Exception $e) {
                    $this->error($type, $e);
                    return "更新失敗，原因: " . $e->getMessage();
                }
            }

            try {
                error_log("downloading {$download_url}");
                list($fp, $filetype) = $this->downloadFile($download_url, $filetype);
                error_log("downloaded");
            }catch (Exception $e) {
                $this->error($type, $e);
                return "更新失敗，原因: " . $e->getMessage();
            }

            $file = stream_get_meta_data($fp)['uri'];
            $md5 = md5_file($file);
            if (!$config['force'] and $sheet_info and $sheet_info->meta->file_hash and $md5 == $sheet_info->meta->file_hash) {
                error_log("md5 same, skip {$type}");
                SheetHubTool::setMeta('data.gov.tw', $type, array('fetched_time' => date('c', time())));
                return "下載原始檔案 md5 未變，不需更新";
            }

            if (property_exists($sheet_info->meta, 'updater:columns')) {
                $config['columns'] = explode(',', $sheet_info->meta->{'updater:columns'});
            }
            if (property_exists($sheet_info->meta, 'updater:row_begin')) {
                $config['row_begin'] = $sheet_info->meta->{'updater:row_begin'};
            }
            if ($config['filetype']) {
                $filetype = $config['filetype'];
            }
            $fp = DataGovTw::specialCase($fp, $config['source']);

            try {
                $upload_id = SheetHubTool::uploadToSheetHub($fp, $filetype);
                if ($sheet_info) {
                    $ret = SheetHubTool::updateFile('data.gov.tw', $type, $upload_id, $config);
                    $result = " insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete);
                    error_log("Type={$type} done, insert: " . count($ret->insert) . ', update: ' . count($ret->update) . ', delete: ' . count($ret->delete));
                    $this->updateStatus($type, date('c', time()), sprintf("更新完成，共新增 %d 筆，更新 %d 筆，刪除 %d 筆", count($ret->insert), count($ret->update), count($ret->delete)));
                } else {
                    $sheet_info = SheetHubTool::newFile('data.gov.tw', $type, $upload_id, $config);
                    error_log("add {$type} done");
                    $this->updateStatus($type, date('c', time()), "建立完成");
                }
            } catch (Exception $e) {
                $this->error($type, $e);
                return "更新失敗，原因: " . $e->getMessage();
            }
        }
        $new_meta = $sheet_info->meta;
        if ($portal_meta['資料集修訂時間']) {
            $new_meta->updated_time = $portal_meta['資料集修訂時間'];
        }
        $new_meta->fetched_time = date('c', time());
        $new_meta->period = $portal_meta['更新頻率'];
        $new_meta->source = $config['source'];
        $new_meta->update_code = 'https://github.com/sheethub/data-import-script/blob/master/data.gov.tw/update.php';
        $new_meta->license = $portal_meta['授權方式'] . ' ' . $portal_meta['授權說明網址'];
        $new_meta->file_hash = $md5;
        foreach(array('資料集提供機關', '資料集提供機關聯絡人', '資料集提供機關聯絡人電話', '備註') as $k) {
            if ($portal_meta[$k]) {
                $new_meta->{$k} = $portal_meta[$k];
            }
        }

        SheetHubTool::setMeta('data.gov.tw', $type, $new_meta);
        if ($portal_meta['資料集描述'] and $portal_meta['資料集描述'] != $sheet_info->description) {
            SheetHubTool::setDescription('data.gov.tw', $type, $portal_meta['資料集描述']);
        }

        return "更新成功，已匯入 https://sheethub.com/data.gov.tw/{$type} , {$result}";
    }

    protected $_error_throw_exception = false;

    public function setErrorThrowException($v)
    {
        $this->_error_throw_exception = true;
    }

    public function error($type, $e) 
    {
        if ($this->_error_throw_exception) {
            throw $e;
        }
        file_put_contents('error', $type . ": {$e->getMessage()}\n", FILE_APPEND);
        error_log("{$type}: {$e->getMessage()}");
        //throw $e;
    }

    public function specialCase($fp, $source)
    {
        switch ($source) {
        case 'http://data.gov.tw/node/14239': // 結婚人數─按區域別國籍分
            rewind($fp);
            $rows = fgetcsv($fp);  // 先濾掉第一行標題 "結婚人數─按區域別及國籍別分"
            if ($rows[1] != '結婚人數─按區域別及國籍別分') {
                throw new Exception("預期第一行應該是 '結婚人數─按區域別及國籍別分'");
            }

            $output = tmpfile();
            fputcsv($output, array('統計期', '結婚人數', '區域別', '國籍別', '數量'));

            $columns = fgetcsv($fp);
            $terms = array();

            array_shift($columns);
            foreach ($columns as $col) {
                $terms[] = array_map('trim', explode('/', $col));
            }

            while ($rows = fgetcsv($fp)) {
                $time = array_shift($rows);
                foreach ($rows as $id => $row) {
                    $value = str_replace(',', '', $row);
                    fputcsv($output, array_merge(array($time), $terms[$id], array($value)));
                }
            }
            return $output;

        case 'http://data.gov.tw/node/14240': // 結婚人數─按年齡婚前婚姻狀況分
            rewind($fp);
            $rows = fgetcsv($fp);  // 先濾掉第一行標題 "結婚人數─按年齡婚前婚姻狀況分"
            if ($rows[1] != '結婚人數─按年齡、婚前婚姻狀況分') {
                throw new Exception("預期第一行應該是 '結婚人數─按年齡、婚前婚姻狀況分', 結果是 '{$rows[1]}'");
            }

            $output = tmpfile();
            fputcsv($output, array('統計期', '結婚人數', '年齡別', '婚姻狀況', '數量'));

            $columns = fgetcsv($fp);
            $terms = array();

            array_shift($columns);
            foreach ($columns as $col) {
                $terms[] = array_map('trim', explode('/', $col));
            }

            while ($rows = fgetcsv($fp)) {
                $time = array_shift($rows);
                foreach ($rows as $id => $row) {
                    $value = str_replace(',', '', $row);
                    fputcsv($output, array_merge(array($time), $terms[$id], array($value)));
                }
            }
            return $output;

        case 'http://data.gov.tw/node/7608': // 科技部補助兩岸科技交流統計資料集
        case 'http://data.gov.tw/node/7607': // 科技部補助延攬科技人才統計資料集
        case 'http://data.gov.tw/node/7609': // 行政院傑出科技貢獻獎統計資料集
            rewind($fp);
            $rows = fgetcsv($fp);  // 第一行資料更新時間
            if (strpos($rows[0], '料更新日期') === false) {
                throw new Exception("預期第一行應該是資料更新日期，結果是 {$rows[0]}");
            }
            $rows = fgetcsv($fp);
            if (count(array_filter('strlen', $rows))) {
                throw new Exception("預期第二行為空白，結果有內容: " . implode('', $rows));
            }
            $rows = fgetcsv($fp);  // 第三行核定件數
            if (!in_Array($rows[1], array('核定件數', '獲獎人數'))) {
                throw new Exception("預期第三行應該是核定件數，結果是 {$rows[1]}");
            }
            $sections = fgetcsv($fp); // 處室 (用的是合併儲存格)
            array_shift($sections);
            foreach ($sections as $i => $s) {
                if (!$s) {
                    $sections[$i] = $sections[$i - 1];
                }
            }

            $orgs = fgetcsv($fp); // 機關類別
            array_shift($orgs);

            $output = tmpfile();
            fputcsv($output, array('申請年度', '機關類別', '學術處室', '核定件數'));

            while ($rows = fgetcsv($fp)) {
                $year = array_shift($rows);
                foreach ($rows as $id => $v) {
                    fputcsv($output, array($year, $orgs[$id], $sections[$id], $v));
                }
            }

            return $output;




        default:
            return $fp;
        }
    }

}
