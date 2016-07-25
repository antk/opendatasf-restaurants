<?php
	// for yelp php request
	require_once('lib/OAuth.php');

	$time_start = microtime(true);

	/***************************** yelp data request **********************************/
	
	// this request function comes straight from yelp's php code sample
	// Set your OAuth credentials here  
	// These credentials can be obtained from the 'Manage API Access' page in the
	// developers documentation (http://www.yelp.com/developers)
	$CONSUMER_KEY = '';
	$CONSUMER_SECRET = '';
	$TOKEN = '';
	$TOKEN_SECRET = '';
	$API_HOST = 'api.yelp.com';
	$DEFAULT_TERM = 'dinner';
	$DEFAULT_LOCATION = 'San Francisco, CA';
	$SEARCH_LIMIT = 3;
	$SEARCH_PATH = '/v2/search/';
	$BUSINESS_PATH = '/v2/business/';
	$PHONE_PATH = '/v2/phone_search/';


	/** 
 * Makes a request to the Yelp API and returns the response
 * 
 * @param    $host    The domain host of the API 
 * @param    $path    The path of the APi after the domain
 * @return   The JSON response from the request      
 */
function request($host, $path) {
    $unsigned_url = "https://" . $host . $path;
    // Token object built using the OAuth library
    $token = new OAuthToken($GLOBALS['TOKEN'], $GLOBALS['TOKEN_SECRET']);
    // Consumer object built using the OAuth library
    $consumer = new OAuthConsumer($GLOBALS['CONSUMER_KEY'], $GLOBALS['CONSUMER_SECRET']);
    // Yelp uses HMAC SHA1 encoding
    $signature_method = new OAuthSignatureMethod_HMAC_SHA1();
    $oauthrequest = OAuthRequest::from_consumer_and_token(
        $consumer, 
        $token, 
        'GET', 
        $unsigned_url
    );
    
    // Sign the request
    $oauthrequest->sign_request($signature_method, $consumer, $token);
    
    // Get the signed URL
    $signed_url = $oauthrequest->to_url();
    
    // Send Yelp API Call
    try {
        $ch = curl_init($signed_url);
        if (FALSE === $ch)
            throw new Exception('Failed to initialize');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $data = curl_exec($ch);
        if (FALSE === $data)
            throw new Exception(curl_error($ch), curl_errno($ch));
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (200 != $http_status)
            throw new Exception($data, $http_status);
        curl_close($ch);
    } catch(Exception $e) {
        trigger_error(sprintf(
            'Curl failed with error #%d: %s',
            $e->getCode(), $e->getMessage()),
            E_USER_ERROR);
    }
    
    return $data;
}

function phone_search($phone) {
	$phone_path = $GLOBALS['PHONE_PATH'] . '?phone=' . urlencode($phone);
	return request($GLOBALS['API_HOST'], $phone_path);
}

// echo phone_search('14154217044');

/***************************** end yelp data request ******************************/

	$a = $argv;
	$args = array();
	
	$yelp = false;

	if(count($a) == 0) exit;
	for($i=0; $i<count($a); $i++) {
		if($a[$i] == "yelp") $yelp = true;
	}


	$url = "https://extxfer.sfdph.org/food/SFBusinesses.zip";
	$filename = "SFBusinesses.zip";
	$path = "./" . $filename;
	// chmod($path, 0755);

	$fp = fopen($path, "w");

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_FILE, $fp);

	$data = curl_exec($ch);

	curl_close($ch);
	fclose($fp);

	//unzip
	$zip = new ZipArchive;
	if($zip->open($path) === TRUE) {
		$zip->extractTo("./data");
		$zip->close();
	}

	$businesses = "./data/businesses.csv"; // we'll use businesses.csv as the main data source and append columns in there
	$inspections = "./data/inspections.csv"; // inspections.csv contains the business_id and score - there are multiple inspection dates and scores for each business id
	$legend = "./data/legend.csv"; // legend.csv contains the min score, max score, and description (eg 0-70 is poor)
	$violations = "./data/violations.csv"; // violations.csv contains every violation for a specific business_id - each business may have multiple violations
	                                       // we'll collect this info with html markup - ul,li and put the markup in it's own column

	// read the business csv and create an associative array with the business_id as the key
	$b_hash = array();
	$header = NULL;
	if(($handle = fopen($businesses, "r")) !== FALSE) {
		while(($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
			if(!$header) {
				$header = $row;
			}
			else {
				$data = array_combine($header, $row);
				unset($data['city']);
				unset($data['state']);
				$b_hash[$data["business_id"]] = $data;
				$b_hash[$data["business_id"]]["inspections"] = array();
				$b_hash[$data["business_id"]]["yelp_rating"] = "";
				$b_hash[$data["business_id"]]["yelp_rating_img"] = "";
				$b_hash[$data["business_id"]]["yelp_img"] = "";
				$b_hash[$data["business_id"]]["yelp_review_snippet"] = "";
			}
		}
		fclose($handle);
	}

	// read the legend csv to populate inspection score
	$l_hash = array();
	$l_header = NULL;
	if(($l_handle = fopen($legend, "r")) !== FALSE) {
		while(($row = fgetcsv($l_handle, 1000, ",")) !== FALSE) {
			if(!$l_header) {
				$l_header = $row;
			}
			else {
				$data = array_combine($l_header, $row);
				array_push($l_hash, $data);
			}
		}
		fclose($l_handle);
	}

	// read the inspections csv and write the inspection score and date 
	$i_hash = array();
	$i_header = NULL;
	if(($i_handle = fopen($inspections, "r")) !== FALSE) {
		while(($row = fgetcsv($i_handle, 1000, ",")) !== FALSE) {
			if(!$i_header) {
				$i_header = $row;
			}
			else {
				$data = array_combine($i_header, $row);
				$c = count($l_hash);

				for($i=0; $i<$c; $i++) {
					$min = $l_hash[$i]["Minimum_Score"];
					$max = $l_hash[$i]["Maximum_Score"];
					if($data["score"] >= $min && $data["score"] <= $max) {
						$data["description"] = $l_hash[$i]["Description"];
						break;
					}
				}
				$data["violations"] = array();
				array_push($b_hash[$data["business_id"]]["inspections"], $data);
			}
		}	
		fclose($i_handle);	
	}

	// read the violations csv to populate violations by date into inspections
	// there may be violations in the violations csv that aren't tied to an inspection
	// in those cases, they should be added to inspections without score data (which comes from inspection csv)
	// see business id 5257 as an example.  has violations records, but no inspection records
	$v_hash = array();
	$v_header = NULL;
	if(($v_handle = fopen($violations, "r")) !== FALSE) {
		while(($row = fgetcsv($v_handle, 1000, ",")) !== FALSE) {
			if(!$v_header) {
				$v_header = $row;
			}
			else {
				$data = array_combine($v_header, $row);
				// read each inspection for business_id and add violation for specific inspection date
				$inspections_by_id = $b_hash[$data["business_id"]]["inspections"];
				// echo json_encode($inspections_by_id) . "<br/><br/>";
				$c = count($inspections_by_id);
				if($c > 0) {
					for($i=0; $i<$c; $i++) {
						if($data["date"] === $inspections_by_id[$i]["date"]) {
							// echo "violation date matches inpsection date: " . $data["date"] . " for business_id: " . $data["business_id"] . " - " . $data["description"];
							// echo "<br/><br/>";
							array_push($inspections_by_id[$i]["violations"], $data["description"]);
						}
					}
					$b_hash[$data["business_id"]]["inspections"] = $inspections_by_id;
				}
				else {
					// inspections is empty, populate using violations

					$rec = array();
					$rec["business_id"] = $data["business_id"];
					$rec["score"] = "";
					$rec["date"] = $data["date"];
					$rec["type"] = "";
					$rec["description"] = "";
					$rec["violations"] = array();
					array_push($rec["violations"], $data["description"]);
					array_push($b_hash[$data["business_id"]]["inspections"], $rec);
				}
			}
		}
		fclose($v_handle);
	}

	// provide most recent score and violations as separate columns
	foreach($b_hash as $key => $value) {
		$most_recent_date = 0;
		$most_recent_score = 0;
		$most_recent_type = "";
		$most_recent_violations = array();
		$most_recent_score_category = "";

		$inspection_recs = $value["inspections"];
		$c = count($inspection_recs);
		for($i=0; $i<$c; $i++) {
			$a_rec = $inspection_recs[$i];
			if($a_rec["date"] >= $most_recent_date) {
				$most_recent_date = $a_rec["date"];
				$most_recent_score = $a_rec["score"];
				$most_recent_type = $a_rec["type"];
				$most_recent_violations = $a_rec["violations"];
				$cc = count($l_hash);
				for($j=0; $j<$cc; $j++) {
					$min = $l_hash[$j]["Minimum_Score"];
					$max = $l_hash[$j]["Maximum_Score"];
					if($a_rec["score"] >= $min && $a_rec["score"] <= $max) {
						$most_recent_score_category = $l_hash[$j]["Description"];
						break;
					}
				}
			}
		}
		$b_hash[$key]["Score"] = $most_recent_score;
		$b_hash[$key]["score_category"] = $most_recent_score_category;
		$b_hash[$key]["date"] = $most_recent_date;
		$b_hash[$key]["type"] = $most_recent_type;
		$b_hash[$key]["violation"] = implode("\\n", $most_recent_violations);

		//reset
		$most_recent_date = 0;
		$most_recent_score = 0;
		$most_recent_type = "";
		$most_recent_violations = array();
		$most_recent_score_category = "";
	}

	// now go through each record and query yelp api by phone number to get rating, review, and image data
	if($yelp) {
		$count = 1;
		$no_data = array();
		$skip = array();
		foreach($b_hash as $key => $value) {
			$p = str_replace(' ', '', $b_hash[$key]["phone_number"]);
			if($p) {
				// some very basic phone validation
				$p = substr($p, strpos($p, '+1')+2);
				$p_len = strlen($p);
				if($p_len !== 10 || preg_match("/[a-z]/i", $p)) { // check for phone number length = 10 and no alpha characters
					$skip_msg = $count . ": invalid phone number for business id: " . $key . " - " . $value["name"] . ", " . $p . " - skip";
					array_push($skip, $skip_msg);
				}
				else {
					echo $count . ": trying to get yelp data for business id: " . $key . " - " . $value["name"] . ", " . $value["phone_number"];
					$data = json_decode(phone_search($p));
					if($data->{'total'} >= 1) {
						$b_hash[$key]["yelp_rating"] = array_key_exists('rating', $data->{'businesses'}[0]) ? $data->{'businesses'}[0]->{'rating'} : "";
						$b_hash[$key]["yelp_rating_img"] = array_key_exists('rating_img_url_large', $data->{'businesses'}[0]) ? $data->{'businesses'}[0]->{'rating_img_url_large'} : "";
						$b_hash[$key]["yelp_img"] = array_key_exists('image_url', $data->{'businesses'}[0]) ? $data->{'businesses'}[0]->{'image_url'} : "";
						$b_hash[$key]["yelp_review_snippet"] = array_key_exists('snippet_text', $data->{'businesses'}[0]) ? $data->{'businesses'}[0]->{'snippet_text'} : "";
						echo " - yes\n";
					}
					else {
						echo " - no <===\n";
						$no_data_msg = "no yelp data for " . $value["name"] . ": bid - " . $key . ", phone: " . $value["phone_number"];
						array_push($no_data, $no_data_msg);
					}
				}
			}
			$count++;
		}

		echo "\n\nskipped " . count($skip) . " records\n";
		foreach($skip as $val) {
			echo $val . "\n";
		}

		echo "\n\nyelp supplied no data for " . count($no_data) . " records\n";
		foreach($no_data as $val) {
			echo $val . "\n";
		}
	}

	/*** debug ***/
	// echo json_encode($b_hash[$_GET["bid"]]);
	// $arr = array('a' => array('b' => array('x'=>0, 'y'=>1), 'c' => 3, 'd' => 4, 'e' => 5));
	// print_r($arr);
	// echo json_encode($arr);

	// print_r($b_hash);

	// echo json_encode($b_hash);

	// $count = 1;
	// echo "[";
	// foreach($b_hash as $key => $value) {
	// 	echo json_encode($value) . "<br/><br/>";
	// 	$count += 1;
	// }
	// echo "]";
	// echo $count;
	/*** end debug ***/


	// write to new file
	// $merged_file = fopen("merged-tmp.csv", "w");
	// $inspection_count = 1;
	// foreach($b_hash as $key => $value) {
	// 	// need to loop through inspections and violoations arrays and change them to ul/li html strings
	// 	$rec = $value;
	// 	$rec_inspections = $rec["inspections"];
	// 	for($i=0; $i<count($rec_inspections); $i++) {
	// 		echo "&nbsp;&nbsp;&nbsp;date: " . $rec_inspections[$i]["date"] . "<br/>";
	// 		echo "&nbsp;&nbsp;&nbsp;&nbsp;score: " . $rec_inspections[$i]["score"] . "<br/>";
	// 		echo "&nbsp;&nbsp;&nbsp;&nbsp;violations: " . "<br/>";
	// 		$rec_inspections_violations = $rec_inspections[$i]["violations"];
	// 		for($j=0; $j<count($rec_inspections_violations); $j++) {
	// 			echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $rec_inspections_violations[$j] . "<br/>";
	// 		}
	// 	}
	// 	$inspection_count++;
	// 	// fputcsv($merged_file, $value);
	// }
	// fclose($merged_file);
	
	$merged_file = fopen("merged-tmp.csv", "w");
	$firstLineKeys = false; 
	foreach($b_hash as $key => $value) {
		$rec = $value;
		if(empty($firstLineKeys)) {
			$firstLineKeys = array_keys($rec);
			fputcsv($merged_file, $firstLineKeys);
			$firstLineKeys = array_flip($firstLineKeys);
		}
		$rec["inspections"] = json_encode($rec["inspections"]);
		fputcsv($merged_file, array_merge($firstLineKeys, $rec));
	}
	fclose($merged_file);

	$time_end = microtime(true);
	$time = $time_end - $time_start;

	$hours = floor($time / 3600);
	$minutes = floor(($time / 60) % 60);
	$seconds = $time % 60;

	echo "\n\nProcessing took " . $hours ."h:" . $minutes ."m:" . $seconds . "s\n\n";

	// $bid = $_GET["bid"] ? $_GET["bid"] : '10';
	// echo json_encode($b_hash[$bid]);

?>