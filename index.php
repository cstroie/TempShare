<?php
/**
 * TempShare - A minimalistic temporary file hosting service
 * 
 * TempShare is a single-file PHP application that provides temporary file hosting
 * with automatic cleanup based on file size. It allows users to upload files via
 * web interface, command line (curl), or dedicated uploaders like ShareX and Hupl.
 * 
 * Features:
 * - Web-based file upload interface
 * - Command-line upload support via curl
 * - Integration with ShareX (Windows) and Hupl (Android)
 * - Automatic file extension detection
 * - Configurable file retention policy (larger files expire sooner)
 * - Upload logging capabilities
 * - External hook support for custom processing
 * - Responsive dark-themed modern UI
 * 
 * Configuration:
 * All configuration is done through the CONFIG class constants:
 * - MAX_FILESIZE: Maximum allowed file size in MiB
 * - MAX_FILEAGE: Maximum retention period in days
 * - MIN_FILEAGE: Minimum retention period in days
 * - DECAY_EXP: Exponent for file size decay calculation
 * - UPLOAD_TIMEOUT: Maximum upload time in seconds
 * - ID_LENGTH: Length of generated file IDs
 * - STORE_PATH: Directory for storing uploaded files
 * - LOG_PATH: Path to upload log file (empty to disable)
 * - DOWNLOAD_PATH: URL path pattern for downloads
 * - MAX_EXT_LEN: Maximum file extension length
 * - EXTERNAL_HOOK: External program to call for each upload
 * - AUTO_FILE_EXT: Enable automatic file extension detection
 * - ADMIN_EMAIL: Contact email for inquiries
 * 
 * Usage:
 * 1. Web Upload: Visit the page and use the upload form
 * 2. Curl Upload: 
 *    curl -F "file=@/path/to/file.jpg" https://example.com/
 *    echo "hello" | curl -F "file=@-;filename=.txt" https://example.com/
 * 3. ShareX: Import the generated .sxcu configuration file
 * 4. Hupl: Import the generated .hupl configuration file
 * 5. CLI Purge: php index.php purge (to manually clean old files)
 * 
 * File Retention Policy:
 * Files are kept for a minimum of MIN_FILEAGE days and a maximum of MAX_FILEAGE days.
 * The actual retention time is calculated using the formula:
 * MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^DECAY_EXP
 * This means larger files expire sooner than smaller ones in a non-linear fashion.
 * 
 * Requirements:
 * - PHP 7.0 or higher
 * - PHP fileinfo extension (for automatic file type detection)
 * - Write permissions to STORE_PATH directory
 * - Appropriate PHP.ini settings (upload_max_filesize, post_max_size, etc.)
 * 
 * @author Rouji <costinstroie@eridu.eu.org>
 * @author Costin Stroie <costinstroie@eridu.eu.org>
 * @version 1.0
 * @license GNU General Public License v3.0
 * @link https://github.com/cstroie/TempShare
 */

/**
 * Configuration class for TempShare service
 * 
 * This class contains all the configuration constants for the TempShare file hosting service.
 * It defines file size limits, retention policies, storage paths, and other service settings.
 */
class CONFIG
{
    /** @var int Maximum file size in MiB */
    const MAX_FILESIZE = 256;
    
    /** @var int Maximum age of files in days */
    const MAX_FILEAGE = 30;
    
    /** @var int Minimum age of files in days */
    const MIN_FILEAGE = 7;
    
    /** @var int Decay exponent - higher values penalize larger files more */
    const DECAY_EXP = 6;

    /** @var int Maximum time an upload can take before it times out (in seconds) */
    const UPLOAD_TIMEOUT = 5*60;
    
    /** @var int Length of the random file ID */
    const ID_LENGTH = 3;
    
    /** @var string Directory to store uploaded files in */
    const STORE_PATH = '/var/cache/lighttpd/uploads/0x0/';
    
    /** @var string Path to log uploads + resulting links to (empty string to disable) */
    const LOG_PATH = '';
    
    /** @var string The path part of the download URL. %s = placeholder for filename */
    const DOWNLOAD_PATH = '%s';
    
    /** @var int Maximum length for file extensions */
    const MAX_EXT_LEN = 7;
    
    /** @var string|null External program to call for each upload (null to disable) */
    const EXTERNAL_HOOK = null;
    
    /** @var bool Automatically try to detect file extension for files that have none */
    const AUTO_FILE_EXT = false;

    /** @var string Address for TempShare inquiries */
    const ADMIN_EMAIL = 'costinstroie@eridu.eu.org';

    /**
     * Get the full site URL
     * 
     * @return string The complete site URL including protocol and path
     */
    public static function SITE_URL() : string
    {
        $proto = ($_SERVER['HTTPS'] ?? 'off') == 'on' ? 'https' : 'http';
        return "$proto://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    }
};


/**
 * Generate a random string of characters with given length
 * 
 * @param int $len Length of the string to generate
 * @return string Random string of specified length
 */
function rnd_str(int $len) : string
{
    //$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $max_idx = strlen($chars) - 1;
    $out = '';
    while ($len--)
    {
        $out .= $chars[mt_rand(0,$max_idx)];
    }
    return $out;
}

/**
 * Check PHP configuration settings and print warnings if anything's not configured properly
 * 
 * This function compares important PHP ini settings with the application's requirements
 * and prints warnings if the ini values are lower than what the application expects.
 * 
 * @return void
 */
function check_config() : void
{
    $warn_config_value = function($ini_name, $var_name, $var_val)
    {
        $ini_val = intval(ini_get($ini_name));
        if ($ini_val < $var_val)
            print("<pre>Warning: php.ini: $ini_name ($ini_val) set lower than $var_name ($var_val)\n</pre>");
    };

    $warn_config_value('upload_max_filesize', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('post_max_size', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('max_input_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
    $warn_config_value('max_execution_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
}

/**
 * Extract extension from a path (does not include the dot)
 * 
 * This function handles special cases like .tar.* archives where there are
 * two extensions (e.g., file.tar.gz).
 * 
 * @param string $path File path to extract extension from
 * @return string File extension without the dot, or empty string if no extension
 */
function ext_by_path(string $path) : string
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    //special handling of .tar.* archives
    $ext2 = pathinfo(substr($path,0,-(strlen($ext)+1)), PATHINFO_EXTENSION);
    if ($ext2 === 'tar')
    {
        $ext = $ext2.'.'.$ext;
    }
    return $ext;
}

/**
 * Extract file extension using the fileinfo extension
 * 
 * This function uses PHP's finfo extension to determine the file type
 * and derive an appropriate extension. If the file is detected as text,
 * it returns 'txt'.
 * 
 * @param string $path Path to the file
 * @return string Detected file extension or empty string if undetermined
 */
function ext_by_finfo(string $path) : string
{
    $finfo = finfo_open(FILEINFO_EXTENSION);
    $finfo_ext = finfo_file($finfo, $path);
    finfo_close($finfo);
    if ($finfo_ext != '???')
    {
        return explode('/', $finfo_ext, 2)[0];
    }
    else
    {
        $finfo = finfo_open();
        $finfo_info = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (strstr($finfo_info, 'text') !== false)
        {
            return 'txt';
        }
    }
    return '';
}

/**
 * Store an uploaded file with a randomized name but preserving its original extension
 * 
 * This function handles the complete file storage process:
 * 1. Validates file size
 * 2. Determines appropriate file extension
 * 3. Generates a unique filename
 * 4. Moves the file to its final location
 * 5. Executes external hooks if configured
 * 6. Logs the upload if logging is enabled
 * 7. Returns the download URL
 * 
 * @param string $name Original filename from the upload
 * @param string $tmpfile Temporary path of uploaded file (from $_FILES)
 * @param bool $formatted Set to true to display formatted message instead of bare link
 * @return void
 */
function store_file(string $name, string $tmpfile, bool $formatted = false) : void
{
    //create folder, if it doesn't exist
    if (!file_exists(CONFIG::STORE_PATH))
    {
        mkdir(CONFIG::STORE_PATH, 0750, true); //TODO: error handling
    }

    //check file size
    $size = filesize($tmpfile);
    if ($size > CONFIG::MAX_FILESIZE * 1024 * 1024)
    {
        header('HTTP/1.0 413 Payload Too Large');
        print("Error 413: Max File Size ({CONFIG::MAX_FILESIZE} MiB) Exceeded\n");
        return;
    }
    if ($size == 0)
    {
        header('HTTP/1.0 400 Bad Request');
        print('Error 400: Uploaded file is empty\n');
        return;
    }

    $ext = ext_by_path($name);
    if (empty($ext) && CONFIG::AUTO_FILE_EXT)
    {
        $ext = ext_by_finfo($tmpfile);
    }
    $ext = substr($ext, 0, CONFIG::MAX_EXT_LEN);
    $tries_per_len=3; //try random names a few times before upping the length
    for ($len = CONFIG::ID_LENGTH; ; ++$len)
    {
        for ($n=0; $n<=$tries_per_len; ++$n)
        {
            $id = rnd_str($len);
            $basename = $id . (empty($ext) ? '' : '.' . $ext);
            $target_file = CONFIG::STORE_PATH . $basename;

            if (!file_exists($target_file))
                break 2;
        }
    }

    $res = move_uploaded_file($tmpfile, $target_file);
    if (!$res)
    {
        //TODO: proper error handling?
        header('HTTP/1.0 520 Unknown Error');
        return;
    }
    
    if (CONFIG::EXTERNAL_HOOK !== null)
    {
        putenv('REMOTE_ADDR='.$_SERVER['REMOTE_ADDR']);
        putenv('ORIGINAL_NAME='.$name);
        putenv('STORED_FILE='.$target_file);
        $ret = -1;
        $out = exec(CONFIG::EXTERNAL_HOOK, $_ = null, $ret);
        if ($out !== false && $ret !== 0)
        {
            unlink($target_file);
            header('HTTP/1.0 400 Bad Request');
            print("Error: $out\n");
            return;
        }
    }

    //print the download link of the file
    $url = sprintf(CONFIG::SITE_URL().CONFIG::DOWNLOAD_PATH, $basename);

    if ($formatted)
    {
        // Calculate file retention time
        $file_size = filesize($tmpfile) / (1024*1024); // size in MiB
        $retention_days = CONFIG::MIN_FILEAGE +
                         (CONFIG::MAX_FILEAGE - CONFIG::MIN_FILEAGE) *
                         pow(1 - ($file_size / CONFIG::MAX_FILESIZE), CONFIG::DECAY_EXP);
        $retention_date = date('Y-m-d H:i:s', time() + ($retention_days * 24 * 60 * 60));
        
        $site_url = CONFIG::SITE_URL();
        echo <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
    <title>TempShare - Upload Successful</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root {
            --bg-primary: #121212;
            --bg-secondary: #1e1e1e;
            --bg-tertiary: #2d2d2d;
            --text-primary: #e0e0e0;
            --text-secondary: #b0b0b0;
            --accent: #bb86fc;
            --accent-hover: #d0a6ff;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        h1 {
            color: var(--accent);
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .card {
            background-color: var(--bg-secondary);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border);
            margin-bottom: 30px;
        }

        .card-title {
            color: var(--accent);
            margin-bottom: 20px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
        }

        .card-title::before {
            content: "●";
            color: var(--success);
            margin-right: 10px;
            font-size: 0.8rem;
        }

        .info-item {
            margin: 15px 0;
        }

        .info-label {
            font-weight: 600;
            color: var(--text-secondary);
            display: inline-block;
            width: 80px;
        }

        .info-value {
            display: inline-block;
        }

        .download-link {
            background-color: var(--bg-tertiary);
            padding: 15px;
            border-radius: 4px;
            word-break: break-all;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            margin: 15px 0;
        }

        .download-link a {
            color: var(--accent);
            text-decoration: none;
        }

        .download-link a:hover {
            text-decoration: underline;
        }

        .note {
            font-size: 0.9em;
            color: var(--text-secondary);
            font-style: italic;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--accent);
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <h1>TempShare</h1>
        <p>Minimalistic service for sharing temporary files</p>
    </header>

    <div class="card">
        <h2 class="card-title">Upload Successful!</h2>
        
        <div class="info-item">
            <span class="info-label">File:</span>
            <span class="info-value">{$name}</span>
        </div>
        
        <div class="info-item">
            <span class="info-label">Size:</span>
            <span class="info-value">{$file_size} MiB</span>
        </div>
        
        <div class="info-item">
            <span class="info-label">Expires:</span>
            <span class="info-value">{$retention_date} (in {$retention_days} days)</span>
        </div>
        
        <div class="info-item">
            <span class="info-label">Link:</span>
            <div class="download-link">
                <a href="{$url}" target="_blank">{$url}</a>
            </div>
        </div>
        
        <div class="note">
            Bookmark this link or copy it before closing this page
        </div>
    </div>
    
    <div class="back-link">
        <a href="{$site_url}">← Back to Upload Page</a>
    </div>
</body>
</html>
EOT;
    }
    else
    {
        print("$url\n");
    }

    // log uploader's IP, original filename, etc.
    if (CONFIG::LOG_PATH)
    {
        file_put_contents(
            CONFIG::LOG_PATH,
            implode("\t", array(
                date('c'),
                $_SERVER['REMOTE_ADDR'],
                filesize($tmpfile),
                escapeshellarg($name),
                $basename
            )) . "\n",
            FILE_APPEND
        );
    }
}

/**
 * Purge all files older than their retention period allows
 * 
 * This function implements the file retention policy based on file size.
 * Larger files are deleted earlier than small ones using a decay formula:
 * MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^DECAY_EXP
 * 
 * @return void
 */
function purge_files() : void
{
    $num_del = 0;    //number of deleted files
    $total_size = 0; //total size of deleted files

    //for each stored file
    foreach (scandir(CONFIG::STORE_PATH) as $file)
    {
        //skip virtual . and .. files
        if ($file === '.' ||
            $file === '..')
        {
            continue;
        }

        $file = CONFIG::STORE_PATH . $file;

        $file_size = filesize($file) / (1024*1024); //size in MiB
        $file_age = (time()-filemtime($file)) / (60*60*24); //age in days

        //keep all files below the min age
        if ($file_age < CONFIG::MIN_FILEAGE)
        {
            continue;
        }

        //calculate the maximum age in days for this file
        $file_max_age = CONFIG::MIN_FILEAGE +
                        (CONFIG::MAX_FILEAGE - CONFIG::MIN_FILEAGE) *
                        pow(1 - ($file_size / CONFIG::MAX_FILESIZE), CONFIG::DECAY_EXP);

        //delete if older
        if ($file_age > $file_max_age)
        {
            unlink($file);

            print("deleted $file, $file_size MiB, $file_age days old\n");
            $num_del += 1;
            $total_size += $file_size;
        }
    }
    print("Deleted $num_del files totalling $total_size MiB\n");
}

/**
 * Send a text file to the client with appropriate headers
 * 
 * @param string $filename Name of the file to send
 * @param string $content Content of the file
 * @return void
 */
function send_text_file(string $filename, string $content) : void
{
    header('Content-type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Content-Length: '.strlen($content));
    print($content);
}

/**
 * Send a ShareX custom uploader configuration as .sxcu file
 * 
 * This function generates and sends a ShareX configuration file that allows
 * users to easily configure ShareX to upload files to this TempShare instance.
 * 
 * @return void
 */
function send_sharex_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = CONFIG::SITE_URL();
    send_text_file($name.'.sxcu', <<<EOT
{
  "Name": "$name",
  "DestinationType": "ImageUploader, FileUploader",
  "RequestType": "POST",
  "RequestURL": "$site_url",
  "FileFormName": "file",
  "ResponseType": "Text"
}
EOT);
}

/**
 * Send a Hupl uploader configuration as .hupl file
 * 
 * This function generates and sends a Hupl configuration file that allows
 * Android users to easily configure Hupl to upload files to this TempShare instance.
 * 
 * @return void
 */
function send_hupl_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = CONFIG::SITE_URL();
    send_text_file($name.'.hupl', <<<EOT
{
  "name": "$name",
  "type": "http",
  "targetUrl": "$site_url",
  "fileParam": "file"
}
EOT);
}

/**
 * Print the main index page with usage instructions and upload form
 * 
 * This function generates the HTML for the main page that users see when
 * they visit the TempShare service. It includes:
 * - Upload form
 * - Usage instructions for various methods (curl, ShareX, Hupl)
 * - File retention policy information
 * - Contact information
 * 
 * @return void
 */
function print_index() : void
{
    $site_url = CONFIG::SITE_URL();
    $sharex_url = $site_url.'?sharex';
    $hupl_url = $site_url.'?hupl';
    $decay = CONFIG::DECAY_EXP;
    $min_age = CONFIG::MIN_FILEAGE;
    $max_size = CONFIG::MAX_FILESIZE;
    $max_age = CONFIG::MAX_FILEAGE;
    $mail = CONFIG::ADMIN_EMAIL;


echo <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
    <title>TempShare</title>
    <meta name="description" content="Minimalistic service for sharing temporary files." />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root {
            --bg-primary: #121212;
            --bg-secondary: #1e1e1e;
            --bg-tertiary: #2d2d2d;
            --text-primary: #e0e0e0;
            --text-secondary: #b0b0b0;
            --accent: #bb86fc;
            --accent-hover: #d0a6ff;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 40px;
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        h1 {
            color: var(--accent);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 1.2rem;
        }

        .container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        @media (min-width: 768px) {
            .container {
                grid-template-columns: 1fr 1fr;
            }
        }

        .card {
            background-color: var(--bg-secondary);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border);
        }

        .card-title {
            color: var(--accent);
            margin-bottom: 20px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
        }

        .card-title::before {
            content: "●";
            color: var(--accent);
            margin-right: 10px;
            font-size: 0.8rem;
        }

        .upload-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        input[type="file"] {
            padding: 12px;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-primary);
            cursor: pointer;
        }

        input[type="file"]::file-selector-button {
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 8px 12px;
            margin-right: 10px;
            cursor: pointer;
        }

        input[type="file"]::file-selector-button:hover {
            background-color: var(--accent);
            color: var(--bg-primary);
        }

        .btn {
            background-color: var(--accent);
            color: var(--bg-primary);
            border: none;
            border-radius: 4px;
            padding: 12px 20px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn:hover {
            background-color: var(--accent-hover);
        }

        .info-section {
            margin-top: 20px;
        }

        .info-section h3 {
            color: var(--text-secondary);
            margin: 15px 0 10px;
            font-size: 1.2rem;
        }

        p {
            margin-bottom: 15px;
        }

        code {
            background-color: var(--bg-tertiary);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        pre {
            background-color: var(--bg-tertiary);
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 15px 0;
        }

        a {
            color: var(--accent);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .formula {
            text-align: center;
            font-family: 'Courier New', monospace;
            background-color: var(--bg-tertiary);
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            font-size: 1.1rem;
        }

        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: var(--text-secondary);
            border-top: 1px solid var(--border);
        }

        .hint {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-style: italic;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <header>
        <h1>TempShare</h1>
        <p class="subtitle">Minimalistic service for sharing temporary files</p>
    </header>

    <div class="container">
        <div class="card">
            <h2 class="card-title">Upload File to TempShare</h2>
            <form class="upload-form" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="file">Choose a file to upload:</label>
                    <input type="file" name="file" id="file" required />
                </div>
                <input type="hidden" name="formatted" value="true" />
                <button type="submit" class="btn">Upload File</button>
            </form>
            <p class="hint">Hint: If you're lucky, your browser may support drag-and-drop onto the file selection input.</p>
            
            <div class="info-section">
                <h3>Upload via Command Line</h3>
                <p>You can upload files to this site via a simple HTTP POST, e.g. using curl:</p>
                <pre>curl -F "file=@/path/to/your/file.jpg" $site_url</pre>
                <p>Or if you want to pipe to curl <em>and</em> have a file extension, add a "filename":</p>
                <pre>echo "hello" | curl -F "file=@-;filename=.txt" $site_url</pre>
            </div>
        </div>

        <div class="card">
            <h2 class="card-title">Upload with Applications</h2>
            <div class="info-section">
                <h3>Windows</h3>
                <p>On Windows, you can use <a href="https://getsharex.com/">ShareX</a> and import <a href="$sharex_url">this</a> custom uploader.</p>
            </div>
            <div class="info-section">
                <h3>Android</h3>
                <p>On Android, you can use an app called <a href="https://github.com/Rouji/Hupl">Hupl</a> with <a href="$hupl_url">this</a> uploader.</p>
            </div>

            <h2 class="card-title">TempShare Retention Policy</h2>
            <div class="info-section">
                <p>The maximum allowed file size is <strong>$max_size MiB</strong>.</p>
                <p>Files are kept for a minimum of <strong>$min_age</strong>, and a maximum of <strong>$max_age Days</strong>.</p>
                <p>How long a file is kept depends on its size. Larger files are deleted earlier than small ones. This relation is non-linear and skewed in favour of small files.</p>
                <p>The exact formula for determining the maximum age for a file is:</p>
                <div class="formula">
                    MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))<sup>$decay</sup>
                </div>
            </div>

            <h2 class="card-title">Source & Contact</h2>
            <div class="info-section">
                <h3>Source Code</h3>
                <p>The PHP script used to provide this service is open source and available on <a href="https://github.com/Rouji/single_php_filehost">GitHub</a></p>
            </div>
            <div class="info-section">
                <h3>Contact</h3>
                <p>If you want to report abuse of this service, or have any other inquiries, please write an email to <a href="mailto:$mail">$mail</a></p>
            </div>
        </div>
    </div>

    <footer>
        <p>TempShare Service</p>
    </footer>
</body>
</html>
EOT;
}


// decide what to do, based on POST parameters etc.
if (isset($_FILES['file']['name']) &&
    isset($_FILES['file']['tmp_name']) &&
    is_uploaded_file($_FILES['file']['tmp_name']))
{
    //file was uploaded, store it
    $formatted = isset($_REQUEST['formatted']);
    store_file($_FILES['file']['name'],
              $_FILES['file']['tmp_name'],
              $formatted);
}
else if (isset($_GET['sharex']))
{
    send_sharex_config();
}
else if (isset($_GET['hupl']))
{
    send_hupl_config();
}
else if ($argv[1] ?? null === 'purge')
{
    purge_files();
}
else
{
    check_config();
    print_index();
}
