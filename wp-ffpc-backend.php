<?php
/**
 * backend driver for WordPress plugin WP-FFPC
 *
 * supported storages:
 *  - APC
 *  - Memcached
 *  - Memcache
 *
 */

if (!class_exists('WP_FFPC_Backend')) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	if ( function_exists ( '__' ) ) {
		function __translate__ ( $text, $domain ) { return __($text, $domain); }
	}
	else {
		function __translate__ ( $text, $domain ) { return $text; }
	}


	/**
	 *
	 * @var array $key_prefixes Prefix keys for entries
	 * @var mixed	$connection	Backend object storage variable
	 * @var array	$config		Configuration settings array
	 * @var boolean	$alive		Backend aliveness indicator
	 * @var mixed	$status		Backend server status storage
	 *
	 */
	class WP_FFPC_Backend {

		const plugin_constant = 'wp-ffpc';
		const network_key = 'network';
		const id_prefix = 'wp-ffpc-id-';
		const prefix = 'prefix-';
		const host_separator  = ',';
		const port_separator  = ':';

		private $key_prefixes = array ( 'meta', 'data' );
		private $connection = NULL;
		private $options;
		private $alive = false;
		private $status;

		/**
		* constructor
		*
		* @param mixed $config Configuration options
		*
		*/
		public function __construct( $config ) {

			$this->options = $config;

			/* no config, nothing is going to work */
			if ( empty ( $this->options ) ) {
				return false;
			}

			/* split hosts entry to servers */
			$this->set_servers();

			/* call backend initiator based on cache type */
			$init = $this->proxy( 'init' );

			$this->log ( __translate__(' init starting', self::plugin_constant ));
			$this->$init();
		}

		/*********************** PUBLIC FUNCTIONS ***********************/

		/**
		 * build key to make requests with
		 *
		 * @param string $suffix suffix to add to prefix
		 *
		 */
		public function key ( $suffix = 'meta' ) {
			/* use the full accessed URL string as key, same will be generated by nginx as well
			   we need a data and a meta key: data is string only with content, meta is not used in nginx */
			if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' )
				$_SERVER['HTTPS'] = 'on';

			$protocol = ( isset($_SERVER['HTTPS']) && ( ( strtolower($_SERVER['HTTPS']) == 'on' )  || ( $_SERVER['HTTPS'] == '1' ) ) ) ? 'https://' : 'http://';

			return $suffix . '-' . $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}


		/**
		 * public get function, transparent proxy to internal function based on backend
		 *
		 * @param string $key Cache key to get value for
		 *
		 * @return mixed False when entry not found or entry value on success
		 */
		public function get ( &$key ) {

			/* look for backend aliveness, exit on inactive backend */
			if ( ! $this->is_alive() )
				return false;

			/* log the current action */
			$this->log ( __translate__('get ', self::plugin_constant ). $key );

			/* proxy to internal function */
			$internal = $this->proxy( 'get' );
			$result = $this->$internal( $key );

			if ( $result === false  )
				$this->log ( __translate__( "failed to get entry: ", self::plugin_constant ) . $key );

			return $result;
		}

		/**
		 * public set function, transparent proxy to internal function based on backend
		 *
		 * @param string $key Cache key to set with ( reference only, for speed )
		 * @param mixed $data Data to set ( reference only, for speed )
		 *
		 * @return mixed $result status of set function
		 */
		public function set ( &$key, &$data ) {

			/* look for backend aliveness, exit on inactive backend */
			if ( ! $this->is_alive() )
				return false;

			/* log the current action */
			$this->log( __translate__('set ', self::plugin_constant ) . $key . __translate__(' expiration time: ', self::plugin_constant ) . $this->options['expire']);

			/* proxy to internal function */
			$internal = $this->options['cache_type'] . '_set';
			$result = $this->$internal( $key, $data );

			/* check result validity */
			if ( $result === false )
				$this->log ( __translate__('failed to set entry: ', self::plugin_constant ) . $key, LOG_WARNING );

			return $result;
		}

		/**
		 * public get function, transparent proxy to internal function based on backend
		 *
		 * @param string $key Cache key to invalidate, false mean full flush
		 */
		public function clear ( $post_id = false ) {

			/* look for backend aliveness, exit on inactive backend */
			if ( ! $this->is_alive() )
				return false;

			/* exit if no post_id is specified */
			if ( empty ( $post_id ) && $this->options['invalidation_method'] != 0) {
				$this->log ( __translate__('not clearing unidentified post ', self::plugin_constant ), LOG_WARNING );
				return false;
			}

			/* if invalidation method is set to full, flush cache */
			if ( $this->options['invalidation_method'] === 0 || empty ( $post_id ) ) {
				/* log action */
				$this->log ( __translate__('flushing cache', self::plugin_constant ) );

				/* proxy to internal function */
				$internal = $this->proxy ( 'flush' );
				$result = $this->$internal();

				if ( $result === false )
					$this->log ( __translate__('failed to flush cache', self::plugin_constant ), LOG_WARNING );

				return $result;
			}

			/* need permalink functions */
			if ( !function_exists('get_permalink') )
				include_once ( ABSPATH . 'wp-includes/link-template.php' );

			/* get path from permalink */
			$path = substr ( get_permalink( $post_id ) , 7 );

			/* no path, don't do anything */
			if ( empty( $path ) ) {
				$this->log ( __translate__('unable to determine path from Post Permalink, post ID: ', self::plugin_constant ) . $post_id , LOG_WARNING );
				return false;
			}

			if ( isset($_SERVER['HTTPS']) && ( ( strtolower($_SERVER['HTTPS']) == 'on' )  || ( $_SERVER['HTTPS'] == '1' ) ) )
				$protocol = 'https://';
			else
				$protocol = 'http://';

			/* elements to clear */
			$to_clear = array (
				$this->options['prefix-meta'] . $protocol . $path,
				$this->options['prefix-data'] . $protocol . $path,
			);

			/* delete entries */
			$internal = $this->proxy ( 'clear' );
			$this->$internal ( $to_clear );
		}

		/**
		 * get backend aliveness
		 *
		 * @return array Array of configured servers with aliveness value
		 *
		 */
		public function status () {

			/* look for backend aliveness, exit on inactive backend */
			if ( ! $this->is_alive() )
				return false;

			$internal = $this->proxy ( 'status' );
			$this->$internal();
			return $this->status;
		}

		/**
		 * backend proxy function name generator
		 *
		 * @return string Name of internal function based on cache_type
		 *
		 */
		private function proxy ( $method ) {
			return $this->options['cache_type'] . '_' . $method;
		}

		/**
		 * function to check backend aliveness
		 *
		 * @return boolean true if backend is alive, false if not
		 *
		 */
		private function is_alive() {
			if ( ! $this->alive ) {
				$this->log ( __translate__("backend is not active, exiting function ", self::plugin_constant ) . __FUNCTION__, LOG_WARNING );
				return false;
			}

			return true;
		}

		/**
		 * split hosts string to backend servers
		 *
		 *
		 */
		private function set_servers () {
			/* replace servers array in config according to hosts field */
			$servers = explode( self::host_separator , $this->options['hosts']);

			$options['servers'] = array();

			foreach ( $servers as $snum => $sstring ) {

				$separator = strpos( $sstring , self::port_separator );
				$host = substr( $sstring, 0, $separator );
				$port = substr( $sstring, $separator + 1 );

				/* IP server */
				if ( !empty ( $host )  && !empty($port) && is_numeric($port) ) {
					$this->options['servers'][$sstring] = array (
						'host' => $host,
						'port' => $port
					);
				}
			}

		}

		/**
		 * get current array of servers
		 *
		 * @return array Server list in current config
		 *
		 */
		public function get_servers () {
			return $this->options['servers'];
		}

		/**
		 * sends message to sysog
		 *
		 * @param mixed $message message to add besides basic info
		 *
		 */
		public function log ( $message, $log_level = LOG_WARNING ) {

			if ( @is_array( $message ) || @is_object ( $message ) )
				$message = serialize($message);

			if (! $this->options['log'] )
				return false;

			switch ( $log_level ) {
				case LOG_ERR :
					if ( function_exists( 'syslog' ) )
						syslog( $log_level , self::plugin_constant . " with " . $this->options['cache_type'] . ' ' . $message );
					/* error level is real problem, needs to be displayed on the admin panel */
					//throw new Exception ( $message );
				break;
				default:
					if ( function_exists( 'syslog' ) && $this->options['debug'] )
						syslog( $log_level , self::plugin_constant . " with " . $this->options['cache_type'] . ' ' . $message );
				break;
			}

		}

		/*********************** END PUBLIC FUNCTIONS ***********************/
		/*********************** APC FUNCTIONS ***********************/
		/**
		 * init apc backend: test APC availability and set alive status
		 */
		private function apc_init () {
			/* verify apc functions exist, apc extension is loaded */
			if ( ! function_exists( 'apc_sma_info' ) ) {
				$this->log ( __translate__('APC extension missing', self::plugin_constant ) );
				return false;
			}

			/* verify apc is working */
			if ( apc_sma_info() ) {
				$this->log ( __translate__('backend OK', self::plugin_constant ) );
				$this->alive = true;
			}
		}

		/**
		 * health checker for APC
		 *
		 * @return boolean Aliveness status
		 *
		 */
		private function apc_status () {
			$this->status = true;
			return $this->alive;
		}

		/**
		 * get function for APC backend
		 *
		 * @param string $key Key to get values for
		 *
		 * @return mixed Fetched data based on key
		 *
		*/
		private function apc_get ( &$key ) {
			return apc_fetch( $key );
		}

		/**
		 * Set function for APC backend
		 *
		 * @param string $key Key to set with
		 * @param mixed $data Data to set
		 *
		 * @return boolean APC store outcome
		 */
		private function apc_set (  &$key, &$data ) {
			return apc_store( $key , $data , $this->options['expire'] );
		}


		/**
		 * Flushes APC user entry storage
		 *
		 * @return boolean APC flush outcome status
		 *
		*/
		private function apc_flush ( ) {
			return apc_clear_cache('user');
		}

		/**
		 * Removes entry from APC or flushes APC user entry storage
		 *
		 * @param mixed $keys Keys to clear, string or array
		*/
		private function apc_clear ( $keys ) {
			/* make an array if only one string is present, easier processing */
			if ( !is_array ( $keys ) )
				$keys = array ( $keys );

			foreach ( $keys as $key ) {
				if ( ! apc_delete ( $key ) ) {
					$this->log ( __translate__('Failed to delete APC entry: ', self::plugin_constant ) . $key, LOG_ERR );
					//throw new Exception ( __translate__('Deleting APC entry failed with key ', self::plugin_constant ) . $key );
				}
				else {
					$this->log ( __translate__( 'APC entry delete: ', self::plugin_constant ) . $key );
				}
			}
		}

		/*********************** END APC FUNCTIONS ***********************/

		/*********************** MEMCACHED FUNCTIONS ***********************/
		/**
		 * init memcached backend
		 */
		private function memcached_init () {
			/* Memcached class does not exist, Memcached extension is not available */
			if (!class_exists('Memcached')) {
				$this->log ( __translate__(' Memcached extension missing', self::plugin_constant ), LOG_ERR );
				return false;
			}

			/* check for existing server list, otherwise we cannot add backends */
			if ( empty ( $this->options['servers'] ) && ! $this->alive ) {
				$this->log ( __translate__("Memcached servers list is empty, init failed", self::plugin_constant ), LOG_WARNING );
				return false;
			}

			/* check is there's no backend connection yet */
			if ( $this->connection === NULL ) {
				/* persistent backend needs an identifier */
				if ( $this->options['persistent'] == '1' )
					$this->connection = new Memcached( self::plugin_constant );
				else
					$this->connection = new Memcached();

				/* use binary and not compressed format, good for nginx and still fast */
				$this->connection->setOption( Memcached::OPT_COMPRESSION , false );
				$this->connection->setOption( Memcached::OPT_BINARY_PROTOCOL , true );
			}

			/* check if initialization was success or not */
			if ( $this->connection === NULL ) {
				$this->log ( __translate__( 'error initializing Memcached PHP extension, exiting', self::prefix ) );
				return false;
			}

			/* check if we already have list of servers, only add server(s) if it's not already connected */
			$servers_alive = array();
			if ( !empty ( $this->status ) ) {
				$servers_alive = $this->connection->getServerList();
				/* create check array if backend servers are already connected */
				if ( !empty ( $servers ) ) {
					foreach ( $servers_alive as $skey => $server ) {
						$skey =  $server['host'] . ":" . $server['port'];
						$servers_alive[ $skey ] = true;
					}
				}
			}

			/* adding servers */
			foreach ( $this->options['servers'] as $server_id => $server ) {
				/* reset server status to unknown */
				$this->status[$server_id] = -1;

				/* only add servers that does not exists already  in connection pool */
				if ( !@array_key_exists($server_id , $servers_alive ) ) {
					$this->connection->addServer( $server['host'], $server['port'] );
					$this->log ( $server_id . __translate__(" added, persistent mode: ", self::plugin_constant ) . $this->options['persistent'] );
				}
			}

			/* backend is now alive */
			$this->alive = true;
			$this->memcached_status();
		}

		/**
		 * sets current backend alive status for Memcached servers
		 *
		 */
		private function memcached_status () {
			/* server status will be calculated by getting server stats */
			$this->log ( __translate__("checking server statuses", self::plugin_constant ));
			/* get servers statistic from connection */
			$report =  $this->connection->getStats();

			foreach ( $report as $server_id => $details ) {
				/* reset server status to offline */
				$this->status[$server_id] = 0;
				/* if server uptime is not empty, it's most probably up & running */
				if ( !empty($details['uptime']) ) {
					$this->log ( $server_id . __translate__(" server is up & running", self::plugin_constant ));
					$this->status[$server_id] = 1;
				}
			}

		}

		/**
		 * get function for Memcached backend
		 *
		 * @param string $key Key to get values for
		 *
		*/
		private function memcached_get ( &$key ) {
			return $this->connection->get($key);
		}

		/**
		 * Set function for Memcached backend
		 *
		 * @param string $key Key to set with
		 * @param mixed $data Data to set
		 *
		 */
		private function memcached_set ( &$key, &$data ) {
			$result = $this->connection->set ( $key, $data , $this->options['expire']  );

			/* if storing failed, log the error code */
			if ( $result === false ) {
				$code = $this->connection->getResultCode();
				$this->log ( __translate__('unable to set entry ', self::plugin_constant ) . $key . __translate__( ', Memcached error code: ', self::plugin_constant ) . $code );
				//throw new Exception ( __translate__('Unable to store Memcached entry ', self::plugin_constant ) . $key . __translate__( ', error code: ', self::plugin_constant ) . $code );
			}

			return $result;
		}

		/**
		 *
		 * Flush memcached entries
		 */
		private function memcached_flush ( ) {
			return $this->connection->flush();
		}


		/**
		 * Removes entry from Memcached or flushes Memcached storage
		 *
		 * @param mixed $keys String / array of string of keys to delete entries with
		*/
		private function memcached_clear ( $keys ) {

			/* make an array if only one string is present, easier processing */
			if ( !is_array ( $keys ) )
				$keys = array ( $keys );

			foreach ( $keys as $key ) {
				$kresult = $this->connection->delete( $key );

				if ( $kresult === false ) {
					$code = $this->connection->getResultCode();
					$this->log ( __translate__('unable to delete entry ', self::plugin_constant ) . $key . __translate__( ', Memcached error code: ', self::plugin_constant ) . $code );
				}
				else {
					$this->log ( __translate__( 'entry deleted: ', self::plugin_constant ) . $key );
				}
			}
		}
		/*********************** END MEMCACHED FUNCTIONS ***********************/

		/*********************** MEMCACHE FUNCTIONS ***********************/
		/**
		 * init memcache backend
		 */
		private function memcache_init () {
			/* Memcached class does not exist, Memcache extension is not available */
			if (!class_exists('Memcache')) {
				$this->log ( __translate__('PHP Memcache extension missing', self::plugin_constant ), LOG_ERR );
				return false;
			}

			/* check for existing server list, otherwise we cannot add backends */
			if ( empty ( $this->options['servers'] ) && ! $this->alive ) {
				$this->log ( __translate__("servers list is empty, init failed", self::plugin_constant ), LOG_WARNING );
				return false;
			}

			/* check is there's no backend connection yet */
			if ( $this->connection === NULL )
				$this->connection = new Memcache();

			/* check if initialization was success or not */
			if ( $this->connection === NULL ) {
				$this->log ( __translate__( 'error initializing Memcache PHP extension, exiting', self::prefix ) );
				return false;
			}

			/* adding servers */
			foreach ( $this->options['servers'] as $server_id => $server ) {
				/* reset server status to unknown */
				if ( $this->options['persistent'] == '1' )
					$this->status[$server_id] = $this->connection->pconnect ( $server['host'] , $server['port'] );
				else
					$this->status[$server_id] = $this->connection->connect ( $server['host'] , $server['port'] );

				$this->log ( $server_id . __translate__(" added, persistent mode: ", self::plugin_constant ) . $this->options['persistent'] );
			}

			/* backend is now alive */
			$this->alive = true;
			$this->memcache_status();
		}

		/**
		 * check current backend alive status for Memcached
		 *
		 */
		private function memcache_status () {
			/* server status will be calculated by getting server stats */
			$this->log ( __translate__("checking server statuses", self::plugin_constant ));
			/* get servers statistic from connection */
			foreach ( $this->options['servers'] as $server_id => $server ) {
				$this->status[$server_id] = $this->connection->getServerStatus( $server['host'], $server['port'] );
				if ( $this->status[$server_id] == 0 )
					$this->log ( $server_id . __translate__(" server is down", self::plugin_constant ));
				else
					$this->log ( $server_id . __translate__(" server is up & running", self::plugin_constant ));
			}
		}

		/**
		 * get function for Memcached backend
		 *
		 * @param string $key Key to get values for
		 *
		*/
		private function memcache_get ( &$key ) {
			return $this->connection->get($key);
		}

		/**
		 * Set function for Memcached backend
		 *
		 * @param string $key Key to set with
		 * @param mixed $data Data to set
		 *
		 */
		private function memcache_set ( &$key, &$data ) {
			$result = $this->connection->set ( $key, $data , 0 , $this->options['expire'] );
			return $result;
		}

		/**
		 *
		 * Flush memcached entries
		 */
		private function memcache_flush ( ) {
			return $this->connection->flush();
		}


		/**
		 * Removes entry from Memcached or flushes Memcached storage
		 *
		 * @param mixed $keys String / array of string of keys to delete entries with
		*/
		private function memcache_clear ( $keys ) {
			/* make an array if only one string is present, easier processing */
			if ( !is_array ( $keys ) )
				$keys = array ( $keys );

			foreach ( $keys as $key ) {
				$kresult = $this->connection->delete( $key );

				if ( $kresult === false ) {
					$this->log ( __translate__('unable to delete entry ', self::plugin_constant ) . $key );
				}
				else {
					$this->log ( __translate__( 'entry deleted: ', self::plugin_constant ) . $key );
				}
			}
		}

		/*********************** END MEMCACHE FUNCTIONS ***********************/

	}

}

?>
