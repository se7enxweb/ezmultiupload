<?php
/**
 * The ZIP import backend: inspect() lists what an archive holds, extract() writes the chosen files to a private
 * directory, cleanup() removes that directory. ZIP files inside the archive are opened too, down to MaxDepth.
 *
 * Safety: every entry is checked before anything is written. An unsafe path (absolute, a drive letter, "..", a NUL),
 * a symbolic link, a device, an encrypted entry, a type that is not allowed (by content, and a list of extensions
 * that are never taken), a file larger than the per-file limit, an entry compressed more than MaxRatio times, an
 * entry over MaxEntries and an entry that would pass MaxTotalBytes are skipped with a reason. The system's own files
 * (__MACOSX/, .DS_Store, Thumbs.db, desktop.ini) and hidden files are skipped too. Names are UTF-8 (a CP437 or
 * ISO-8859-1 name is converted). Files are copied from the entry's stream in chunks and stop at the size the
 * archive declares, so a lying header cannot write more than the limits allow. Every target is checked with
 * realpath() against the directory, files are opened with "x" (an existing file or link is never followed), and
 * directories are 0700, files 0600.
 *
 * No state is kept between calls (Velocity's persistent workers): each call has its own counters and its own
 * temporary directory, under var/<site>/expmultiupload-zip-import-<system user id>/, so a directory made by one
 * system user (Velocity runs as root, PHP-FPM as the site user) is never one the other has to remove.
 *
 * Settings: ezmultiupload.ini [ZipSettings]; every one can be given as an option instead (options()).
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

class expMultiuploadZipImporter
{
    const STATUS_OK = 'ok';
    const STATUS_SKIPPED = 'skipped';

    const REASON_TYPE = 'type_not_allowed';
    const REASON_SIZE = 'too_big';
    const REASON_UNSAFE = 'unsafe_path';
    const REASON_SYMLINK = 'symlink';
    const REASON_DEVICE = 'device';
    const REASON_DEPTH = 'too_deep';
    const REASON_ENCRYPTED = 'encrypted';
    const REASON_RATIO = 'ratio_exceeded';
    const REASON_ENTRIES = 'too_many_entries';
    const REASON_TOTAL = 'total_size_exceeded';
    const REASON_SYSTEM = 'system_file';
    const REASON_UNREADABLE = 'unreadable';

    /** Bytes read from an entry to find its type */
    const HEAD_BYTES = 65536;

    /** Bytes per read when an entry is copied */
    const CHUNK_BYTES = 65536;

    /** Never taken, whatever the content says */
    const DENIED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
                                     'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'dll', 'so',
                                     'msi', 'scr', 'jar', 'js', 'mjs', 'vbs', 'ps1', 'htaccess', 'htm', 'html',
                                     'xhtml', 'shtml', 'asp', 'aspx', 'jsp' );

    /** The types an archive's files may have besides the image types of image.ini */
    const DEFAULT_ALLOWED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
                                              'image/tiff', 'image/bmp', 'image/svg+xml', 'application/pdf',
                                              'text/plain', 'text/csv', 'application/rtf', 'text/rtf',
                                              'application/msword', 'application/vnd.ms-excel',
                                              'application/vnd.ms-powerpoint',
                                              'application/vnd.openxmlformats-officedocument.*',
                                              'application/vnd.oasis.opendocument.*',
                                              'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
                                              'video/mp4', 'video/quicktime', 'video/webm', 'video/ogg',
                                              'video/x-flv', 'video/mpeg' );

    /** Formats that are ZIP files inside: application/zip by content becomes this type by the extension */
    const ZIP_BASED_TYPES = array( 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                   'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                   'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                   'odt' => 'application/vnd.oasis.opendocument.text',
                                   'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                                   'odp' => 'application/vnd.oasis.opendocument.presentation' );

    /** Types libmagic reports for content it cannot tell apart; refined by the extension, never to an image */
    const OCTET_TYPES = array( 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
                               'webm' => 'video/webm', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                               'flv' => 'video/x-flv', 'doc' => 'application/msword',
                               'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint' );

    /** @var array the options of this instance (options()) */
    protected $options;

    /**
     * @param array $options see options(); merged over ezmultiupload.ini [ZipSettings]
     */
    public function __construct( array $options = array() )
    {
        $this->options = $options;
    }

    // ---- public API ---------------------------------------------------------------------------------------------

    /**
     * Lists every entry of the archive, also those of ZIP files in it.
     *
     * @param string $zipPath
     * @param array $options this call's options, over the instance's
     * @return array{ok: bool, error: string, message: string, refused: string[], limits: array, totalBytes: int,
     *               entries: array<int, array{name: string, path: string, size: int, compressedSize: int,
     *               mime: string, class: string, depth: int, nested: bool, status: string, reason: string}>}
     */
    public function inspect( string $zipPath, array $options = array() ): array
    {
        $limits = $this->options( $options );
        $result = $this->precheck( $zipPath, $limits );
        if ( $result !== null )
            return $result;
        $scratch = $this->makeTempDir( $limits['varDir'] );
        if ( $scratch === false )
            return $this->failure( 'temp_dir', 'A temporary directory could not be made.', $limits );
        $ctx = $this->context( $limits, 'inspect', array(), $scratch, $scratch );
        try
        {
            if ( !$this->walk( $zipPath, '', 1, $ctx ) )
                return $this->failure( 'invalid_zip', 'The file is not a ZIP file that can be read.', $limits );
        }
        finally
        {
            $this->cleanup( $scratch );
        }
        return array( 'ok' => true, 'error' => '', 'message' => '', 'refused' => array_keys( $ctx['refused'] ),
                      'limits' => $this->publicLimits( $limits ), 'totalBytes' => $ctx['total'], 'entries' => $ctx['rows'] );
    }

    /**
     * Writes the chosen entries (their "path" from inspect()) that pass every check to $tmpDir; a nested ZIP file's
     * path chooses everything in it. Each entry is checked again here, never taken from the caller.
     *
     * @param string $zipPath
     * @param string[] $selectedPaths
     * @param string $tmpDir an empty directory of the caller, or '' for a new one (var/<site>/..., 0700)
     * @param array $options this call's options, over the instance's
     * @return array{ok: bool, error: string, message: string, tmpDir: string,
     *               files: array<int, array{path: string, relativePath: string, size: int, mime: string, class: string}>,
     *               skipped: array<string, string>}
     */
    public function extract( string $zipPath, array $selectedPaths, string $tmpDir = '', array $options = array() ): array
    {
        $limits = $this->options( $options );
        $result = $this->precheck( $zipPath, $limits );
        if ( $result !== null )
            return $result + array( 'tmpDir' => '', 'files' => array(), 'skipped' => array() );
        if ( $tmpDir === '' )
            $tmpDir = (string)$this->makeTempDir( $limits['varDir'] );
        else if ( !is_dir( $tmpDir ) && !@mkdir( $tmpDir, 0700, true ) && !is_dir( $tmpDir ) )
            $tmpDir = '';
        $real = $tmpDir !== '' ? realpath( $tmpDir ) : false;
        if ( $real === false || is_link( $tmpDir ) )
            return $this->failure( 'temp_dir', 'The temporary directory could not be made.', $limits )
                   + array( 'tmpDir' => '', 'files' => array(), 'skipped' => array() );

        $selected = array();
        foreach ( $selectedPaths as $path )
        {
            $path = trim( str_replace( '\\', '/', (string)$path ), '/' );
            if ( $path !== '' )
                $selected[$path] = true;
        }
        $scratch = $this->makeTempDir( $limits['varDir'] );
        if ( $scratch === false )
            return $this->failure( 'temp_dir', 'A temporary directory could not be made.', $limits )
                   + array( 'tmpDir' => $real, 'files' => array(), 'skipped' => array() );
        $ctx = $this->context( $limits, 'extract', $selected, $real, $scratch );
        try
        {
            $readable = $this->walk( $zipPath, '', 1, $ctx );
        }
        finally
        {
            $this->cleanup( $scratch );
        }
        if ( !$readable )
            return $this->failure( 'invalid_zip', 'The file is not a ZIP file that can be read.', $limits )
                   + array( 'tmpDir' => $real, 'files' => array(), 'skipped' => array() );

        $skipped = array();
        foreach ( $ctx['rows'] as $row )
        {
            if ( $row['status'] !== self::STATUS_OK && $this->isSelected( $row['path'], $selected ) )
                $skipped[$row['path']] = $row['reason'];
        }
        foreach ( $ctx['failed'] as $path => $reason )
            $skipped[$path] = $reason;
        foreach ( array_keys( $selected ) as $path )
        {
            if ( !isset( $ctx['seen'][$path] ) && !isset( $skipped[$path] ) )
                $skipped[$path] = 'not_found';
        }
        return array( 'ok' => true, 'error' => '', 'message' => '', 'tmpDir' => $real, 'files' => $ctx['files'],
                      'skipped' => $skipped );
    }

    /**
     * Removes the directory with everything in it; links are removed, never followed. Safe to call twice.
     *
     * @param string $tmpDir
     */
    public function cleanup( string $tmpDir ): void
    {
        if ( $tmpDir === '' || is_link( $tmpDir ) || !is_dir( $tmpDir ) )
            return;
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmpDir, FilesystemIterator::SKIP_DOTS ),
                                                RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item )
        {
            if ( $item->isDir() && !$item->isLink() )
                @rmdir( $item->getPathname() );
            else
                @unlink( $item->getPathname() );
        }
        @rmdir( $tmpDir );
    }

    /**
     * The options in use: $options over the instance's options over ezmultiupload.ini [ZipSettings] over the
     * built-in defaults.
     *
     * Keys: enabled, maxDepth, maxEntries, maxTotalBytes, maxRatio, ratioMinSize, maxFileSize, maxPathDepth,
     * allowedMimeTypes (types, "group/*" for a whole group), deniedExtensions, skipNames, skipHidden, classMap (an
     * array MIME type or group => class identifier, or a callable taking the type), varDir.
     *
     * @param array $options
     * @return array
     */
    public function options( array $options = array() ): array
    {
        $limits = $options + $this->options + self::iniOptions() + self::defaults();
        $limits['enabled'] = (bool)$limits['enabled'];
        $limits['skipHidden'] = (bool)$limits['skipHidden'];
        foreach ( array( 'maxDepth', 'maxEntries', 'maxRatio', 'maxPathDepth' ) as $key )
            $limits[$key] = max( 1, (int)$limits[$key] );
        foreach ( array( 'maxTotalBytes', 'ratioMinSize', 'maxFileSize' ) as $key )
            $limits[$key] = max( 0, is_int( $limits[$key] ) ? $limits[$key] : self::parseSize( $limits[$key] ) );
        // no total limit is not an option: 0 is the built-in 500 MB
        if ( $limits['maxTotalBytes'] === 0 )
            $limits['maxTotalBytes'] = 500 * 1024 * 1024;
        $limits['allowedMimeTypes'] = array_values( array_unique( array_map( 'strtolower', (array)$limits['allowedMimeTypes'] ) ) );
        $limits['deniedExtensions'] = array_map( 'strtolower', (array)$limits['deniedExtensions'] );
        $limits['skipNames'] = array_map( 'strtolower', (array)$limits['skipNames'] );
        return $limits;
    }

    /**
     * The built-in defaults.
     *
     * @return array
     */
    public static function defaults(): array
    {
        return array( 'enabled' => true, 'maxDepth' => 3, 'maxEntries' => 1000,
                      'maxTotalBytes' => self::smallestLimit( array( 500 * 1024 * 1024, self::phpUploadLimit() ) ),
                      'maxRatio' => 100, 'ratioMinSize' => 1024 * 1024, 'maxFileSize' => self::phpUploadLimit(),
                      'maxPathDepth' => 16, 'allowedMimeTypes' => self::DEFAULT_ALLOWED_MIME_TYPES,
                      'deniedExtensions' => self::DENIED_EXTENSIONS,
                      'skipNames' => array( '__macosx', '.ds_store', 'thumbs.db', 'desktop.ini' ), 'skipHidden' => true,
                      'classMap' => null, 'varDir' => '' );
    }

    /**
     * The options of ezmultiupload.ini [ZipSettings] that are set; none without the kernel.
     *
     * @return array
     */
    public static function iniOptions(): array
    {
        if ( !class_exists( 'eZINI' ) )
            return array();
        $options = array();
        try
        {
            $ini = eZINI::instance( 'ezmultiupload.ini' );
            $get = function ( $names ) use ( $ini ) {
                foreach ( (array)$names as $name )
                {
                    if ( $ini->hasVariable( 'ZipSettings', $name ) )
                        return $ini->variable( 'ZipSettings', $name );
                }
                return null;
            };
            if ( ( $v = $get( array( 'Enabled', 'ZipImport' ) ) ) !== null )
                $options['enabled'] = !in_array( strtolower( (string)$v ), array( 'disabled', 'false', '0', '' ), true );
            if ( ( $v = $get( 'MaxDepth' ) ) !== null && $v !== '' )
                $options['maxDepth'] = (int)$v;
            if ( ( $v = $get( 'MaxEntries' ) ) !== null && $v !== '' )
                $options['maxEntries'] = (int)$v;
            if ( ( $v = $get( array( 'MaxTotalBytes', 'MaxTotalSize' ) ) ) !== null && self::parseSize( $v ) > 0 )
                $options['maxTotalBytes'] = self::smallestLimit( array( self::parseSize( $v ), self::phpUploadLimit() ) );
            if ( ( $v = $get( array( 'MaxRatio', 'MaxCompressionRatio' ) ) ) !== null && $v !== '' )
                $options['maxRatio'] = (int)$v;
            if ( ( $v = $get( 'CompressionRatioMinSize' ) ) !== null && $v !== '' )
                $options['ratioMinSize'] = self::parseSize( $v );
            if ( ( $v = $get( 'MaxPathDepth' ) ) !== null && $v !== '' )
                $options['maxPathDepth'] = (int)$v;
            // the per-file limit: [ZipSettings] MaxFileSize, else the page's own MaxFileSize, with PHP's limits
            $perFile = $get( 'MaxFileSize' );
            if ( ( $perFile === null || self::parseSize( $perFile ) === 0 ) && $ini->hasVariable( 'MultiUploadSettings', 'MaxFileSize' ) )
                $perFile = $ini->variable( 'MultiUploadSettings', 'MaxFileSize' );
            $options['maxFileSize'] = self::smallestLimit( array( self::parseSize( $perFile ), self::phpUploadLimit() ) );
            $types = array();
            if ( class_exists( 'expImageUploadValidator' ) )
                $types = expImageUploadValidator::allowedMIMETypes();
            else
                $types = array_filter( self::DEFAULT_ALLOWED_MIME_TYPES, function ( $t ) { return strpos( $t, 'image/' ) === 0; } );
            if ( !in_array( 'image/svg+xml', $types, true ) )
                $types[] = 'image/svg+xml';
            $extra = $get( 'AllowedMIMETypes' );
            $options['allowedMimeTypes'] = array_merge( $types, is_array( $extra ) && $extra ? $extra : array_diff( self::DEFAULT_ALLOWED_MIME_TYPES, $types ) );
            if ( is_array( $v = $get( 'DeniedExtensions' ) ) && $v )
                $options['deniedExtensions'] = array_unique( array_merge( self::DENIED_EXTENSIONS, $v ) );
            if ( is_array( $v = $get( 'SkipNames' ) ) )
                $options['skipNames'] = $v;
            if ( ( $v = $get( 'SkipHidden' ) ) !== null )
                $options['skipHidden'] = $v !== 'disabled';
            if ( class_exists( 'eZSys' ) )
                $options['varDir'] = eZSys::varDirectory();
        }
        catch ( Throwable $e )
        {
            return $options;
        }
        return $options;
    }

    /**
     * A size ("64M", "1G", "512k", "1048576") in bytes; 0 for empty or anything else.
     *
     * @param mixed $value
     * @return int
     */
    public static function parseSize( $value ): int
    {
        if ( is_int( $value ) )
            return max( 0, $value );
        if ( !preg_match( '/^([0-9]+)\s*([kmgt]?)b?$/i', trim( (string)$value ), $m ) )
            return 0;
        $bytes = (int)$m[1];
        $power = strpos( 'kmgt', strtolower( $m[2] ) );
        return $m[2] === '' ? $bytes : $bytes * ( 1024 ** ( $power + 1 ) );
    }

    /**
     * A name as UTF-8 without control characters: valid UTF-8 stays; else it is CP437 (the ZIP default) when it has
     * a byte 0x80-0xA5 (letters there in CP437, control or rare signs in ISO-8859-1), else ISO-8859-1.
     *
     * @param string $name
     * @return string
     */
    public static function utf8Name( string $name ): string
    {
        if ( !preg_match( '//u', $name ) )
        {
            $converted = false;
            if ( preg_match( '/[\x80-\xA5]/', $name ) && function_exists( 'iconv' ) )
                $converted = @iconv( 'CP437', 'UTF-8', $name );
            if ( !is_string( $converted ) || !preg_match( '//u', $converted ) )
                $converted = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $name, 'UTF-8', 'ISO-8859-1' )
                                                                       : self::latin1ToUtf8( $name );
            $name = (string)$converted;
        }
        if ( class_exists( 'Normalizer' ) )
        {
            $normal = Normalizer::normalize( $name, Normalizer::FORM_C );
            if ( is_string( $normal ) )
                $name = $normal;
        }
        return (string)preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
    }

    /**
     * An entry name as its path parts, or why it is unsafe: \ is a separator too, empty and "." parts are dropped;
     * an absolute path, a drive letter, a ".." part or a NUL is unsafe.
     *
     * @param string $name UTF-8
     * @return array{parts: string[], unsafe: string}
     */
    public static function pathParts( string $name ): array
    {
        $name = str_replace( '\\', '/', $name );
        if ( strpos( $name, "\0" ) !== false )
            return array( 'parts' => array(), 'unsafe' => 'nul' );
        if ( $name !== '' && ( $name[0] === '/' || preg_match( '#^[a-zA-Z]:#', $name ) ) )
            return array( 'parts' => array(), 'unsafe' => 'absolute' );
        $parts = array();
        foreach ( explode( '/', $name ) as $part )
        {
            if ( $part === '' || $part === '.' )
                continue;
            if ( $part === '..' )
                return array( 'parts' => array(), 'unsafe' => 'parent' );
            $parts[] = $part;
        }
        return array( 'parts' => $parts, 'unsafe' => '' );
    }

    // ---- the walk -----------------------------------------------------------------------------------------------

    /** @return array|null a failure result, or null when the archive can be read */
    protected function precheck( string $zipPath, array $limits )
    {
        if ( !class_exists( 'ZipArchive' ) )
            return $this->failure( 'zip_extension_missing', 'The PHP extension zip (ext-zip) is required to import ZIP files.', $limits );
        if ( !$limits['enabled'] )
            return $this->failure( 'disabled', 'ZIP import is disabled ([ZipSettings] in ezmultiupload.ini).', $limits );
        if ( !is_file( $zipPath ) || !is_readable( $zipPath ) )
            return $this->failure( 'not_found', 'The ZIP file cannot be read.', $limits );
        return null;
    }

    protected function failure( string $code, string $message, array $limits ): array
    {
        return array( 'ok' => false, 'error' => $code, 'message' => $message, 'refused' => array(),
                      'limits' => $this->publicLimits( $limits ), 'totalBytes' => 0, 'entries' => array() );
    }

    protected function publicLimits( array $limits ): array
    {
        unset( $limits['classMap'], $limits['varDir'] );
        return $limits;
    }

    /** The counters of one call */
    protected function context( array $limits, string $mode, array $selected, string $outDir, string $scratch ): array
    {
        return array( 'limits' => $limits, 'mode' => $mode, 'selected' => $selected, 'outDir' => $outDir,
                      'scratch' => $scratch, 'serial' => 0, 'count' => 0, 'total' => 0, 'written' => 0,
                      'rows' => array(), 'files' => array(), 'failed' => array(), 'seen' => array(), 'refused' => array(),
                      'finfo' => function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false );
    }

    /**
     * Walks one archive.
     *
     * @param string $zipPath
     * @param string $prefix the path of this archive inside the uploaded one, with a final /, or ''
     * @param int $depth 1 for the uploaded archive
     * @param array $ctx
     * @return bool false when the archive cannot be opened
     */
    protected function walk( string $zipPath, string $prefix, int $depth, array &$ctx ): bool
    {
        $zip = new ZipArchive();
        if ( $zip->open( $zipPath, ZipArchive::RDONLY ) !== true )
            return false;
        $limits = $ctx['limits'];
        try
        {
            $listed = 0;
            for ( $i = 0; $i < $zip->numFiles; $i++ )
            {
                $stat = $zip->statIndex( $i, ZipArchive::FL_ENC_RAW );
                if ( !is_array( $stat ) )
                    continue;
                $name = self::utf8Name( (string)$stat['name'] );
                $isDir = substr( str_replace( '\\', '/', $name ), -1 ) === '/';
                $parts = self::pathParts( $name );
                $relative = $parts['parts'] ? implode( '/', $parts['parts'] ) : trim( str_replace( '\\', '/', $name ), '/' );
                $row = array( 'name' => $parts['parts'] ? (string)end( $parts['parts'] ) : basename( str_replace( '\\', '/', $name ) ),
                              'path' => $prefix . $relative, 'size' => (int)$stat['size'],
                              'compressedSize' => (int)$stat['comp_size'], 'mime' => '', 'class' => '', 'depth' => $depth,
                              'nested' => false, 'status' => self::STATUS_OK, 'reason' => '' );
                ++$ctx['count'];
                if ( $ctx['count'] > $limits['maxEntries'] )
                {
                    $ctx['refused'][self::REASON_ENTRIES] = true;
                    // the rows over the limit are listed up to the limit again, then the walk stops
                    if ( ++$listed > $limits['maxEntries'] )
                        break;
                    $this->addRow( $ctx, $row, self::REASON_ENTRIES );
                    continue;
                }
                $mode = $this->unixType( $zip, $i );
                if ( $parts['unsafe'] !== '' )
                {
                    $this->addRow( $ctx, $row, self::REASON_UNSAFE );
                    continue;
                }
                if ( $isDir || $mode === 0040000 || !$parts['parts'] )
                    continue;
                if ( $this->isSystemFile( $parts['parts'], $limits ) )
                {
                    $this->addRow( $ctx, $row, self::REASON_SYSTEM );
                    continue;
                }
                if ( $mode === 0120000 )
                {
                    $this->addRow( $ctx, $row, self::REASON_SYMLINK );
                    continue;
                }
                if ( $mode !== null && $mode !== 0 && $mode !== 0100000 )
                {
                    $this->addRow( $ctx, $row, self::REASON_DEVICE );
                    continue;
                }
                if ( isset( $stat['encryption_method'] ) && (int)$stat['encryption_method'] !== 0 )
                {
                    $ctx['refused'][self::REASON_ENCRYPTED] = true;
                    $this->addRow( $ctx, $row, self::REASON_ENCRYPTED );
                    continue;
                }
                if ( count( $parts['parts'] ) > $limits['maxPathDepth'] )
                {
                    $this->addRow( $ctx, $row, self::REASON_UNSAFE );
                    continue;
                }
                $size = $row['size'];
                $packed = $row['compressedSize'];
                if ( $size >= max( 1, $limits['ratioMinSize'] ) && ( $packed <= 0 || $size / $packed > $limits['maxRatio'] ) )
                {
                    $this->addRow( $ctx, $row, self::REASON_RATIO );
                    continue;
                }
                $extension = strtolower( (string)pathinfo( $row['name'], PATHINFO_EXTENSION ) );
                if ( in_array( $extension, $limits['deniedExtensions'], true ) )
                {
                    $this->addRow( $ctx, $row, self::REASON_TYPE );
                    continue;
                }
                $head = $this->readHead( $zip, $i );
                if ( $head === null )
                {
                    $this->addRow( $ctx, $row, self::REASON_UNREADABLE );
                    continue;
                }
                $isZip = $extension === 'zip' && ( strncmp( $head, "PK\x03\x04", 4 ) === 0 || strncmp( $head, "PK\x05\x06", 4 ) === 0 );
                if ( $isZip )
                {
                    $row['mime'] = 'application/zip';
                    $row['nested'] = true;
                    if ( $depth + 1 > $limits['maxDepth'] )
                    {
                        $this->addRow( $ctx, $row, self::REASON_DEPTH );
                        continue;
                    }
                    if ( $size > $limits['maxTotalBytes'] )
                    {
                        $this->addRow( $ctx, $row, self::REASON_SIZE );
                        continue;
                    }
                    if ( $ctx['mode'] === 'extract' && !$this->isNeeded( $row['path'], $ctx['selected'] ) )
                    {
                        $this->addRow( $ctx, $row, '' );
                        continue;
                    }
                    $inner = $ctx['scratch'] . '/' . ( ++$ctx['serial'] ) . '.zip';
                    if ( !$this->copyEntry( $zip, $i, $inner, $size ) )
                    {
                        $this->addRow( $ctx, $row, self::REASON_UNREADABLE );
                        continue;
                    }
                    $this->addRow( $ctx, $row, '' );
                    $opened = $this->walk( $inner, $row['path'] . '/', $depth + 1, $ctx );
                    @unlink( $inner );
                    if ( !$opened )
                        $this->markLast( $ctx, $row['path'], self::REASON_UNREADABLE );
                    continue;
                }
                $row['mime'] = $this->detectMime( $head, $extension, $ctx );
                $row['class'] = $this->classFor( $row['mime'], $limits );
                if ( !$this->mimeAllowed( $row['mime'], $limits['allowedMimeTypes'] ) )
                {
                    $this->addRow( $ctx, $row, self::REASON_TYPE );
                    continue;
                }
                if ( $limits['maxFileSize'] > 0 && $size > $limits['maxFileSize'] )
                {
                    $this->addRow( $ctx, $row, self::REASON_SIZE );
                    continue;
                }
                if ( $ctx['total'] + $size > $limits['maxTotalBytes'] )
                {
                    $ctx['refused'][self::REASON_TOTAL] = true;
                    $this->addRow( $ctx, $row, self::REASON_TOTAL );
                    continue;
                }
                $ctx['total'] += $size;
                $this->addRow( $ctx, $row, '' );
                if ( $ctx['mode'] === 'extract' && $this->isSelected( $row['path'], $ctx['selected'] ) )
                    $this->extractEntry( $zip, $i, $row, $ctx );
            }
        }
        finally
        {
            $zip->close();
        }
        return true;
    }

    protected function addRow( array &$ctx, array $row, string $reason ): void
    {
        if ( $reason !== '' )
        {
            $row['status'] = self::STATUS_SKIPPED;
            $row['reason'] = $reason;
        }
        $ctx['rows'][] = $row;
        $ctx['seen'][$row['path']] = true;
    }

    /** Marks the row of $path as skipped (a nested ZIP file that turned out unreadable) */
    protected function markLast( array &$ctx, string $path, string $reason ): void
    {
        foreach ( $ctx['rows'] as $k => $row )
        {
            if ( $row['path'] === $path )
            {
                $ctx['rows'][$k]['status'] = self::STATUS_SKIPPED;
                $ctx['rows'][$k]['reason'] = $reason;
            }
        }
    }

    /** Whether $path or a nested ZIP file it is in was chosen */
    protected function isSelected( string $path, array $selected ): bool
    {
        if ( isset( $selected[$path] ) )
            return true;
        $parts = explode( '/', $path );
        $prefix = '';
        foreach ( array_slice( $parts, 0, -1 ) as $part )
        {
            $prefix .= ( $prefix === '' ? '' : '/' ) . $part;
            if ( isset( $selected[$prefix] ) && preg_match( '/\.zip\z/i', $part ) )
                return true;
        }
        return false;
    }

    /** Whether a nested ZIP file has to be opened: it or something in it was chosen */
    protected function isNeeded( string $zipRelative, array $selected ): bool
    {
        if ( $this->isSelected( $zipRelative, $selected ) )
            return true;
        foreach ( array_keys( $selected ) as $path )
        {
            if ( strpos( (string)$path, $zipRelative . '/' ) === 0 )
                return true;
        }
        return false;
    }

    protected function isSystemFile( array $parts, array $limits ): bool
    {
        foreach ( $parts as $part )
        {
            if ( in_array( strtolower( $part ), $limits['skipNames'], true ) )
                return true;
            if ( $limits['skipHidden'] && $part[0] === '.' )
                return true;
        }
        return false;
    }

    /** The Unix file type bits (0100000 file, 0040000 directory, 0120000 link ...), null when not a Unix entry */
    protected function unixType( ZipArchive $zip, int $index )
    {
        $opsys = 0;
        $attr = 0;
        if ( !$zip->getExternalAttributesIndex( $index, $opsys, $attr ) || $opsys !== ZipArchive::OPSYS_UNIX )
            return null;
        return ( $attr >> 16 ) & 0170000;
    }

    /**
     * The entry's stream by its index (PHP 8.2+), else by its name, as ZipArchive stores it (PHP 8.0 and 8.1).
     *
     * @return resource|false
     */
    protected function openStream( ZipArchive $zip, int $index )
    {
        if ( method_exists( $zip, 'getStreamIndex' ) )
            return $zip->getStreamIndex( $index );
        $name = $zip->getNameIndex( $index );
        return is_string( $name ) ? $zip->getStream( $name ) : false;
    }

    /** The first HEAD_BYTES of an entry, null when it cannot be read */
    protected function readHead( ZipArchive $zip, int $index )
    {
        $in = $this->openStream( $zip, $index );
        if ( !$in )
            return null;
        $head = '';
        while ( strlen( $head ) < self::HEAD_BYTES && !feof( $in ) )
        {
            $chunk = fread( $in, self::HEAD_BYTES - strlen( $head ) );
            if ( $chunk === false || $chunk === '' )
                break;
            $head .= $chunk;
        }
        fclose( $in );
        return $head;
    }

    /** The type by content (finfo), refined by the extension only where content cannot tell (never to an image) */
    protected function detectMime( string $head, string $extension, array $ctx ): string
    {
        $mime = '';
        if ( $ctx['finfo'] )
            $mime = strtolower( (string)finfo_buffer( $ctx['finfo'], $head ) );
        if ( $mime === '' )
            $mime = 'application/octet-stream';
        $semicolon = strpos( $mime, ';' );
        if ( $semicolon !== false )
            $mime = trim( substr( $mime, 0, $semicolon ) );
        if ( $mime === 'image/svg' )
            $mime = 'image/svg+xml';
        $isSvgText = (bool)preg_match( '/<svg[\s>]/i', $head );
        if ( in_array( $mime, array( 'text/xml', 'application/xml', 'text/plain', 'text/html' ), true ) && $isSvgText
             && in_array( $extension, array( 'svg', 'xml', '' ), true ) )
            return 'image/svg+xml';
        if ( $mime === 'application/gzip' || $mime === 'application/x-gzip' )
            return $extension === 'svgz' ? 'image/svg+xml' : $mime;
        if ( ( $mime === 'application/zip' || $mime === 'application/octet-stream' ) && isset( self::ZIP_BASED_TYPES[$extension] ) )
            return self::ZIP_BASED_TYPES[$extension];
        if ( $mime === 'application/octet-stream' && isset( self::OCTET_TYPES[$extension] ) )
            return self::OCTET_TYPES[$extension];
        if ( $mime === 'text/plain' && $extension === 'csv' )
            return 'text/csv';
        if ( $mime === 'application/x-empty' || $mime === 'inode/x-empty' )
            return 'text/plain';
        return $mime;
    }

    protected function mimeAllowed( string $mime, array $allowed ): bool
    {
        foreach ( $allowed as $pattern )
        {
            if ( $pattern === $mime || ( substr( $pattern, -1 ) === '*' && strpos( $mime, substr( $pattern, 0, -1 ) ) === 0 ) )
                return true;
        }
        return false;
    }

    /** The content class a type becomes: the classMap option, else upload.ini through the upload page's service */
    protected function classFor( string $mime, array $limits ): string
    {
        $map = $limits['classMap'];
        if ( is_callable( $map ) )
            return (string)call_user_func( $map, $mime );
        $group = explode( '/', $mime )[0];
        if ( is_array( $map ) )
            return isset( $map[$mime] ) ? (string)$map[$mime] : ( isset( $map[$group] ) ? (string)$map[$group] : '' );
        if ( class_exists( 'expMultiuploadService' ) && class_exists( 'eZINI' ) )
        {
            try
            {
                $class = expMultiuploadService::classForMime( $mime );
                return (string)$class['identifier'];
            }
            catch ( Throwable $e )
            {
                return '';
            }
        }
        return '';
    }

    // ---- writing ------------------------------------------------------------------------------------------------

    /** Writes a chosen entry under its relative path in the output directory */
    protected function extractEntry( ZipArchive $zip, int $index, array $row, array &$ctx ): void
    {
        $limits = $ctx['limits'];
        $parts = explode( '/', $row['path'] );
        $file = array_pop( $parts );
        $dir = $ctx['outDir'];
        foreach ( $parts as $part )
        {
            $dir .= '/' . $part;
            if ( is_link( $dir ) || ( !is_dir( $dir ) && !@mkdir( $dir, 0700 ) && !is_dir( $dir ) ) )
            {
                $ctx['failed'][$row['path']] = self::REASON_UNSAFE;
                return;
            }
        }
        $realDir = realpath( $dir );
        if ( $realDir === false || ( $realDir !== $ctx['outDir'] && strpos( $realDir . '/', $ctx['outDir'] . '/' ) !== 0 ) )
        {
            $ctx['failed'][$row['path']] = self::REASON_UNSAFE;
            return;
        }
        $budget = $limits['maxTotalBytes'] - $ctx['written'];
        $limit = min( $row['size'], $budget );
        if ( $limits['maxFileSize'] > 0 )
            $limit = min( $limit, $limits['maxFileSize'] );
        $target = $realDir . '/' . $file;
        $written = $this->copyEntry( $zip, $index, $target, $limit, $row['size'] );
        if ( $written === false )
        {
            $ctx['failed'][$row['path']] = self::REASON_UNREADABLE;
            return;
        }
        $ctx['written'] += $written;
        $ctx['files'][] = array( 'path' => $target, 'relativePath' => $row['path'], 'size' => $written,
                                 'mime' => $row['mime'], 'class' => $row['class'] );
    }

    /**
     * Copies an entry's stream to a new file in chunks, at most $limit bytes; more than $limit (a lying size header)
     * or fewer than $expected removes the file. The file is opened with "x": an existing file or link is never
     * written through.
     *
     * @return int|false the bytes written
     */
    protected function copyEntry( ZipArchive $zip, int $index, string $target, int $limit, int $expected = -1 )
    {
        $in = $this->openStream( $zip, $index );
        if ( !$in )
            return false;
        $out = @fopen( $target, 'xb' );
        if ( !$out )
        {
            fclose( $in );
            return false;
        }
        @chmod( $target, 0600 );
        $written = 0;
        $ok = true;
        while ( !feof( $in ) )
        {
            $chunk = fread( $in, self::CHUNK_BYTES );
            if ( $chunk === false )
            {
                $ok = false;
                break;
            }
            if ( $chunk === '' )
                break;
            $written += strlen( $chunk );
            if ( $written > $limit || fwrite( $out, $chunk ) !== strlen( $chunk ) )
            {
                $ok = false;
                break;
            }
        }
        fclose( $in );
        fclose( $out );
        if ( !$ok || ( $expected >= 0 && $written !== $expected ) )
        {
            @unlink( $target );
            return false;
        }
        return $written;
    }

    /**
     * A new directory, mode 0700, unique per call: <varDir>/expmultiupload-zip-import-<system user id>/<random>.
     *
     * @return string|false
     */
    protected function makeTempDir( string $varDir )
    {
        if ( $varDir === '' )
            $varDir = 'var';
        $user = function_exists( 'posix_geteuid' ) ? posix_geteuid() : getmyuid();
        $base = rtrim( $varDir, '/' ) . '/expmultiupload-zip-import-' . $user;
        if ( !is_dir( $base ) && !@mkdir( $base, 0700, true ) && !is_dir( $base ) )
            return false;
        if ( is_link( $base ) )
            return false;
        for ( $i = 0; $i < 5; $i++ )
        {
            $dir = $base . '/' . bin2hex( random_bytes( 12 ) );
            if ( @mkdir( $dir, 0700 ) )
            {
                @chmod( $dir, 0700 );
                return (string)realpath( $dir );
            }
        }
        return false;
    }

    /** PHP's upload limit: the smaller of upload_max_filesize and post_max_size that is set, 0 for none */
    protected static function phpUploadLimit(): int
    {
        return self::smallestLimit( array( self::parseSize( ini_get( 'upload_max_filesize' ) ),
                                           self::parseSize( ini_get( 'post_max_size' ) ) ) );
    }

    /** The smallest size that is set (above 0), 0 when none is */
    protected static function smallestLimit( array $sizes ): int
    {
        $set = array_filter( array_map( 'intval', $sizes ), function ( $s ) { return $s > 0; } );
        return $set ? (int)min( $set ) : 0;
    }

    protected static function latin1ToUtf8( string $name ): string
    {
        $out = '';
        for ( $i = 0, $n = strlen( $name ); $i < $n; $i++ )
        {
            $c = ord( $name[$i] );
            $out .= $c < 0x80 ? $name[$i] : chr( 0xC0 | ( $c >> 6 ) ) . chr( 0x80 | ( $c & 0x3F ) );
        }
        return $out;
    }
}
