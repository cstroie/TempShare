# TempShare - Minimalistic Temporary File Hosting

TempShare is a single-file PHP application that provides temporary file hosting with automatic cleanup based on file size. It supports uploads via web interface, command line (curl), and dedicated uploaders like ShareX (Windows) and Hupl (Android).

## Features

- Web-based file upload interface
- Command-line upload support via curl
- Integration with ShareX and Hupl
- Automatic file extension detection
- Configurable file retention policy (larger files expire sooner)
- Upload logging capabilities
- External hook support
- Responsive dark-themed modern UI

## Requirements

- PHP 7.0+
- PHP fileinfo extension
- Write permissions to STORE_PATH directory
- Appropriate PHP.ini settings

## Installation

1. Place `index.php` in your web root
2. Ensure the STORE_PATH directory exists and is writable
3. Configure your web server to execute PHP

## Server Configuration

### Apache

```apache
<Directory /path/to/webroot/>
    Options +FollowSymLinks -MultiViews -Indexes
    AddDefaultCharset UTF-8
    AllowOverride None

    RewriteEngine On
    RewriteCond "%{ENV:REDIRECT_STATUS}" "^$"
    RewriteRule "^/?$" "index.php" [L,END]
    RewriteRule "^(.+)$" "files/$1" [L,END]
</Directory>

<Directory /path/to/webroot/files>
    Options -ExecCGI
    php_flag engine off
    SetHandler None
    AddType text/plain .php .php5 .html .htm .cpp .c .h .sh
</Directory>
```

### Nginx

```nginx
root /path/to/webroot;
index index.php;

location ~ /(.+)$ {
    root /path/to/webroot/files;
}

location = / {
    include fastcgi_params;
    fastcgi_param HTTP_PROXY "";
    fastcgi_intercept_errors On;
    fastcgi_param SCRIPT_NAME index.php;
    fastcgi_param SCRIPT_FILENAME /path/to/webroot/index.php;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass 127.0.0.1:9000;
}
```

## Configuration

All settings are in the `CONFIG` class:
- `MAX_FILESIZE`: Max file size in MiB
- `MAX_FILEAGE`/`MIN_FILEAGE`: Retention period in days
- `DECAY_EXP`: Size decay exponent
- `STORE_PATH`: Upload storage directory
- `LOG_PATH`: Upload log path
- `EXTERNAL_HOOK`: Custom processing hook
- `AUTO_FILE_EXT`: Auto-detect extensions
- `ADMIN_EMAIL`: Support contact

Adjust these PHP settings in `php.ini`:
- upload_max_filesize
- post_max_size
- max_input_time
- max_execution_time

## Usage

### Web Upload
Visit the page and use the upload form.

### Command Line
```bash
curl -F "file=@/path/to/file.jpg" https://example.com/
echo "hello" | curl -F "file=@-;filename=.txt" https://example.com/
```

### ShareX
Visit `https://example.com/?sharex` to download the config file.

### Hupl
Visit `https://example.com/?hupl` to download the config file.

## Purging Old Files

Manual purge:
```bash
php index.php purge
```

Automated via cron:
```bash
0 0 * * * php index.php purge > /dev/null
```

## File Retention Policy

Files are kept for a minimum of `MIN_FILEAGE` days and a maximum of `MAX_FILEAGE` days. Retention time is calculated by:

```
MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^DECAY_EXP
```

This means larger files expire sooner in a non-linear fashion.

## Related Tools

- [ssh2p](https://github.com/Rouji/ssh2p) and [nc2p](https://github.com/Rouji/nc2p): Upload via SSH/netcat
- [Docker container](https://github.com/Rouji/single_php_filehost_docker)

## FAQ

**Q: Can you add this feature?**  
A: The project follows KISS principles. I'm open to suggestions but avoid features that can be implemented externally (auth, scanning, etc).

**Q: Why does the UI look modern?**  
A: The interface was designed to be accessible and visually pleasing while maintaining simplicity.

**Q: Is it safe without authentication?**  
A: The service has operated safely for years. For authentication needs, use server-level solutions (basic auth, etc).

## License

This project is licensed under the GNU General Public License v3.0.
