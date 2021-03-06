<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ExpressionEngine - by EllisLab
 *
 * @package		ExpressionEngine
 * @author		ExpressionEngine Dev Team
 * @copyright	Copyright (c) 2003 - 2011, EllisLab, Inc.
 * @license		http://expressionengine.com/user_guide/license.html
 * @link		http://expressionengine.com
 * @since		Version 2.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * YQL Plugin
 *
 * @package		ExpressionEngine
 * @subpackage	Addons
 * @category	Plugin
 * @author		Jesse Bunch
 * @link		http://paramore.is/
 */

$plugin_info = array(
	'pi_name'		=> 'YQL',
	'pi_version'	=> '1.0',
	'pi_author'		=> 'Jesse Bunch',
	'pi_author_url'	=> 'http://paramore.is/',
	'pi_description'=> 'Simple plugin that allows you to query the YQL service from your ExpressionEngine templates.',
	'pi_usage'		=> Yql::usage()
);


class Yql {

	/**
	 * The EE cache group
	 * @param string
	 * @author Jesse Bunch
	*/
	const CACHE_GROUP = 'ee_yql';

	/**
	 * Return data for the constructor
	 * @author Jesse Bunch
	*/
	public $return_data;
    
	/**
	 * Constructor
	 * @author Jesse Bunch
	*/
	public function __construct() {
		$this->EE =& get_instance();
	}

	/**
	 * exp:yql:query
	 * @param sql The query to execute
	 * @param param:key Replaces the @variables in your YQL query
	 * @param cache_timeout Local caching time. Defaults to 0 (no cache)
	 * @author Jesse Bunch
	*/
	public function query() {

		// Fetch params
		$sql = $this->EE->TMPL->fetch_param('sql', FALSE);
		$cache_timeout = $this->EE->TMPL->fetch_param('cache_timeout', 0);
		$debug = $this->EE->TMPL->fetch_param('debug', 'no');
		$params = $this->_fetch_colon_params('param');
		$prefix = $this->EE->TMPL->fetch_param('prefix', 'no');
		
		// No SQL, no results
		if (empty($sql)) {
			return $this->EE->TMPL->no_results();
		}

		// Construct the cache key
		$cache_key = $sql.serialize($params);
		$cache_key = md5($cache_key);

		// Fetch Cache
		if ($cache_timeout > 0) {
			
			$this->EE->load->library('yql_caching_library');
			$cached_results = $this->EE->yql_caching_library->read_cache($cache_key, Yql::CACHE_GROUP, $cache_timeout);

			// Find cache?
			if (FALSE !== $cached_results) {
				
				if ($debug == 'yes') {
					var_dump('CACHED RESULTS', $sql, $params, $cached_results);
					exit;
				}

				$cached_results = unserialize($cached_results);

				if (empty($cached_results)) {
					return $this->EE->TMPL->no_results();
				}

				return $this->_parse_results(array($cached_results), $this->EE->TMPL->tagdata, ($prefix == 'yes'));

			}

		}

		// Run the query
		$this->EE->load->library('yql_library');
		$results = $this->EE->yql_library->run_query($sql, $params);

		// Set the cache
		if ($cache_timeout > 0
			&& !empty($results)) {
			$this->EE->load->library('yql_caching_library', NULL, 'yql_caching_library');
			$cache_value = serialize($results);
			$this->EE->yql_caching_library->set_cache($cache_key, $cache_value, Yql::CACHE_GROUP);
		}

		if ($debug == 'yes') {
			var_dump('FETCHED RESULTS', $sql, $params, $results);
			exit;
		}

		if (empty($results)) {
			return $this->EE->TMPL->no_results();
		}

		// Parse template
		return $this->_parse_results(array($results), $this->EE->TMPL->tagdata, ($prefix == 'yes'));

	}

	/**
	 * Parses the YQL results
	 * @param $results array Array of results to parse
	 * @param $tagdata string The template code to parse
	 * @param $prefix bool Should we prefix all vars?
	 * @return string
	 * @author Jesse Bunch
	*/
	private function _parse_results($results, $tagdata, $prefix) {

		// Parse {results path="element.table[2].element2.array[0]"} tags
		if (preg_match_all("/{\s*results\s+path=(.*?)}/", $tagdata, $matches)) {
			foreach($matches[0] as $index => $match) {
				foreach($results as $result) {
					$tagdata = str_replace($match, 
						$this->_traverse_array($result, 
							trim($matches[1][$index], '\'"')
						),
						$tagdata
					);
				}
			}
		}

		// Prefix?
		if ($prefix) {
			$results = $this->_prefix_array($results);
		}

		// Make sure all arrays are indexed arrays
		$results = $this->_force_array($results);
		
		// Parse away!
		return $this->EE->TMPL->parse_variables($tagdata, $results);

	}

	/**
	 * Recursively traverses an array, returning 
	 * the value specified by the dot notated path
	 * @param $array array The array to traverse
	 * @param #path string The dot notated path to return (ie results.item.forecast.temperature)
	 * @return mixed
	 * @author Jesse Bunch
	*/
	private function _traverse_array(&$array, $path) {
		
		$next_path = $path;
		$paths = explode('.', $path);
		$next_path = $paths[0];

		// Index or Assoc Key?
		$key_matches;
		$matched_value;
		if ($num_matches = preg_match('/([a-zA-Z\-\_]*)\[["]?([0-9a-zA-Z]+)["]?\]/', $next_path, $key_matches)) {
			if ($key_matches[1] != "") {
				$matched_value = (isset($array[$key_matches[1]][$key_matches[2]]))
					? $array[$key_matches[1]][$key_matches[2]]
					: FALSE;
			} else {
				$matched_value = (isset($array[$key_matches[2]]))
					? $array[$key_matches[2]]
					: FALSE;
			}
		} else {
			$matched_value = (isset($array[$next_path]))
				? $array[$next_path]
				: FALSE;
		}

		// Matched value an array?
		if (is_array($matched_value)) {
			array_shift($paths);
			return $this->_traverse_array($matched_value, implode('.', $paths));
		}

		return $matched_value;	

	}

	/**
	 * Extracts parameters from the tag param array that are
	 * considered to be colon parameters. e.g. attribute:param="value"
	 * @param string $colon_key The "attribute" part
	 * @return array key/value pairs (param = "value")
	 * @author Jesse Bunch
	*/
	private function _fetch_colon_params($colon_key) {

		// Get all params
		$all_params = $this->EE->TMPL->tagparams;

		// Pull out params that start with "custom:"
		$colon_params = array();
		if (is_array($all_params) && count($all_params)) {
			$colon_key_end_index = strlen($colon_key) + 1;
			foreach ($all_params as $key => $val) {
				if (strncmp($key, $colon_key, $colon_key_end_index-1) == 0) {
					$colon_params[substr($key, $colon_key_end_index)] = $val;
				}
			}					
		}

		return $colon_params;

	}

	/**
	 * Prefixes every variable under the first level
	 * to prevent conflicts in EE's template parser
	 * @param $array array 
	 * @param $level int
	 * @author Jesse Bunch
	*/
	private function _prefix_array($array, $prefix = '', $level = 1) {

		$new_array = array();
		
		foreach($array as $key => $value) {
			
			if (empty($prefix)) {
				$actual_prefix = $key;
			} else {
				$actual_prefix = $prefix.':'.$key;
			}
			
			if (is_array($value)) {
				if (is_int($key)) {
					$new_array[$key] = $this->_prefix_array($value, $prefix, $level + 1);;
				} else {
					$new_array[$actual_prefix] = $this->_prefix_array($value, $actual_prefix, $level + 1);
				}
			} else {
				$new_array[$actual_prefix] = $value;
			}

		}

		return $new_array;

	}

	/**
	 * Force Array
	 *
	 * Take a totally mixed item and parse it into an array compatible with EE's Template library
	 * 
	 * Taken from Phil Sturgeon's REST plugin
	 *
	 * @access	private
	 * @param	mixed
	 * @return	string
	 */
	private function _force_array($var, $level = 1) {

		if (is_object($var)) {
			$var = (array) $var;
		}

		if ($level == 1 && ! isset($var[0])) {
			$var = array($var);
		}

		if (is_array($var)) {

			// Make sure everything else is array or single value
			foreach($var as $index => &$child) {
				$child = self::_force_array($child, $level + 1);

				if (is_object($child)) {
					$child = (array) $child;
				}

				// Format dates to unix timestamps
				elseif (isset($this->EE->TMPL->date_vars[$index]) and ! is_numeric($child)) {
					$child = strtotime($child);
				}

				// Format for EE syntax looping
				if (is_array($child) && ! is_int($index) && ! isset($child[0])) {
					$child = array($child);
				}

			}

		}

		return $var;
	}
	
	/**
	 * Plugin usage
	 * @author Jesse Bunch
	*/
	public static function usage() {
		ob_start();
?>
See the README at github for usage instructions.
<?php
		$buffer = ob_get_contents();
		ob_end_clean();
		return $buffer;
	}

}


/* End of file pi.yql.php */
/* Location: /system/expressionengine/third_party/yql/pi.yql.php */