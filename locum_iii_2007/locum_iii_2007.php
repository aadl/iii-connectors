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
 * The Locum connector class for III Mil. 2006.
 * Note the naming convention that is required by Locum: locum _ vendor _ version
 * Also, from a philisophical standpoint, I try to use only public-facing services here,
 * with the exception of the III patron API, which is a product we bought, along with 
 * practically every other III customer.
 */
class locum_iii_2007 {

	public $locum_config;

	/**
	 * Prep this class
	 */
	public function __construct() {
		require_once('patronapi.php');
	}

	/**
	 * Grabs bib info from XRECORD and returns it in a Locum-ready array.
	 *
	 * @param int $bnum Bib number to scrape
	 * @param boolean $skip_cover Forget about grabbing cover images.  Default: FALSE
	 * @return boolean|array Will either return a Locum-ready array or FALSE
	 */
	public function scrape_bib($bnum, $skip_cover = FALSE) {

		$iii_webcat = $this->locum_config[ils_config][ils_server];
		$iii_webcat_port = $this->locum_config[ils_config][ils_harvest_port];

		$bnum = trim($bnum);

		$xrecord = @simplexml_load_file('http://' . $iii_webcat . ':' . $iii_webcat_port . '/xrecord=b' . $bnum);

		// If there is no record, return false (weeded or non-existent)
		if ($xrecord->NULLRECORD) { return FALSE; }
		if ($xrecord->VARFLD) {
			if (!$xrecord->VARFLD[0]->MARCINFO) { return FALSE; }
		} else {
			return FALSE;
		}

		$bib_info_record = $xrecord->RECORDINFO;
		$bib_info_local = $xrecord->TYPEINFO->BIBLIOGRAPHIC->FIXFLD;
		$bib_info_marc = self::parse_marc_subfields($xrecord->VARFLD);
		unset($xrecord);

		// Process record information
		$bib[bnum] = $bnum;
		$bib[bib_created] = self::fixdate($bib_info_record->CREATEDATE);
		$bib[bib_lastupdate] = self::fixdate($bib_info_record->LASTUPDATEDATE);
		$bib[bib_prevupdate] = self::fixdate($bib_info_record->PREVUPDATEDATE);
		$bib[bib_revs] = (int) $bib_info_record->REVISIONS;

		// Process local record data
		foreach ($bib_info_local as $bil_obj) {
			switch (trim($bil_obj->FIXLABEL)) {
				case 'LANG':
					$bib[lang] = trim($bil_obj->FIXVALUE);
					break;
				case 'LOCATION':
					$bib[loc_code] = trim($bil_obj->FIXVALUE);
					break;
				case 'MAT TYPE':
					$bib[mat_code] = trim($bil_obj->FIXVALUE);
					break;

			}
		}

		// Process MARC fields

		// Process Author information
		$bib[author] = '';
		$author_arr = self::prepare_marc_values($bib_info_marc['100'], array('a','b','c','d'));
		$bib[author] = $author_arr[0];

		// In no author info, we'll go for the 110 field
		if (!$bib[author]) {
			$author_110 = self::prepare_marc_values($bib_info_marc['110'], array('a'));
			$bib[author] = $author_110[0];
		}

		// Additional author information
		$bib[addl_author] = '';
		$addl_author = self::prepare_marc_values($bib_info_marc['700'], array('a','b','c','d'));
		if (is_array($addl_author)) {
			$bib[addl_author] = serialize($addl_author);
		}

		// In no additional author info, we'll go for the 710 field
		if (!$bib[addl_author]) {
			$author_710 = self::prepare_marc_values($bib_info_marc['710'], array('a'));
			if (is_array($author_710)) {
				$bib[addl_author] = serialize($author_710);
			}
		}

		// Title information
		$bib[title] = '';
		$title = self::prepare_marc_values($bib_info_marc['245'], array('a','b'));
		if (substr($title[0], -1) == '/') { $title[0] = trim(substr($title[0], 0, -1)); }
		$bib[title] = trim($title[0]);

		// Title medium information
		$bib[title_medium] = '';
		$title_medium = self::prepare_marc_values($bib_info_marc['245'], array('h'));
		if ($title_medium[0]) {
			if (preg_match('/\[(.*?)\]/', $title_medium[0], $medium_match)) {
				$bib[title_medium] = $medium_match[1];
			}
		}
		
		// Edition information
		$bib[edition] = '';
		$edition = self::prepare_marc_values($bib_info_marc['250'], array('a'));
		$bib[edition] = trim($edition[0]);

		// Series information
		$bib[series] = '';
		$series = self::prepare_marc_values($bib_info_marc['490'], array('a','v'));
		if (!$series[0]) { $series = self::prepare_marc_values($bib_info_marc['440'], array('a','v')); }
		if (!$series[0]) { $series = self::prepare_marc_values($bib_info_marc['400'], array('a','v')); }
		if (!$series[0]) { $series = self::prepare_marc_values($bib_info_marc['410'], array('a','v')); }
		if (!$series[0]) { $series = self::prepare_marc_values($bib_info_marc['800'], array('a','v')); }
		if (!$series[0]) { $series = self::prepare_marc_values($bib_info_marc['810'], array('a','v')); }
		$bib[series] = $series[0];

		// Call number
		$callnum = '';
		$callnum_arr = self::prepare_marc_values($bib_info_marc['099'], array('a'));
		if (is_array($callnum_arr) && count($callnum_arr)) {
			foreach ($callnum_arr as $cn_sub) {
				$callnum .= $cn_sub . ' ';
			}
		}
		$bib[callnum] = trim($callnum);
	
		// Publication information
		$bib[pub_info] = '';
		$pub_info = self::prepare_marc_values($bib_info_marc['260'], array('a','b','c'));
		$bib[pub_info] = $pub_info[0];

		// Publication year
		$bib[pub_year] = '';
		$pub_year = self::prepare_marc_values($bib_info_marc['260'], array('c'));
		$c_arr = explode(',', $pub_year[0]);
		$c_key = count($c_arr) - 1;
		$bib[pub_year] = substr(ereg_replace("[^0-9]", '', $c_arr[$c_key]), -4);

		// ISBN / Std. number
		$bib[stdnum] = '';
		$stdnum = self::prepare_marc_values($bib_info_marc['020'], array('a'));
		$bib[stdnum] = $stdnum[0];

		// Grab the cover image URL if we're doing that
		$bib[cover_img] = '';
		if ($skip_cover != TRUE) {
			if ($bib[stdnum]) { $bib[cover_img] = locum_server::get_cover_img($bib[stdnum]); }
		}

		// LCCN
		$bib[lccn] = '';
		$lccn = self::prepare_marc_values($bib_info_marc['010'], array('a'));
		$bib[lccn] = $lccn[0];

		// Description
		$bib[descr] = '';
		$descr = self::prepare_marc_values($bib_info_marc['300'], array('a','b','c'));
		$bib[descr] = $descr[0];

		// Notes
		$notes = array();
		$bib[notes] = '';
		$notes_tags = array('500', '520');
		foreach ($notes_tags as $notes_tag) {
			$notes_arr = self::prepare_marc_values($bib_info_marc[$notes_tag], array('a'));
			if (is_array($notes_arr)) {
				foreach ($notes_arr as $notes_arr_val) {
					array_push($notes, $notes_arr_val);
				}
			}
		}
		if (count($notes)) { $bib[notes] = serialize($notes); }

		// Subject headings
		$subjects = array();
		$subj_tags = array(
			'600', '610', '611', '630', '650', '651', 
			'653', '654', '655', '656', '657', '658', 
			'690', '691', '692', '693', '694', '695',
			'696', '697', '698', '699'
		);
		foreach ($subj_tags as $subj_tag) {
			$subj_arr = self::prepare_marc_values($bib_info_marc[$subj_tag], array('a','b','c','d','e','v','x','y','z'), ' -- ');
			if (is_array($subj_arr)) {
				foreach ($subj_arr as $subj_arr_val) {
					array_push($subjects, $subj_arr_val);
				}
			}
		}
		$bib[subjects] = '';
		if (count($subjects)) { $bib[subjects] = $subjects; }
		
		unset($bib_info_marc);
		return $bib;
	}

	/**
	 * Parses item status for a particular bib item.
	 *
	 * @param string $bnum Bib number to query
	 * @return array Returns a Locum-ready availability array
	 */
	public function item_status($bnum) {
		$iii_webcat = $this->locum_config[ils_config][ils_server];
		$iii_webcat_port = $this->locum_config[ils_config][ils_harvest_port];
		$avail_token = locum::csv_parser($this->locum_config[ils_custom_config][iii_available_token]);

		$bnum = trim($bnum);
		$url = 'http://' . $iii_webcat . '/search/.b' . $bnum . '/.b' . $bnum . '/1,1,1,B/holdings~' . $bnum . '&FF=&1,0,';

		$avail_page_raw = utf8_encode(file_get_contents($url));

		// Holdings Regex
		$regex_h = '%field 1 -->&nbsp;(.*?)</td>(.*?)browse">(.*?)</a>(.*?)field \% -->&nbsp;(.*?)</td>%s';
		preg_match_all($regex_h, $avail_page_raw, $matches);
		$avail_temp[location] = $matches[1];
		$avail_temp[callnum] = $matches[3];
		$avail_temp[status] = $matches[5];
		
		// Reserves Regex
		$regex_r = '%<div>[\r\n](.*?)holds on%U';
		preg_match($regex_r, $avail_page_raw, $match_r);
		$item_status_result[holds] = (int) trim($match_r[1]) ? trim($match_r[1]) : 0;

		// Order Entry Regex
		$regex_o = '%bibOrderEntry(.*?)td(.*?)>(.*?)<%s';
		preg_match($regex_o, $avail_page_raw, $match_o);
		$order_entry_msg = trim($match_o[3]);
		$item_status_result[order] = $order_entry_msg ? $order_entry_msg : '';

		$total_avail = 0;
		foreach ($matches[3] as $num => $cnum) {
			$cnum = trim($cnum);
			$item_status = trim($matches[5][$num]);
			$location = trim($matches[1][$num]);
			if (in_array($item_status, $avail_token)) {
				$avail[$cnum][$location][avail]++;
				$total_avail++;
			} else if (preg_match('/DUE/i', $item_status)) {
				$due_arr = explode(' ', trim($item_status));
				$due_date_arr = explode('-', $due_arr[1]);
				$due_date = mktime(0, 0, 0, $due_date_arr[0], $due_date_arr[1], (2000 + (int) $due_date_arr[2]));
				$avail[$cnum][$location][due][] = $due_date;
				sort($avail[$cnum][$location][due]);
			}
		}
		$item_status_result[total] = count($matches[3]);
		$item_status_result[copies] = (int) $total_avail;
		$item_status_result[details] = $avail;

		return $item_status_result;
	}
	
	/**
	 * Returns an array of patron information
	 *
	 * @param string $pid Patron barcode number or record number
	 * @return boolean|array Array of patron information or FALSE if login fails
	 */
	public function patron_info($pid) {
		$papi = new iii_patronapi;
		$papi->iiiserver = $this->locum_config[ils_config][ils_server];
		$papi_data = $papi->get_patronapi_data($pid);

		if (!$papi_data) { return FALSE; }

		$pdata[pnum] = $papi_data[RECORDNUM];
		$pdata[cardnum] = $papi_data[PBARCODE];
		$pdata[checkouts] = $papi_data[CURCHKOUT];
		$pdata[homelib] = $papi_data[HOMELIBR];
		$pdata[balance] = (float) preg_replace('%\$%s', '', $papi_data[MONEYOWED]);
		$pdata[expires] = $papi_data[EXPDATE] ? self::date_to_timestamp($papi_data[EXPDATE], 2000) : NULL;
		$pdata[name] = $papi_data[PATRNNAME];
		$pdata[address] = preg_replace('%\$%s', "\n", $papi_data[ADDRESS]);
		$pdata[tel1] = $papi_data[TELEPHONE];
		if ($papi_data[TELEPHONE2]) { $pdata[tel2] = $papi_data[TELEPHONE2]; }
		$pdata[email] = $papi_data[EMAILADDR];

		return $pdata;
	}

	/**
	 * Returns an array of patron checkouts
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @return boolean|array Array of patron checkouts or FALSE if login fails
	 */
	public function patron_checkouts($cardnum, $pin = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		return $iii->get_patron_items();
	}
	
	/**
	 * Returns an array of patron holds
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @return boolean|array Array of patron holds or FALSE if login fails
	 */
	public function patron_holds($cardnum, $pin = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		return $iii->get_patron_holds();
	}
	
	/**
	 * Renews items and returns the renewal result
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @param array Array of varname => item numbers to be renewed, or NULL for everything.
	 * @return boolean|array Array of item renewal statuses or FALSE if it cannot renew for some reason
	 */
	public function renew_items($cardnum, $pin = NULL, $items = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		return $iii->renew_material($items);
	}
	
	/**
	 * Cancels holds
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @param array Array of varname => item/bib numbers to be cancelled, or NULL for everything.
	 * @return boolean TRUE or FALSE if it cannot cancel for some reason
	 */
	public function cancel_holds($cardnum, $pin = NULL, $items = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		$iii->cancel_holds($items);
		return TRUE;
	}

	/**
	 * Places holds
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $bnum Bib item record number to place a hold on
	 * @param string $inum Item number to place a hold on if required (presented as $varname in locum)
	 * @param string $pin Patron pin/password
	 * @param string $pickup_loc Pickup location value
	 * @return boolean TRUE or FALSE if it cannot place the hold for some reason
	 */
	public function place_hold($cardnum, $bnum, $inum = NULL, $pin = NULL, $pickup_loc = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		return $iii->place_hold($bnum, $inum, $pickup_loc);
	}
	
	/**
	 * Returns an array of patron fines
	 *
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @return boolean|array Array of patron fines or FALSE if login fails
	 */
	public function patron_fines($cardnum, $pin = NULL) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		return $iii->get_patron_fines();
	}
	
	/**
	 * Pays patron fines.
	 * @param string $cardnum Patron barcode/card number
	 * @param string $pin Patron pin/password
	 * @param array payment_details
	 * @return array Payment result
	 */
	public function pay_patron_fines($cardnum, $pin = NULL, $payment_details) {
		require_once('iiitools_2007.php');
		$iii = new iiitools;
		$iii->set_iiiserver($this->locum_config[ils_config][ils_server]);
		$iii->set_cardnum($cardnum);
		$iii->set_pin($pin);
		if ($iii->catalog_login() == FALSE) { return FALSE; }
		foreach ($payment_details[varnames] as $varname) {
			$iii_payment_details[$varname] = 'on';
		}
		$iii_payment_details[amount] = '$' . number_format($payment_details[total], 2);
		$iii_payment_details[ccname] = $payment_details[name];
		$iii_payment_details[address1] = $payment_details[address1];
		$iii_payment_details[city] = $payment_details[city];
		$iii_payment_details[state] = $payment_details[state];
		$iii_payment_details[zip] = $payment_details[zip];
		$iii_payment_details[emailaddr] = $payment_details[email];
		$iii_payment_details[ccnum] = $payment_details[ccnum];
		$iii_payment_details[ccexpmonth] = $payment_details[ccexpmonth];
		$iii_payment_details[ccexpyear] = $payment_details[ccexpyear];
		$iii_payment_details[cc_cvv2] = $payment_details[ccseccode];
		$payment_result = $iii->pay_fine($iii_payment_details);
		return $payment_result;
	}
	
	/**
	 * This is an internal function used to parse MARC values.
	 * This function is called by scrape_bib()
	 *
	 * @param array $value_arr SimpleXML values from XRECORD for that MARC item
	 * @param array $subfields An array of MARC subfields to parse
	 * @param string $delimiter Delimiter to use for storage and indexing purposes.  A space seems to work fine
	 * @return array An array of processed MARC values
	 */
	public function prepare_marc_values($value_arr, $subfields, $delimiter = ' ') {

		// Repeatable values can be returned as an array or a serialized value
		foreach ($subfields as $subfield) {
			if (is_array($value_arr[$subfield])) {

				foreach ($value_arr[$subfield] as $subkey => $subvalue) {

					if (is_array($subvalue)) {
						foreach ($subvalue as $sub_subvalue) {
							if ($i[$subkey]) { $pad[$subkey] = $delimiter; }
							$sv_tmp = preg_replace('/\{(.*?)\}/', '', trim($sub_subvalue));
							$sv_tmp = trim(preg_replace('/</i', '"', $sv_tmp));
							if (trim($sub_subvalue)) { $marc_values[$subkey] .= $pad[$subkey] . $sv_tmp; }
							$i[$subkey] = 1;
						}
					} else {
						if ($i[$subkey]) { $pad[$subkey] = $delimiter; }
						
						// This is a workaround until I can figure out wtf III is doing with encoding.  For now
						// there will be no extended characters:
						$sv_tmp = preg_replace('/\{(.*?)\}/', '', trim($subvalue));

						// Fix odd quote issues.  May be a club method of doing this, but oh well.
						$sv_tmp = trim(preg_replace('/</i', '"', $sv_tmp));

						if (trim($subvalue)) { $marc_values[$subkey] .= $pad[$subkey] . $sv_tmp; }
						$i[$subkey] = 1;
					}
				}	
			}		
		}

		if (is_array($marc_values)) {
			foreach ($marc_values as $mv) {
				$result[] = $mv;
			}
		}
		return $result;
	}

	/**
	 * Does the initial job of creating an array out of the SimpleXML content from XRECORD.
	 * This function is called by scrape_bib() and the data is ultimately used by prepare_marc_values()
	 *
	 * @param array $bib_info_marc VARFLD value tree from XRECORD via SimpleXML
	 * @return array A normalized array of marc and subfield info
	 */
	public function parse_marc_subfields($bib_info_marc) {
		$bim_item = 0;
		foreach ($bib_info_marc as $bim_obj) {
			// We need to treat MARC tag numbers as a string, or things would be a mess
			$marc_num = (string) $bim_obj->MARCINFO->MARCTAG;
			if (count($bim_obj->MARCSUBFLD) == 1) {
				// Only one subfield value
				$subfld = get_object_vars($bim_obj->MARCSUBFLD);
				$marc_sub[$marc_num][trim($subfld[SUBFIELDINDICATOR])][$bim_item] = trim($subfld[SUBFIELDDATA]);
			} else if (count($bim_obj->MARCSUBFLD) > 1) {
				// Multiple subfield values
				for ($i = 0; $i < count($bim_obj->MARCSUBFLD); $i++) {
					$subfld = get_object_vars($bim_obj->MARCSUBFLD[$i]);
					$marc_sub[$marc_num][trim($subfld[SUBFIELDINDICATOR])][$bim_item][] = trim($subfld[SUBFIELDDATA]);
				}
			}
			$bim_item++;
		}

		return $marc_sub;
	}

	/**
	 * Fixes a non-standard date format.
	 *
	 * @param string $olddate Date string in MM-DD-YY format
	 * @param string Date string in YYYY-MM-DD format
	 */
	public function fixdate($olddate) {
		return date('Y-m-d', self::date_to_timestamp($olddate));
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


















}
