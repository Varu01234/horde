<?php
/**
 * Test the LDAP result handler.
 *
 * PHP version 5
 *
 * @category Kolab
 * @package  Kolab_Server
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Kolab_Server
 */

/**
 * Prepare the test setup.
 */
require_once dirname(__FILE__) . '/../Autoload.php';

/**
 * Test the LDAP result handler.
 *
 * Copyright 2008-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Kolab
 * @package  Kolab_Server
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Kolab_Server
 */
class Horde_Kolab_Server_Result_LdapTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!extension_loaded('ldap') && !@dl('ldap.' . PHP_SHLIB_SUFFIX)) {
            $this->markTestSuiteSkipped('Ldap extension is missing!');
        };

        if (!class_exists('Net_LDAP2')) {
            $this->markTestSuiteSkipped('PEAR package Net_LDAP2 is not installed!');
        }
    }

    public function testMethodConstructHasParameterNetldap2searchSearchResult()
    {
        $search = $this->getMock(
            'Net_LDAP2_Search', array(), array(), '', false
        );
        $result = new Horde_Kolab_Server_Result_Ldap($search);
    }


    public function testMethodCountHasResultIntTheNumberOfElementsFound()
    {
        $search = $this->getMock(
            'Net_LDAP2_Search', array('count'), array(), '', false
        );
        $search->expects($this->exactly(1))
            ->method('count')
            ->will($this->returnValue(1));
        $result = new Horde_Kolab_Server_Result_Ldap($search);
        $this->assertEquals(1, $result->count());
    }

    public function testMethodSizelimitexceededHasResultBooleanIndicatingIfTheSearchSizeLimitWasHit()
    {
        $search = $this->getMock(
            'Net_LDAP2_Search', array('sizeLimitExceeded'), array(), '', false
        );
        $search->expects($this->exactly(1))
            ->method('sizeLimitExceeded')
            ->will($this->returnValue(true));
        $result = new Horde_Kolab_Server_Result_Ldap($search);
        $this->assertTrue($result->sizeLimitExceeded());
    }

    public function testMethodAsarrayHasResultArrayWithTheSearchResults()
    {
        $search = $this->getMock(
            'Net_LDAP2_Search', array('as_struct'), array(), '', false
        );
        $search->expects($this->exactly(1))
            ->method('as_struct')
            ->will($this->returnValue(array('a' => 'a')));
        $result = new Horde_Kolab_Server_Result_Ldap($search);
        $this->assertEquals(array('a' => 'a'), $result->asArray());
    }
}
