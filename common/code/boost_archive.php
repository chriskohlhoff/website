<?php
/*
  Copyright 2005-2008 Redshift Software, Inc.
  Distributed under the Boost Software License, Version 1.0.
  (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)
*/
require_once(dirname(__FILE__) . '/boost.php');
require_once(dirname(__FILE__) . '/url.php');

define('BOOST_DOCS_MODIFIED_DATE', 'Sun 30 Sep 2012 10:18:33 +0000');

function get_archive_location($params)
{
    $path_parts = array();
    preg_match($params['pattern'], $params['vpath'], $path_parts);

    if ($path_parts[1] == 'boost-build') {
        $params['version'] = null;
    } else {
        $params['version'] = $path_parts[1];
    }
    $params['key'] = $path_parts[2];

    // TODO: Would be better to use the version object to get this, but
    // we can't create it yet. I think it needs to also model 'boost-build',
    // or maybe create another class which represents a collection of
    // documentation.
    $version_dir = (preg_match('@^[0-9]@', $params['version'])) ?
            "boost_{$params['version']}" : $params['version'];

    $file = false;

    if ($params['fix_dir']) {
        $fix_path = "{$params['fix_dir']}{$params['vpath']}";

        if (is_file($fix_path) ||
            (is_dir($fix_path) && is_file("{$fix_path}/index.html")))
        {
            $params['zipfile'] = false;
            $file = "{$params['fix_dir']}{$params['vpath']}";
        }
    }

    if (!$file) {
        $file = ($params['zipfile'] ? '' : $params['archive_dir'] . '/');

        if ($params['archive_subdir'])
        {
            $file = $file . $params['archive_file_prefix'] . $version_dir . '/' . $params['key'];
        }
        else
        {
            $file = $file . $params['archive_file_prefix'] . $params['key'];
        }
    }

    $params['file'] = $file;

    $params['archive'] = $params['zipfile'] ?
            str_replace('\\','/', $params['archive_dir'] . '/' . $version_dir . '.zip') :
            Null;
    
    return $params;
}

function display_from_archive(
    $content_map = array(),
    $params = array())
{
    // Set default values

    $params = array_merge(
        array(
            'pattern' => '@^[/]([^/]+)[/](.*)$@',
            'vpath' => $_SERVER["PATH_INFO"],
            'archive_subdir' => true,
            'zipfile' => true,
            'fix_dir' => false,
            'archive_dir' => ARCHIVE_DIR,
            'archive_file_prefix' => ARCHIVE_FILE_PREFIX,
            'use_http_expire_date' => false,
            'override_extractor' => null,
            'template' => dirname(__FILE__)."/template.php",
            'title' => NULL,
            'charset' => NULL,
            'content' => NULL,
            'error' => false,
        ),
        $params
    );

    $params = get_archive_location($params);

    // Calculate expiry date if requested.

    $expires = null;
    if ($params['use_http_expire_date'])
    {
        if (!$params['version']) {
            $expires = "+1 week";
        }
        else {
            $compare_version = BoostVersion::from($params['version'])->
                compare(BoostVersion::current());
            $expires = $compare_version === -1 ? "+1 year" :
                ($compare_version === 0 ? "+1 week" : "+1 day");
        }
    }

    // Check file exists.

    if ($params['zipfile'])
    {
        $check_file = $params['archive'];

        if (!is_readable($check_file)) {
            file_not_found($params, 'Unable to find zipfile.');
            return;        
        }
    }
    else
    {
        $check_file = $params['file'];
        
        if (is_dir($check_file))
        {
            if(substr($check_file, -1) != '/') {
                $redirect = resolve_url(basename($check_file).'/');
                header("Location: $redirect", TRUE, 301);
                return;
            }

            $found_file = NULL;
            if (is_readable("$check_file/index.html")) $found_file = 'index.html';
            else if (is_readable("$check_file/index.htm")) $found_file = 'index.htm';

            if ($found_file) {
                $params['file'] = $check_file = $check_file.$found_file;
                $params['key'] = $params['key'].$found_file;
            }
            else {
                if (!http_headers('text/html', filemtime($check_file), $expires))
                    return;

                return display_dir($params, $check_file);
            }
        }
        else if (!is_readable($check_file)) {
            file_not_found($params, 'Unable to find file.');
            return;        
        }
    }

    // Choose filter to use

    $info_map = array_merge($content_map, array(
        array('@[.](txt|py|rst|jam|v2|bat|sh|xml|toyxml)$@i','text','text/plain'),
        array('@[.](qbk|quickbook)$@i','qbk','text/plain'),
        array('@[.](c|h|cpp|hpp)$@i','cpp','text/plain'),
        array('@[.]png$@i','raw','image/png'),
        array('@[.]gif$@i','raw','image/gif'),
        array('@[.](jpg|jpeg|jpe)$@i','raw','image/jpeg'),
        array('@[.]css$@i','raw','text/css'),
        array('@[.]js$@i','raw','application/x-javascript'),
        array('@[.]pdf$@i','raw','application/pdf'),
        array('@[.](html|htm)$@i','raw','text/html'),
        array('@(/|^)(Jamroot|Jamfile|ChangeLog|configure)$@i','text','text/plain'),
        array('@[.]dtd$@i','raw','application/xml-dtd'),
        ));

    $preprocess = null;
    $extractor = null;
    $type = null;

    foreach ($info_map as $i)
    {
        if (preg_match($i[0],$params['key']))
        {
            $extractor = $i[1];
            $type = $i[2];
            $preprocess = isset($i[3]) ? $i[3] : NULL;
            break;
        }
    }
    
    if ($params['override_extractor'])
        $extractor = $params['override_extractor'];

    if (!$extractor) {
        file_not_found($params);
        return;
    }

    // Handle ETags and Last-Modified HTTP headers.

    // Output raw files.

    if($extractor == 'raw') {
        if (!http_headers($type, filemtime($check_file), $expires))
            return;

        if($_SERVER['REQUEST_METHOD'] != 'HEAD')
            display_raw_file($params, $type);
    }
    else {
        // Read file from hard drive or zipfile
    
        // Note: this sets $params['content'] with either the content or an error
        // message:
        if(!extract_file($params, $params['content'])) {
            file_not_found($params, $params['content']);
            return;
        }
    
        // Check if the file contains a redirect.
    
        if($type == 'text/html') {
            if($redirect = detect_redirect($params['content'])) {
                http_headers('text/html', null, "+1 day");
                header("Location: $redirect", TRUE, 301);
                if($_SERVER['REQUEST_METHOD'] != 'HEAD') echo $params['content'];
                return;
            }
        }
    
        if (!http_headers('text/html', filemtime($check_file), $expires))
            return;

        // Finally process the file and display it.
    
        if($_SERVER['REQUEST_METHOD'] != 'HEAD') {
            if ($preprocess) {
                $params['content'] = call_user_func($preprocess, $params['content']);
            }
    
            echo_filtered($extractor, $params);
        }
   }
}

// HTTP header handling

function http_headers($type, $last_modified, $expires = null)
{
    header('Content-type: '.$type);
    switch($type) {
        case 'image/png':
        case 'image/gif':
        case 'image/jpeg':
        case 'text/css':
        case 'application/x-javascript':
        case 'application/pdf':
        case 'application/xml-dtd':
            header('Expires: '.date(DATE_RFC2822, strtotime("+1 year")));
            header('Cache-Control: max-age=31556926'); // A year, give or take a day.
            break;
        default:
            if($expires) {
                header('Expires: '.date(DATE_RFC2822, strtotime($expires)));
                header('Cache-Control: max-age='.strtotime($expires, 0));
            }
            break;
    }
    
    return conditional_get(max(
        strtotime(BOOST_DOCS_MODIFIED_DATE),        // last manual documenation update
        filemtime(dirname(__FILE__).'/boost.php'),  // last release (since the version number is updated)
        $last_modified                              // when the file was modified
    ));
}

function conditional_get($last_modified)
{
    if(!$last_modified) return true;

    $last_modified_text = date(DATE_RFC2822, $last_modified);
    $etag = '"'.md5($last_modified).'"';

    header("Last-Modified: $last_modified_text");
    header("ETag: $etag");

    $checked = false;

    if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $checked = true;
        $if_modified_since = strtotime(stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']));
        if(!$if_modified_since || $if_modified_since < $last_modified)
            return true;
    }

    if(isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $checked = true;
        if(stripslashes($_SERVER['HTTP_IF_NONE_MATCH'] != $etag))
            return true;
    }
    
    if(!$checked) return true;
    
    header($_SERVER["SERVER_PROTOCOL"].' 304 Not Modified');
    return false;
}

// General purpose render callbacks.

function boost_archive_render_callbacks($content, $params) {
    $charset = !empty($params['charset']) ? $params['charset'] : 'us-ascii';
    $title = !empty($params['title']) ? "$params[title]" : 'Boost C++ Libraries';

    if (!empty($params['version'])) {
        $title = "$params[title] - " . BoostVersion::from($params['version']);
    }

    $head = "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=${charset}\" />\n";

    if (!empty($params['noindex']))
        $head .= "<meta name=\"robots\" content=\"noindex\">\n";

    $head .= "<title>${title}</title>";

    return Array(
        'head' => $head,
        'content' => $content
    );
}

function display_dir($params, $dir)
{
    $handle = opendir($dir);
    
    $title = html_encode("Index listing for $params[key]");

    $params['title'] = $title;
    $params['noindex'] = true;
    
    $content = "<h3>$title</h3>\n<ul>\n";

    while (($file = readdir($handle)) !== false)
    {
        if (substr($file, 0, 1) == '.') continue;
        if (is_dir("$dir$file")) $file .= '/';
        $file = html_encode($file);
        $content .= "<li><a rel='nofollow' href='$file'>$file</a></li>\n";
    }

    $content .= "</ul>\n";
    
    $params['content'] = $content;

    display_template($params, boost_archive_render_callbacks($content, $params));
}

function display_raw_file($params, $type)
{
    ## header('Content-Disposition: attachment; filename="downloaded.pdf"');
    if($params['zipfile']) {
        $file_handle = popen(unzip_command($params), 'rb');
        fpassthru($file_handle);
        $exit_status = pclose($file_handle);
    
        // Don't display errors for a corrupt zip file, as we seemd to
        // be getting them for legitimate files.

        if($exit_status > 3)
            echo 'Error extracting file: '.unzip_error($exit_status);
    }
    else {
        readfile($params['file']);
    }
}

function extract_file($params, &$content) {
    if($params['zipfile']) {
        $file_handle = popen(unzip_command($params),'r');
        $text = '';
        while ($file_handle && !feof($file_handle)) {
            $text .= fread($file_handle,8*1024);
        }
        $exit_status = pclose($file_handle);

        if($exit_status == 0) {
            $content = $text;
            return true;
        }
        else {
            $content = strstr($_SERVER['HTTP_HOST'], 'beta') ? unzip_error($exit_status) : null;
            return false;
        }
    }
    else {
        $content = file_get_contents($params['file']);
        return true;
    }
}

function unzip_command($params) {
    return
      UNZIP
      .' -p '.escapeshellarg($params['archive'])
      .' '.escapeshellarg($params['file']);
}

//
// Filters
//

function echo_filtered($extractor, $params) {
    require_once(dirname(__FILE__)."/boost_filter_$extractor.php");
    $extractor_name = $extractor.'_filter';
    call_user_func($extractor_name, $params);
}

/* File Not Found */

function file_not_found($params, $message = null)
{
    header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
    if (!$params['error']) { $params['error'] = 404; }

    $head = <<<HTML
  <meta http-equiv="Content-Type" content="text/html; charset=us-ascii" />
  <title>Boost C++ Libraries - 404 Not Found</title>
HTML;

    $content = '<h1>404 Not Found</h1><p>File "' . $params['file'] . '" not found.</p><p>';
    if(!empty($params['zipfile'])) $content .= "Unzip error: ";
    $content .= html_encode($message);
    $content .= '</p>';

    display_template($params, Array('head' => $head, 'content' => $content));
}

/*
 * HTML processing functions
 */

function detect_redirect($content)
{
    // Only check small files, since larger files are never redirects, and are
    // expensive to search.
    if(strlen($content) <= 2000 &&
        preg_match(
            '@<meta\s+http-equiv\s*=\s*["\']?refresh["\']?\s+content\s*=\s*["\']0;\s*URL=([^"\']*)["\']\s*/?>@i',
            $content, $redirect))
    {
        return resolve_url($redirect[1]);
    }

    return false;
}

// Display the content in the standard boost template

function display_template($params, $_file) {
    include($params['template']);
}

function latest_link($params)
{
    if (!isset($params['version']) || $params['error']) {
        return;
    }

    $version = BoostVersion::from($params['version']);

    $current = BoostVersion::current();
    switch ($current->compare($version))
    {
    case 0:
        break;
    case 1:
        echo '<div class="boost-common-header-notice">';
        if (realpath("{$params['archive_dir']}/{$current->dir()}/$params[key]") !== false)
        {
            echo '<a class="boost-common-header-inner" href="/doc/libs/release/',$params['key'],'">',
                "Click here to view the latest version of this page.",
                '</a>';
        }
        else
        {
            echo '<a class="boost-common-header-inner" href="/doc/libs/">',
                "This is an old version of boost. ",
                "Click here for the latest version's documentation home page.",
                '</a>';
        }
        echo '</div>', "\n";
        break;
    case -1:
        echo '<div class="boost-common-header-notice">';
        echo '<span class="boost-common-header-inner">';
        echo 'This is the documentation for a development version of boost';
        echo '</span>';
        echo '</div>', "\n";
        break;
    }
}

// Return a readable error message for unzip exit state.

function unzip_error($exit_status) {
    switch($exit_status) {
    case 0: return 'No error.';
    case 1: return 'One  or  more  warning  errors  were  encountered.';
    case 2: return 'A generic error in the zipfile format was detected.';
    case 3: return 'A severe error in the zipfile format was detected.';
    case 4: return 'Unzip was unable to allocate memory for one or more buffers during program initialization.';
    case 5: return 'Unzip was unable to allocate memory or unable to obtain a tty to read the decryption password(s).';
    case 6: return 'Unzip was unable to allocate memory during decompression to disk.';
    case 7: return 'Unzip was unable to allocate memory during in-memory decompression.';
    case 9: return 'The specified zipfile was not found.';
    case 10: return 'Invalid options were specified on the command line.';
    case 11: return 'No matching files were found.';
    case 50: return 'The disk is (or was) full during extraction.';
    case 51: return 'The end of the ZIP archive was encountered prematurely.';
    case 80: return 'The user aborted unzip prematurely with control-C (or similar).';
    case 81: return 'Testing or extraction of one or more files failed due to unsupported compression methods or unsupported decryption.';
    case 82: return 'No files were found due to bad decryption password(s).';
    default: return 'Unknown unzip error code: ' + $exit_status;
    }
}
