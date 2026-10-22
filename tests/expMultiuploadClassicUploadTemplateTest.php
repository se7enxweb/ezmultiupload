<?php
/**
 * The upload page of the classic designs (design/standard ezmultiupload/upload.tpl) and the context menu entries need
 * no inline code: their scripts are files, so they work under a Content Security Policy without 'unsafe-inline'.
 * Reads the files only, without a kernel or a database:
 *
 *   php vendor/bin/phpunit --no-configuration extension/ezmultiupload/tests/expMultiuploadClassicUploadTemplateTest.php
 *
 *  CU-01 - upload.tpl has no script element or handler; the configuration is the JSON of data-ezmultiupload-conf and
 *          ezmultiupload_upload.js is required after the Exp modules it runs on, with the core that brings no jQuery
 *  CU-02 - ezmultiupload_upload.js reads that configuration and starts the upload on #uploadButtonOverlay with
 *          Exp.upload.create(), in plain JavaScript
 *  CU-03 - both menu templates print ezmultiupload_menu.js after their entry, which adds the entry to menuArray for an
 *          older ezpopupmenu.js without assigning menuArray anew
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

use PHPUnit\Framework\TestCase;

class expMultiuploadClassicUploadTemplateTest extends TestCase
{
    private static function file( string $path ): string
    {
        return (string)file_get_contents( dirname( __DIR__ ) . '/' . $path );
    }

    private static function template( string $path ): string
    {
        return (string)preg_replace( '/\{\*.*?\*\}/s', '', self::file( $path ) );
    }

    /** CU-01 */
    public function testUploadTemplateHasNoInlineCode()
    {
        $source = self::template( 'design/standard/templates/ezmultiupload/upload.tpl' );
        $this->assertDoesNotMatchRegularExpression( '/<script\b/i', $source );
        $this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $source );
        $this->assertStringNotContainsString( 'javascript:', $source );
        $this->assertStringContainsString( '<div id="uploadButtonOverlay" data-ezmultiupload-conf="{json_encode( hash(', $source );
        $this->assertStringContainsString( "'ezxform_token', '@\$ezxFormToken@'", $source );
        $this->assertStringContainsString( "{ezscript_require( array( 'exp::core::shared', 'exp::io', 'exp::dialog', 'exp::upload', 'ezmultiupload_upload.js' ) )}", $source );
    }

    /** CU-02 */
    public function testUploadScriptReadsTheConfiguration()
    {
        $script = self::file( 'design/standard/javascript/ezmultiupload_upload.js' );
        $this->assertStringContainsString( "root.getAttribute('data-ezmultiupload-conf')", $script );
        $this->assertStringContainsString( "Exp.upload.create(root, {", $script );
        $code = (string)preg_replace( array( '#/\*.*?\*/#s', '#(?<![:\'"\\\\])//[^\n]*#' ), '', $script );
        $this->assertDoesNotMatchRegularExpression( '/\$\(|\bjQuery\b|\.expUpload\b/', $code, 'no jQuery' );
    }

    /** CU-03 */
    public function testMenuEntriesPrintTheMenuScript()
    {
        foreach ( array( 'ezmucontextmenu.tpl', 'ezmusubitemscontextmenu.tpl' ) as $name )
        {
            $source = self::template( 'design/standard/templates/node/' . $name );
            $this->assertMatchesRegularExpression( "#</a>\s*\{ezscript\( array\( 'ezmultiupload_menu.js' \) \)\}\s*$#", $source, $name );
        }
        $script = self::file( 'design/standard/javascript/ezmultiupload_menu.js' );
        $this->assertStringContainsString( "menus[menu].elements[id] = { url: link.getAttribute('data-menu-url') };", $script );
        $this->assertDoesNotMatchRegularExpression( '/menuArray\s*=(?!=)/', $script );
    }
}
