<?php

	/**
	 * @package core
	 */

	/**
	 * The Administration class is an instance of Symphony that controls
	 * all backend pages. These pages are HTMLPages are usually generated
	 * using XMLElement before being rendered as HTML. These pages do not
	 * use XSLT. The Administration is only accessible by logged in Authors
	 */
	require_once(CORE . '/class.symphony.php');
	require_once(TOOLKIT . '/class.lang.php');
	require_once(TOOLKIT . '/class.manager.php');
	require_once(TOOLKIT . '/class.htmlpage.php');
	require_once(TOOLKIT . '/class.ajaxpage.php');

	Class Administration extends Symphony{

		/**
		 * The path of the current page, ie. '/blueprints/sections/'
		 * @var string
		 */
		private $_currentPage  = null;

		/**
		 * An associative array of the page's callback, including the keys
		 * 'driver', which is a lowercase version of `$this->_currentPage`
		 * with any slashes removed, 'classname', which is the name of the class
		 * for this page, 'pageroot', which is the root page for the given page, (ie.
		 * excluding /saved/, /created/ or any sub pages of the current page that are
		 * handled using the _switchboard function.
		 *
		 * @see toolkit.AdministrationPage#__switchboard()
		 * @var array
		 */
		private $_callback = null;

		/**
		 * The class representation of the current Symphony backend page,
		 * which is a subclass of the HTMLPage class. Symphony uses a convention
		 * of prefixing backend page classes with 'content'. ie. 'contentBlueprintsSections'
		 * @var HTMLPage
		 */
		public $Page;

		/**
		 * This function returns an instance of the Administration
		 * class. It is the only way to create a new Administration, as
		 * it implements the Singleton interface
		 *
		 * @return Frontend
		 */
		public static function instance(){
			if(!(self::$_instance instanceof Administration))
				self::$_instance = new self;

			return self::$_instance;
		}

		/**
		 * The constructor for Administration calls the parent Symphony
		 * constructor.
		 *
		 * @see core.Symphony#__construct()
		 * @deprecated The constructor creates backwards compatible references
		 *  to `$this->Database` and `$this->Configuration`
		 *  that act as alias for `Symphony::Database()`
		 *  or `Symphony::Configuration()`. These will be removed in the
		 *  next Symphony release
		 */
		protected function __construct(){
			parent::__construct();

			// Need this part for backwards compatiblity
			$this->Database = Symphony::Database();
			$this->Configuration = Symphony::Configuration();
		}

		/**
		 * Returns the current Page path, excluding the domain and Symphony path.
		 *
		 * @return string
		 *  The path of the current page, ie. '/blueprints/sections/'
		 */
		public function getCurrentPageURL(){
			return $this->_currentPage;
		}

		/**
		 * Overrides the Symphony isLoggedIn function to allow Authors
		 * to become logged into the backend when `$_REQUEST['auth-token']`
		 * is present. This logs an Author in using the loginFromToken function.
		 * A token may be 6 or 8 characters in length in the backend. A 6 character token
		 * is used for forget password requests, whereas the 8 character token is used to login
		 * an Author into the page
		 *
		 * @see core.Symphony#loginFromToken()
		 * @return boolean
		 */
		public function isLoggedIn(){
			if (isset($_REQUEST['auth-token']) && $_REQUEST['auth-token'] && in_array(strlen($_REQUEST['auth-token']), array(6, 8))) {
				return $this->loginFromToken($_REQUEST['auth-token']);
			}

			return parent::isLoggedIn();
		}

		/**
		 * Given the URL path of a Symphony backend page, this function will
		 * attempt to resolve the URL to a Symphony content page in the backend
		 * or a page provided by an extension. This function checks to ensure a user
		 * is logged in, otherwise it will direct them to the login page
		 *
		 * @param string $page
		 *  The URL path after the root of the Symphony installation, including a starting
		 *  slash, such as '/login/'
		 * @return HTMLPage
		 */
		private function __buildPage($page){

			if(empty($page) || is_null($page)){
				if(!$this->isLoggedIn()) {
					$page  = "/login";
				}
				else {

					// Will redirect an Author to their default area of the Backend
					// Integers are indicative of section's, text is treated as the path
					// to the page after `SYMPHONY_URL`
					$default_area = null;

					if(is_numeric($this->Author->get('default_area'))) {
						$section_handle = Symphony::Database()->fetchVar('handle', 0, "SELECT `handle` FROM `tbl_sections` WHERE `id` = '".$this->Author->get('default_area')."' LIMIT 1");

						if(!$section_handle){
							$section_handle = Symphony::Database()->fetchVar('handle', 0, "SELECT `handle` FROM `tbl_sections` ORDER BY `sortorder` LIMIT 1");
						}

						$default_area = "/publish/{$section_handle}/";

					}
					else if(!is_null($this->Author->get('default_area'))) {
						$default_area = preg_replace('/^' . preg_quote(SYMPHONY_URL, '/') . '/i', '', $this->Author->get('default_area'));
					}

					if(is_null($default_area)) {
						if($this->Author->isDeveloper()) {
							redirect(SYMPHONY_URL . '/blueprints/sections/');
						}
						else {
							redirect(SYMPHONY_URL . "/system/authors/edit/".$this->Author->get('id')."/");
						}
					}
					else {
						redirect(SYMPHONY_URL . $default_area);
					}
				}
			}

			if(!$this->_callback = $this->getPageCallback($page)){
				$this->errorPageNotFound();
			}

			include_once((isset($this->_callback['driverlocation']) ? $this->_callback['driverlocation'] : CONTENT) . '/content.' . $this->_callback['driver'] . '.php');
			$this->Page = new $this->_callback['classname']($this);

			if(!$this->isLoggedIn() && $this->_callback['driver'] != 'login'){
				if(is_callable(array($this->Page, 'handleFailedAuthorisation'))) $this->Page->handleFailedAuthorisation();
				else{

					include_once(CONTENT . '/content.login.php');
					$this->Page = new contentLogin($this);
					$this->Page->build();
				}
			}

			else {
				if (!is_array($this->_callback['context'])) $this->_callback['context'] = array();
				$this->Page->build($this->_callback['context']);
			}

			return $this->Page;
		}

		/**
		 * This function resolves the string of the page to the relevant
		 * backend page class. The path to the backend page is split on
		 * the slashes and the resulting pieces used to determine if the page
		 * is provided by an extension, is a section (index or entry creation)
		 * or finally a standard Symphony content page. If no page driver can
		 * be found, this function will return false
		 *
		 * @param string $page
		 *  The full path (including the domain) of the Symphony backend page
		 * @return array|boolean
		 *  If successful, this function will return an associative array that at the
		 *  very least will return the page's classname, pageroot, driver and
		 *  context, otherwise this will return false.
		 */
		public function getPageCallback($page = null){

			if(!$page && $this->_callback) return $this->_callback;
			elseif(!$page && !$this->_callback) trigger_error(__('Cannot request a page callback without first specifying the page.'));

			$this->_currentPage = URL . preg_replace('/\/{2,}/', '/', '/symphony' . $page);
			$bits = preg_split('/\//', trim($page, '/'), 3, PREG_SPLIT_NO_EMPTY);

			$callback = array(
				'driver' => null,
				'context' => null,
				'classname' => null,
				'pageroot' => null
			);

			if($bits[0] == 'login'){
				$callback = array(
					'driver' => 'login',
					'context' => preg_split('/\//', $bits[1] . '/' . $bits[2], -1, PREG_SPLIT_NO_EMPTY),
					'classname' => 'contentLogin',
					'pageroot' => '/login/'
				);
			}

			elseif($bits[0] == 'extension' && isset($bits[1])){

				$extension_name = $bits[1];
				$bits = preg_split('/\//', trim($bits[2], '/'), 2, PREG_SPLIT_NO_EMPTY);

				$callback['driverlocation'] = EXTENSIONS . '/' . $extension_name . '/content/';
				$callback['driver'] = 'index';
				$callback['classname'] = 'contentExtension' . ucfirst($extension_name) . 'Index';
				$callback['pageroot'] = '/extension/' . $extension_name. '/';

				if(isset($bits[0])){
					$callback['driver'] = $bits[0];
					$callback['classname'] = 'contentExtension' . ucfirst($extension_name) . ucfirst($bits[0]);
					$callback['pageroot'] .= $bits[0] . '/';
				}

				if(isset($bits[1])) $callback['context'] = preg_split('/\//', $bits[1], -1, PREG_SPLIT_NO_EMPTY);

				if(!is_file($callback['driverlocation'] . '/content.' . $callback['driver'] . '.php')) return false;

			}

			elseif($bits[0] == 'publish'){

				if(!isset($bits[1])) return false;

				$callback = array(
					'driver' => 'publish',
					'context' => array(
						'section_handle' => $bits[1],
						'page' => null,
						'entry_id' => null,
						'flag' => null
					),
					'pageroot' => '/' . $bits[0] . '/' . $bits[1] . '/',
					'classname' => 'contentPublish'
				);

				if(isset($bits[2])){
					$extras = preg_split('/\//', $bits[2], -1, PREG_SPLIT_NO_EMPTY);

					$callback['context']['page'] = $extras[0];
					if(isset($extras[1])) $callback['context']['entry_id'] = intval($extras[1]);

					if(isset($extras[2])) $callback['context']['flag'] = $extras[2];
				}

				else $callback['context']['page'] = 'index';

			}

			else{
				$callback['driver'] = ucfirst($bits[0]);
				$callback['pageroot'] = '/' . $bits[0] . '/';

				if(isset($bits[1])){
					$callback['driver'] = $callback['driver'] . ucfirst($bits[1]);
					$callback['pageroot'] .= $bits[1] . '/';
				}

				if(isset($bits[2])) $callback['context'] = preg_split('/\//', $bits[2], -1, PREG_SPLIT_NO_EMPTY);

				$callback['classname'] = 'content' . $callback['driver'];
				$callback['driver'] = strtolower($callback['driver']);

				if(!is_file(CONTENT . '/content.' . $callback['driver'] . '.php')) return false;

			}

			## TODO: Add delegate for custom callback creation

			return $callback;
		}

		/**
		 * Called by index.php, this function is responsible for rendering the current
		 * page on the Frontend. Two delegates are fired, AdminPagePreGenerate and
		 * AdminPagePostGenerate. This function runs the Profiler for the page build
		 * process.
		 *
		 * @uses AdminPagePreGenerate
		 * @uses AdminPagePostGenerate
		 * @see core.Symphony#__buildPage()
		 * @see boot.getCurrentPage()
		 * @param string $page
		 *  The result of getCurrentPage, which returns the $_GET['symphony-page']
		 *  variable.
		 * @return string
		 *  The HTML of the page to return
		 */
		public function display($page){

			$this->Profiler->sample('Page build process started');
			$this->__buildPage($page);

			/**
			 * Immediately before generating the admin page. Provided with the page object
			 * @delegate AdminPagePreGenerate
			 * @param string $context
			 *  '/backend/'
			 * @param HTMLPage $oPage
			 *  An instance of the current page to be rendered, this will usually be a class that
			 *  extends HTMLPage. The Symphony backend uses a convention of contentPageName
			 *  as the class that extends the HTMLPage
			 */
			$this->ExtensionManager->notifyMembers('AdminPagePreGenerate', '/backend/', array('oPage' => &$this->Page));

			$output = $this->Page->generate();

			/**
			 * Immediately after generating the admin page. Provided with string containing page source
			 * @delegate AdminPagePostGenerate
			 * @param string $context
			 *  '/backend/'
			 * @param string $output
			 *  The resulting backend page HTML as a string, passed by reference
			 */
			$this->ExtensionManager->notifyMembers('AdminPagePostGenerate', '/backend/', array('output' => &$output));

			$this->Profiler->sample('Page built');

			return $output;
		}

		/**
		 * Writes the current Symphony Configuration object to a file in the
		 * CONFIG directory. This will overwrite any existing configuration
		 * file every time this function is called.
		 *
		 * @see core.Configuration#__toString()
		 * @return boolean
		 *  True if the Configuration object was successfully written, false otherwise
		 */
		public function saveConfig(){
			$string  = "<?php\n\t\$settings = ".(string)self::Configuration().";\n";
			return General::writeFile(CONFIG, $string, self::Configuration()->get('write_mode', 'file'));
		}

		/**
		 * If a page is not found in the Symphony backend, this function should
		 * be called which will raise a customError to display the default Symphony
		 * page not found template
		 */
		public function errorPageNotFound(){
			$this->customError(__('Page Not Found'), __('The page you requested does not exist.'), 'error', array('header' => 'HTTP/1.0 404 Not Found'));
		}

	}
