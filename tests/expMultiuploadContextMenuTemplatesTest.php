<?php
/**
 * The "Upload multiple files" entry of the content tree and sub-items context menus is declared in data-menu-url,
 * so it works on an administration page under a Content Security Policy without 'unsafe-inline'. Reads the files
 * only, without a kernel or a database:
 *
 *   php vendor/bin/phpunit --no-configuration extension/ezmultiupload/tests/expMultiuploadContextMenuTemplatesTest.php
 *
 *  CM-01 - The menu templates carry no script element and no event handler attribute
 *  CM-02 - Each entry declares the upload view of the node in data-menu-url (through ezurl, with %nodeID%), and
 *          admininterface.ini lists both templates
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

use PHPUnit\Framework\TestCase;

class expMultiuploadContextMenuTemplatesTest extends TestCase
{
    const TEMPLATES = array( 'ezmucontextmenu.tpl' => 'menu-multiupload', 'ezmusubitemscontextmenu.tpl' => 'child-menu-multiupload' );

    private static function source( string $name ): string
    {
        $source = (string)file_get_contents( dirname( __DIR__ ) . '/design/standard/templates/node/' . $name );
        return (string)preg_replace( '/\{\*.*?\*\}/s', '', $source );
    }

    /** CM-01 */
    public function testNoInlineScriptOrHandler()
    {
        foreach ( array_keys( self::TEMPLATES ) as $name )
        {
            $source = self::source( $name );
            $this->assertDoesNotMatchRegularExpression( '/<script\b/i', $source, $name );
            $this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $source, $name );
            $this->assertStringNotContainsString( 'javascript:', $source, $name );
        }
    }

    /** CM-02 */
    public function testEntryDeclaresItsAddress()
    {
        foreach ( self::TEMPLATES as $name => $id )
        {
            $this->assertMatchesRegularExpression(
                '#<a id="' . $id . '" data-menu-url=\{"/ezmultiupload/upload/%nodeID%"\|ezurl\} href="\#">#', self::source( $name ), $name );
        }
        $ini = (string)file_get_contents( dirname( __DIR__ ) . '/settings/admininterface.ini.append.php' );
        $this->assertStringContainsString( 'ContextMenuTemplateArray[]=node/ezmucontextmenu.tpl', $ini );
        $this->assertStringContainsString( 'SubitemsContextMenuTemplateArray[]=node/ezmusubitemscontextmenu.tpl', $ini );
    }
}
