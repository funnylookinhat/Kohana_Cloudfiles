<?php defined('SYSPATH')  or die('No direct script access.');

/*
Rackspace Cloudfiles Helper Class
Written by David Overcash ( FunnyLookinHat ) - Feel free to take and use this for whatever you need.

To setup - get your correct credentials in the $CLOUD_USERNAME and $CLOUD_KEY fields.
Make sure you have php-cloudfiles checked out of git https://github.com/rackspace/php-cloudfiles
Place the source into /application/includes/ so that the full path to the included file is:
/application/includes/php-cloudfiles/cloudfiles.php

To use:
1) Helper_Cloudfiles::Connect();
2) Helper_Cloudfiles::UploadFile('container','/local/path/to/file','remote-filename');

*/

// Requires the PHP-CLOUDFILES Class
require_once Kohana::find_file('includes', 'php-cloudfiles/cloudfiles');

class Helper_Cloudfiles {
	// Define Variables to Connect.
	private static $CLOUD_USERNAME = "YOUR_RACKSPACE_CLOUD_USERNAME";
	private static $CLOUD_KEY = "YOUR_RACKSPACE_API_KEY";
	
	// Class Variables.
	private static $cloud_auth;
	private static $cloud_conn;
	
	/*
	Cloudfiles::Connect()
	Creates new authorized connection to CloudFiles using $CLOUD_USERNAME and $CLOUD_KEY
	This must be run before any other functions for managing files.
	*/
	public static function Connect() {
		
		if( ! self::$cloud_auth ) {
			
			self::$cloud_auth = new CF_Authentication(self::$CLOUD_USERNAME,self::$CLOUD_KEY);
        	
        	self::$cloud_auth->ssl_use_cabundle();
        	
        	self::$cloud_auth->authenticate();
			
		}
		
		if( ! self::$cloud_conn ) {
			
			self::$cloud_conn = new CF_Connection(self::$cloud_auth,true);
			
		}
        
	}
	
	/*
	Cloudfiles::CheckContainer($container_name)
	Checks if a container named $container_name exists.
	*/
	public static function CheckContainer($container_name) {
		
		try {
			
			$container = self::$cloud_conn->get_container($container_name);
			
			if( ! $container ) {
				
				return false;
				
			}
			
		} catch( NoSuchContainerException $e ) {
			
			return false;
			
		} catch( InvalidResponseException $e ) {
			
			return false;
			
		}
		
		return true;
		
	}
	
	/*
	Cloudfiles::GetContainerFiles($container_name,$limit,$offset)
	Returns the list of files in a container - paging is controlled by $limit and $start_from_filename
	If you provide $start_from_filename, the result set will start with the file 
	immediately following the one provided.
	For example - one could loop results with the following code:
	
		Helper_Cloudfiles::Connect();
		$i = 0;
		$l = 5;
		do {
			$results = Helper_Cloudfiles::GetContainerFiles('container_name',$l,( isset($results[(count($results)-1)]) ?($results[(count($results)-1)] : NULL ));
			$i++;
		} while( count($results) );
	
	*/
	public static function GetContainerFiles($container_name,$limit = 0,$start_from_filename = NULL) {

		try {
			
			$container = self::$cloud_conn->get_container($container_name);
			
			if( ! $container ) {
				
				return false;
				
			}
			
		} catch( NoSuchContainerException $e ) {
			
			return false;
			
		} catch( InvalidResponseException $e ) {
			
			return false;
			
		}
		
		$result = $container->list_objects($limit,$start_from_filename);

		return $result;

	}
	
	/*
	Cloudfiles::UploadFile($container_name, $local_filepath)
	Uploads the file at $local_filepath to $container_name using $cloud_conn
	Returns true on success, false on failure.
	*/
	public static function UploadFile($container_name, $local_filepath, $remote_filename = false) {
		
		$file_name = $remote_filename;
		
		if( ! $file_name ) {
			// Isolate the filename from $local_filepath
			$file_name = substr($local_filepath,(strrpos($local_filepath,'/')+1));
		}
		
		// Verify that we have a file that exists and has an actual file size.
		if( ! file_exists($local_filepath) || filesize($local_filepath) == 0 ) {
			return false;
		}
		
		// Create a connection to the container.
		$container = self::$cloud_conn->get_container($container_name);
		
		if( ! $container ) {
			return false;
		}
		
		// Check if file already exists.
		try {
			$container->get_object($file_name);
			
			// If we make it here the file already exists.
			return false;
		} catch (NoSuchObjectException $e) {
			// We're all set.
		} catch (Exception $e) {
			// Some other error occurred.
			return false;
		}
		
		$object = $container->create_object($file_name);
		
		$object->load_from_filename($local_filepath);
		
		return true;
		
	}
	
	/*
	Cloudfiles::DeleteFile($container_name, $file_name)
	Deletes the file named $filename from $container using $cloud_conn
	Returns true on success, false on failure.
	*/
	public static function DeleteFile($container_name, $file_name) {
		
		// Create a connection to the container.
		
		$container = self::$cloud_conn->get_container($container_name);
		
		if( ! $container ) {
			return false;
		}
		
		// Try to delete the object.
		try {
			$container->delete_object($file_name);
			// If we made it here - we were successful.
			return true;
		} catch (Exception $e) {
			return false;
		}
		
	}
	
	/*
	 * Cloudfiles::UpdateFile($container_name, $remote_filename, $local_filepath)
	 * Checks if the local file differs from the remote one - 
	 * If no file - uploads new file.
	 * 	RETURN 3
	 * If file exists and does not match - deletes remote object and uploads new file.
	 * 	RETURN 2
	 * Otherwise - nothing.
	 * 	RETURN 1
	 * If Error -
	 *  RETURN 0
	 */
	public static function UpdateFile($container_name, $remote_filename, $local_filepath) {
		
		// Create a connection to the container.
		$container = self::$cloud_conn->get_container($container_name);
		
		if( ! $container ) {
			return 0;
		}
		
		$local_md5 = md5_file($local_filepath);
		
		// Check if file exists.
		try {
			
			$remote_object = $container->get_object($remote_filename);
			
			// Verify MD5 Sums.
			if( $local_md5 != $remote_object->getETag() ) {
				
				// Replace file.
				$container->delete_object($remote_object);
				
				$remote_object = $container->create_object($remote_filename);
				
				try {
					
					$remote_object->load_from_filename($local_filepath);
					return 2;
					
				} catch( Exception $e ) {
					
					// Upload failed - delete bad file object.
					try {
						$container->delete_object($remote_object);
					} catch ( NoSuchObjectException $ee ) {
						// We're cool.
					}
					return 0;
					
				}
				
			} else {
				
				return 1;
				
			}
			
		} catch ( NoSuchObjectException $e ) {
			
			// Doesn't exist - upload the file.
			$remote_object = $container->create_object($remote_filename);
			
			try {
				
				$remote_object->load_from_filename($local_filepath);
				return 3;
				
			} catch( Exception $e ) {
				
				// Upload failed - delete bad file object.
				try {
					$container->delete_object($remote_object);
				} catch ( NoSuchObjectException $ee ) {
					// We're cool.
				}
				return 0;
				
			}
			
		} catch ( Exception $e ) {
			
			// Error !
			return 0;
			
		}
		
	}
	
	public static function FileExists($container_name, $remote_filename) {
		
		// Create a connection to the container.
		$container = self::$cloud_conn->get_container($container_name);
		
		if( ! $container ) {
			return 0;
		}
		
		try {
			$remote_object = $container->get_object($remote_filename);
		} catch ( NoSuchObjectException $e ) {
			return FALSE;
		} catch ( Exception $e ) {
			return FALSE;
		}
		
		return TRUE;
		
	}
	
}

?>
