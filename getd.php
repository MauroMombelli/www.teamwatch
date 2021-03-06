<?php
/*
$id_chat (idc)    = indice messaggi chat
$id_twitter (idt) = indice messaggi twitter  *******  si può usare l'id twitter?
$query (q)        = query
$twid (twid)      = id teamwatch

se $id_chat e $id_twitter sono -1
(SELECT * FROM `teamwatch` WHERE `is_tweet` = 1 and `feed` like '%$query%' ORDER by id DESC LIMIT 1)
--> idt = $row->id_twitter
(SELECT * FROM `teamwatch` WHERE `is_tweet` = 0 and `feed` like '%$query%' ORDER by id DESC LIMIT 1)
--> idc = $row->id
--> restituisce staus = setting con idt e idc più alti per la query q
    
se trova un setting con id > idc
(SELECT * FROM `teamwatch` WHERE `id` > $id_chat and `user` = '$twid' ORDER by id DESC LIMIT 1)
--> restituisce il status = setting, text = text

se trova un messaggio con id > idc, feed like %q% e is_tweet = 0
(SELECT * FROM `teamwatch` WHERE `id` > $id_chat and `feed` like '%$query%' and not `text` like '#tw:%' and `is_tweet` = 0 ORDER by id DESC LIMIT 1)
--> restituisce messaggio chat

se trova un messaggio con id > idt, feed like %q% e is_tweet = 1
(SELECT * FROM `teamwatch` WHERE `id_twitter` > $id_twitter and `feed` like '%$query%' and `is_tweet` = 1 ORDER by id DESC LIMIT 1)
--> restituisce messaggio twitter

recupera messaggi da twitter e li salva nel database
--> restituisce il primo messaggio twitter con id_twitter > $id_twitter
*/

require_once('TwitterAPIExchange.php');
    $settings = array(
        'oauth_access_token' => "*******secret*******",
        'oauth_access_token_secret' => "*******secret*******",
        'consumer_key' => "*******secret*******",
        'consumer_secret' => "*******secret*******"
    );

    $servername = "localhost"; 
    $database = "TeamWatch";
    $username = "<db username>";
    $password = "<db password>";

    // id, user, text, time, feed, is_tweet, twitter_id

    define('DEBUG', 1);
    if (DEBUG == 1) {
        error_reporting(E_ALL);
    } else {
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
    }

    $mysqli = mysqli_connect($servername, $username, $password, $database);
    if ($mysqli->connect_errno) {
        die (json_error($mysqli->connect_error));
    }

    $mysqli->query("SET NAMES utf8");
    $mysqli->query("SET character_set_results = 'utf8', character_set_client = 'utf8', character_set_connection = 'utf8', character_set_database = 'utf8', character_set_server = 'utf8'");
    
    function escape_string ($txt) {
        return str_replace("\"", "\\\"", $txt);
    }

    function json_message ($id, $user, $text, $is_twitter, $time) {
        $escape_string = escape_string;
        return "{\"status\":\"ok\", \"id\":$id, \"user\":\"{$escape_string($user)}\", \"text\":\"{$escape_string($text)}\", \"is_twitter\":$is_twitter, \"time\":\"$time\"}";
    }

    function json_error ($reason) {
        $escape_string = escape_string;
        return "{\"status\":\"fail\", \"reason\":\"{$escape_string($reason)}\"}";
    }

    function sql_select($sql) {
        global $mysqli;
        
        if (DEBUG == 1) echo $sql . "<br>";
        
        if ($result = $mysqli->query($sql)) {
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                return $row;
            } else {
                return NULL;
            }
            
            $result->close();
        } else {
            if (isset($mysqli)) {
                $err = $mysqli->error;
                $mysqli->close();
            } else {
                $err = "error in sql_select mysqli is null";
            }
            
            die (json_error($err));
        }
    }

    // ******* GET PARAMETERS ****************************************************************************************

    $id_chat = intval($_GET['idc']);
    $id_twitter = intval($_GET['idt']);
    if (isset($_GET['q'])) $query = $mysqli->real_escape_string($_GET['q']);
    if (isset($_GET['twid'])) $twid = $mysqli->real_escape_string($_GET['twid']);
    if (isset($_GET['pcid'])) $pcid = $mysqli->real_escape_string($_GET['pcid']);
    $twitter_query_params = $mysqli->real_escape_string($_GET['tqp']);
    $twitter_lang = (isset($_GET['tl'])) ? $_GET['tl'] : '%';
    
    if (isset($query)) {
        $query_array = explode(":", $query);

        $twitter_query = implode (" OR ", $query_array);

        $i = count($query_array);
        $sql_query = '';

        while ($i) {
            $q = $query_array[--$i];
            $sql_query .= " `feed` like '%$q%' ";
            if ($i>0) $sql_query .= "OR";
        }
    }
    
    if (DEBUG == 1) {
        echo "\$id_chat = $id_chat<br>";
        echo "\$id_twitter = $id_twitter<br>";
        echo "\$query = $query<br>";
        echo "\$twid = $twid<br>";
        echo "\$pcid = $pcid<br>";
        echo "\$twitter_query_parms = $twitter_query_params<br>";
        echo "\$twitter_query = $twitter_query<br>";
        echo "\$sql_query = $sql_query<br>";
        echo "<br>";
    }

    // ******* SETTINGS #TW:ID ***************************************************************************************
    if (!isset($_GET['idc'], $_GET['idt']) || ($id_chat == -1 && $id_twitter == -1)) {
        // ******* GET LAST TWITTER_ID ***********************************************************************************
        $sql = "SELECT `twitter_id` FROM `messages` WHERE `is_tweet` = 1 ORDER by `twitter_id` DESC LIMIT 1";
        $res = sql_select($sql);
        $id_twitter = ($res) ? $res['twitter_id'] : 0;
        
        // ******* GET LAST CHAT_ID **************************************************************************************
        $sql = "SELECT `id` FROM `messages` WHERE `is_tweet` = 0 ORDER by `id` DESC LIMIT 1";
        $res = sql_select($sql);
        $id_chat = ($res) ? $res['id'] : 0;

        $mysqli->close();
        die("{\"status\":\"settings\", \"param\": \"#tw:id\", \"idc\": $id_chat, \"idt\": $id_twitter}");
    }
    
    if (!isset($twid) || $twid == '') die(json_error('missing parameter twid'));
    
    // ******* SETTINGS #TW:... **************************************************************************************
    if (isset($pcid) && $pcid != '') {
        $sql = "SELECT * FROM `messages` WHERE `id` > $id_chat and (`user` = '$twid' or `user` = '$pcid') ORDER by `id` DESC LIMIT 1";
    } else {
        $sql = "SELECT * FROM `messages` WHERE `id` > $id_chat and `user` = '$twid' ORDER by `id` DESC LIMIT 1";
    }
    $res = sql_select($sql);
    if ($res) {
        $mysqli->close();
        die("{\"status\":\"settings\", \"param\": \"{$res['text']}\", \"id\":{$res['id']}}");
    }
    
    if (!isset($query) || $query = '') die(json_error('missing parameter q'));    

    // ******* GET NEXT CHAT MESSAGE *********************************************************************************
    $sql = "SELECT * FROM `messages` WHERE `id` > $id_chat and ($sql_query) and not `text` like '#tw:%' and `is_tweet` = 0 ORDER by `id` LIMIT 1";
    $res = sql_select($sql);
    if ($res) {
        $mysqli->close();
        die(json_message($res['id'], $res['user'], $res['text'], 0, $res['time']));
    }
    
    if (!isset($_GET['notweet'])) {
        // ******* GET NEXT TWITTER MESSAGE ******************************************************************************
        $sql = "SELECT * FROM `messages` WHERE `twitter_id` > $id_twitter and ($sql_query) and `is_tweet` = 1 and `twitter_lang` = '$twitter_lang' ORDER by `twitter_id` LIMIT 1";
        $res = sql_select($sql);
        if ($res) {
            $mysqli->close();
            die(json_message($res['twitter_id'], $res['user'], $res['text'], 1, $res['time']));
        }
    
        $res = sql_select("SELECT * FROM `twitter_access` WHERE 1 LIMIT 1");
        if ($res) {
            $tdiff = time() - strtotime($res['last_request_time']);
            if ($tdiff < 10) {
                $mysqli->close();
                $t = 10-$tdiff;
                die(json_error("waiting $t secs to avoid search rate limit"));
            }
        }
        
        // ******* TWITTER API SEARCH ************************************************************************************
        $url = 'https://api.twitter.com/1.1/search/tweets.json';
        // $twitter_query_params = "+-filter:retweets&lang=it&result_type=recent&count=15";
        $getfield = "?q=($twitter_query)+-filter:retweet$twitter_query_params&count=15&since_id=$id_twitter";
        if (DEBUG == 1) echo "Twitter: $url$getfield";
        $requestMethod = 'GET';
        $twitter = new TwitterAPIExchange($settings);
        $response = $twitter->setGetfield($getfield)
            ->buildOauth($url, $requestMethod)
            ->performRequest();

        $statuses = json_decode($response)->statuses;

        if (count($statuses) == 0) {
            // ******* NO NEW TWEETS SAVE LAST API/SEARCH TIME FOR RATE LIMIT ************************************************
            $sql = "UPDATE `twitter_access` SET result='no feeds', last_request_time=CURRENT_TIMESTAMP WHERE 1";
            $mysqli->query($sql);
            $mysqli->close();
            die(json_error('no feeds'));
        } else {
            // ******* SAVE LAST API/SEARCH TIME FOR RATE LIMIT **************************************************************
            $sql = "UPDATE `twitter_access` SET result='ok', last_request_time=CURRENT_TIMESTAMP WHERE 1";
            $mysqli->query($sql);
            
            // ******* ADD THE NEW TWEETS TO THE DATABASE ********************************************************************
            $index = count($statuses);
            while($index) {
                $status = $statuses[--$index];

                foreach ($query_array as $tag) if (strpos($status->text . $status->user->name, $tag) !== false) break;

                // if (DEBUG == 1) var_dump($status);
                
                $sql = "INSERT INTO `messages` VALUES (NULL, '" . 
                    $mysqli->real_escape_string($status->user->name) . "', '" . 
                    $mysqli->real_escape_string($status->text) . "', NULL, '" . 
                    $mysqli->real_escape_string($tag) . "', 1, " . 
                    $status->id . ", '" .
                    $status->lang . "')";
                
                if (DEBUG == 1) echo $sql . "<br>"; // var_dump($status);
                
                $mysqli->query($sql);
            }
            $mysqli->close();
            
            // ******* RETURN THE OLDEST TWEET *******************************************************************************
            $status = $statuses[count($statuses)-1];
            die(json_message($status->id, $status->user->name, $status->text, 1, $status->created_at));
        }
    }
    
    $mysqli->close();
    die(json_error('no feeds'));
?>
