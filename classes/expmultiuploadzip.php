<?php
/**
 * ZIP files on the upload page: the archive is opened with ZipArchive, its entries are checked and the files that
 * pass are written to a private directory of this request, also those of ZIP files inside it, down to a depth.
 *
 * What is refused (the whole archive): an encrypted entry, more entries than MaxEntries, more than MaxTotalSize
 * bytes uncompressed, an entry compressed more than MaxRatio times (a ZIP bomb). What is skipped (that entry, with
 * the reason): an absolute path, a ".." part, a symbolic link or device, a ZIP file deeper than MaxDepth, a path
 * deeper than MaxPathDepth, a file larger than the per-file limit, and the system's own files (__MACOSX/,
 * .DS_Store, Thumbs.db, desktop.ini, hidden files). Names are UTF-8 (a CP437 or Latin-1 name is converted).
 * Nothing is run and nothing is written outside the request's directory: entries are written under a name made
 * here (a number and the cleaned base name), never under the path the archive gives.
 * Settings: ezmultiupload.ini [ZipSettings]. The directory: workDirectory() (var/<site>/ezmultiupload-zip-<user id>/<random>,
 * mode 0700), removed by removeDirectory() when the request is done, also after a failure.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

class expMultiuploadZip
{
    /** The eZPreference of the folder handling: keep or flat */
    const PREFERENCE_MODE = 'exp_multiupload_zip_mode';

    /** @var array the limits in use (settings()) */
    protected $limits;

    /** @var string the request's directory */
    protected $workDir;

    /** @var int entries seen, over all levels */
    protected $count = 0;

    /** @var int bytes uncompressed, over all levels */
    protected $total = 0;

    /** @var int a number for each written file */
    protected $serial = 0;

    /** @var array the entries found */
    protected $entries = array();

    /**
     * @param string $workDir the request's directory (workDirectory())
     * @param array $limits settings() or a test's own
     */
    public function __construct( $workDir, array $limits )
    {
        $this->workDir = rtrim( $workDir, '/' );
        $this->limits = $limits + self::defaults();
    }

    /** @return array the built-in limits */
    public static function defaults()
    {
        return array( 'enabled' => true, 'maxDepth' => 3, 'maxEntries' => 1000, 'maxTotalSize' => 500 * 1024 * 1024,
                      'maxRatio' => 100, 'ratioMinSize' => 1024 * 1024, 'maxPathDepth' => 16, 'maxFileSize' => 0,
                      'skipNames' => array( '__macosx', '.ds_store', 'thumbs.db', 'desktop.ini' ), 'skipHidden' => true );
    }

    /**
     * The limits of ezmultiupload.ini [ZipSettings], the per-file limit of the page (maxFileSize).
     *
     * @return array
     */
    public static function settings()
    {
        $ini = eZINI::instance( 'ezmultiupload.ini' );
        $d = self::defaults();
        $get = function ( $name, $default ) use ( $ini ) {
            return $ini->hasVariable( 'ZipSettings', $name ) ? $ini->variable( 'ZipSettings', $name ) : $default;
        };
        $total = expMultiuploadService::parseSize( $get( 'MaxTotalSize', '500M' ) );
        return array(
            'enabled' => $get( 'ZipImport', 'enabled' ) !== 'disabled' && class_exists( 'ZipArchive' ),
            'maxDepth' => max( 1, (int)$get( 'MaxDepth', $d['maxDepth'] ) ),
            'maxEntries' => max( 1, (int)$get( 'MaxEntries', $d['maxEntries'] ) ),
            'maxTotalSize' => $total ?: $d['maxTotalSize'],
            'maxRatio' => max( 1, (int)$get( 'MaxCompressionRatio', $d['maxRatio'] ) ),
            'ratioMinSize' => expMultiuploadService::parseSize( $get( 'CompressionRatioMinSize', '1M' ) ),
            'maxPathDepth' => max( 1, (int)$get( 'MaxPathDepth', $d['maxPathDepth'] ) ),
            'maxFileSize' => expMultiuploadService::maxFileSize(),
            'skipNames' => array_map( 'strtolower', (array)$get( 'SkipNames', $d['skipNames'] ) ),
            'skipHidden' => $get( 'SkipHidden', 'enabled' ) !== 'disabled',
        );
    }

    /**
     * Whether the file is a ZIP file: by its name (.zip) and its first bytes (a local file header or an empty
     * archive's end record), never by the name alone.
     *
     * @param string $name
     * @param string $path
     * @return bool
     */
    public static function isZip( $name, $path )
    {
        if ( !preg_match( '/\.zip\z/i', (string)$name ) || !is_file( $path ) )
            return false;
        $head = (string)file_get_contents( $path, false, null, 0, 4 );
        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }

    /**
     * A new private directory for this request: var/<site>/ezmultiupload-zip-<user id>/<random>, mode 0700.
     *
     * @return string|false
     */
    public static function workDirectory()
    {
        // one base directory per system user: Velocity and PHP-FPM can run as different users, and a 0700 directory
        // of one is closed to the other
        $base = eZSys::varDirectory() . '/ezmultiupload-zip-' . ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : getmyuid() );
        if ( !is_dir( $base ) && !@mkdir( $base, 0700, true ) && !is_dir( $base ) )
            return false;
        for ( $i = 0; $i < 5; $i++ )
        {
            $dir = $base . '/' . bin2hex( random_bytes( 12 ) );
            if ( @mkdir( $dir, 0700 ) )
            {
                @chmod( $dir, 0700 );
                return $dir;
            }
        }
        return false;
    }

    /**
     * Removes the request's directory with everything in it (no link is followed).
     *
     * @param string $dir
     */
    public static function removeDirectory( $dir )
    {
        if ( !is_string( $dir ) || $dir === '' || !is_dir( $dir ) || is_link( $dir ) )
            return;
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                                                RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item )
        {
            if ( $item->isDir() && !$item->isLink() )
                @rmdir( $item->getPathname() );
            else
                @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }

    /**
     * An entry name as a list of path parts, or the reason it is unsafe. Separators are / (a \ is one too),
     * empty and "." parts are dropped; an absolute path, a drive letter, ".." or a NUL is unsafe.
     *
     * @param string $name UTF-8
     * @return array{parts: string[], unsafe: string}
     */
    public static function pathParts( $name )
    {
        $name = str_replace( '\\', '/', (string)$name );
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

    /**
     * A name as UTF-8 without control characters: valid UTF-8 stays, else it is read as CP437 (the ZIP default)
     * or Latin-1.
     *
     * @param string $name
     * @return string
     */
    public static function utf8Name( $name )
    {
        $name = (string)$name;
        if ( !preg_match( '//u', $name ) )
        {
            $converted = function_exists( 'iconv' ) ? @iconv( 'CP437', 'UTF-8//TRANSLIT', $name ) : false;
            if ( !is_string( $converted ) || $converted === '' || !preg_match( '//u', $converted ) )
                $converted = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $name, 'UTF-8', 'ISO-8859-1' ) : utf8_encode( $name );
            $name = $converted;
        }
        return (string)preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
    }

    /**
     * Walks the archive: the entries with their path, size and status; the files that pass are written to the
     * request's directory ('file').
     *
     * @param string $zipPath
     * @param string $zipName the archive's own name
     * @return array{ok: bool, error: string, reason: string, entries: array[]}
     */
    public function walk( $zipPath, $zipName )
    {
        $this->entries = array();
        $this->count = $this->total = 0;
        if ( empty( $this->limits['enabled'] ) )
            return array( 'ok' => false, 'reason' => 'disabled', 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'ZIP files are not taken on this site.' ), 'entries' => array() );
        try
        {
            $this->walkArchive( $zipPath, array(), '', 1 );
        }
        catch ( expMultiuploadZipRefused $e )
        {
            return array( 'ok' => false, 'reason' => $e->reason, 'error' => $e->getMessage(), 'entries' => array() );
        }
        return array( 'ok' => true, 'reason' => '', 'error' => '', 'entries' => $this->entries );
    }

    /**
     * @param string $zipPath
     * @param string[] $dirs the folders of the archive (a ZIP file inside one is a folder of its name)
     * @param string $keyPrefix
     * @param int $depth 1 for the uploaded archive
     */
    protected function walkArchive( $zipPath, array $dirs, $keyPrefix, $depth )
    {
        $zip = new ZipArchive();
        if ( $zip->open( $zipPath, ZipArchive::RDONLY ) !== true )
            throw new expMultiuploadZipRefused( 'invalid', ezpI18n::tr( 'extension/ezmultiupload', 'The file is not a ZIP file that can be read.' ) );
        try
        {
            for ( $i = 0; $i < $zip->numFiles; $i++ )
            {
                $stat = $zip->statIndex( $i );
                if ( !is_array( $stat ) )
                    continue;
                if ( isset( $stat['encryption_method'] ) && (int)$stat['encryption_method'] !== 0 )
                    throw new expMultiuploadZipRefused( 'encrypted', ezpI18n::tr( 'extension/ezmultiupload', 'The ZIP file is encrypted. Encrypted ZIP files are not supported; unpack it and upload the files.' ) );
                if ( ++$this->count > $this->limits['maxEntries'] )
                    throw new expMultiuploadZipRefused( 'entries', ezpI18n::tr( 'extension/ezmultiupload', 'The ZIP file has more than %max entries.', null, array( '%max' => $this->limits['maxEntries'] ) ) );
                $size = (int)$stat['size'];
                $packed = (int)$stat['comp_size'];
                $this->total += $size;
                if ( $this->total > $this->limits['maxTotalSize'] )
                    throw new expMultiuploadZipRefused( 'total', ezpI18n::tr( 'extension/ezmultiupload', 'The files in the ZIP file are larger than %max together.', null, array( '%max' => expMultiuploadService::sizeText( $this->limits['maxTotalSize'] ) ) ) );
                if ( $size >= max( 1, $this->limits['ratioMinSize'] ) && ( $packed <= 0 || $size / $packed > $this->limits['maxRatio'] ) )
                    throw new expMultiuploadZipRefused( 'ratio', ezpI18n::tr( 'extension/ezmultiupload', 'The ZIP file is compressed suspiciously strongly (a ZIP bomb?) and is refused.' ) );

                $raw = (string)$zip->getNameIndex( $i );
                $name = self::utf8Name( $raw );
                $isDir = substr( str_replace( '\\', '/', $name ), -1 ) === '/';
                $path = self::pathParts( $name );
                $entry = array( 'key' => $keyPrefix . implode( '/', $path['parts'] ? $path['parts'] : array( $name ) ),
                                'name' => $path['parts'] ? end( $path['parts'] ) : $name, 'dirs' => $dirs, 'size' => $size,
                                'depth' => $depth, 'file' => null, 'status' => 'ok', 'reason' => '', 'reasonCode' => '' );
                if ( $path['unsafe'] !== '' )
                {
                    $this->skip( $entry, 'unsafe', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the path is not safe.' ) );
                    continue;
                }
                if ( $isDir || !$path['parts'] )
                    continue;
                $entryDirs = array_merge( $dirs, array_slice( $path['parts'], 0, -1 ) );
                $entry['dirs'] = $entryDirs;
                if ( $this->systemFile( $path['parts'] ) )
                    continue;
                $mode = $this->unixMode( $zip, $i );
                if ( $mode !== null && $mode !== 0 && $mode !== 0100000 && $mode !== 0040000 )
                {
                    $this->skip( $entry, 'link', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: a link or device, not a file.' ) );
                    continue;
                }
                if ( $mode === 0040000 )
                    continue;
                if ( count( $entryDirs ) + 1 > $this->limits['maxPathDepth'] )
                {
                    $this->skip( $entry, 'deep', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the folders are nested too deeply.' ) );
                    continue;
                }
                $isZip = (bool)preg_match( '/\.zip\z/i', $entry['name'] );
                if ( !$isZip && $this->limits['maxFileSize'] && $size > $this->limits['maxFileSize'] )
                {
                    $this->skip( $entry, 'size', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the file is larger than %max.', null, array( '%max' => expMultiuploadService::sizeText( $this->limits['maxFileSize'] ) ) ) );
                    continue;
                }
                if ( $isZip && $depth + 1 > $this->limits['maxDepth'] )
                {
                    $this->skip( $entry, 'depth', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: ZIP files are nested deeper than %max levels.', null, array( '%max' => $this->limits['maxDepth'] ) ) );
                    continue;
                }
                $file = $this->extract( $zip, $i, $entry['name'], $size );
                if ( $file === false )
                {
                    $this->skip( $entry, 'read', ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the entry could not be read.' ) );
                    continue;
                }
                if ( $isZip )
                {
                    $base = preg_replace( '/\.zip\z/i', '', $entry['name'] );
                    $this->walkArchive( $file, array_merge( $entryDirs, array( $base ) ), $entry['key'] . '/', $depth + 1 );
                    @unlink( $file );
                    continue;
                }
                $entry['file'] = $file;
                $this->entries[] = $entry;
            }
        }
        finally
        {
            $zip->close();
        }
    }

    /** Whether a path is one of the system's own files (or a hidden one), which is left out silently */
    protected function systemFile( array $parts )
    {
        foreach ( $parts as $part )
        {
            if ( in_array( strtolower( $part ), $this->limits['skipNames'], true ) )
                return true;
            if ( $this->limits['skipHidden'] && $part[0] === '.' )
                return true;
        }
        return false;
    }

    /** The Unix file type bits of an entry (0100000 file, 0040000 directory, 0120000 link ...), null without them */
    protected function unixMode( ZipArchive $zip, $index )
    {
        $opsys = 0;
        $attr = 0;
        if ( !$zip->getExternalAttributesIndex( $index, $opsys, $attr ) || $opsys !== ZipArchive::OPSYS_UNIX )
            return null;
        return ( $attr >> 16 ) & 0170000;
    }

    protected function skip( array $entry, $code, $reason )
    {
        $entry['status'] = 'skipped';
        $entry['reasonCode'] = $code;
        $entry['reason'] = $reason;
        $this->entries[] = $entry;
    }

    /**
     * Writes entry $index to the request's directory under a name made here, at most $size bytes (the size the
     * archive declares; more is a damaged or lying archive and the entry is dropped).
     *
     * @return string|false the file
     */
    protected function extract( ZipArchive $zip, $index, $name, $size )
    {
        $this->serial++;
        $dir = $this->workDir . '/' . $this->serial;
        if ( !@mkdir( $dir, 0700 ) )
            return false;
        $safe = preg_replace( '/[^\pL\pN._ -]+/u', '_', $name );
        $safe = trim( (string)$safe, '. ' );
        if ( $safe === '' )
            $safe = 'file';
        $target = $dir . '/' . ( function_exists( 'mb_substr' ) ? mb_substr( $safe, 0, 200, 'UTF-8' ) : substr( $safe, 0, 200 ) );
        $in = $zip->getStream( $zip->getNameIndex( $index ) );
        if ( !$in )
            return false;
        $out = @fopen( $target, 'xb' );
        if ( !$out )
        {
            fclose( $in );
            return false;
        }
        $written = 0;
        $ok = true;
        while ( !feof( $in ) )
        {
            $chunk = fread( $in, 65536 );
            if ( $chunk === false )
            {
                $ok = false;
                break;
            }
            $written += strlen( $chunk );
            if ( $written > $size )
            {
                $ok = false;
                break;
            }
            fwrite( $out, $chunk );
        }
        fclose( $in );
        fclose( $out );
        if ( !$ok )
        {
            @unlink( $target );
            return false;
        }
        @chmod( $target, 0600 );
        return $target;
    }
}

/** A whole archive refused (encrypted, too many entries, too large, a bomb, unreadable) */
class expMultiuploadZipRefused extends RuntimeException
{
    /** @var string */
    public $reason;

    public function __construct( $reason, $message )
    {
        parent::__construct( $message );
        $this->reason = $reason;
    }
}
