<?php


class CWB
{
    protected static $_stops = null;
    public static function getStops()
    {
        if (is_null(self::$_stops)) {
            $doc = new DOMDocument;
            $doc->loadHtml("<html><table></table>" . file_get_contents("http://www.cwb.gov.tw/V7/climate/30day/30day.php"). "</html>");
            self::$_stops = array();
            foreach ($doc->getElementById('st')->getElementsByTagName('option') as $option_dom) {
                self::$_stops[$option_dom->getAttribute('value')] = $option_dom->nodeValue;
            }
            unset($doc);
        }

        return self::$_stops;
    }

    public function getInfoByStopAndTime($stop_id, $stop_name, $time)
    {
        $doc = new DOMDocument;
        error_log("{$stop_id} {$stop_name} {$time}");
        $url = "http://www.cwb.gov.tw/V7/climate/30day/Data/{$stop_id}_{$time}.htm";
        $doc->loadHtml(file_get_contents($url));
        $table_dom = $doc->getElementsByTagName('table')->item(1);
        $tr_doms = $table_dom->getElementsByTagName('tr');

        $columns = array('測站代碼', '測站名稱', '時間');
        foreach ($tr_doms->item(0)->getElementsByTagName('th') as $th_dom) {
            $columns[] = trim($th_dom->nodeValue);
        }

        $all_values = array();
        for ($i = 1; $i < $tr_doms->length; $i ++) {
            $values = array($stop_id, $stop_name, $time);
            foreach ($tr_doms->item($i)->getElementsByTagName('td') as $td_dom) {
                $values[] = trim($td_dom->nodeValue);
            }
            $all_values[] = $values;
        }

        return array($columns, $all_values);
    }

    public function getAll15mInfo()
    {
        $doc = new DOMDocument;
        $doc->loadHtml("<html><table></table>" . file_get_contents("http://www.cwb.gov.tw/V7/observe/real/ALL.htm?_=". time()). "</html>");
        $table_dom = $doc->getElementsByTagName('table')->item(1);
        if (!$table_dom) { 
            throw new Exception('table not found');
        } 
        $tr_doms = $table_dom->getElementsByTagName('tr');

        $columns = array('測站代碼');
        foreach ($tr_doms->item(0)->getElementsByTagName('th') as $th_dom) {
            $columns[] = trim($th_dom->nodeValue);
        }
        $all_values = array();
        for ($i = 1; $i < $tr_doms->length; $i ++) {
            $values = array();
            $th_doms = $tr_doms->item($i)->getElementsByTagName('th');
            $values[] = explode('.', $th_doms->item(0)->getElementsByTagName('a')->item(0)->getAttribute('href'))[0];
            $values[] = $th_doms->item(0)->getElementsByTagName('a')->item(0)->nodeValue;
            $values[] = $th_doms->item(1)->nodeValue;
            foreach ($tr_doms->item($i)->getElementsByTagName('td') as $td_dom) {
                $values[] = trim($td_dom->nodeValue);
            }
            $all_values[] = $values;
        }
        return array($columns, $all_values);
    }

    public function getLatest24hr15mInfo($stop_id)
    {
        $doc = new DOMDocument;
        $doc->loadHtml("<html><table></table>" . file_get_contents("http://www.cwb.gov.tw/V7/observe/24real/Data/{$stop_id}.htm?_=". time()). "</html>");
        $table_dom = $doc->getElementsByTagName('table')->item(1);
        if (!$table_dom) { 
            throw new Exception('table not found');
        } 
        $tr_doms = $table_dom->getElementsByTagName('tr');

        $columns = array('測站代碼', '測站名稱', '時間');
        foreach ($tr_doms->item(0)->getElementsByTagName('th') as $th_dom) {
            $columns[] = trim($th_dom->nodeValue);
        }
        $all_values = array();
        for ($i = 1; $i < $tr_doms->length; $i ++) {
            $values = array($stop_id, $stop_name, $time);
            foreach ($tr_doms->item($i)->getElementsByTagName('td') as $td_dom) {
                $values[] = trim($td_dom->nodeValue);
            }
            $all_values[] = $values;
        }
        return array($columns, $all_values);
    }
}
