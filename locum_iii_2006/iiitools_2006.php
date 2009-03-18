<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * @package Locum
 * @category Locum Connector
 * @author John Blyberg
 */

/**
 * This is a standalone class that interacts with the III webpac.
 * It provides a PHP interface to all interactive functions within the III webpac.
 *
 * In order to be actively logged in, you must set either $cardnum or $pnum as well 
 * as $pin, even if your library doesn't use pins.  If that's the case, then $pin
 * can be set to anything.
 */
class iiitools {

	public $iiiserver;
	public $cardnum;
	public $pnum;
	public $cookie;
	public $patroninfo;
	protected $papi;
	protected $pin;

	/**
	 * Class constructor.
	 * Initializes the requisite variables and classes.
	 */
	public function __construct() {
		$this->cookie = self::set_cookie_file();
		$this->papi = new iii_patronapi;
	}

	/**
	 * Class destructor.
	 * Logs the process out and deletes the cookie file.
	 */
	public function __destruct() {
		self::catalog_logout();
		if (is_file($this->cookie)) { unlink($this->cookie); }
	}

	/**
	 * Sets the cardnumber for the active instansiation
	 *
	 * @param string $cardnum Library card number
	 */
	public function set_cardnum($cardnum) {
		$this->cardnum = $cardnum;
		self::load_patroninfo($cardnum);
		self::set_cookie_file($cardnum, NULL);
		$this->pnum = $this->patroninfo[RECORDNUM];
	}

	/**
	 * Sets the III server for the active instansiation
	 *
	 * @param string $iiiserver III server address, IP or FQDN
	 */
	public function set_iiiserver($iiiserver) {
		$this->iiiserver = $iiiserver;
		$this->papi->iiiserver = $iiiserver;
	}
	
	/**
	 * Sets the pin for the current $pnum or $cardnum within the active instansiation
	 *
	 * @param string $pin Pin/password
	 */
	public function set_pin($pin = 'unused') {
		$this->pin = $pin ? $pin : 'unused';
	}

	/**
	 * Populates the patroninfo object with patron info via the Patron API class
	 *
	 * @param string $pid Patron ID: can be either cardnum or pnum
	 */
	public function load_patroninfo($pid) {
		if (!$this->papi->iiiserver) { exit('No servers set'); }
		$this->patroninfo = $this->papi->get_patronapi_data($pid);
	}

	/**
	 * Sets the cookie file for the class.
	 * Used in the class constructor as well as the login routine.
	 *
	 * @param string $cardnum Library card number (optional)
	 * @param string $pnum Patron ID number (optional)
	 */
	public function set_cookie_file($cardnum = NULL, $pnum = NULL) {
		
		$cookie_dir = '/tmp/cookies_iii';
		if (!is_dir($cookie_dir)) {
			if (is_file($cookie_dir)) {
				if (!@unlink($cookie_dir)) { exit('Unable to create cookie directory: ' . $cookie_dir); }
			}
			if (!@mkdir($cookie_dir)) { exit('Unable to create cookie directory: ' . $cookie_dir); }
		}

		if (!$cardnum && !$pnum) {
			$id = rand(1,1000000);
		} else {
			$id = $cardnum ? $cardnum : $pnum;
		}
		$this->cookie = $cookie_dir . '/cookie.txt.' . $id;
	}

	/**
	 * Logs the process in to the webcat interface
	 *
	 * @return boolean TRUE if logged in, FALSE if not
	 */
	public function catalog_login() { // TODO add boolean result
		if (!isset($this->patroninfo)) { exit('Patron Info not yet initialized'); }
		if (!$this->pin) { exit('PIN not yet set'); }
		$form_url = "patroninfo/";
		$postvars = 'name=' . $this->patroninfo[PATRNNAME] . '&code=' . $this->cardnum . '&pin=' . $this->pin;
		self::my_curl_exec($form_url, $postvars);
		return TRUE;
	}

	/**
	 * Logs the process out of the webcat interface
	 */
	public function catalog_logout() {
		$url = "logout/";
		return self::my_curl_exec($form_url);
	}

	/**
	 * Returns an array of checked-out items.
	 *
	 * @param boolean $sort_by_due Sort items by due date (optional)
	 * @return array An array of items checked out.
	 */
	public function get_patron_items($sort_by_due = TRUE) {
		if ($sort_by_due) {
			$url_suffix = 'patroninfo/' . $this->pnum . '/sorteditems';
		} else {
			$url_suffix = 'patroninfo/' . $this->pnum . '/items';
		}
		$result = self::my_curl_exec($url_suffix);
		return self::parse_patron_items($result[body]);
	}

	/**
	 * Parses through the raw return from cURL to formulate the array passed back by get_patron_items()
	 *
	 * @param string $itemlist_raw Raw output from cURL
	 * @return array An array of items checked out.
	 */
	public function parse_patron_items($itemlist_raw) {

		$regex = '%<input type="checkbox" name="(.+?)" value="(.+?)" />(.+?)patFuncTitle">(.+?)</td>(.+?)patFuncBarcode"> (.+?) </td>(.+?)patFuncStatus"> DUE (.+?) (<span  class="patFuncRenewCount">(.+?)</span>)?(.+?)</td>(.+?)patFuncCallNo"> (.+?)</td>%s';
		$count = preg_match_all($regex, $itemlist_raw, $rawmatch);

	
		for ($i=0; $i < $count; $i++) {
			$item[$i][varname] = trim($rawmatch[1][$i]);
		
			$item[$i][inum] = substr(trim($rawmatch[2][$i]), 1);
			$item[$i][bnum] = self::inum_to_bnum($item[$i][inum]);

			$title_mess = trim($rawmatch[4][$i]);
			if (preg_match('%href(.+?)</a>%s', $title_mess, $sub_title_mess)) {
				preg_match('%">(.+?)</a>%s', $sub_title_mess[0], $sub_title_mess_arr);
				$item[$i][title] = trim($sub_title_mess_arr[1]);
				$item[$i][ill] = 0;
			} else {
				$item[$i][title] = trim($title_mess);
				$item[$i][ill] = 1;
			}

			if (trim($rawmatch[11][$i])) {
				preg_match('%Renewed (.+?) time%s', $rawmatch[11][$i], $num_renews_raw);
				$item[$i][numrenews] = trim($num_renews_raw[1]);
			} else {
				$item[$i][numrenews] = 0;
			}

			$item[$i][duedate] = self::date_to_timestamp(trim($rawmatch[8][$i]));
			$item[$i][callnum] = trim($rawmatch[13][$i]);
		}
		return $item;

	}
	
	/**
	 * Returns an array of on-hold items.
	 *
	 * @return array An array of on-hold items.
	 */
	public function get_patron_holds() {

		$url_suffix = 'patroninfo/' . $this->pnum . '/holds';
		$result = self::my_curl_exec($url_suffix);

		$regex = '%<input type="checkbox" name="(.+?)" /></td>(.+?)patFuncTitle">(.+?)</td>(.+?)patFuncStatus">(.+?)</td>(.+?)patFuncPickup">(.+?)</td>(.+?)patFuncCancel">(.+?)</td>%s';
	
		$count = preg_match_all($regex, $result[body], $rawmatch);
		for ($i=0; $i < $count; $i++) {
			$item[$i][varname] = trim($rawmatch[1][$i]);

			if (!preg_match('%@%s', $item[$i][varname])) {
				preg_match('%item&(.+?)">(.+?)</a>%s', trim($rawmatch[3][$i]), $sub_title_mess_arr);
				$item[$i][bnum] = trim($sub_title_mess_arr[1]);
				$item[$i][title] = trim($sub_title_mess_arr[2]);
				$item[$i][ill] = 0;
			} else {
				// ILL request
				$item[$i][title] = trim($rawmatch[3][$i]);
				$item[$i][ill] = 1;
			}
			$status = trim($rawmatch[5][$i]);
			if ((!preg_match('/of/i', $status)) && (!preg_match('/ready/i', $status)) && (!preg_match('/RECEIVED/i', $status))) { 
				$status = "Waiting for your copy";
			}
			$item[$i][status] = $status;
			$item[$i][pickuploc] = trim($rawmatch[7][$i]);
			$canceldate = trim(str_replace('&nbsp;', '', $rawmatch[9][$i]));
			if ($canceldate) {
				$item[$i][canceldate] = $canceldate;
			}
		}
		return $item;
	}

	/**
	 * Place a hold on a particular bib item.
	 *
	 * @param string $bnum Bib number
	 * @param string $pickup_loc Pickup location (optional).  //TODO
	 * @return array my_curl_exec result array
	 */
	public function place_hold($bnum = NULL, $inum = NULL, $pickup_loc = NULL) {

		$url_suffix = 'search~S3/.b' . $bnum . '/.b' . $bnum . '/1,1,1,B/request~b' . $bnum;
		$postvars[] = 'name=' . urlencode($this->patroninfo[PATRNNAME]);
		$postvars[] = 'code=' . $this->cardnum;
		$postvars[] = 'pin=' . $this->pin;
		$postvars[] = 'neededby_Month=' . date('m');
		$postvars[] = 'neededby_Day=' . date('d');
		$postvars[] = 'neededby_Year=' . (int)(date('Y') + 1);
		if ($inum) {
			$postvars[] = 'submit=SUBMIT';
			$postvars[] = 'radio=' . $inum;
		}
		$post = implode('&', $postvars);
		if ($pickup_loc) { $postvars[] = 'loc=' . $pickup_loc; }
		
		// To make sure the record has been freed.  Otherwise we run in to a race condition.
		usleep(300000);
		
		$result = self::my_curl_exec($url_suffix, $post);
		
		if (preg_match('/Your request for(.*?)was successful/is', $result[body])) {
			$result[success] = 1;
		} else {
			$result[success] = 0;
		}
		
		if (preg_match('/<font color="red" size="(.+?)">(.+?)<\/font>/is', $result[body], $error_match)) {
			$result[error] = trim($error_match[2]);
		}
		if (preg_match('/Choose one item from the list below/is', $result[body])) {
			preg_match_all('/<tr  class="bibItemsEntry">(.+?)<\/td>(.+?)<!-- field 1 -->&nbsp; (.+?)<\/td>(.+?)<!-- field C -->&nbsp;(.+?)&nbsp; <!-- field v -->(.*?)&nbsp;(.+?)field \% -->&nbsp;(.+?)</is', $result[body], $items_match_raw);
			$num_items = count($items_match_raw[0]);
			for ($i = 0; $i < $num_items; $i++) {
				preg_match('/value="(.+?)"/is', $items_match_raw[1][$i], $inum_match);
				$result[selection][$i][varname] = trim($inum_match[1]);
				$result[selection][$i][location] = trim($items_match_raw[3][$i]);
				$result[selection][$i][callnum] = trim($items_match_raw[5][$i]) . ' ' . trim($items_match_raw[6][$i]);
				$result[selection][$i][status] = trim($items_match_raw[8][$i]);
			}
		}

		return $result;
	}

	/**
	 * Cancel a hold on a particular item or list of items.
	 *
	 * @param array $holdvars Array of hold variables to cancel.  Holdvars come from get_patron_holds().
	 * @return array my_curl_exec result array
	 */
	public function cancel_holds($holdvars) {
		$url_suffix = 'patroninfo/' . $this->pnum . '/holds?updateholdssome=TRUE';
		foreach ($holdvars as $var1 => $var2) {
			$getvars[] = $var2 . '=1';
		}
		$cancelations = implode('&', $getvars);
		$url_suffix .= '&' . $cancelations;
		usleep(300000); // To make sure the record has been freed.
		$result = self::my_curl_exec($url_suffix);
		return $result; // TODO make the return info a little more useful - Handle errors, etc
	}

	/**
	 * Renew an item or list of items or everything.
	 *
	 * @param array $renew_arg Array of varname and item numbers to renew (optional).  If not given, it renews everything.
	 * @return boolean|array Array of checked-out items, their renewal status, and new due date if applicable.
	 */
	public function renew_material($renew_arg = 'all') {

		if (is_array($renew_arg)) {
			foreach ($renew_arg as $inum => $varname) {
				if ($inum[0] != 'i') { $inum = 'i' . $inum; }
				$get_args[] = $varname . '=' . $inum;
			}
			$args = implode('&', $get_args);
			$url_suffix = 'patroninfo/' . $this->pnum . '/sorteditems?renewsome=TRUE&' . $args;
		} else if (strtolower($renew_arg) == 'all') {
			$url_suffix = 'patroninfo/' . $this->pnum . '/sorteditems?renewall';
		}
		usleep(300000); // To make sure the record has been freed.
		$result = self::my_curl_exec($url_suffix);
		return self::parse_patron_renews($result[body], $renew_arg);
	
	}

	/**
	 * Returns an array of checked-out items, their renewal status, and new due date if applicable.
	 *
	 * @param string $renewlist_raw HTTP body from cURL execution
	 * @param array $renew_arg Array of varname and item numbers to renew (optional).  Assumes remew-all if not given.
	 * @return boolean|array Array of checked-out items, their renewal status, and new due date if applicable.
	 */
	public function parse_patron_renews($renewlist_raw, $renew_arg = NULL) {

		// These are subject to change at any time
		$regex_indiv = '%%<input type="checkbox" name="%s" value="%s" \/>(.*?)DUE(.*?)<(.+?)td%%s';
		$regex_rnall = '%<input type="checkbox" name="(.*?)" value="i(.*?)" \/>(.*?)DUE(.*?)<(.+?)td%s';

		// If renewing individual items
		if (is_array($renew_arg)) {
			foreach ($renew_arg as $inum => $varname) {
				if ($inum[0] != 'i') { $inum_reg = 'i' . $inum; } else { $inum_reg = $inum; }
				$regex = sprintf($regex_indiv, $varname, $inum_reg);
				preg_match($regex, $renewlist_raw, $rawmatch);
				$extra = $rawmatch[3];
				if (preg_match('/Renewed(.*?)time/i', $extra, $renew_match)) { 
					$renew_res[$inum][num_renews] = (int) trim($renew_match[1]);
				} else {
					$renew_res[$inum][num_renews] = 0;
				}
				if (preg_match('/color=\"red\">(.*?)</i', $extra, $error_match)) {
					$renew_res[$inum][error] = ucwords(strtolower(trim($error_match[1])));
				}
				$renew_res[$inum][varname] = $varname;
				$renew_res[$inum][new_duedate] = self::date_to_timestamp($rawmatch[2]);
			}
		// If remewing all items
		} else {
			if (strtolower($renew_arg) == 'all') {
				$regex = $regex_rnall;
				preg_match_all($regex, $renewlist_raw, $rawmatch);
				$varnames = $rawmatch[1];
				$inums = $rawmatch[2];
				foreach ($rawmatch[5] as $key => $extra) {
					if (preg_match('/Renewed(.*?)time/i', $extra, $renew_match)) {
						$renew_res[$inums[$key]][num_renews] = (int) trim($renew_match[1]);
					} else {
						$renew_res[$inums[$key]][num_renews] = 0;
					}
					if (preg_match('/color=\"red\">(.*?)</i', $extra, $error_match)) {
						$renew_res[$inums[$key]][error] = ucwords(strtolower(trim($error_match[1])));
					}
				}
				foreach ($rawmatch[2] as $key => $inum) {
					$renew_res[$inum][varname] = trim($varnames[$key]);
				}
				foreach ($rawmatch[4] as $key => $due) {
					$renew_res[$inums[$key]][new_duedate] = self::date_to_timestamp($due);
				}
			} else {
				return FALSE;
			}
		}
		return $renew_res;
	}

	/**
	* Returns current fines
	*
	* @return string HTML body from the fine payment screen
	*/
	function get_patron_fines() {
		$url_suffix = 'patroninfo/' . $this->pnum . '/overdues?pay=1';
		$result = self::my_curl_exec($url_suffix);
		return self::parse_patron_fines($result[body]);
	}

	/**
	* Parses current fines result
	*
	* @return array of fine details
	*/
	function parse_patron_fines($body) {
		$regex = '%type="checkbox" name="charge(.+?)" checked>(.+?)</td>(.+?)right">\$(.+?)<%s';
		$count = preg_match_all($regex, $body, $rawmatch);
		$fines = array();
		for ($i=0; $i < $count; $i++) {
			$fines[$i][varname] = 'charge' . trim($rawmatch[1][$i]);
			$fines[$i][desc] = trim($rawmatch[2][$i]);
			$fines[$i][amount] = (float) trim($rawmatch[4][$i]);
		}
		return $fines;
	}
	
	/**
	* Pays fines for whichever fines are passed through to the function.
	* $payment_arr looks like:
	* [{varnames}] 	= 'on' :: This tells III which fines are being paid.
	* [amount]		= payment amount.
	* [ccname]		= Name on the credit card.
	* [address1]	= Billing address.
	* [city]		= Billing address city.
	* [state]		= Billing address state.
	* [zip]			= Billing address zip.
	* [emailaddr]	= Cardholder email address.
	* [ccnum]		= Credit card number.
	* [ccexpmonth]	= Credit card expiration date.
	* [ccexpyear]	= Credit card expiration year.
	* [cc_cvv2]		= Credit card verification number.
	*
	* @param array Payment details array.
	* @return array Payment results array.
	*/
	function pay_fine($payment_arr) {

		$url_suffix_pre = '/payconfirm/' . $this->pnum . '/%2Fpatroninfo%2F' . $this->pnum . '%2Foverdues%3Fpay%3D1/%2Fpatroninfo%2F' . $this->pnum . '%2Foverdues';
		$url_suffix = 'patroninfo/' . $this->pnum . '/overdues?pay=1';

		$postvars_noconfirm = 'entered=Y';
		foreach ($payment_arr as $pkey => $pval) {
			$postvars .= '&' . $pkey . '=' . urlencode($pval);
		}
		
		$pay_result_pre = self::my_curl_exec($url_suffix_pre, $postvars_noconfirm . $postvars);
		preg_match('/name="cksum" value="(.*?)">/', $pay_result_pre[body], $chksum_match);
		$postvars_confirm = 'cksum=' . trim($chksum_match[1]) . '&entered=Y&confirmed=Y';
		usleep(500000); // To make sure the record has been freed.
		$pay_result = self::my_curl_exec($url_suffix, $postvars_confirm . $postvars);
		if (preg_match('%Your payment has been approved%s', $pay_result[body])) {
			$result_arr[approved] = 1;
		} else {
			$result_arr[approved] = 0;
			$is_msg = preg_match('%errormessage">(.+?)<(.+?)class="msg">(.+?)<%s', $pay_result[body], $err_match);
			$result_arr[reason] = trim($err_match[3]);
			$result_arr[error] = trim($err_match[1]);
		}
		return $result_arr;
	}

	/**
	 * Resolves the bib record number from an item record number
	 */
	public function inum_to_bnum($inum) {
		$url_suffix = 'record=i' . $inum;
		$record_result = self::my_curl_exec($url_suffix);
		preg_match('%">B(.*?)<\/%s', $record_result[body], $bnum_raw_match);
		return substr(trim($bnum_raw_match[1]), 0, -1);
	}

	/**
	 * Converts MM-DD-YY to unix timestamp
	 *
	 * @param string $date_orig Original date in MM-DD-YY format
	 * @param int Optional century to use as a baseline.  Fix for III's Y2K issues.
	 * @return timestamp
	 */
	public function date_to_timestamp($date_orig, $default_century = NULL) {
		$date_arr = explode('-', trim($date_orig));
		if (strlen(trim($date_arr[2])) == 2) {
			if ($default_century) {
				$year = $default_century + (int) trim($date_arr[2]);
			} else {
				$year = $default_century + (int) trim($date_arr[2]);
				if (date('Y') < $year) { $year = 1900 + (int) trim($date_arr[2]); }
			}
		} else {
			$year = trim($date_arr[2]);
		}
		$time = mktime(0, 0, 0, $date_arr[0], $date_arr[1], $year);
		return $time;
	}

	/**
	 * Executes a cURL request while handling all the session business.
	 *
	 * @param string $url_suffix The URL to query.  Everything after the 'http://my.addr.org/'
	 * @param string $postvars POST variables to pass in GET format. Ex:  var1=foo&var2=bar
	 * @param boolean $no_loop Overrides this functions default to loop through 10 times to get a result
	 * @param int $curl_timeout Timeout, in seconds, before cURL gives up curl_exec.  (optional).  Default: 6
	 * @return array Array of parsed components from the cURL result as provided by parse_response()
	 */
	public function my_curl_exec($url_suffix, $postvars = NULL, $no_loop = FALSE, $curl_timeout = 6) {

		$agent = "Macintosh; U; Intel Mac OS X 10.5; en-US; rv:1.9) Gecko/2008061004 Firefox/3.0"; // You got a better idea?
		if ($url_suffix[0] == '/') { $url_suffix = substr($url_suffix, 1); }
		$curl_url = 'https://' . $this->iiiserver . '/' . $url_suffix;

		$ch = curl_init();

		// If we have POST variables to send, initializr them here
		if ($postvars) {
			curl_setopt ($ch, CURLOPT_POST, 1);
			curl_setopt ($ch, CURLOPT_POSTFIELDS, $postvars);
		}

		// Set all the CURL options
		curl_setopt ($ch, CURLOPT_USERAGENT, $agent);
		curl_setopt ($ch, CURLOPT_URL, $curl_url);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_COOKIEJAR, $this->cookie);
		curl_setopt ($ch, CURLOPT_COOKIEFILE, $this->cookie);
		curl_setopt ($ch, CURLOPT_COOKIESESSION, TRUE);
		curl_setopt ($ch, CURLOPT_HEADER, 1);
		curl_setopt ($ch, CURLOPT_TIMEOUT, $curl_timeout);
		curl_setopt ($ch, CURLE_OPERATION_TIMEOUTED, 2);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt ($ch, CURLOPT_AUTOREFERER, 1);

		// Execute the CURL query.  Loop 10 times if needed.  Sometimes it's needed.  Really.
		$curl_loop = 0;
		while (!$body) {
			$body = curl_exec($ch);
			if ($no_loop) {
				curl_close($ch);
				exec($cleanup_cmd);
				return self::parse_response($body); 
			}
			$curl_loop++;
			if ($curl_loop == 10) { 
				return "Unable to contact catalog. ($curl_url) Please try again later.<br/><br/>";
			}
		}
		curl_close($ch);
		return self::parse_response($body);
	}

	/**
	 * Parses the cURL result into response code, header, and body.
	 *
	 * @param string $this_response cURL response
	 * @return array Array of response components, keyed by 'code', 'header', and 'body'
	 */
	function parse_response($this_response) {

		// Split response into header and body sections
		list($response_headers, $response_body) = explode("\n\n", $this_response, 2);
		$response_header_lines = explode("\n", $response_headers);

		// First line of headers is the HTTP response code
		$http_response_line = array_shift($response_header_lines);
		if(preg_match('@^HTTP/[0-9]\.[0-9] ([0-9]{3})@',$http_response_line, $matches)) { $response_code = $matches[1]; }

		// put the rest of the headers in an array
		$response_header_array = array();
		foreach($response_header_lines as $header_line) {
		       list($header,$value) = explode(': ', $header_line, 2);
			$response_header_array[$header] .= $value."\n";
		}

		return array("code" => $response_code, "header" => $response_header_array, "body" => $response_body);
	}

}
