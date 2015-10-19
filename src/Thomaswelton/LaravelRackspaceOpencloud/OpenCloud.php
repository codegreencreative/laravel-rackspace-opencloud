<?php namespace Thomaswelton\LaravelRackspaceOpencloud;

use \Config;
use \File;
use Alchemy\Zippy\Zippy;

// 5 minutes
define('RAXSDK_TIMEOUT', 300);

class OpenCloud extends \OpenCloud\Rackspace {

    public $region = null;

    function __construct(){

        $this->region = Config::get('laravel-rackspace-opencloud::region');
        $this->cdn = Config::get('laravel-rackspace-opencloud::cdn');
        $authUrl = $this->region == 'LON' ? 'https://lon.identity.api.rackspacecloud.com/v2.0/' : 'https://identity.api.rackspacecloud.com/v2.0/';

        parent::__construct($authUrl, array(
            'username' => Config::get('laravel-rackspace-opencloud::username'),
            'apiKey' => Config::get('laravel-rackspace-opencloud::apiKey')
        ));
        // Always check if new token is needed
        $this->authenticate();
    }

    public function objectStore(){
        return parent::objectStoreService(null, $this->region);
    }

    public function getContainer($name){
        try {
            // First check to see if the container exists
            $container = self::objectStore()->getContainer($name);
        } catch (Exception $e) {
            // If container does not exist create a new container
            $container->createContainer(array('name' => $name ));
            // Check if we are dealing with a CDN
            if (self::$cdn) {
                $ttl = 60 * 60 * 24 * 365;
                $container->PublishToCDN($ttl);
            }
        }
        return $container;
    }

    public function upload($container, $file, $name = null)
    {
        if (is_object($file) && get_class($file) == 'Symfony\Component\HttpFoundation\File\UploadedFile') {
            // Determine file name
            $fileName = is_null($name) ? basename($file) . '.' . $file->guessExtension() : $name;
            // Open file for reading
            $fileData = fopen($file->getRealPath(), 'r');
        } else if (File::isFile($file)) {
            // Determine file name
            $fileName = is_null($name) ? basename($file) : $name;
            // Open file for reading
            $fileData = fopen($file, 'r');
        } else {
            throw new Exception("OpenCloud::upload file not found", 1);
        }
        // Upload object
        try {
            $container = self::getContainer($container);
            $container->uploadObject($fileName, $fileData);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // Create and archive and upload a whole directory
    // $dir - Directory to upload
    // $cdnDir - Directory on the CDN to upload to
    // $dirTrim - Path segments to trim from the dir path when on the CDN
    public function uploadDir($container, $dir, $cdnDir = '', $dirTrim = ''){
        $temp_file =  storage_path() . '/CDN-' . time() . '.tar.gz';

        $zip_dir_name = (0 === strpos($dir, $dirTrim)) ? substr($dir, strlen($dirTrim) + 1) : $dir;

        $zippy = Zippy::load();
        // creates an archive.zip that contains a directory "folder" that contains
        // files contained in "/path/to/directory" recursively
        $archive = $zippy->create($temp_file, array(
            $cdnDir . '/' . $zip_dir_name => $dir
        ), true);

        $cdnFile = $this->createDataObject($container, $temp_file, '/', 'tar.gz');

        File::delete($temp_file);

        return $cdnFile;
    }

    public function exists($container, $file){
        $container = $this->getContainer($container);
        try{
            return $container->DataObject($file);
        }catch(\OpenCloud\Common\Exceptions\ObjFetchError $e){
            return false;
        }
    }

    // public function createDataObject($container, $filePath, $fileName = null, $extract = null)
    // {
    //     $fileName = is_null($fileName) ? basename($filePath) : $fileName;
    //     // Get the container
    //     $container = self::getContainer($container);
    //     // If CDN set access control
    //     if (self::$cdn) {
    //         $headers = array('Access-Control-Allow-Origin' => '*');
    //     }

    //     // Create data object
    //     $object = $container->DataObject();
    //     $object->Create(array('name'=> $fileName, 'extra_headers' => $headers), $filePath, $extract);

    //     return $object;
    // }

    public function destroy($container, $file)
    {
        $container = self::getContainer($container);
        // if file is fed with full url, shorten to last component
        $file = explode('/',$file);
        $file = end($file);
        try {
            return $container->DataObject($file)->delete();
        } catch(\OpenCloud\Common\Exceptions\ObjFetchError $e) {
            return $e;
        }
    }
}

