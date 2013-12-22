<?php
/**
 * Request and response wrapper
 *
 * Uses the [Route] class to determine what [Controller]
 * to send the request to.
 *
 * @package    Gleez\Request
 * @version    1.1.4
 * @author     Gleez Team
 * @copyright  (c) 2011-2013 Gleez Technologies
 * @license    http://gleezcms.org/license Gleez CMS License
 */
class Request implements HTTP_Request {

	/**
	 * Default maximum size of POST data
	 * @type string
	 */
	const DEFAULT_POST_MAX_SIZE = '1M';

	/**
	 * Request Redirect URL for ajax requests
	 * @var string
	 */
	public static $redirect_url;

	/**
	 * Client user agent
	 * @var string
	 */
	public static $user_agent = '';

	/**
	 * Client IP address
	 * @var string
	 */
	public static $client_ip = '0.0.0.0';

	/**
	 * Trusted proxy server IPs
	 * @var string
	 */
	public static $trusted_proxies = array('127.0.0.1', 'localhost', 'localhost.localdomain');

	/**
	 * Main request instance
	 * @var Request
	 */
	public static $initial;

	/**
	 * Currently executing request instance
	 * @var Request
	 */
	public static $current;

	/**
	 * The X-Requested-With header which most likely will be XMLHttpRequest
	 * @var string
	 */
	protected $_requested_with;

	/**
	 * Method: GET, POST, PUT, DELETE, HEAD, etc
	 * @var string
	 */
	protected $_method = 'GET';

	/**
	 * Protocol: HTTP/1.1, FTP, CLI, etc
	 * @var string
	 */
	protected $_protocol;

	/**
	 * @var boolean
	 */
	protected $_secure = FALSE;

	/**
	 * Referring URL
	 * @var string
	 */
	protected $_referrer;

	/**
	 * Route matched for this request
	 * @var Route
	 */
	protected $_route;

	/**
	 * Array of routes to manually look at instead of the global namespace
	 * @var Route
	 */
	protected $_routes;

	/**
	 * Response
	 * @var Response
	 */
	protected $_response;

	/**
	 * Headers to sent as part of the request
	 * @var HTTP_Header
	 */
	protected $_header;

	/**
	 * The body
	 * @var string
	 */
	protected $_body;

	/**
	 * Controller directory
	 * @var string
	 */
	protected $_directory = '';

	/**
	 * Controller to be executed
	 * @var string
	 */
	protected $_controller;

	/**
	 * Action to be executed in the controller
	 * @var string
	 */
	protected $_action;

	/**
	 * The URI of the request
	 * @var string
	 */
	protected $_uri;

	/**
	 * External request
	 * @var boolean
	 */
	protected $_external = FALSE;

	/**
	 * Parameters from the route
	 * @var array
	 */
	protected $_params = array();

	/**
	 * Query parameters
	 * @var array
	 */
	protected $_get = array();

	/**
	 * Post parameters
	 * @var array
	 */
	protected $_post = array();

	/**
	 * Cookies to send with the request
	 * @var array
	 */
	protected $_cookies = array();

	/**
	 * @var Request_Client
	 */
	protected $_client;

	/**
	 * Creates a new request object for the given URI
	 *
	 * New requests should be created using this method.
	 *
	 * Example:
	 * ~~~
	 * $request = Request::factory($uri);
	 * ~~~
	 *
	 * If $cache parameter is set, the response for the request will attempt to
	 * be retrieved from the cache.
	 *
	 * @param   boolean|string  $uri              URI of the request [Optional]
	 * @param   HTTP_Cache      $cache
	 * @param   array           $injected_routes  An array of routes to use, for testing [Optional]
	 *
	 * @return  void|Request
	 *
	 * @throws  Request_Exception
	 *
	 * @uses    Route::all
	 * @uses    Route::matches
	 * @uses    HTTP::$protocol
	 * @uses    HTTP_Request::GET
	 * @uses    Cookie::get
	 */
	public static function factory($uri = TRUE, HTTP_Cache $cache = NULL, $injected_routes = array())
	{
		// If this is the initial request
		if ( ! Request::$initial)
		{
			if (isset($_SERVER['SERVER_PROTOCOL']))
			{
				$protocol = $_SERVER['SERVER_PROTOCOL'];
			}
			else
			{
				$protocol = HTTP::$protocol;
			}

			if (isset($_SERVER['REQUEST_METHOD']))
			{
				// Use the server request method
				$method = $_SERVER['REQUEST_METHOD'];
			}
			else
			{
				// Default to GET requests
				$method = HTTP_Request::GET;
			}

			if ( ! empty($_SERVER['HTTPS']) AND filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN))
			{
				// This request is secure
				$secure = TRUE;
			}

			if (isset($_SERVER['HTTP_REFERER']))
			{
				// There is a referrer for this request
				$referrer = $_SERVER['HTTP_REFERER'];
			}

			if (isset($_SERVER['HTTP_USER_AGENT']))
			{
				// Browser type
				Request::$user_agent = $_SERVER['HTTP_USER_AGENT'];
			}

			if (isset($_SERVER['HTTP_X_REQUESTED_WITH']))
			{
				// Typically used to denote AJAX requests
				$requested_with = $_SERVER['HTTP_X_REQUESTED_WITH'];
			}

			if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])
			    AND isset($_SERVER['REMOTE_ADDR'])
			    AND in_array($_SERVER['REMOTE_ADDR'], Request::$trusted_proxies))
			{
				// Use the forwarded IP address, typically set when the
				// client is using a proxy server.
				// Format: "X-Forwarded-For: client1, proxy1, proxy2"
				$client_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

				Request::$client_ip = array_shift($client_ips);

				unset($client_ips);
			}
			elseif (isset($_SERVER['HTTP_CLIENT_IP'])
				AND isset($_SERVER['REMOTE_ADDR'])
				AND in_array($_SERVER['REMOTE_ADDR'], Request::$trusted_proxies))
			{
				// Use the forwarded IP address, typically set when the
				// client is using a proxy server.
				$client_ips = explode(',', $_SERVER['HTTP_CLIENT_IP']);

				Request::$client_ip = array_shift($client_ips);

				unset($client_ips);
			}
			elseif (isset($_SERVER['REMOTE_ADDR']))
			{
				// The remote IP address
				Request::$client_ip = $_SERVER['REMOTE_ADDR'];
			}

			if ($method !== HTTP_Request::GET)
			{
				// Ensure the raw body is saved for future use
				$body = file_get_contents('php://input');
			}

			if ($uri === TRUE)
			{
				// Attempt to guess the proper URI
				$uri = Request::detect_uri();
			}

			$cookies = array();

			if (($cookie_keys = array_keys($_COOKIE)))
			{
				foreach ($cookie_keys as $key)
				{
					$cookies[$key] = Cookie::get($key);
				}
			}

			// Create the instance singleton
			Request::$initial = $request = new Request($uri, $cache, $injected_routes);

			// Store global GET and POST data in the initial request only
			$request->protocol($protocol)
				->query($_GET)
				->post($_POST);

			if (isset($secure))
			{
				// Set the request security
				$request->secure($secure);
			}

			if (isset($method))
			{
				// Set the request method
				$request->method($method);
			}

			if (isset($referrer))
			{
				// Set the referrer
				$request->referrer($referrer);
			}

			if (isset($requested_with))
			{
				// Apply the requested with variable
				$request->requested_with($requested_with);
			}

			if (isset($body))
			{
				// Set the request body (probably a PUT type)
				$request->body($body);
			}

			if (isset($cookies))
			{
				$request->cookie($cookies);
			}
		}
		else
		{
			$request = new Request($uri, $cache, $injected_routes);
		}

		return $request;
	}

	/**
	 * Automatically detects the URI of the main request using PATH_INFO,
	 * REQUEST_URI, PHP_SELF or REDIRECT_URL
	 *
	 * Example:
	 * ~~~
	 * $uri = Request::detect_uri();
	 * ~~~
	 *
	 * @return  string  URI of the main request
	 *
	 * @throws  Gleez_Exception
	 *
	 * @uses    Kohana::$base_url
	 * @uses    Kohana::$index_file
	 */
	public static function detect_uri()
	{
		if ( ! empty($_SERVER['PATH_INFO']))
		{
			// PATH_INFO does not contain the docroot or index
			$uri = $_SERVER['PATH_INFO'];
		}
		else
		{
			// REQUEST_URI and PHP_SELF include the docroot and index

			if (isset($_SERVER['REQUEST_URI']))
			{
				/**
				 * We use REQUEST_URI as the fallback value. The reason
				 * for this is we might have a malformed URL such as:
				 *
				 *  http://localhost/http://example.com/judge.php
				 *
				 * which parse_url can't handle. So rather than leave empty
				 * handed, we'll use this.
				 */
				$uri = $_SERVER['REQUEST_URI'];

				if ($request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))
				{
					// Valid URL path found, set it.
					$uri = $request_uri;
				}

				// Decode the request URI
				$uri = rawurldecode($uri);
			}
			elseif (isset($_SERVER['PHP_SELF']))
			{
				$uri = $_SERVER['PHP_SELF'];
			}
			elseif (isset($_SERVER['REDIRECT_URL']))
			{
				$uri = $_SERVER['REDIRECT_URL'];
			}
			else
			{
				// If you ever see this error, please report an issue at http://dev.kohanaphp.com/projects/kohana3/issues
				// along with any relevant information about your web server setup. Thanks!
				throw new Gleez_Exception('Unable to detect the URI using PATH_INFO, REQUEST_URI, PHP_SELF or REDIRECT_URL');
			}

			// Get the path from the base URL, including the index file
			$base_url = parse_url(Kohana::$base_url, PHP_URL_PATH);

			if (strpos($uri, $base_url) === 0)
			{
				// Remove the base URL from the URI
				$uri = (string) substr($uri, strlen($base_url));
			}

			if (Kohana::$index_file AND strpos($uri, Kohana::$index_file) === 0)
			{
				// Remove the index file from the URI
				$uri = (string) substr($uri, strlen(Kohana::$index_file));
			}
		}

		return $uri;
	}

	/**
	 * Return the currently executing request
	 *
	 * This is changed to the current request when [Request::execute]
	 * is called and restored when the request is completed.
	 *
	 * Example:
	 * ~~~
	 * $request = Request::current();
	 * ~~~
	 *
	 * @return  Request
	 */
	public static function current()
	{
		return Request::$current;
	}

	/**
	 * Returns the first request encountered by this framework
	 *
	 * This will should only be set once during the first [Request::factory] invocation.
	 *
	 * Example:
	 * ~~~
	 * // Get the first request
	 * $request = Request::initial();
	 *
	 * // Test whether the current request is the first request
	 * if (Request::initial() === Request::current())
	 * // Do something useful
	 * ~~~
	 *
	 * @return  Request
	 */
	public static function initial()
	{
		return Request::$initial;
	}

	/**
	 * Returns information about the client user agent
	 *
	 * Example:
	 * ~~~
	 * // Returns "Chrome" when using Google Chrome
	 * $browser = Request::user_agent('browser');
	 * ~~~
	 *
	 * Multiple values can be returned at once by using an array:
	 * ~~~
	 * // Get the browser and platform with a single call
	 * $info = Request::user_agent(array('browser', 'platform'));
	 * ~~~
	 *
	 * When using an array for the value, an associative array will be returned.
	 *
	 * @param   mixed   $value  String to return: browser, version, robot, mobile, platform; or array of values
	 *
	 * @return  mixed   Requested information, FALSE if nothing is found
	 *
	 * @uses    Config::get
	 * @uses    Request::$user_agent
	 * @uses    Request::user_agent
	 */
	public static function user_agent($value)
	{
		if (is_array($value))
		{
			$agent = array();
			foreach ($value as $v)
			{
				// Add each key to the set
				$agent[$v] = Request::user_agent($v);
			}

			return $agent;
		}

		static $info;

		if (isset($info[$value]))
		{
			// This value has already been found
			return $info[$value];
		}

		if ($value === 'browser' OR $value == 'version')
		{
			// Load browsers
			$browsers = Config::get('user_agents.browser', array());

			foreach ($browsers as $search => $name)
			{
				if (stripos(Request::$user_agent, $search) !== FALSE)
				{
					// Set the browser name
					$info['browser'] = $name;

					if (preg_match('#'.preg_quote($search).'[^0-9.]*+([0-9.][0-9.a-z]*)#i', Request::$user_agent, $matches))
					{
						// Set the version number
						$info['version'] = $matches[1];
					}
					else
					{
						// No version number found
						$info['version'] = FALSE;
					}

					return $info[$value];
				}
			}
		}
		else
		{
			// Load the search group for this type
			$group =Config::get('user_agents.value', array());

			foreach ($group as $search => $name)
			{
				if (stripos(Request::$user_agent, $search) !== FALSE)
				{
					// Set the value name
					return $info[$value] = $name;
				}
			}
		}

		// The value requested could not be found
		return $info[$value] = FALSE;
	}

	/**
	 * Returns the accepted content types
	 *
	 * If a specific type is defined, the quality of that type will be returned.
	 *
	 * Example:
	 * ~~~
	 * $types = Request::accept_type();
	 * ~~~
	 *
	 * @param   string  $type Content MIME type
	 * @return  mixed   An array of all types or a specific type as a string
	 * @uses    Request::_parse_accept
	 */
	public static function accept_type($type = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT'], array('*/*' => 1.0));
		}

		if (isset($type))
		{
			// Return the quality setting for this type
			return isset($accepts[$type]) ? $accepts[$type] : $accepts['*/*'];
		}

		return $accepts;
	}

	/**
	 * Returns the accepted languages
	 *
	 * If a specific language is defined, the quality of that language will be
	 * returned. If the language is not accepted, FALSE will be returned.
	 *
	 * Example:
	 * ~~~
	 * $langs = Request::accept_lang();
	 * ~~~
	 *
	 * @param   string  $lang  Language code
	 * @return  mixed   An array of all types or a specific type as a string
	 * @uses    Request::_parse_accept
	 */
	public static function accept_lang($lang = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT_LANGUAGE header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT_LANGUAGE']);
		}

		if (isset($lang))
		{
			// Return the quality setting for this lang
			return isset($accepts[$lang]) ? $accepts[$lang] : FALSE;
		}

		return $accepts;
	}

	/**
	 * Returns the accepted encodings
	 *
	 * If a specific encoding is defined, the quality of that encoding will be
	 * returned. If the encoding is not  accepted, FALSE will be returned.
	 *
	 * Example:
	 * ~~~
	 * $encodings = Request::accept_encoding();
	 * ~~~
	 *
	 * @param   string  $type Encoding type
	 * @return  mixed   An array of all types or a specific type as a string
	 * @uses    Request::_parse_accept
	 */
	public static function accept_encoding($type = NULL)
	{
		static $accepts;

		if ($accepts === NULL)
		{
			// Parse the HTTP_ACCEPT_LANGUAGE header
			$accepts = Request::_parse_accept($_SERVER['HTTP_ACCEPT_ENCODING']);
		}

		if (isset($type))
		{
			// Return the quality setting for this type
			return isset($accepts[$type]) ? $accepts[$type] : FALSE;
		}

		return $accepts;
	}

	/**
	 * Determines if a file larger than the post_max_size has been uploaded
	 *
	 * PHP does not handle this situation gracefully on its own, so this method
	 * helps to solve that problem.
	 *
	 * @return  boolean
	 *
	 * @uses    Arr::get
	 * @link    http://php.net/post-max-size
	 */
	public static function post_max_size_exceeded()
	{
		//return false for cli request
		if(Kohana::$is_cli === TRUE) return FALSE;

		// Make sure the request method is POST
		if ( ! Request::current()->is_post())
		{
			return FALSE;
		}

		// Error occurred if method is POST, and content length is too long
		return (Arr::get($_SERVER, 'CONTENT_LENGTH') > Request::get_post_max_size());
	}

	/**
	 * Fix for pagination on lambda routes
	 *
	 * Process URI
	 *
	 * @param   string  $uri     URI
	 * @param   array   $routes  Route [Optional]
	 * @return  array
	 */
	public static function process_uri($uri, $routes = NULL)
	{
		// Load routes
		$routes = (empty($routes)) ? Route::all() : $routes;
		$params = NULL;

		foreach ($routes as $name => $route)
		{
			// We found something suitable
			if ($params = $route->matches($uri))
			{
				// fix for pagination on lambda routes
				if ( ! isset($params['uri']))
				{
					$params['uri'] = $uri;
				}

				return array(
					'params' => $params,
					'route'  => $route,
					'name'   => $name,
				);
			}
		}

		return NULL;
	}

	/**
	 * Parses an accept header and returns an array (type => quality) of the
	 * accepted types, ordered by quality
	 *
	 * Example:
	 * ~~~
	 * $accept = Request::_parse_accept($header, $defaults);
	 * ~~~
	 *
	 * @param   string   $header   Header to parse
	 * @param   array    $accepts  Default values
	 * @return  array
	 */
	protected static function _parse_accept( & $header, array $accepts = NULL)
	{
		if ( ! empty($header))
		{
			// Get all of the types
			$types = explode(',', $header);

			foreach ($types as $type)
			{
				// Split the type into parts
				$parts = explode(';', $type);

				// Make the type only the MIME
				$type = trim(array_shift($parts));

				// Default quality is 1.0
				$quality = 1.0;

				foreach ($parts as $part)
				{
					// Prevent undefined $value notice below
					if (strpos($part, '=') === FALSE)
						continue;

					// Separate the key and value
					list ($key, $value) = explode('=', trim($part));

					if ($key === 'q')
					{
						// There is a quality for this type
						$quality = (float) trim($value);
					}
				}

				// Add the accept type and quality
				$accepts[$type] = $quality;
			}
		}

		// Make sure that accepts is an array
		$accepts = (array) $accepts;

		// Order by quality
		arsort($accepts);

		return $accepts;
	}

	/**
	 * Checks whether the request called by bot/crawller by useragent string
	 * Preg is faster than for loop
	 *
	 * @return boolean
	 *
	 * @todo use Request::$user_agent but it is null
	 */
	public static function is_crawler()
	{
		$crawlers = 'Bloglines subscriber|Dumbot|Sosoimagespider|QihooBot|FAST-WebCrawler'.
			'|Superdownloads Spiderman|LinkWalker|msnbot|ASPSeek|WebAlta Crawler|'.
			'Lycos|FeedFetcher-Google|Yahoo|YoudaoBot|AdsBot-Google|Googlebot|Scooter|'.
			'Gigabot|Charlotte|eStyle|AcioRobot|GeonaBot|msnbot-media|Baidu|CocoCrawler|'.
			'Google|Charlotte t|Yahoo! Slurp China|Sogou web spider|YodaoBot|MSRBOT|AbachoBOT|'.
			'Sogou head spider|AltaVista|IDBot|Sosospider|Yahoo! Slurp|'.
			'Java VM|DotBot|LiteFinder|Yeti|Rambler|Scrubby|Baiduspider|accoona';

		if (isset($_SERVER['HTTP_USER_AGENT']))
		{
			return (preg_match("/$crawlers/i", $_SERVER['HTTP_USER_AGENT']) > 0);
		}

		return FALSE;
	}

	/**
	 * Checks whether the request called by mobile device by useragent string
	 * Preg is faster than for loop
	 *
	 * @return boolean
	 *
	 * @todo use Request::$user_agent but it is null
	 */
	public static function is_mobile()
	{
		$devices = 'android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos';

		if (isset($_SERVER['HTTP_USER_AGENT']))
		{
			return (preg_match("/$devices/i", $_SERVER['HTTP_USER_AGENT']) > 0);
		}

		return FALSE;
	}

	/**
	 * Whether or not current request is DataTables
	 *
	 * @param   mixed  Request  Request [Optional]
	 * @return  boolean
	 * @uses    Request::current
	 */
	public static function is_datatables(Request $request = NULL)
	{
		$request = ($request) ? $request : Request::current();

		return (bool) $request->query('sEcho');
	}

	/**
	 * Gets POST max size in bytes
	 *
	 * @link    http://php.net/post-max-size
	 *
	 * @return  float
	 *
	 * @uses    Config::get
	 * @uses    Config::set
	 * @uses    Num::bytes
	 * @uses    Request::DEFAULT_POST_MAX_SIZE
	 */
	public static function get_post_max_size()
	{
		$max_size = Config::get('media.post_max_size', NULL);

		// Set post_max_size default value if it not exists
		if (is_null($max_size))
		{
			Config::set('media', 'post_max_size', Request::DEFAULT_POST_MAX_SIZE);
		}

		// Get the post_max_size in bytes from php.ini
		$php_settings = Num::bytes(ini_get('post_max_size'));

		// Get the post_max_size in bytes from `config/media`
		$gleez_settings = Num::bytes($max_size);

		return ($gleez_settings <= $php_settings) ? $gleez_settings : $php_settings;
	}

	/**
	 * Creates a new request object for the given URI
	 *
	 * New requests should be created using the [Request::factory] method
	 *
	 * Example:
	 * ~~~
	 * $request = new Request($uri);
	 * ~~~
	 *
	 * If $cache parameter is set, the response for the request will attempt to
	 * be retrieved from the cache.
	 *
	 * @param   string      $uri URI of the request
	 * @param   HTTP_Cache  $cache
	 * @param   array       $injected_routes an array of routes to use, for testing
	 *
	 * @throws  Request_Exception
	 *
	 * @uses    Route::all
	 * @uses    Route::matches
	 */
	public function __construct($uri, HTTP_Cache $cache = NULL, $injected_routes = array())
	{
		// Initialise the header
		$this->_header = new HTTP_Header(array());

		// Assign injected routes
		$this->_routes = $injected_routes;

		// Cleanse query parameters from URI (faster that parse_url())
		$split_uri = explode('?', $uri);
		$uri = array_shift($split_uri);

		// Initial request has global $_GET already applied
		if (Request::$initial !== NULL)
		{
			if ($split_uri)
			{
				parse_str($split_uri[0], $this->_get);
			}
		}

		// Detect protocol (if present)
		// Always default to an internal request if we don't have an initial.
		// This prevents the default index.php from being able to proxy
		// external pages.
		if (Request::$initial === NULL OR strpos($uri, '://') === FALSE)
		{
			// Remove trailing slashes from the URI
			$uri = trim($uri, '/');

			$processed_uri = Request::process_uri($uri, $this->_routes);

			// Return here rather than throw exception. This will allow
			// use of Request object even with unmatched route
			if ($processed_uri === NULL)
			{
				$this->_uri = $uri;
				return;
			}

			// Store the URI
			$this->_uri = $uri;

			// Store the matching route
			$this->_route = $processed_uri['route'];
			$params = $processed_uri['params'];

			// Is this route external?
			$this->_external = $this->_route->is_external();

			if (isset($params['directory']))
			{
				// Controllers are in a sub-directory
				$this->_directory = $params['directory'];
			}

			// Store the controller
			$this->_controller = $params['controller'];

			if (isset($params['action']))
			{
				// Store the action
				$this->_action = $params['action'];
			}
			else
			{
				// Use the default action
				$this->_action = Route::$default_action;
			}

			// These are accessible as public vars and can be overloaded
			unset($params['controller'], $params['action'], $params['directory']);

			// Params cannot be changed once matched
			$this->_params = $params;

			// Apply the client
			$this->_client = new Request_Client_Internal(array('cache' => $cache));
		}
		else
		{
			// Create a route
			$this->_route = new Route($uri);

			// Store the URI
			$this->_uri = $uri;

			// Set the security setting if required
			if (strpos($uri, 'https://') === 0)
			{
				$this->secure(TRUE);
			}

			// Set external state
			$this->_external = TRUE;

			// Setup the client
			$this->_client = Request_Client_External::factory(array('cache' => $cache));
		}
	}

	/**
	 * Returns the response as the string representation of a request
	 *
	 * Example:
	 * ~~~
	 * echo $request;
	 * ~~~
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return $this->render();
	}

	/**
	 * Returns the URI for the current route
	 *
	 * Example:
	 * ~~~
	 * $request->uri();
	 * ~~~
	 *
	 * @return  string
	 */
	public function uri()
	{
		return empty($this->_uri) ? '/' : $this->_uri;
	}

	/**
	 * Create a URL string from the current request
	 *
	 * This is a shortcut for:
	 * ~~~
	 * echo URL::site($this->request->uri(), $protocol);
	 * ~~~
	 *
	 * @param   mixed    $protocol  protocol string or Request object
	 *
	 * @return  string
	 *
	 * @uses    URL::site
	 */
	public function url($protocol = NULL)
	{
		// Create a URI with the current route and convert it to a URL
		return URL::site($this->uri(), $protocol);
	}

	/**
	 * Retrieves a value from the route parameters
	 *
	 * Example:
	 * ~~~
	 * $id = $request->param('id');
	 * ~~~
	 *
	 * @param   string   $key      Key of the value
	 * @param   mixed    $default  Default value if the key is not set
	 * @return  mixed
	 */
	public function param($key = NULL, $default = NULL)
	{
		if ($key === NULL)
		{
			// Return the full array
			return $this->_params;
		}

		return isset($this->_params[$key]) ? $this->_params[$key] : $default;
	}

	/**
	 * Redirects as the request response. If the URL does not include a
	 * protocol, it will be converted into a complete URL.
	 *
	 * Example:
	 * ~~~
	 * $request->redirect($url);
	 * ~~~
	 *
	 * [!!] No further processing can be done after this method is called!
	 *
	 * @param   string   $url   Redirect location
	 * @param   integer  $code  Status code: 301, 302, etc
	 * @return  void
	 * @uses    URL::site
	 * @uses    Request::send_headers
	 */
	public function redirect($url = '', $code = 302)
	{
		$referrer = $this->uri();

		if (strpos($referrer, '://') === FALSE)
		{
			$referrer = URL::site($referrer, TRUE, Kohana::$index_file);
		}

		if (strpos($url, '://') === FALSE)
		{
			// Make the URI into a URL
			$url = URL::site($url, TRUE, Kohana::$index_file);
		}

		// Check whether the current request is ajax request
		if ($this->is_ajax())
		{
			self::$redirect_url = $url;
			// Stop execution
			return;
		}

		if (($response = $this->response()) === NULL)
		{
			$response = $this->create_response();
		}

		echo $response->status($code)
			->headers('Location', $url)
			->headers('Referer', $referrer)
			->send_headers()
			->body();

		// Stop execution
		exit;
	}

	/**
	 * Sets and gets the referrer from the request.
	 *
	 * @param   string $referrer
	 * @return  mixed
	 */
	public function referrer($referrer = NULL)
	{
		if ($referrer === NULL)
		{
			// Act as a getter
			return $this->_referrer;
		}

		// Act as a setter
		$this->_referrer = (string) $referrer;

		return $this;
	}

	/**
	 * Sets and gets the route from the request.
	 *
	 * @param   Route $route
	 * @return  mixed
	 */
	public function route(Route $route = NULL)
	{
		if ($route === NULL)
		{
			// Act as a getter
			return $this->_route;
		}

		// Act as a setter
		$this->_route = $route;

		return $this;
	}

	/**
	 * Sets and gets the directory for the controller.
	 *
	 * @param   string   $directory  Directory to execute the controller from
	 * @return  mixed
	 */
	public function directory($directory = NULL)
	{
		if ($directory === NULL)
		{
			// Act as a getter
			return $this->_directory;
		}

		// Act as a setter
		$this->_directory = (string) $directory;

		return $this;
	}

	/**
	 * Sets and gets the controller for the matched route.
	 *
	 * @param   string   $controller  Controller to execute the action
	 * @return  mixed
	 */
	public function controller($controller = NULL)
	{
		if ($controller === NULL)
		{
			// Act as a getter
			return $this->_controller;
		}

		// Act as a setter
		$this->_controller = (string) $controller;

		return $this;
	}

	/**
	 * Sets and gets the action for the controller.
	 *
	 * @param   string   $action  Action to execute the controller from
	 * @return  mixed
	 */
	public function action($action = NULL)
	{
		if ($action === NULL)
		{
			// Act as a getter
			return $this->_action;
		}

		// Act as a setter
		$this->_action = (string) $action;

		return $this;
	}

	/**
	 * Provides access to the [Request_Client]
	 *
	 * @param Request_Client $client
	 *
	 * @return Request|Request_Client
	 */
	public function client(Request_Client $client = NULL)
	{
		if ($client === NULL)
			return $this->_client;
		else
		{
			$this->_client = $client;
			return $this;
		}
	}

	/**
	 * Gets and sets the requested with property, which should
	 * be relative to the x-requested-with pseudo header.
	 *
	 * @param   string    $requested_with Requested with value
	 * @return  mixed
	 */
	public function requested_with($requested_with = NULL)
	{
		if ($requested_with === NULL)
		{
			// Act as a getter
			return $this->_requested_with;
		}

		// Act as a setter
		$this->_requested_with = strtolower($requested_with);

		return $this;
	}

	/**
	 * Processes the request, executing the controller action that handles this
	 * request, determined by the [Route].
	 *
	 * 1. Before the controller action is called, the [Controller::before] method
	 * will be called.
	 * 2. Next the controller action will be called.
	 * 3. After the controller action is called, the [Controller::after] method
	 * will be called.
	 *
	 * By default, the output from the controller is captured and returned, and
	 * no headers are sent.
	 *
	 * Example:
	 * ~~~
	 * $request->execute();
	 * ~~~
	 *
	 * @return  Response
	 *
	 * @throws  Request_Exception
	 *
	 * @throws  HTTP_Exception_404
	 * @uses    [Kohana::$profiling]
	 * @uses    [Profiler]
	 * @uses    Gleez::block_ips
	 * @uses    Gleez::maintenance_mode
	 */
	public function execute()
	{
		if (Gleez::$installed)
		{
			// Deny access to blocked IP addresses
			Gleez::block_ips();

			// Check Maintenance Mode
			Gleez::maintenance_mode();
		}

		if ( ! $this->_route instanceof Route)
		{
			return HTTP_Exception::factory(404, 'Unable to find a route to match the URI: :uri', array(
					':uri' => $this->_uri
				))
				->request($this)
				->get_response();
		}

		if ( ! $this->_client instanceof Request_Client)
		{
			throw new Request_Exception('Unable to execute :uri without a Kohana_Request_Client', array(
				':uri' => $this->_uri,
			));
		}

		return $this->_client->execute($this);
	}

	/**
	 * Returns whether this request is the initial request Kohana received.
	 *
	 * Can be used to test for sub requests:
	 * ~~~
	 * if ( ! $request->is_initial())
	 *     // This is a sub request
	 * ~~~
	 *
	 * @return  boolean
	 */
	public function is_initial()
	{
		return ($this === Request::$initial);
	}

	/**
	 * Readonly access to the [Request::$_external] property.
	 *
	 * Example:
	 * ~~~
	 * if ( ! $request->is_external())
	 *     // This is an internal request
	 * ~~~
	 *
	 * @return  boolean
	 */
	public function is_external()
	{
		return $this->_external;
	}

	/**
	 * Returns whether this is an ajax request (as used by JS frameworks)
	 *
	 * @return  boolean
	 */
	public function is_ajax()
	{
		return ($this->requested_with() === 'xmlhttprequest');
	}

	/**
	 * Generates an [ETag](http://en.wikipedia.org/wiki/HTTP_ETag) from the
	 * request response.
	 *
	 * Example:
	 * ~~~
	 * $etag = $request->generate_etag();
	 * ~~~
	 *
	 * [!!] If the request response is empty when this method is called, an
	 * exception will be thrown!
	 *
	 * @return string
	 * @throws Request_Exception
	 */
	public function generate_etag()
	{
	    if ($this->_response === NULL)
		{
			throw new Request_Exception('No response yet associated with request - cannot auto generate resource ETag');
		}

		// Generate a unique hash for the response
		return '"'.sha1($this->_response).'"';
	}

	/**
	 * Set or get the response for this request
	 *
	 * @param   Response  $response  Response to apply to this request
	 * @return  Response
	 * @return  void
	 */
	public function response(Response $response = NULL)
	{
		if ($response === NULL)
		{
			// Act as a getter
			return $this->_response;
		}

		// Act as a setter
		$this->_response = $response;

		return $this;
	}

	/**
	 * Creates a response based on the type of request, i.e. an
	 * Request_HTTP will produce a Response_HTTP, and the same applies
	 * to CLI.
	 *
	 * Example:
	 * ~~~
	 * // Create a response to the request
	 * $response = $request->create_response();
	 * ~~~
	 *
	 * @param   boolean  $bind  Bind to this request
	 * @return  Response
	 * @since   3.1.0
	 */
	public function create_response($bind = TRUE)
	{
		$response = new Response(array('_protocol' => $this->protocol()));

		if ($bind)
		{
			// Bind a new response to the request
			$this->_response = $response;
		}

		return $response;
	}

	/**
	 * Gets or sets the HTTP method. Usually GET, POST, PUT or DELETE in
	 * traditional CRUD applications.
	 *
	 * @param   string   $method  Method to use for this request
	 *
	 * @return  string|Request
	 */
	public function method($method = NULL)
	{
		if (is_null($method))
		{
			// Act as a getter
			return $this->_method;
		}

		// Act as a setter
		$this->_method = strtoupper($method);

		return $this;
	}

	/**
	 * Gets or sets the HTTP protocol. If there is no current protocol set,
	 * it will use the default set in HTTP::$protocol
	 *
	 * @param   string   $protocol  Protocol to set to the request/response
	 * @return  mixed
	 */
	public function protocol($protocol = NULL)
	{
		if ($protocol === NULL)
		{
			if ($this->_protocol)
				return $this->_protocol;
			else
				return $this->_protocol = HTTP::$protocol;
		}

		// Act as a setter
		$this->_protocol = strtoupper($protocol);
		return $this;
	}

	/**
	 * Getter/Setter to the security settings for this request. This
	 * method should be treated as immutable.
	 *
	 * @param   boolean $secure is this request secure?
	 * @return  mixed
	 */
	public function secure($secure = NULL)
	{
		if ($secure === NULL)
			return $this->_secure;

		// Act as a setter
		$this->_secure = (bool) $secure;
		return $this;
	}

	/**
	 * Gets or sets HTTP headers to the request or response. All headers
	 * are included immediately after the HTTP protocol definition during
	 * transmission. This method provides a simple array or key/value
	 * interface to the headers.
	 *
	 * @param   mixed   $key   Key or array of key/value pairs to set
	 * @param   string  $value Value to set to the supplied key
	 *
	 * @return  Request|HTTP_Header|mixed
	 */
	public function headers($key = NULL, $value = NULL)
	{
		if ($key instanceof HTTP_Header)
		{
			// Act a setter, replace all headers
			$this->_header = $key;

			return $this;
		}

		if (is_array($key))
		{
			// Act as a setter, replace all headers
			$this->_header->exchangeArray($key);

			return $this;
		}

		if ($this->_header->count() === 0 AND $this->is_initial())
		{
			// Lazy load the request headers
			$this->_header = HTTP::request_headers();
		}

		if ($key === NULL)
		{
			// Act as a getter, return all headers
			return $this->_header;
		}
		elseif ($value === NULL)
		{
			// Act as a getter, single header
			return ($this->_header->offsetExists($key)) ? $this->_header->offsetGet($key) : NULL;
		}

		// Act as a setter for a single header
		$this->_header[$key] = $value;

		return $this;
	}

	/**
	 * Set and get cookies values for this request.
	 *
	 * @param   mixed    $key    Cookie name, or array of cookie values
	 * @param   string   $value  Value to set to cookie
	 * @return  string
	 * @return  mixed
	 */
	public function cookie($key = NULL, $value = NULL)
	{
		if (is_array($key))
		{
			// Act as a setter, replace all cookies
			$this->_cookies = $key;
			return $this;
		}
		elseif ($key === NULL)
		{
			// Act as a getter, all cookies
			return $this->_cookies;
		}
		elseif ($value === NULL)
		{
			// Act as a getting, single cookie
			return isset($this->_cookies[$key]) ? $this->_cookies[$key] : NULL;
		}

		// Act as a setter for a single cookie
		$this->_cookies[$key] = (string) $value;

		return $this;
	}

	/**
	 * Gets or sets the HTTP body to the request or response. The body is
	 * included after the header, separated by a single empty new line.
	 *
	 * @param   string  $content Content to set to the object
	 *
	 * @return  HTTP_Request|string
	 */
	public function body($content = NULL)
	{
		if (is_null($content))
		{
			// Act as a getter
			return $this->_body;
		}

		// Act as a setter
		$this->_body = $content;

		return $this;
	}

	/**
	 * Returns the length of the body for use with
	 * content header
	 *
	 * @return  integer
	 */
	public function content_length()
	{
		return strlen($this->body());
	}

	/**
	 * Renders the HTTP_Interaction to a string, producing
	 *
	 *  - Protocol
	 *  - Headers
	 *  - Body
	 *
	 *  If there are variables set to the `Request::$_post`
	 *  they will override any values set to body.
	 *
	 * @return  string
	 */
	public function render()
	{
		if ( ! $post = $this->post())
		{
			$body = $this->body();
		}
		else
		{
			$this->headers('content-type', 'application/x-www-form-urlencoded');
			$body = http_build_query($post, NULL, '&');
		}

		// Set the content length
		$this->headers('content-length', (string) $this->content_length());

		// If Kohana expose, set the user-agent
		if (Kohana::$expose)
		{
			$this->headers('user-agent', 'Gleez CMS '.Gleez::VERSION.' ('.Gleez::CODENAME.')');
		}

		// Prepare cookies
		if ($this->_cookies)
		{
			$cookie_string = array();

			// Parse each
			foreach ($this->_cookies as $key => $value)
			{
				$cookie_string[] = $key.'='.$value;
			}

			// Create the cookie string
			$this->_header['cookie'] = implode('; ', $cookie_string);
		}

		$output = $this->method().' '.$this->uri().' '.$this->protocol()."\r\n";
		$output .= (string) $this->_header;
		$output .= $body;

		return $output;
	}

	/**
	 * Gets or sets HTTP query string.
	 *
	 * @param   mixed   $key    Key or key value pairs to set
	 * @param   string  $value  Value to set to a key
	 * @return  mixed
	 */
	public function query($key = NULL, $value = NULL)
	{
		if (is_array($key))
		{
			// Act as a setter, replace all query strings
			$this->_get = $key;

			return $this;
		}

		if ($key === NULL)
		{
			// Act as a getter, all query strings
			return $this->_get;
		}
		elseif ($value === NULL)
		{
			// Act as a getter, single query string
			return Arr::get($this->_get, $key);
		}

		// Act as a setter, single query string
		$this->_get[$key] = $value;

		return $this;
	}

	/**
	 * Gets or sets HTTP POST parameters to the request.
	 *
	 * @param   mixed  $key    Key or key value pairs to set
	 * @param   string $value  Value to set to a key
	 * @return  mixed
	 */
	public function post($key = NULL, $value = NULL)
	{
		if (is_array($key))
		{
			// Act as a setter, replace all fields
			$this->_post = $key;

			return $this;
		}

		if ($key === NULL)
		{
			// Act as a getter, all fields
			return $this->_post;
		}
		elseif ($value === NULL)
		{
			// Act as a getter, single field
			return Arr::get($this->_post, $key);
		}

		// Act as a setter, single field
		$this->_post[$key] = $value;

		return $this;
	}

	/**
	 * Check to see if the current request is a GET request
	 *
	 * Example:
	 * ~~~
	 * $this->request->is_get();
	 * ~~~
	 *
	 * @return  boolean  Whether the request is a GET request or not
	 */
	public function is_get()
	{
		return (self::GET === $this->_method);
	}

	/**
	 * Check to see if the current request is a POST request
	 *
	 * Example:
	 * ~~~
	 * $this->request->is_post();
	 * ~~~
	 *
	 * @return  boolean  Whether the request is a POST request or not
	 */
	public function is_post()
	{
		return (self::POST === $this->_method);
	}

	/**
	 * Check to see if the current request is a PUT request
	 *
	 * Example:
	 * ~~~
	 * $this->request->is_put();
	 * ~~~
	 *
	 * @return  boolean  Whether the request is a PUT request or not
	 */
	public function is_put()
	{
		return (self::PUT === $this->_method);
	}

	/**
	 * Check to see if the current request is a DELETE request
	 *
	 * Example:
	 * ~~~
	 * $this->request->is_delete();
	 * ~~~
	 *
	 * @return  boolean  Whether the request is a DELETE request or not
	 */
	public function is_delete()
	{
		return (self::DELETE === $this->_method);
	}
}

