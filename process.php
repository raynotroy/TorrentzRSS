<?php

/* CONFIGURATION PARAMETERS */

define("RSSZ_USE_PROXY", false);
define("RSSZ_PROXY", 'localhost:8118');
/* Allow web browsers to get content from this file (Your TorrentzRSS back end) if its not located in the same domain as the requesting web page. */
define("RSSZ_ALLOW_CROSS_DOMAIN", true);

/* END OF CONFIGURATION PARAMETERS */

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

require_once 'XML/RSS.php';
require_once 'XML/Serializer.php';

if (RSSZ_ALLOW_CROSS_DOMAIN)
    header('Access-Control-Allow-Origin: *');

function array_orderby()
{
    $args = func_get_args();
    $data = array_shift($args);
    foreach ($args as $n => $field) {
        if (is_string($field)) {
            $tmp = array();
            foreach ($data as $key => $row)
                $tmp[$key] = $row[$field];
            $args[$n] = $tmp;
            }
    }
    $args[] = &$data;
    call_user_func_array('array_multisort', $args);
    return array_pop($args);
}

function process_url($url, &$channel) {
	$url = addslashes(stripslashes($url));
    $content = "";

    $command = (RSSZ_USE_PROXY) ? 'curl -x '.RSSZ_PROXY.' "'.$url.'" -iX GET' : 'curl "'.$url.'" -iX GET';
    $response = shell_exec($command);

    $isHeader = true;
    foreach(preg_split("/((\r?\n)|(\r\n?))/", $response) as $line){

        if ($isHeader && empty($line)) {
            $isHeader = false;
            continue;
        }
        if (!$isHeader) {
            $content .= $line.PHP_EOL;
        }
    }

    $filename = microtime(true);
    file_put_contents("data/".$filename.".xml", $content);

    $rss = new XML_RSS("data/".$filename.".xml");
    $rss->parse();
    $items = $rss->getItems();

    foreach ($items as $item) {
        preg_match("/Size\:\s(.*?)\sSeeds\:\s(\d+?)\D.*?Peers\:\s(\d+?)\D.*?Hash\:\s(.*?)$/", $item['description'], $m);
        $item['link'] = "http://torrage.com/torrent/".strtoupper($m[4]).".torrent";
        $item['size'] = $m[1];
        $item['size_raw'] = intval($m[1]);
        $item['hash'] = strtoupper($m[4]);
        $item['seeds'] = $m[2];
        $item['peers'] = $m[2] + $m[3];
        $item['leechers'] = $m[3];
        $item['seeds-leechers'] = $m[2] - $m[3];
        @$item['pubtimestamp'] = strtotime($item['pubdate']);
        $channel[] = $item;
    }

    unset($rss);
    unlink("data/".$filename.".xml");

    return count($items);
}

function run($p, $r, $q) {
	$channel = array();
	$params = explode('-', $p);
	$cin = array("ñ", "Ñ", "ç", "Ç", " ", ">", "<");
	$cout = array("%C3%B1", "%C3%91", "%C3%A7", "%C3%87", "+", "%3E", "%3C");
	$q = str_replace($cin, $cout, $q);
	
	$query = "http://torrentz.eu/" . $params[0] . "?q=" . $q;
	
	$total = 0;
	$page = 0;
	while (($sum = process_url($query.'&p='.$page, $channel)) != 0 && ($params[1] * 2 > $page)) {
		$total += $sum;
		$page+=2;
	}
	
	$r = explode('-', $r);
	foreach ($r as $rule) {
		switch (substr($rule, 0, 1)) {
			case 'l':
				//limit results
				if (count($channel) > intval(substr($rule, 1))) {
					//echo "RULE: $rule : ".count($channel)." > ".intval(substr($rule, 1))."<br>\n";
					$channel = array_slice($channel, 0, intval(substr($rule, 1)));
				}
				break;
			case 'm':
				//merge
				$tiny = substr($rule, 1);
				if (strlen($tiny) > 10 && file_exists("data/".$tiny)) {
					$request = unserialize(file_get_contents("data/".$tiny));
					$aux = run($request['p'], $request['r'], $request['q']);
					$channel = array_merge($channel, $aux);
				}
				break;
			case 's':
				//limit results
				$arg = (substr($rule, 2, 1) == 'A') ? SORT_ASC : SORT_DESC;
				$field = '';
				switch (substr($rule, 1, 1)) {
					case 't': $field = 'title'; break;
					case 'd': $field = 'pubtimestamp'; break;
					case 's': $field = 'size_raw'; break;
					case 'p': $field = 'peers'; break;
					case 'e': $field = 'seeds'; break;
					case 'l': $field = 'leechers'; break;
					case 'm': $field = 'seeds-leechers'; break;
				}
				$channel = array_orderby($channel, $field, $arg);
				break;
		}
	}
	
	return $channel;
}

//$params = explode('-', $_REQUEST['p']);
//$cin = array("ñ", "Ñ", "ç", "Ç", " ", ">", "<");
//$cout = array("%C3%B1", "%C3%91", "%C3%A7", "%C3%87", "+", "%3E", "%3C");
//$_REQUEST['q'] = str_replace($cin, $cout, $_REQUEST['q']);

if (isset($_REQUEST['tiny'])) {
	$data = array('q' => $_REQUEST['q'], 'p' => $_REQUEST['p'], 'r' => $_REQUEST['r']);
	$sdata = serialize($data);
	$tiny = md5($sdata);
	file_put_contents("data/".$tiny, $sdata);
	echo $tiny;
} else {
	//$query = "http://torrentz.eu/" . $params[0] . "?q=" . $_REQUEST['q'];

	$data['channel'] = array(
		"title" => "TorrentzRSS!",
		"link"  => "http://37.187.9.5/rssz",
		"ttl"  => 15,
		"total" => 0
	);

	/*$total = 0;
	$page = 0;
	while (($sum = process_url($query.'&p='.$page, $data['channel'])) != 0 && ($params[1] * 2 > $page)) {
		$total += $sum;
		$page+=2;
	}*/
	$value = run($_REQUEST['p'], $_REQUEST['r'], $_REQUEST['q']);
	$data['channel'] = array_merge($data['channel'], $value);
	$data['channel']["total"] = count($value);

	

	if (isset($_REQUEST['f']) && strtolower($_REQUEST['f']) == 'json') {
		header('Content-Type: application/json');
		echo json_encode($data, JSON_PRETTY_PRINT);
	} else {
		$serializer = new XML_Serializer($options);

		if ($serializer->serialize($data)) {
			header('Content-type: text/xml');
			echo $serializer->getSerializedData();
		}
	}

}

?>
