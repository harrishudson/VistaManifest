<?php

 $GLOBALS['CATALOG_PREFIX'] = "http://opendap.bom.gov.au/thredds";
 $GLOBALS['ALLOWED_DOMAINS'] = ["opendap.bom.gov.au"];
 $GLOBALS['ALLOWED_SCHEMES'] = ["http"];
 $GLOBALS['CACHE_TIMEOUT'] = 30;
 $GLOBALS['CACHE_LOCATION'] = "./cache";
 $GLOBALS['CACHE_FILENAME_SUFFIX'] = "BOM";  // Suffix must not have any spaces
 $GLOBALS['USER_AGENT'] = "Vista Manifest Proxy. No CORS headers detected in your responses. More info; vistamanifest.com/about.php";

 function error() {
  header('HTTP/1.1 404 Not Found');
  exit;
 };

 // Simple check to not cache dynamic BBOX type requests
 function is_cachable($url) {
  $url_lower = strtolower($url);
  if (strpos($url_lower, 'west=') !== false) {
      return false;
  }
  return true;
 } 

 // Make cache file name (note - there must be no spaces in suffix)
 function get_cache_filename($url) {
  $prefix = hash('md5', $url);
  $filename = $GLOBALS['CACHE_LOCATION'].'/'.$prefix.'.'.$GLOBALS['CACHE_FILENAME_SUFFIX'];
  return $filename;
 }

 function cache_cleanup() {
  $path = $GLOBALS['CACHE_LOCATION'];
  if ($handle = opendir($path)) {
   while (false !== ($file = readdir($handle))) { 
    if (pathinfo($file, PATHINFO_EXTENSION) == $GLOBALS['CACHE_FILENAME_SUFFIX']) {
     $filelastmodified = filemtime($path . '/' . $file);
     if((time() - $filelastmodified) > $GLOBALS['CACHE_TIMEOUT']) {
      unlink($path . '/' . $file);
      }
     }
    }
   closedir($handle); 
  }  
 }

 function catalog_request() {
  $url = $_GET['url'];
  $full_url = $GLOBALS['CATALOG_PREFIX'].$url;
  if (!(filter_var($full_url, FILTER_VALIDATE_URL))) {
    error();
    exit;
  }
  $parsed_url = parse_url($full_url);
  if (!in_array($parsed_url['host'], $GLOBALS['ALLOWED_DOMAINS'])) {
   error();
  }
  if (!in_array(parse_url($full_url, PHP_URL_SCHEME), $GLOBALS['ALLOWED_SCHEMES'])) {
   error();
  }

  $cache_file = get_cache_filename($full_url);
  $file_is_cachable = is_cachable($full_url);
  
  if (($file_is_cachable) && 
      (file_exists($cache_file)) && 
      (filemtime($cache_file) > (time() - $GLOBALS['CACHE_TIMEOUT']))) {
   // File is cached and still valid 
   // Don't bother refreshing, just use the file as-is.
   $file = file_get_contents($cache_file);
  } else {
   // Our cache is out-of-date, so load the data from our remote server,
   // and also save it over our cache for next time.
   $file = file_get_contents($full_url);
   if ($file_is_cachable) {
    file_put_contents($cache_file, $file, LOCK_EX);
   }
 }
  
  header('Content-Type: application/octet-stream');
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Cache-Control: post-check=0, pre-check=0", false); // For HTTP/1.0 compatibility
  header("Pragma: no-cache"); // Legacy HTTP/1.0 caching control
  header("Expires: 0"); // Immediately expire the content
  echo($file);
  exit();
 }

 /* Main */ 

 /* Perform some cache maintenance every so often */
 if (rand(0,100) < 5) {
  cache_cleanup();
 }

 catalog_request();

 exit();
?>
