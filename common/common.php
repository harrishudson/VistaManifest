<?php
/* Copyright (c) Harris Hudson 2025 */

function get_root() {
 //NOTE: This needs to be setup on install
 return 'https://vistamanifest.com';
}

function escHTML($string) {
 return htmlspecialchars($string);
}

function page_top($title, $scale = null) {
 $root = get_root();
 $esc_root = escHTML($root);
 $esc_title = escHTML($title);
 if (is_null($scale)) {
  $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=4.0, user-scalable=yes">';
 } else {
  if (($scale == 'no') || ($scale == '0')) {
   $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';
  } else {
   $viewport = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=4.0, user-scalable=yes">';
  }
 };
 $nonce = base64_encode(random_bytes(32));

 echo <<<EOF
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="UTF-8">
  <title>{$esc_title}</title>
  <meta http-equiv="Content-Security-Policy" content="script-src 'self' 'nonce-{$nonce}'">
  {$viewport}
  <meta name="description" content="Visualizations of Climate Forecasting Open Data">
  <meta name="author" content="Copyright (c) Harris Hudson 2025.  harris@harrishudson.com.  https://harrishudson.com">
  <link rel="stylesheet" href="{$root}/common/common.css">
  <script nonce="{$nonce}" src="{$root}/common/common.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" 
   integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" 
   crossorigin="anonymous">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
   integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
   crossorigin="">
  <script nonce="{$nonce}" src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
   integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
   crossorigin=""></script>
  <link id="ico" rel="icon" href="{$root}/common/vistamanifest.svg">
  <script nonce="{$nonce}">
   function get_root() { return "{$esc_root}"; }
  </script>
 </head>
 <body>

  <a href="{$root}" title="Home"> 
  <div class="header">
   <i style="position:absolute; left:15px; top:4px; font-size:32px;" class="fa fa-ellipsis-v" id="show_menu"></i>
   <img alt="Vista Manifest Logo" style="position:absolute; left:30px; top:4px;" src="{$root}/common/vistamanifest.svg">
   <div style="position:absolute; left:65px; top:10px;">Vista Manifest
    <span class="largescreen_only"><em>&nbsp; Visualizations of Climate Forecasting Open Data</em></span>
   </div>
  </div>
  </a> 

  <!-- Begin Main Content --> 
  <div class="page_content">
EOF;

 return $nonce;
};

function page_bottom($show_home = false) {
 $home_link = '';
 if ($show_home) {
  $home = escHTML(get_root());
  $home_link = '<p class="indent"><a href="'.$home.'">Home</a></p>';
 }
 echo <<<EOF
  {$home_link}
  </div>
  <!-- End Main Content -->

  <!-- Status, Throbber and Progress -->
  <ul id="status_queue" class="status"></ul>
  <div class="page_throbber">
   <div id="throbber" class="throbber" style="visibility:hidden;"><i class="fa fa-circle-o-notch spin"></i></div>
   <progress id="page_progress" value="0" max="24" style="visibility:hidden;"></progress> 
   <div id="page_progress_text" class="progress_text"></div>
  </div>

 </body>
</html>
EOF;
}

?>
