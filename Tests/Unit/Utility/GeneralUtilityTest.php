<?php
namespace TYPO3\CMS\Core\Tests\Unit\Utility;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\GeneralUtilityFixture;
use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\GeneralUtilityMinifyJavaScriptFixture;
use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\OriginalClassFixture;
use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\OtherReplacementClassFixture;
use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\ReplacementClassFixture;
use TYPO3\CMS\Core\Tests\Unit\Utility\Fixtures\TwoParametersConstructorFixture;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use TYPO3\CMS\Core\Tests\FileStreamWrapper;

/**
 * Testcase for class \TYPO3\CMS\Core\Utility\GeneralUtility
 */
class GeneralUtilityTest extends \TYPO3\CMS\Core\Tests\UnitTestCase
{
    /**
     * @var array A backup of registered singleton instances
     */
    protected $singletonInstances = array();

    protected function setUp()
    {
        GeneralUtilityFixture::flushInternalRuntimeCaches();
        GeneralUtilityFixture::$isAllowedHostHeaderValueCallCount = 0;
        GeneralUtilityFixture::setAllowHostHeaderValue(false);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = GeneralUtility::ENV_TRUSTED_HOSTS_PATTERN_ALLOW_ALL;
        $this->singletonInstances = GeneralUtility::getSingletonInstances();
    }

    protected function tearDown()
    {
        GeneralUtility::resetSingletonInstances($this->singletonInstances);
        parent::tearDown();
    }

    /**
     * Helper method to test for an existing internet connection.
     * Some tests are skipped if there is no working uplink.
     *
     * @return bool $isConnected
     */
    public function isConnected()
    {
        $isConnected = false;
        $connected = @fsockopen('typo3.org', 80);
        if ($connected) {
            $isConnected = true;
            fclose($connected);
        }
        return $isConnected;
    }

    ///////////////////////////
    // Tests concerning _GP
    ///////////////////////////
    /**
     * @test
     * @dataProvider gpDataProvider
     */
    public function canRetrieveValueWithGP($key, $get, $post, $expected)
    {
        $_GET = $get;
        $_POST = $post;
        $this->assertSame($expected, GeneralUtility::_GP($key));
    }

    /**
     * Data provider for canRetrieveValueWithGP.
     * All test values also check whether slashes are stripped properly.
     *
     * @return array
     */
    public function gpDataProvider()
    {
        return array(
            'No key parameter' => array(null, array(), array(), null),
            'Key not found' => array('cake', array(), array(), null),
            'Value only in GET' => array('cake', array('cake' => 'li\\e'), array(), 'li\\e'),
            'Value only in POST' => array('cake', array(), array('cake' => 'l\\ie'), 'l\\ie'),
            'Value from POST preferred over GET' => array('cake', array('cake' => 'is a'), array('cake' => '\\lie'), '\\lie'),
            'Value can be an array' => array(
                'cake',
                array('cake' => array('is a' => 'l\\ie')),
                array(),
                array('is a' => 'l\\ie')
            )
        );
    }

    ///////////////////////////
    // Tests concerning _GPmerged
    ///////////////////////////
    /**
     * @test
     * @dataProvider gpMergedDataProvider
     */
    public function gpMergedWillMergeArraysFromGetAndPost($get, $post, $expected)
    {
        $_POST = $post;
        $_GET = $get;
        $this->assertEquals($expected, GeneralUtility::_GPmerged('cake'));
    }

    /**
     * Data provider for gpMergedWillMergeArraysFromGetAndPost
     *
     * @return array
     */
    public function gpMergedDataProvider()
    {
        $fullDataArray = array('cake' => array('a' => 'is a', 'b' => 'lie'));
        $postPartData = array('cake' => array('b' => 'lie'));
        $getPartData = array('cake' => array('a' => 'is a'));
        $getPartDataModified = array('cake' => array('a' => 'is not a'));
        return array(
            'Key doesn\' exist' => array(array('foo'), array('bar'), array()),
            'No POST data' => array($fullDataArray, array(), $fullDataArray['cake']),
            'No GET data' => array(array(), $fullDataArray, $fullDataArray['cake']),
            'POST and GET are merged' => array($getPartData, $postPartData, $fullDataArray['cake']),
            'POST is preferred over GET' => array($getPartDataModified, $fullDataArray, $fullDataArray['cake'])
        );
    }

    ///////////////////////////////
    // Tests concerning _GET / _POST
    ///////////////////////////////
    /**
     * Data provider for canRetrieveGlobalInputsThroughGet
     * and canRetrieveGlobalInputsThroughPost
     *
     * @return array
     */
    public function getAndPostDataProvider()
    {
        return array(
            'Requested input data doesn\'t exist' => array('cake', array(), null),
            'No key will return entire input data' => array(null, array('cake' => 'l\\ie'), array('cake' => 'l\\ie')),
            'Can retrieve specific input' => array('cake', array('cake' => 'l\\ie', 'foo'), 'l\\ie'),
            'Can retrieve nested input data' => array('cake', array('cake' => array('is a' => 'l\\ie')), array('is a' => 'l\\ie'))
        );
    }

    /**
     * @test
     * @dataProvider getAndPostDataProvider
     */
    public function canRetrieveGlobalInputsThroughGet($key, $get, $expected)
    {
        $_GET = $get;
        $this->assertSame($expected, GeneralUtility::_GET($key));
    }

    /**
     * @test
     * @dataProvider getAndPostDataProvider
     */
    public function canRetrieveGlobalInputsThroughPost($key, $post, $expected)
    {
        $_POST = $post;
        $this->assertSame($expected, GeneralUtility::_POST($key));
    }

    ///////////////////////////////
    // Tests concerning _GETset
    ///////////////////////////////
    /**
     * @test
     * @dataProvider getSetDataProvider
     */
    public function canSetNewGetInputValues($input, $key, $expected, $getPreset = array())
    {
        $_GET = $getPreset;
        GeneralUtility::_GETset($input, $key);
        $this->assertSame($expected, $_GET);
    }

    /**
     * Data provider for canSetNewGetInputValues
     *
     * @return array
     */
    public function getSetDataProvider()
    {
        return array(
            'No input data used without target key' => array(null, null, array()),
            'No input data used with target key' => array('', 'cake', array('cake' => '')),
            'No target key used with string input data' => array('data', null, array()),
            'No target key used with array input data' => array(array('cake' => 'lie'), null, array('cake' => 'lie')),
            'Target key and string input data' => array('lie', 'cake', array('cake' => 'lie')),
            'Replace existing GET data' => array('lie', 'cake', array('cake' => 'lie'), array('cake' => 'is a lie')),
            'Target key pointing to sublevels and string input data' => array('lie', 'cake|is', array('cake' => array('is' => 'lie'))),
            'Target key pointing to sublevels and array input data' => array(array('a' => 'lie'), 'cake|is', array('cake' => array('is' => array('a' => 'lie'))))
        );
    }

    ///////////////////////////
    // Tests concerning cmpIPv4
    ///////////////////////////
    /**
     * Data provider for cmpIPv4ReturnsTrueForMatchingAddress
     *
     * @return array Data sets
     */
    public static function cmpIPv4DataProviderMatching()
    {
        return array(
            'host with full IP address' => array('127.0.0.1', '127.0.0.1'),
            'host with two wildcards at the end' => array('127.0.0.1', '127.0.*.*'),
            'host with wildcard at third octet' => array('127.0.0.1', '127.0.*.1'),
            'host with wildcard at second octet' => array('127.0.0.1', '127.*.0.1'),
            '/8 subnet' => array('127.0.0.1', '127.1.1.1/8'),
            '/32 subnet (match only name)' => array('127.0.0.1', '127.0.0.1/32'),
            '/30 subnet' => array('10.10.3.1', '10.10.3.3/30'),
            'host with wildcard in list with IPv4/IPv6 addresses' => array('192.168.1.1', '127.0.0.1, 1234:5678::/126, 192.168.*'),
            'host in list with IPv4/IPv6 addresses' => array('192.168.1.1', '::1, 1234:5678::/126, 192.168.1.1'),
        );
    }

    /**
     * @test
     * @dataProvider cmpIPv4DataProviderMatching
     */
    public function cmpIPv4ReturnsTrueForMatchingAddress($ip, $list)
    {
        $this->assertTrue(GeneralUtility::cmpIPv4($ip, $list));
    }

    /**
     * Data provider for cmpIPv4ReturnsFalseForNotMatchingAddress
     *
     * @return array Data sets
     */
    public static function cmpIPv4DataProviderNotMatching()
    {
        return array(
            'single host' => array('127.0.0.1', '127.0.0.2'),
            'single host with wildcard' => array('127.0.0.1', '127.*.1.1'),
            'single host with /32 subnet mask' => array('127.0.0.1', '127.0.0.2/32'),
            '/31 subnet' => array('127.0.0.1', '127.0.0.2/31'),
            'list with IPv4/IPv6 addresses' => array('127.0.0.1', '10.0.2.3, 192.168.1.1, ::1'),
            'list with only IPv6 addresses' => array('10.20.30.40', '::1, 1234:5678::/127')
        );
    }

    /**
     * @test
     * @dataProvider cmpIPv4DataProviderNotMatching
     */
    public function cmpIPv4ReturnsFalseForNotMatchingAddress($ip, $list)
    {
        $this->assertFalse(GeneralUtility::cmpIPv4($ip, $list));
    }

    ///////////////////////////
    // Tests concerning cmpIPv6
    ///////////////////////////
    /**
     * Data provider for cmpIPv6ReturnsTrueForMatchingAddress
     *
     * @return array Data sets
     */
    public static function cmpIPv6DataProviderMatching()
    {
        return array(
            'empty address' => array('::', '::'),
            'empty with netmask in list' => array('::', '::/0'),
            'empty with netmask 0 and host-bits set in list' => array('::', '::123/0'),
            'localhost' => array('::1', '::1'),
            'localhost with leading zero blocks' => array('::1', '0:0::1'),
            'host with submask /128' => array('::1', '0:0::1/128'),
            '/16 subnet' => array('1234::1', '1234:5678::/16'),
            '/126 subnet' => array('1234:5678::3', '1234:5678::/126'),
            '/126 subnet with host-bits in list set' => array('1234:5678::3', '1234:5678::2/126'),
            'list with IPv4/IPv6 addresses' => array('1234:5678::3', '::1, 127.0.0.1, 1234:5678::/126, 192.168.1.1')
        );
    }

    /**
     * @test
     * @dataProvider cmpIPv6DataProviderMatching
     */
    public function cmpIPv6ReturnsTrueForMatchingAddress($ip, $list)
    {
        $this->assertTrue(GeneralUtility::cmpIPv6($ip, $list));
    }

    /**
     * Data provider for cmpIPv6ReturnsFalseForNotMatchingAddress
     *
     * @return array Data sets
     */
    public static function cmpIPv6DataProviderNotMatching()
    {
        return array(
            'empty against localhost' => array('::', '::1'),
            'empty against localhost with /128 netmask' => array('::', '::1/128'),
            'localhost against different host' => array('::1', '::2'),
            'localhost against host with prior bits set' => array('::1', '::1:1'),
            'host against different /17 subnet' => array('1234::1', '1234:f678::/17'),
            'host against different /127 subnet' => array('1234:5678::3', '1234:5678::/127'),
            'host against IPv4 address list' => array('1234:5678::3', '127.0.0.1, 192.168.1.1'),
            'host against mixed list with IPv6 host in different subnet' => array('1234:5678::3', '::1, 1234:5678::/127')
        );
    }

    /**
     * @test
     * @dataProvider cmpIPv6DataProviderNotMatching
     */
    public function cmpIPv6ReturnsFalseForNotMatchingAddress($ip, $list)
    {
        $this->assertFalse(GeneralUtility::cmpIPv6($ip, $list));
    }

    ///////////////////////////////
    // Tests concerning IPv6Hex2Bin
    ///////////////////////////////
    /**
     * Data provider for IPv6Hex2BinCorrect
     *
     * @return array Data sets
     */
    public static function IPv6Hex2BinDataProviderCorrect()
    {
        return array(
            'empty 1' => array('::', str_pad('', 16, "\x00")),
            'empty 2, already normalized' => array('0000:0000:0000:0000:0000:0000:0000:0000', str_pad('', 16, "\x00")),
            'already normalized' => array('0102:0304:0000:0000:0000:0000:0506:0078', "\x01\x02\x03\x04" . str_pad('', 8, "\x00") . "\x05\x06\x00\x78"),
            'expansion in middle 1' => array('1::2', "\x00\x01" . str_pad('', 12, "\x00") . "\x00\x02"),
            'expansion in middle 2' => array('beef::fefa', "\xbe\xef" . str_pad('', 12, "\x00") . "\xfe\xfa"),
        );
    }

    /**
     * @test
     * @dataProvider IPv6Hex2BinDataProviderCorrect
     */
    public function IPv6Hex2BinCorrectlyConvertsAddresses($hex, $binary)
    {
        $this->assertTrue(GeneralUtility::IPv6Hex2Bin($hex) === $binary);
    }

    ///////////////////////////////
    // Tests concerning IPv6Bin2Hex
    ///////////////////////////////
    /**
     * Data provider for IPv6Bin2HexCorrect
     *
     * @return array Data sets
     */
    public static function IPv6Bin2HexDataProviderCorrect()
    {
        return array(
            'empty' => array(str_pad('', 16, "\x00"), '::'),
            'non-empty front' => array("\x01" . str_pad('', 15, "\x00"), '100::'),
            'non-empty back' => array(str_pad('', 15, "\x00") . "\x01", '::1'),
            'normalized' => array("\x01\x02\x03\x04" . str_pad('', 8, "\x00") . "\x05\x06\x00\x78", '102:304::506:78'),
            'expansion in middle 1' => array("\x00\x01" . str_pad('', 12, "\x00") . "\x00\x02", '1::2'),
            'expansion in middle 2' => array("\xbe\xef" . str_pad('', 12, "\x00") . "\xfe\xfa", 'beef::fefa'),
        );
    }

    /**
     * @test
     * @dataProvider IPv6Bin2HexDataProviderCorrect
     */
    public function IPv6Bin2HexCorrectlyConvertsAddresses($binary, $hex)
    {
        $this->assertEquals(GeneralUtility::IPv6Bin2Hex($binary), $hex);
    }

    ////////////////////////////////////////////////
    // Tests concerning normalizeIPv6 / compressIPv6
    ////////////////////////////////////////////////
    /**
     * Data provider for normalizeIPv6ReturnsCorrectlyNormalizedFormat
     *
     * @return array Data sets
     */
    public static function normalizeCompressIPv6DataProviderCorrect()
    {
        return array(
            'empty' => array('::', '0000:0000:0000:0000:0000:0000:0000:0000'),
            'localhost' => array('::1', '0000:0000:0000:0000:0000:0000:0000:0001'),
            'expansion in middle 1' => array('1::2', '0001:0000:0000:0000:0000:0000:0000:0002'),
            'expansion in middle 2' => array('1:2::3', '0001:0002:0000:0000:0000:0000:0000:0003'),
            'expansion in middle 3' => array('1::2:3', '0001:0000:0000:0000:0000:0000:0002:0003'),
            'expansion in middle 4' => array('1:2::3:4:5', '0001:0002:0000:0000:0000:0003:0004:0005')
        );
    }

    /**
     * @test
     * @dataProvider normalizeCompressIPv6DataProviderCorrect
     */
    public function normalizeIPv6CorrectlyNormalizesAddresses($compressed, $normalized)
    {
        $this->assertEquals($normalized, GeneralUtility::normalizeIPv6($compressed));
    }

    /**
     * @test
     * @dataProvider normalizeCompressIPv6DataProviderCorrect
     */
    public function compressIPv6CorrectlyCompressesAdresses($compressed, $normalized)
    {
        $this->assertEquals($compressed, GeneralUtility::compressIPv6($normalized));
    }

    /**
     * @test
     */
    public function compressIPv6CorrectlyCompressesAdressWithSomeAddressOnRightSide()
    {
        if (strtolower(PHP_OS) === 'darwin') {
            $this->markTestSkipped('This test does not work on OSX / Darwin OS.');
        }
        $this->assertEquals('::f0f', GeneralUtility::compressIPv6('0000:0000:0000:0000:0000:0000:0000:0f0f'));
    }

    ///////////////////////////////
    // Tests concerning validIP
    ///////////////////////////////
    /**
     * Data provider for checkValidIpReturnsTrueForValidIp
     *
     * @return array Data sets
     */
    public static function validIpDataProvider()
    {
        return array(
            '0.0.0.0' => array('0.0.0.0'),
            'private IPv4 class C' => array('192.168.0.1'),
            'private IPv4 class A' => array('10.0.13.1'),
            'private IPv6' => array('fe80::daa2:5eff:fe8b:7dfb')
        );
    }

    /**
     * @test
     * @dataProvider validIpDataProvider
     */
    public function validIpReturnsTrueForValidIp($ip)
    {
        $this->assertTrue(GeneralUtility::validIP($ip));
    }

    /**
     * Data provider for checkValidIpReturnsFalseForInvalidIp
     *
     * @return array Data sets
     */
    public static function invalidIpDataProvider()
    {
        return array(
            'null' => array(null),
            'zero' => array(0),
            'string' => array('test'),
            'string empty' => array(''),
            'string NULL' => array('NULL'),
            'out of bounds IPv4' => array('300.300.300.300'),
            'dotted decimal notation with only two dots' => array('127.0.1')
        );
    }

    /**
     * @test
     * @dataProvider invalidIpDataProvider
     */
    public function validIpReturnsFalseForInvalidIp($ip)
    {
        $this->assertFalse(GeneralUtility::validIP($ip));
    }

    ///////////////////////////////
    // Tests concerning cmpFQDN
    ///////////////////////////////
    /**
     * Data provider for cmpFqdnReturnsTrue
     *
     * @return array Data sets
     */
    public static function cmpFqdnValidDataProvider()
    {
        return array(
            'localhost should usually resolve, IPv4' => array('127.0.0.1', '*'),
            'localhost should usually resolve, IPv6' => array('::1', '*'),
            // other testcases with resolving not possible since it would
            // require a working IPv4/IPv6-connectivity
            'aaa.bbb.ccc.ddd.eee, full' => array('aaa.bbb.ccc.ddd.eee', 'aaa.bbb.ccc.ddd.eee'),
            'aaa.bbb.ccc.ddd.eee, wildcard first' => array('aaa.bbb.ccc.ddd.eee', '*.ccc.ddd.eee'),
            'aaa.bbb.ccc.ddd.eee, wildcard last' => array('aaa.bbb.ccc.ddd.eee', 'aaa.bbb.ccc.*'),
            'aaa.bbb.ccc.ddd.eee, wildcard middle' => array('aaa.bbb.ccc.ddd.eee', 'aaa.*.eee'),
            'list-matches, 1' => array('aaa.bbb.ccc.ddd.eee', 'xxx, yyy, zzz, aaa.*.eee'),
            'list-matches, 2' => array('aaa.bbb.ccc.ddd.eee', '127:0:0:1,,aaa.*.eee,::1')
        );
    }

    /**
     * @test
     * @dataProvider cmpFqdnValidDataProvider
     */
    public function cmpFqdnReturnsTrue($baseHost, $list)
    {
        $this->assertTrue(GeneralUtility::cmpFQDN($baseHost, $list));
    }

    /**
     * Data provider for cmpFqdnReturnsFalse
     *
     * @return array Data sets
     */
    public static function cmpFqdnInvalidDataProvider()
    {
        return array(
            'num-parts of hostname to check can only be less or equal than hostname, 1' => array('aaa.bbb.ccc.ddd.eee', 'aaa.bbb.ccc.ddd.eee.fff'),
            'num-parts of hostname to check can only be less or equal than hostname, 2' => array('aaa.bbb.ccc.ddd.eee', 'aaa.*.bbb.ccc.ddd.eee')
        );
    }

    /**
     * @test
     * @dataProvider cmpFqdnInvalidDataProvider
     */
    public function cmpFqdnReturnsFalse($baseHost, $list)
    {
        $this->assertFalse(GeneralUtility::cmpFQDN($baseHost, $list));
    }

    ///////////////////////////////
    // Tests concerning inList
    ///////////////////////////////
    /**
     * @test
     * @param string $haystack
     * @dataProvider inListForItemContainedReturnsTrueDataProvider
     */
    public function inListForItemContainedReturnsTrue($haystack)
    {
        $this->assertTrue(GeneralUtility::inList($haystack, 'findme'));
    }

    /**
     * Data provider for inListForItemContainedReturnsTrue.
     *
     * @return array
     */
    public function inListForItemContainedReturnsTrueDataProvider()
    {
        return array(
            'Element as second element of four items' => array('one,findme,three,four'),
            'Element at beginning of list' => array('findme,one,two'),
            'Element at end of list' => array('one,two,findme'),
            'One item list' => array('findme')
        );
    }

    /**
     * @test
     * @param string $haystack
     * @dataProvider inListForItemNotContainedReturnsFalseDataProvider
     */
    public function inListForItemNotContainedReturnsFalse($haystack)
    {
        $this->assertFalse(GeneralUtility::inList($haystack, 'findme'));
    }

    /**
     * Data provider for inListForItemNotContainedReturnsFalse.
     *
     * @return array
     */
    public function inListForItemNotContainedReturnsFalseDataProvider()
    {
        return array(
            'Four item list' => array('one,two,three,four'),
            'One item list' => array('one'),
            'Empty list' => array('')
        );
    }

    ///////////////////////////////
    // Tests concerning rmFromList
    ///////////////////////////////
    /**
     * @test
     * @param string $initialList
     * @param string $listWithElementRemoved
     * @dataProvider rmFromListRemovesElementsFromCommaSeparatedListDataProvider
     */
    public function rmFromListRemovesElementsFromCommaSeparatedList($initialList, $listWithElementRemoved)
    {
        $this->assertSame($listWithElementRemoved, GeneralUtility::rmFromList('removeme', $initialList));
    }

    /**
     * Data provider for rmFromListRemovesElementsFromCommaSeparatedList
     *
     * @return array
     */
    public function rmFromListRemovesElementsFromCommaSeparatedListDataProvider()
    {
        return array(
            'Element as second element of three' => array('one,removeme,two', 'one,two'),
            'Element at beginning of list' => array('removeme,one,two', 'one,two'),
            'Element at end of list' => array('one,two,removeme', 'one,two'),
            'One item list' => array('removeme', ''),
            'Element not contained in list' => array('one,two,three', 'one,two,three'),
            'Empty element survives' => array('one,,three,,removeme', 'one,,three,'),
            'Empty element survives at start' => array(',removeme,three,removeme', ',three'),
            'Empty element survives at end' => array('removeme,three,removeme,', 'three,'),
            'Empty list' => array('', ''),
            'List contains removeme multiple times' => array('removeme,notme,removeme,removeme', 'notme'),
            'List contains removeme multiple times nothing else' => array('removeme,removeme,removeme', ''),
            'List contains removeme multiple times nothing else 2x' => array('removeme,removeme', ''),
            'List contains removeme multiple times nothing else 3x' => array('removeme,removeme,removeme', ''),
            'List contains removeme multiple times nothing else 4x' => array('removeme,removeme,removeme,removeme', ''),
            'List contains removeme multiple times nothing else 5x' => array('removeme,removeme,removeme,removeme,removeme', ''),
        );
    }

    ///////////////////////////////
    // Tests concerning expandList
    ///////////////////////////////
    /**
     * @test
     * @param string $list
     * @param string $expectation
     * @dataProvider expandListExpandsIntegerRangesDataProvider
     */
    public function expandListExpandsIntegerRanges($list, $expectation)
    {
        $this->assertSame($expectation, GeneralUtility::expandList($list));
    }

    /**
     * Data provider for expandListExpandsIntegerRangesDataProvider
     *
     * @return array
     */
    public function expandListExpandsIntegerRangesDataProvider()
    {
        return array(
            'Expand for the same number' => array('1,2-2,7', '1,2,7'),
            'Small range expand with parameters reversed ignores reversed items' => array('1,5-3,7', '1,7'),
            'Small range expand' => array('1,3-5,7', '1,3,4,5,7'),
            'Expand at beginning' => array('3-5,1,7', '3,4,5,1,7'),
            'Expand at end' => array('1,7,3-5', '1,7,3,4,5'),
            'Multiple small range expands' => array('1,3-5,7-10,12', '1,3,4,5,7,8,9,10,12'),
            'One item list' => array('1-5', '1,2,3,4,5'),
            'Nothing to expand' => array('1,2,3,4', '1,2,3,4'),
            'Empty list' => array('', '')
        );
    }

    /**
     * @test
     */
    public function expandListExpandsForTwoThousandElementsExpandsOnlyToThousandElementsMaximum()
    {
        $list = GeneralUtility::expandList('1-2000');
        $this->assertSame(1000, count(explode(',', $list)));
    }

    ///////////////////////////////
    // Tests concerning uniqueList
    ///////////////////////////////
    /**
     * @test
     * @param string $initialList
     * @param string $unifiedList
     * @dataProvider uniqueListUnifiesCommaSeparatedListDataProvider
     */
    public function uniqueListUnifiesCommaSeparatedList($initialList, $unifiedList)
    {
        $this->assertSame($unifiedList, GeneralUtility::uniqueList($initialList));
    }

    /**
     * Data provider for uniqueListUnifiesCommaSeparatedList
     *
     * @return array
     */
    public function uniqueListUnifiesCommaSeparatedListDataProvider()
    {
        return array(
            'List without duplicates' => array('one,two,three', 'one,two,three'),
            'List with two consecutive duplicates' => array('one,two,two,three,three', 'one,two,three'),
            'List with non-consecutive duplicates' => array('one,two,three,two,three', 'one,two,three'),
            'One item list' => array('one', 'one'),
            'Empty list' => array('', '')
        );
    }

    ///////////////////////////////
    // Tests concerning isFirstPartOfStr
    ///////////////////////////////
    /**
     * Data provider for isFirstPartOfStrReturnsTrueForMatchingFirstParts
     *
     * @return array
     */
    public function isFirstPartOfStrReturnsTrueForMatchingFirstPartDataProvider()
    {
        return array(
            'match first part of string' => array('hello world', 'hello'),
            'match whole string' => array('hello', 'hello'),
            'integer is part of string with same number' => array('24', 24),
            'string is part of integer with same number' => array(24, '24'),
            'integer is part of string starting with same number' => array('24 beer please', 24)
        );
    }

    /**
     * @test
     * @dataProvider isFirstPartOfStrReturnsTrueForMatchingFirstPartDataProvider
     */
    public function isFirstPartOfStrReturnsTrueForMatchingFirstPart($string, $part)
    {
        $this->assertTrue(GeneralUtility::isFirstPartOfStr($string, $part));
    }

    /**
     * Data provider for checkIsFirstPartOfStrReturnsFalseForNotMatchingFirstParts
     *
     * @return array
     */
    public function isFirstPartOfStrReturnsFalseForNotMatchingFirstPartDataProvider()
    {
        return array(
            'no string match' => array('hello', 'bye'),
            'no case sensitive string match' => array('hello world', 'Hello'),
            'array is not part of string' => array('string', array()),
            'string is not part of array' => array(array(), 'string'),
            'NULL is not part of string' => array('string', null),
            'string is not part of NULL' => array(null, 'string'),
            'NULL is not part of array' => array(array(), null),
            'array is not part of NULL' => array(null, array()),
            'empty string is not part of empty string' => array('', ''),
            'NULL is not part of empty string' => array('', null),
            'false is not part of empty string' => array('', false),
            'empty string is not part of NULL' => array(null, ''),
            'empty string is not part of false' => array(false, ''),
            'empty string is not part of zero integer' => array(0, ''),
            'zero integer is not part of NULL' => array(null, 0),
            'zero integer is not part of empty string' => array('', 0)
        );
    }

    /**
     * @test
     * @dataProvider isFirstPartOfStrReturnsFalseForNotMatchingFirstPartDataProvider
     */
    public function isFirstPartOfStrReturnsFalseForNotMatchingFirstPart($string, $part)
    {
        $this->assertFalse(GeneralUtility::isFirstPartOfStr($string, $part));
    }

    ///////////////////////////////
    // Tests concerning formatSize
    ///////////////////////////////
    /**
     * @test
     * @dataProvider formatSizeDataProvider
     */
    public function formatSizeTranslatesBytesToHigherOrderRepresentation($size, $labels, $base, $expected)
    {
        $this->assertEquals($expected, GeneralUtility::formatSize($size, $labels, $base));
    }

    /**
     * Data provider for formatSizeTranslatesBytesToHigherOrderRepresentation
     *
     * @return array
     */
    public function formatSizeDataProvider()
    {
        return array(
            'IEC Bytes stay bytes (min)' => array(1, '', 0, '1 '),
            'IEC Bytes stay bytes (max)' => array(921, '', 0, '921 '),
            'IEC Kilobytes are used (min)' => array(922, '', 0, '0.90 Ki'),
            'IEC Kilobytes are used (max)' => array(943718, '', 0, '922 Ki'),
            'IEC Megabytes are used (min)' => array(943719, '', 0, '0.90 Mi'),
            'IEC Megabytes are used (max)' => array(966367641, '', 0, '922 Mi'),
            'IEC Gigabytes are used (min)' => array(966367642, '', 0, '0.90 Gi'),
            'IEC Gigabytes are used (max)' => array(989560464998, '', 0, '922 Gi'),
            'IEC Decimal is omitted for large kilobytes' => array(31080, '', 0, '30 Ki'),
            'IEC Decimal is omitted for large megabytes' => array(31458000, '', 0, '30 Mi'),
            'IEC Decimal is omitted for large gigabytes' => array(32212254720, '', 0, '30 Gi'),
            'SI Bytes stay bytes (min)' => array(1, 'si', 0, '1 '),
            'SI Bytes stay bytes (max)' => array(899, 'si', 0, '899 '),
            'SI Kilobytes are used (min)' => array(901, 'si', 0, '0.90 k'),
            'SI Kilobytes are used (max)' => array(900000, 'si', 0, '900 k'),
            'SI Megabytes are used (min)' => array(900001, 'si', 0, '0.90 M'),
            'SI Megabytes are used (max)' => array(900000000, 'si', 0, '900 M'),
            'SI Gigabytes are used (min)' => array(900000001, 'si', 0, '0.90 G'),
            'SI Gigabytes are used (max)' => array(900000000000, 'si', 0, '900 G'),
            'SI Decimal is omitted for large kilobytes' => array(30000, 'si', 0, '30 k'),
            'SI Decimal is omitted for large megabytes' => array(30000000, 'si', 0, '30 M'),
            'SI Decimal is omitted for large gigabytes' => array(30000000000, 'si', 0, '30 G'),
            'Label for bytes can be exchanged (binary unit)' => array(1, ' Foo|||', 0, '1 Foo'),
            'Label for kilobytes can be exchanged (binary unit)' => array(1024, '| Foo||', 0, '1.00 Foo'),
            'Label for megabyes can be exchanged (binary unit)' => array(1048576, '|| Foo|', 0, '1.00 Foo'),
            'Label for gigabytes can be exchanged (binary unit)' => array(1073741824, '||| Foo', 0, '1.00 Foo'),
            'Label for bytes can be exchanged (decimal unit)' => array(1, ' Foo|||', 1000, '1 Foo'),
            'Label for kilobytes can be exchanged (decimal unit)' => array(1000, '| Foo||', 1000, '1.00 Foo'),
            'Label for megabyes can be exchanged (decimal unit)' => array(1000000, '|| Foo|', 1000, '1.00 Foo'),
            'Label for gigabytes can be exchanged (decimal unit)' => array(1000000000, '||| Foo', 1000, '1.00 Foo'),
            'IEC Base is ignored' => array(1024, 'iec', 1000, '1.00 Ki'),
            'SI Base is ignored' => array(1000, 'si', 1024, '1.00 k'),
            'Use binary base for unexpected base' => array(2048, '| Bar||', 512, '2.00 Bar')
        );
    }

    ///////////////////////////////
    // Tests concerning splitCalc
    ///////////////////////////////
    /**
     * Data provider for splitCalc
     *
     * @return array expected values, arithmetic expression
     */
    public function splitCalcDataProvider()
    {
        return array(
            'empty string returns empty array' => array(
                array(),
                ''
            ),
            'number without operator returns array with plus and number' => array(
                array(array('+', 42)),
                '42'
            ),
            'two numbers with asterisk return first number with plus and second number with asterisk' => array(
                array(array('+', 42), array('*', 31)),
                '42 * 31'
            )
        );
    }

    /**
     * @test
     * @dataProvider splitCalcDataProvider
     */
    public function splitCalcCorrectlySplitsExpression($expected, $expression)
    {
        $this->assertEquals($expected, GeneralUtility::splitCalc($expression, '+-*/'));
    }

    ///////////////////////////////
    // Tests concerning htmlspecialchars_decode
    ///////////////////////////////
    /**
     * @test
     */
    public function htmlspecialcharsDecodeReturnsDecodedString()
    {
        $string = '<typo3 version="6.0">&nbsp;</typo3>';
        $encoded = htmlspecialchars($string);
        $decoded = htmlspecialchars_decode($encoded);
        $this->assertEquals($string, $decoded);
    }

    ///////////////////////////////
    // Tests concerning deHSCentities
    ///////////////////////////////
    /**
     * @test
     * @dataProvider deHSCentitiesReturnsDecodedStringDataProvider
     */
    public function deHSCentitiesReturnsDecodedString($input, $expected)
    {
        $this->assertEquals($expected, GeneralUtility::deHSCentities($input));
    }

    /**
     * Data provider for deHSCentitiesReturnsDecodedString
     *
     * @return array
     */
    public function deHSCentitiesReturnsDecodedStringDataProvider()
    {
        return array(
            'Empty string' => array('', ''),
            'Double encoded &' => array('&amp;amp;', '&amp;'),
            'Double encoded numeric entity' => array('&amp;#1234;', '&#1234;'),
            'Double encoded hexadecimal entity' => array('&amp;#x1b;', '&#x1b;'),
            'Single encoded entities are not touched' => array('&amp; &#1234; &#x1b;', '&amp; &#1234; &#x1b;')
        );
    }

    //////////////////////////////////
    // Tests concerning slashJS
    //////////////////////////////////
    /**
     * @test
     * @dataProvider slashJsDataProvider
     */
    public function slashJsEscapesSingleQuotesAndSlashes($input, $extended, $expected)
    {
        $this->assertEquals($expected, GeneralUtility::slashJS($input, $extended));
    }

    /**
     * Data provider for slashJsEscapesSingleQuotesAndSlashes
     *
     * @return array
     */
    public function slashJsDataProvider()
    {
        return array(
            'Empty string is not changed' => array('', false, ''),
            'Normal string is not changed' => array('The cake is a lie √', false, 'The cake is a lie √'),
            'String with single quotes' => array('The \'cake\' is a lie', false, 'The \\\'cake\\\' is a lie'),
            'String with single quotes and backslashes - just escape single quotes' => array('The \\\'cake\\\' is a lie', false, 'The \\\\\'cake\\\\\' is a lie'),
            'String with single quotes and backslashes - escape both' => array('The \\\'cake\\\' is a lie', true, 'The \\\\\\\'cake\\\\\\\' is a lie')
        );
    }

    //////////////////////////////////
    // Tests concerning rawUrlEncodeJS
    //////////////////////////////////
    /**
     * @test
     */
    public function rawUrlEncodeJsPreservesWhitespaces()
    {
        $input = 'Encode \'me\', but leave my spaces √';
        $expected = 'Encode %27me%27%2C but leave my spaces %E2%88%9A';
        $this->assertEquals($expected, GeneralUtility::rawUrlEncodeJS($input));
    }

    //////////////////////////////////
    // Tests concerning rawUrlEncodeJS
    //////////////////////////////////
    /**
     * @test
     */
    public function rawUrlEncodeFpPreservesSlashes()
    {
        $input = 'Encode \'me\', but leave my / √';
        $expected = 'Encode%20%27me%27%2C%20but%20leave%20my%20/%20%E2%88%9A';
        $this->assertEquals($expected, GeneralUtility::rawUrlEncodeFP($input));
    }

    //////////////////////////////////
    // Tests concerning strtoupper / strtolower
    //////////////////////////////////
    /**
     * Data provider for strtoupper and strtolower
     *
     * @return array
     */
    public function strtouppperDataProvider()
    {
        return array(
            'Empty string' => array('', ''),
            'String containing only latin characters' => array('the cake is a lie.', 'THE CAKE IS A LIE.'),
            'String with umlauts and accent characters' => array('the càkê is ä lie.', 'THE CàKê IS ä LIE.')
        );
    }

    /**
     * @test
     * @dataProvider strtouppperDataProvider
     */
    public function strtoupperConvertsOnlyLatinCharacters($input, $expected)
    {
        $this->assertEquals($expected, GeneralUtility::strtoupper($input));
    }

    /**
     * @test
     * @dataProvider strtouppperDataProvider
     */
    public function strtolowerConvertsOnlyLatinCharacters($expected, $input)
    {
        $this->assertEquals($expected, GeneralUtility::strtolower($input));
    }

    //////////////////////////////////
    // Tests concerning validEmail
    //////////////////////////////////
    /**
     * Data provider for valid validEmail's
     *
     * @return array Valid email addresses
     */
    public function validEmailValidDataProvider()
    {
        return array(
            'short mail address' => array('a@b.c'),
            'simple mail address' => array('test@example.com'),
            'uppercase characters' => array('QWERTYUIOPASDFGHJKLZXCVBNM@QWERTYUIOPASDFGHJKLZXCVBNM.NET'),
            'equal sign in local part' => array('test=mail@example.com'),
            'dash in local part' => array('test-mail@example.com'),
            'plus in local part' => array('test+mail@example.com'),
            'question mark in local part' => array('test?mail@example.com'),
            'slash in local part' => array('foo/bar@example.com'),
            'hash in local part' => array('foo#bar@example.com'),
            'dot in local part' => array('firstname.lastname@employee.2something.com'),
            'dash as local part' => array('-@foo.com'),
            'umlauts in domain part' => array('foo@äöüfoo.com')
        );
    }

    /**
     * @test
     * @dataProvider validEmailValidDataProvider
     */
    public function validEmailReturnsTrueForValidMailAddress($address)
    {
        $this->assertTrue(GeneralUtility::validEmail($address));
    }

    /**
     * Data provider for invalid validEmail's
     *
     * @return array Invalid email addresses
     */
    public function validEmailInvalidDataProvider()
    {
        return array(
            'empty string' => array(''),
            'empty array' => array(array()),
            'integer' => array(42),
            'float' => array(42.23),
            'array' => array(array('foo')),
            'object' => array(new \stdClass()),
            '@ sign only' => array('@'),
            'string longer than 320 characters' => array(str_repeat('0123456789', 33)),
            'duplicate @' => array('test@@example.com'),
            'duplicate @ combined with further special characters in local part' => array('test!.!@#$%^&*@example.com'),
            'opening parenthesis in local part' => array('foo(bar@example.com'),
            'closing parenthesis in local part' => array('foo)bar@example.com'),
            'opening square bracket in local part' => array('foo[bar@example.com'),
            'closing square bracket as local part' => array(']@example.com'),
            'top level domain only' => array('test@com'),
            'dash as second level domain' => array('foo@-.com'),
            'domain part starting with dash' => array('foo@-foo.com'),
            'domain part ending with dash' => array('foo@foo-.com'),
            'number as top level domain' => array('foo@bar.123'),
            'dot at beginning of domain part' => array('test@.com'),
            'local part ends with dot' => array('e.x.a.m.p.l.e.@example.com'),
            'umlauts in local part' => array('äöüfoo@bar.com'),
            'trailing whitespace' => array('test@example.com '),
            'trailing carriage return' => array('test@example.com' . CR),
            'trailing linefeed' => array('test@example.com' . LF),
            'trailing carriage return linefeed' => array('test@example.com' . CRLF),
            'trailing tab' => array('test@example.com' . TAB)
        );
    }

    /**
     * @test
     * @dataProvider validEmailInvalidDataProvider
     */
    public function validEmailReturnsFalseForInvalidMailAddress($address)
    {
        $this->assertFalse(GeneralUtility::validEmail($address));
    }

    //////////////////////////////////
    // Tests concerning intExplode
    //////////////////////////////////
    /**
     * @test
     */
    public function intExplodeConvertsStringsToInteger()
    {
        $testString = '1,foo,2';
        $expectedArray = array(1, 0, 2);
        $actualArray = GeneralUtility::intExplode(',', $testString);
        $this->assertEquals($expectedArray, $actualArray);
    }

    //////////////////////////////////
    // Tests concerning implodeArrayForUrl / explodeUrl2Array
    //////////////////////////////////
    /**
     * Data provider for implodeArrayForUrlBuildsValidParameterString and
     * explodeUrl2ArrayTransformsParameterStringToArray
     *
     * @return array
     */
    public function implodeArrayForUrlDataProvider()
    {
        $valueArray = array('one' => '√', 'two' => 2);
        return array(
            'Empty input' => array('foo', array(), ''),
            'String parameters' => array('foo', $valueArray, '&foo[one]=%E2%88%9A&foo[two]=2'),
            'Nested array parameters' => array('foo', array($valueArray), '&foo[0][one]=%E2%88%9A&foo[0][two]=2'),
            'Keep blank parameters' => array('foo', array('one' => '√', ''), '&foo[one]=%E2%88%9A&foo[0]=')
        );
    }

    /**
     * @test
     * @dataProvider implodeArrayForUrlDataProvider
     */
    public function implodeArrayForUrlBuildsValidParameterString($name, $input, $expected)
    {
        $this->assertSame($expected, GeneralUtility::implodeArrayForUrl($name, $input));
    }

    /**
     * @test
     */
    public function implodeArrayForUrlCanSkipEmptyParameters()
    {
        $input = array('one' => '√', '');
        $expected = '&foo[one]=%E2%88%9A';
        $this->assertSame($expected, GeneralUtility::implodeArrayForUrl('foo', $input, '', true));
    }

    /**
     * @test
     */
    public function implodeArrayForUrlCanUrlEncodeKeyNames()
    {
        $input = array('one' => '√', '');
        $expected = '&foo%5Bone%5D=%E2%88%9A&foo%5B0%5D=';
        $this->assertSame($expected, GeneralUtility::implodeArrayForUrl('foo', $input, '', false, true));
    }

    /**
     * @test
     * @dataProvider implodeArrayForUrlDataProvider
     */
    public function explodeUrl2ArrayTransformsParameterStringToNestedArray($name, $array, $input)
    {
        $expected = $array ? array($name => $array) : array();
        $this->assertEquals($expected, GeneralUtility::explodeUrl2Array($input, true));
    }

    /**
     * @test
     * @dataProvider explodeUrl2ArrayDataProvider
     */
    public function explodeUrl2ArrayTransformsParameterStringToFlatArray($input, $expected)
    {
        $this->assertEquals($expected, GeneralUtility::explodeUrl2Array($input, false));
    }

    /**
     * Data provider for explodeUrl2ArrayTransformsParameterStringToFlatArray
     *
     * @return array
     */
    public function explodeUrl2ArrayDataProvider()
    {
        return array(
            'Empty string' => array('', array()),
            'Simple parameter string' => array('&one=%E2%88%9A&two=2', array('one' => '√', 'two' => 2)),
            'Nested parameter string' => array('&foo[one]=%E2%88%9A&two=2', array('foo[one]' => '√', 'two' => 2))
        );
    }

    //////////////////////////////////
    // Tests concerning compileSelectedGetVarsFromArray
    //////////////////////////////////
    /**
     * @test
     */
    public function compileSelectedGetVarsFromArrayFiltersIncomingData()
    {
        $filter = 'foo,bar';
        $getArray = array('foo' => 1, 'cake' => 'lie');
        $expected = array('foo' => 1);
        $result = GeneralUtility::compileSelectedGetVarsFromArray($filter, $getArray, false);
        $this->assertSame($expected, $result);
    }

    /**
     * @test
     */
    public function compileSelectedGetVarsFromArrayUsesGetPostDataFallback()
    {
        $_GET['bar'] = '2';
        $filter = 'foo,bar';
        $getArray = array('foo' => 1, 'cake' => 'lie');
        $expected = array('foo' => 1, 'bar' => '2');
        $result = GeneralUtility::compileSelectedGetVarsFromArray($filter, $getArray, true);
        $this->assertSame($expected, $result);
    }

    //////////////////////////////////
    // Tests concerning array_merge
    //////////////////////////////////
    /**
     * Test demonstrating array_merge. This is actually
     * a native PHP operator, therefore this test is mainly used to
     * show how this function can be used.
     *
     * @test
     */
    public function arrayMergeKeepsIndexesAfterMerge()
    {
        $array1 = array(10 => 'FOO', '20' => 'BAR');
        $array2 = array('5' => 'PLONK');
        $expected = array('5' => 'PLONK', 10 => 'FOO', '20' => 'BAR');
        $this->assertEquals($expected, GeneralUtility::array_merge($array1, $array2));
    }

    //////////////////////////////////
    // Tests concerning revExplode
    //////////////////////////////////

    /**
     * @return array
     */
    public function revExplodeDataProvider()
    {
        return array(
            'limit 0 should return unexploded string' => array(
                ':',
                'my:words:here',
                0,
                array('my:words:here')
            ),
            'limit 1 should return unexploded string' => array(
                ':',
                'my:words:here',
                1,
                array('my:words:here')
            ),
            'limit 2 should return two pieces' => array(
                ':',
                'my:words:here',
                2,
                array('my:words', 'here')
            ),
            'limit 3 should return unexploded string' => array(
                ':',
                'my:words:here',
                3,
                array('my', 'words', 'here')
            ),
            'limit 0 should return unexploded string if no delimiter is contained' => array(
                ':',
                'mywordshere',
                0,
                array('mywordshere')
            ),
            'limit 1 should return unexploded string if no delimiter is contained' => array(
                ':',
                'mywordshere',
                1,
                array('mywordshere')
            ),
            'limit 2 should return unexploded string if no delimiter is contained' => array(
                ':',
                'mywordshere',
                2,
                array('mywordshere')
            ),
            'limit 3 should return unexploded string if no delimiter is contained' => array(
                ':',
                'mywordshere',
                3,
                array('mywordshere')
            ),
            'multi character delimiter is handled properly with limit 2' => array(
                '[]',
                'a[b][c][d]',
                2,
                array('a[b][c', 'd]')
            ),
            'multi character delimiter is handled properly with limit 3' => array(
                '[]',
                'a[b][c][d]',
                3,
                array('a[b', 'c', 'd]')
            ),
        );
    }

    /**
     * @test
     * @dataProvider revExplodeDataProvider
     */
    public function revExplodeCorrectlyExplodesStringForGivenPartsCount($delimiter, $testString, $count, $expectedArray)
    {
        $actualArray = GeneralUtility::revExplode($delimiter, $testString, $count);
        $this->assertEquals($expectedArray, $actualArray);
    }

    /**
     * @test
     */
    public function revExplodeRespectsLimitThreeWhenExploding()
    {
        $testString = 'even:more:of:my:words:here';
        $expectedArray = array('even:more:of:my', 'words', 'here');
        $actualArray = GeneralUtility::revExplode(':', $testString, 3);
        $this->assertEquals($expectedArray, $actualArray);
    }

    //////////////////////////////////
    // Tests concerning trimExplode
    //////////////////////////////////
    /**
     * @test
     * @dataProvider trimExplodeReturnsCorrectResultDataProvider
     *
     * @param string $delimiter
     * @param string $testString
     * @param bool $removeEmpty
     * @param int $limit
     * @param array $expectedResult
     */
    public function trimExplodeReturnsCorrectResult($delimiter, $testString, $removeEmpty, $limit, $expectedResult)
    {
        $this->assertSame($expectedResult, GeneralUtility::trimExplode($delimiter, $testString, $removeEmpty, $limit));
    }

    /**
     * @return array
     */
    public function trimExplodeReturnsCorrectResultDataProvider()
    {
        return [
            'spaces at element start and end' => [
                ',',
                ' a , b , c ,d ,,  e,f,',
                false,
                0,
                ['a', 'b', 'c', 'd', '', 'e', 'f', '']
            ],
            'removes newline' => [
                ',',
                ' a , b , ' . LF . ' ,d ,,  e,f,',
                true,
                0,
                ['a', 'b', 'd', 'e', 'f']
            ],
            'removes empty elements' => [
                ',',
                'a , b , c , ,d ,, ,e,f,',
                true,
                0,
                ['a', 'b', 'c', 'd', 'e', 'f']
            ],
            'keeps remaining results with empty items after reaching limit with positive parameter' => [
                ',',
                ' a , b , c , , d,, ,e ',
                false,
                3,
                ['a', 'b', 'c , , d,, ,e']
            ],
            'keeps remaining results without empty items after reaching limit with positive parameter' => [
                ',',
                ' a , b , c , , d,, ,e ',
                true,
                3,
                ['a', 'b', 'c , d,e']
            ],
            'keeps remaining results with empty items after reaching limit with negative parameter' => [
                ',',
                ' a , b , c , d, ,e, f , , ',
                false,
                -3,
                ['a', 'b', 'c', 'd', '', 'e']
            ],
            'keeps remaining results without empty items after reaching limit with negative parameter' => [
                ',',
                ' a , b , c , d, ,e, f , , ',
                true,
                -3,
                ['a', 'b', 'c']
            ],
            'returns exact results without reaching limit with positive parameter' => [
                ',',
                ' a , b , , c , , , ',
                true,
                4,
                ['a', 'b', 'c']
            ],
            'keeps zero as string' => [
                ',',
                'a , b , c , ,d ,, ,e,f, 0 ,',
                true,
                0,
                ['a', 'b', 'c', 'd', 'e', 'f', '0']
            ],
            'keeps whitespace inside elements' => [
                ',',
                'a , b , c , ,d ,, ,e,f, g h ,',
                true,
                0,
                ['a', 'b', 'c', 'd', 'e', 'f', 'g h']
            ],
            'can use internal regex delimiter as explode delimiter' => [
                '/',
                'a / b / c / /d // /e/f/ g h /',
                true,
                0,
                ['a', 'b', 'c', 'd', 'e', 'f', 'g h']
            ],
            'can use whitespaces as delimiter' => [
                ' ',
                '* * * * *',
                true,
                0,
                ['*', '*', '*', '*', '*']
            ],
            'can use words as delimiter' => [
                'All',
                'HelloAllTogether',
                true,
                0,
                ['Hello', 'Together']
            ],
            'can use word with appended and prepended spaces as delimiter' => [
                ' all   ',
                'Hello all   together',
                true,
                0,
                ['Hello', 'together']
            ],
            'can use word with appended and prepended spaces as delimiter and do not remove empty' => [
                ' all   ',
                'Hello all   together     all      there all       all   are  all    none',
                false,
                0,
                ['Hello', 'together', 'there', '', 'are', 'none']
            ],
            'can use word with appended and prepended spaces as delimiter, do not remove empty and limit' => [
                ' all   ',
                'Hello all   together     all      there all       all   are  all    none',
                false,
                5,
                ['Hello', 'together', 'there', '', 'are  all    none']
            ],
            'can use word with appended and prepended spaces as delimiter, do not remove empty, limit and multiple delimiter in last' => [
                ' all   ',
                'Hello all   together     all      there all       all   are  all    none',
                false,
                4,
                ['Hello', 'together', 'there', 'all   are  all    none']
            ],
            'can use word with appended and prepended spaces as delimiter, remove empty and limit' => [
                ' all   ',
                'Hello all   together     all      there all       all   are  all    none',
                true,
                4,
                ['Hello', 'together', 'there', 'are  all    none']
            ],
            'can use word with appended and prepended spaces as delimiter, remove empty and limit and multiple delimiter in last' => [
                ' all   ',
                'Hello all   together     all      there all       all   are  all    none',
                true,
                5,
                ['Hello', 'together', 'there', 'are' ,'none']
            ],
            'can use words as delimiter and do not remove empty' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there   are   all  there     none',
                false,
                0,
                ['Hello', 'together', '', 'are', 'none']
            ],
            'can use words as delimiter, do not remove empty and limit' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there    are   all  there     none',
                false,
                4,
                ['Hello', 'together', '', 'are   all  there     none']
            ],
            'can use words as delimiter, do not remove empty, limit and multiple delimiter in last' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there    are   all  there     none',
                false,
                3,
                ['Hello', 'together', 'all  there    are   all  there     none']
            ],
            'can use words as delimiter, remove empty' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there    are   all  there     none',
                true,
                0,
                ['Hello', 'together', 'are', 'none']
            ],
            'can use words as delimiter, remove empty and limit' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there    are   all  there     none',
                true,
                3,
                ['Hello', 'together', 'are   all  there     none']
            ],
            'can use words as delimiter, remove empty and limit and multiple delimiter in last' => [
                'all  there',
                'Helloall  theretogether  all  there    all  there    are   all  there     none',
                true,
                4,
                ['Hello', 'together', 'are' , 'none']
            ],
            'can use new line as delimiter' => [
                LF,
                "Hello\nall\ntogether",
                true,
                0,
                ['Hello', 'all', 'together']
            ],
            'works with whitespace separator' => [
                "\t",
                " a  b \t c  \t  \t    d  \t  e     \t u j   \t s",
                false,
                0,
                ['a  b', 'c', '', 'd', 'e', 'u j', 's']
            ],
            'works with whitespace separator and limit' => [
                "\t",
                " a  b \t c  \t  \t    d  \t  e     \t u j   \t s",
                false,
                4,
                ['a  b', 'c', '', "d  \t  e     \t u j   \t s"]
            ],
            'works with whitespace separator and remove empty' => [
                "\t",
                " a  b \t c  \t  \t    d  \t  e     \t u j   \t s",
                true,
                0,
                ['a  b', 'c', 'd', 'e', 'u j', 's']
            ],
            'works with whitespace separator remove empty and limit' => [
                "\t",
                " a  b \t c  \t  \t    d  \t  e     \t u j   \t s",
                true,
                3,
                ['a  b', 'c', "d  \t  e     \t u j   \t s"]
            ],
        ];
    }

    //////////////////////////////////
    // Tests concerning getBytesFromSizeMeasurement
    //////////////////////////////////
    /**
     * Data provider for getBytesFromSizeMeasurement
     *
     * @return array expected value, input string
     */
    public function getBytesFromSizeMeasurementDataProvider()
    {
        return array(
            '100 kilo Bytes' => array('102400', '100k'),
            '100 mega Bytes' => array('104857600', '100m'),
            '100 giga Bytes' => array('107374182400', '100g')
        );
    }

    /**
     * @test
     * @dataProvider getBytesFromSizeMeasurementDataProvider
     */
    public function getBytesFromSizeMeasurementCalculatesCorrectByteValue($expected, $byteString)
    {
        $this->assertEquals($expected, GeneralUtility::getBytesFromSizeMeasurement($byteString));
    }

    //////////////////////////////////
    // Tests concerning getIndpEnv
    //////////////////////////////////
    /**
     * @test
     */
    public function getIndpEnvTypo3SitePathReturnNonEmptyString()
    {
        $this->assertTrue(strlen(GeneralUtility::getIndpEnv('TYPO3_SITE_PATH')) >= 1);
    }

    /**
     * @test
     */
    public function getIndpEnvTypo3SitePathReturnsStringStartingWithSlash()
    {
        if (TYPO3_OS === 'WIN') {
            $this->markTestSkipped('Test not available on Windows OS.');
        }
        $result = GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
        $this->assertEquals('/', $result[0]);
    }

    /**
     * @test
     */
    public function getIndpEnvTypo3SitePathReturnsStringStartingWithDrive()
    {
        if (TYPO3_OS !== 'WIN') {
            $this->markTestSkipped('Test available only on Windows OS.');
        }
        $result = GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
        $this->assertRegExp('/^[a-z]:\//i', $result);
    }

    /**
     * @test
     */
    public function getIndpEnvTypo3SitePathReturnsStringEndingWithSlash()
    {
        $result = GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
        $this->assertEquals('/', $result[strlen($result) - 1]);
    }

    /**
     * @return array
     */
    public static function hostnameAndPortDataProvider()
    {
        return array(
            'localhost ipv4 without port' => array('127.0.0.1', '127.0.0.1', ''),
            'localhost ipv4 with port' => array('127.0.0.1:81', '127.0.0.1', '81'),
            'localhost ipv6 without port' => array('[::1]', '[::1]', ''),
            'localhost ipv6 with port' => array('[::1]:81', '[::1]', '81'),
            'ipv6 without port' => array('[2001:DB8::1]', '[2001:DB8::1]', ''),
            'ipv6 with port' => array('[2001:DB8::1]:81', '[2001:DB8::1]', '81'),
            'hostname without port' => array('lolli.did.this', 'lolli.did.this', ''),
            'hostname with port' => array('lolli.did.this:42', 'lolli.did.this', '42')
        );
    }

    /**
     * @test
     * @dataProvider hostnameAndPortDataProvider
     */
    public function getIndpEnvTypo3HostOnlyParsesHostnamesAndIpAdresses($httpHost, $expectedIp)
    {
        GeneralUtility::flushInternalRuntimeCaches();
        $_SERVER['HTTP_HOST'] = $httpHost;
        $this->assertEquals($expectedIp, GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY'));
    }

    /**
     * @test
     */
    public function isAllowedHostHeaderValueReturnsFalseIfTrusedHostsIsNotConfigured()
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern']);
        $this->assertFalse(GeneralUtilityFixture::isAllowedHostHeaderValue('evil.foo.bar'));
    }

    /**
     * @return array
     */
    public static function hostnamesMatchingTrustedHostsConfigurationDataProvider()
    {
        return array(
            'hostname without port matching' => array('lolli.did.this', '.*\.did\.this'),
            'other hostname without port matching' => array('helmut.did.this', '.*\.did\.this'),
            'two different hostnames without port matching 1st host' => array('helmut.is.secure', '(helmut\.is\.secure|lolli\.is\.secure)'),
            'two different hostnames without port matching 2nd host' => array('lolli.is.secure', '(helmut\.is\.secure|lolli\.is\.secure)'),
            'hostname with port matching' => array('lolli.did.this:42', '.*\.did\.this:42'),
            'hostnames are case insensitive 1' => array('lolli.DID.this:42', '.*\.did.this:42'),
            'hostnames are case insensitive 2' => array('lolli.did.this:42', '.*\.DID.this:42'),
        );
    }

    /**
     * @return array
     */
    public static function hostnamesNotMatchingTrustedHostsConfigurationDataProvider()
    {
        return array(
            'hostname without port' => array('lolli.did.this', 'helmut\.did\.this'),
            'hostname with port, but port not allowed' => array('lolli.did.this:42', 'helmut\.did\.this'),
            'two different hostnames in pattern but host header starts with differnet value #1' => array('sub.helmut.is.secure', '(helmut\.is\.secure|lolli\.is\.secure)'),
            'two different hostnames in pattern but host header starts with differnet value #2' => array('sub.lolli.is.secure', '(helmut\.is\.secure|lolli\.is\.secure)'),
            'two different hostnames in pattern but host header ends with differnet value #1' => array('helmut.is.secure.tld', '(helmut\.is\.secure|lolli\.is\.secure)'),
            'two different hostnames in pattern but host header ends with differnet value #2' => array('lolli.is.secure.tld', '(helmut\.is\.secure|lolli\.is\.secure)'),
        );
    }

    /**
     * @param string $httpHost HTTP_HOST string
     * @param string $hostNamePattern trusted hosts pattern
     * @test
     * @dataProvider hostnamesMatchingTrustedHostsConfigurationDataProvider
     */
    public function isAllowedHostHeaderValueReturnsTrueIfHostValueMatches($httpHost, $hostNamePattern)
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = $hostNamePattern;
        $this->assertTrue(GeneralUtilityFixture::isAllowedHostHeaderValue($httpHost));
    }

    /**
     * @param string $httpHost HTTP_HOST string
     * @param string $hostNamePattern trusted hosts pattern
     * @test
     * @dataProvider hostnamesNotMatchingTrustedHostsConfigurationDataProvider
     */
    public function isAllowedHostHeaderValueReturnsFalseIfHostValueMatches($httpHost, $hostNamePattern)
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = $hostNamePattern;
        $this->assertFalse(GeneralUtilityFixture::isAllowedHostHeaderValue($httpHost));
    }

    public function serverNamePatternDataProvider()
    {
        return array(
            'host value matches server name and server port is default http' => array(
                'httpHost' => 'secure.web.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => true,
                'serverPort' => '80',
                'ssl' => 'Off',
            ),
            'host value matches server name if compared case insensitive 1' => array(
                'httpHost' => 'secure.web.server',
                'serverName' => 'secure.WEB.server',
                'isAllowed' => true,
            ),
            'host value matches server name if compared case insensitive 2' => array(
                'httpHost' => 'secure.WEB.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => true,
            ),
            'host value matches server name and server port is default https' => array(
                'httpHost' => 'secure.web.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => true,
                'serverPort' => '443',
                'ssl' => 'On',
            ),
            'host value matches server name and server port' => array(
                'httpHost' => 'secure.web.server:88',
                'serverName' => 'secure.web.server',
                'isAllowed' => true,
                'serverPort' => '88',
            ),
            'host value matches server name case insensitive 1 and server port' => array(
                'httpHost' => 'secure.WEB.server:88',
                'serverName' => 'secure.web.server',
                'isAllowed' => true,
                'serverPort' => '88',
            ),
            'host value matches server name case insensitive 2 and server port' => array(
                'httpHost' => 'secure.web.server:88',
                'serverName' => 'secure.WEB.server',
                'isAllowed' => true,
                'serverPort' => '88',
            ),
            'host value is ipv6 but matches server name and server port' => array(
                'httpHost' => '[::1]:81',
                'serverName' => '[::1]',
                'isAllowed' => true,
                'serverPort' => '81',
            ),
            'host value does not match server name' => array(
                'httpHost' => 'insecure.web.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => false,
            ),
            'host value does not match server port' => array(
                'httpHost' => 'secure.web.server:88',
                'serverName' => 'secure.web.server',
                'isAllowed' => false,
                'serverPort' => '89',
            ),
            'host value has default port that does not match server port' => array(
                'httpHost' => 'secure.web.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => false,
                'serverPort' => '81',
                'ssl' => 'Off',
            ),
            'host value has default port that does not match server ssl port' => array(
                'httpHost' => 'secure.web.server',
                'serverName' => 'secure.web.server',
                'isAllowed' => false,
                'serverPort' => '444',
                'ssl' => 'On',
            ),
        );
    }

    /**
     * @param string $httpHost
     * @param string $serverName
     * @param bool $isAllowed
     * @param string $serverPort
     * @param string $ssl
     *
     * @test
     * @dataProvider serverNamePatternDataProvider
     */
    public function isAllowedHostHeaderValueWorksCorrectlyWithWithServerNamePattern($httpHost, $serverName, $isAllowed, $serverPort = '80', $ssl = 'Off')
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = GeneralUtility::ENV_TRUSTED_HOSTS_PATTERN_SERVER_NAME;
        $_SERVER['SERVER_NAME'] = $serverName;
        $_SERVER['SERVER_PORT'] = $serverPort;
        $_SERVER['HTTPS'] = $ssl;
        $this->assertSame($isAllowed, GeneralUtilityFixture::isAllowedHostHeaderValue($httpHost));
    }

    /**
     * @test
     */
    public function allGetIndpEnvCallsRelatedToHostNamesCallIsAllowedHostHeaderValue()
    {
        GeneralUtilityFixture::getIndpEnv('HTTP_HOST');
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtilityFixture::getIndpEnv('TYPO3_HOST_ONLY');
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtilityFixture::getIndpEnv('TYPO3_REQUEST_HOST');
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtilityFixture::getIndpEnv('TYPO3_REQUEST_URL');
        $this->assertSame(4, GeneralUtilityFixture::$isAllowedHostHeaderValueCallCount);
    }

    /**
     * @param string $httpHost HTTP_HOST string
     * @param string $hostNamePattern trusted hosts pattern
     * @test
     * @dataProvider hostnamesNotMatchingTrustedHostsConfigurationDataProvider
     * @expectedException \UnexpectedValueException
     * @expectedExceptionCode 1396795884
     */
    public function getIndpEnvForHostThrowsExceptionForNotAllowedHostnameValues($httpHost, $hostNamePattern)
    {
        $_SERVER['HTTP_HOST'] = $httpHost;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = $hostNamePattern;
        GeneralUtilityFixture::getIndpEnv('HTTP_HOST');
    }

    /**
     * @param string $httpHost HTTP_HOST string
     * @param string $hostNamePattern trusted hosts pattern (not used in this test currently)
     * @test
     * @dataProvider hostnamesNotMatchingTrustedHostsConfigurationDataProvider
     */
    public function getIndpEnvForHostAllowsAllHostnameValuesIfHostPatternIsSetToAllowAll($httpHost, $hostNamePattern)
    {
        $_SERVER['HTTP_HOST'] = $httpHost;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = GeneralUtility::ENV_TRUSTED_HOSTS_PATTERN_ALLOW_ALL;
        $this->assertSame($httpHost, GeneralUtility::getIndpEnv('HTTP_HOST'));
    }

    /**
     * @test
     * @dataProvider hostnameAndPortDataProvider
     */
    public function getIndpEnvTypo3PortParsesHostnamesAndIpAdresses($httpHost, $dummy, $expectedPort)
    {
        $_SERVER['HTTP_HOST'] = $httpHost;
        $this->assertEquals($expectedPort, GeneralUtility::getIndpEnv('TYPO3_PORT'));
    }

    //////////////////////////////////
    // Tests concerning underscoredToUpperCamelCase
    //////////////////////////////////
    /**
     * Data provider for underscoredToUpperCamelCase
     *
     * @return array expected, input string
     */
    public function underscoredToUpperCamelCaseDataProvider()
    {
        return array(
            'single word' => array('Blogexample', 'blogexample'),
            'multiple words' => array('BlogExample', 'blog_example')
        );
    }

    /**
     * @test
     * @dataProvider underscoredToUpperCamelCaseDataProvider
     */
    public function underscoredToUpperCamelCase($expected, $inputString)
    {
        $this->assertEquals($expected, GeneralUtility::underscoredToUpperCamelCase($inputString));
    }

    //////////////////////////////////
    // Tests concerning underscoredToLowerCamelCase
    //////////////////////////////////
    /**
     * Data provider for underscoredToLowerCamelCase
     *
     * @return array expected, input string
     */
    public function underscoredToLowerCamelCaseDataProvider()
    {
        return array(
            'single word' => array('minimalvalue', 'minimalvalue'),
            'multiple words' => array('minimalValue', 'minimal_value')
        );
    }

    /**
     * @test
     * @dataProvider underscoredToLowerCamelCaseDataProvider
     */
    public function underscoredToLowerCamelCase($expected, $inputString)
    {
        $this->assertEquals($expected, GeneralUtility::underscoredToLowerCamelCase($inputString));
    }

    //////////////////////////////////
    // Tests concerning camelCaseToLowerCaseUnderscored
    //////////////////////////////////
    /**
     * Data provider for camelCaseToLowerCaseUnderscored
     *
     * @return array expected, input string
     */
    public function camelCaseToLowerCaseUnderscoredDataProvider()
    {
        return array(
            'single word' => array('blogexample', 'blogexample'),
            'single word starting upper case' => array('blogexample', 'Blogexample'),
            'two words starting lower case' => array('minimal_value', 'minimalValue'),
            'two words starting upper case' => array('blog_example', 'BlogExample')
        );
    }

    /**
     * @test
     * @dataProvider camelCaseToLowerCaseUnderscoredDataProvider
     */
    public function camelCaseToLowerCaseUnderscored($expected, $inputString)
    {
        $this->assertEquals($expected, GeneralUtility::camelCaseToLowerCaseUnderscored($inputString));
    }

    //////////////////////////////////
    // Tests concerning lcFirst
    //////////////////////////////////
    /**
     * Data provider for lcFirst
     *
     * @return array expected, input string
     */
    public function lcfirstDataProvider()
    {
        return array(
            'single word' => array('blogexample', 'blogexample'),
            'single Word starting upper case' => array('blogexample', 'Blogexample'),
            'two words' => array('blogExample', 'BlogExample')
        );
    }

    /**
     * @test
     * @dataProvider lcfirstDataProvider
     */
    public function lcFirst($expected, $inputString)
    {
        $this->assertEquals($expected, GeneralUtility::lcfirst($inputString));
    }

    //////////////////////////////////
    // Tests concerning encodeHeader
    //////////////////////////////////
    /**
     * @test
     */
    public function encodeHeaderEncodesWhitespacesInQuotedPrintableMailHeader()
    {
        $this->assertEquals('=?utf-8?Q?We_test_whether_the_copyright_character_=C2=A9_is_encoded_correctly?=', GeneralUtility::encodeHeader('We test whether the copyright character © is encoded correctly', 'quoted-printable', 'utf-8'));
    }

    /**
     * @test
     */
    public function encodeHeaderEncodesQuestionmarksInQuotedPrintableMailHeader()
    {
        $this->assertEquals('=?utf-8?Q?Is_the_copyright_character_=C2=A9_really_encoded_correctly=3F_Really=3F?=', GeneralUtility::encodeHeader('Is the copyright character © really encoded correctly? Really?', 'quoted-printable', 'utf-8'));
    }

    //////////////////////////////////
    // Tests concerning isValidUrl
    //////////////////////////////////
    /**
     * Data provider for valid isValidUrl's
     *
     * @return array Valid resource
     */
    public function validUrlValidResourceDataProvider()
    {
        return array(
            'http' => array('http://www.example.org/'),
            'http without trailing slash' => array('http://qwe'),
            'http directory with trailing slash' => array('http://www.example/img/dir/'),
            'http directory without trailing slash' => array('http://www.example/img/dir'),
            'http index.html' => array('http://example.com/index.html'),
            'http index.php' => array('http://www.example.com/index.php'),
            'http test.png' => array('http://www.example/img/test.png'),
            'http username password querystring and ancher' => array('https://user:pw@www.example.org:80/path?arg=value#fragment'),
            'file' => array('file:///tmp/test.c'),
            'file directory' => array('file://foo/bar'),
            'ftp directory' => array('ftp://ftp.example.com/tmp/'),
            'mailto' => array('mailto:foo@bar.com'),
            'news' => array('news:news.php.net'),
            'telnet' => array('telnet://192.0.2.16:80/'),
            'ldap' => array('ldap://[2001:db8::7]/c=GB?objectClass?one'),
            'http punycode domain name' => array('http://www.xn--bb-eka.at'),
            'http punicode subdomain' => array('http://xn--h-zfa.oebb.at'),
            'http domain-name umlauts' => array('http://www.öbb.at'),
            'http subdomain umlauts' => array('http://äh.oebb.at'),
        );
    }

    /**
     * @test
     * @dataProvider validUrlValidResourceDataProvider
     */
    public function validURLReturnsTrueForValidResource($url)
    {
        $this->assertTrue(GeneralUtility::isValidUrl($url));
    }

    /**
     * Data provider for invalid isValidUrl's
     *
     * @return array Invalid ressource
     */
    public function isValidUrlInvalidRessourceDataProvider()
    {
        return array(
            'http missing colon' => array('http//www.example/wrong/url/'),
            'http missing slash' => array('http:/www.example'),
            'hostname only' => array('www.example.org/'),
            'file missing protocol specification' => array('/tmp/test.c'),
            'slash only' => array('/'),
            'string http://' => array('http://'),
            'string http:/' => array('http:/'),
            'string http:' => array('http:'),
            'string http' => array('http'),
            'empty string' => array(''),
            'string -1' => array('-1'),
            'string array()' => array('array()'),
            'random string' => array('qwe'),
            'http directory umlauts' => array('http://www.oebb.at/äöü/'),
        );
    }

    /**
     * @test
     * @dataProvider isValidUrlInvalidRessourceDataProvider
     */
    public function validURLReturnsFalseForInvalidRessoure($url)
    {
        $this->assertFalse(GeneralUtility::isValidUrl($url));
    }

    //////////////////////////////////
    // Tests concerning isOnCurrentHost
    //////////////////////////////////
    /**
     * @test
     */
    public function isOnCurrentHostReturnsTrueWithCurrentHost()
    {
        $testUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        $this->assertTrue(GeneralUtility::isOnCurrentHost($testUrl));
    }

    /**
     * Data provider for invalid isOnCurrentHost's
     *
     * @return array Invalid Hosts
     */
    public function checkisOnCurrentHostInvalidHosts()
    {
        return array(
            'empty string' => array(''),
            'arbitrary string' => array('arbitrary string'),
            'localhost IP' => array('127.0.0.1'),
            'relative path' => array('./relpath/file.txt'),
            'absolute path' => array('/abspath/file.txt?arg=value'),
            'differnt host' => array(GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . '.example.org')
        );
    }

    ////////////////////////////////////////
    // Tests concerning sanitizeLocalUrl
    ////////////////////////////////////////
    /**
     * Data provider for valid sanitizeLocalUrl paths
     *
     * @return array Valid url
     */
    public function sanitizeLocalUrlValidPathsDataProvider()
    {
        return array(
            'alt_intro.php' => array('alt_intro.php'),
            'alt_intro.php?foo=1&bar=2' => array('alt_intro.php?foo=1&bar=2'),
            '../index.php' => array('../index.php'),
            '../typo3/alt_intro.php' => array('../typo3/alt_intro.php'),
            '../~userDirectory/index.php' => array('../~userDirectory/index.php'),
            '../typo3/index.php?var1=test-case&var2=~user' => array('../typo3/index.php?var1=test-case&var2=~user'),
            PATH_site . 'typo3/alt_intro.php' => array(PATH_site . 'typo3/alt_intro.php'),
        );
    }

    /**
     * @test
     * @param string $path
     * @dataProvider sanitizeLocalUrlValidPathsDataProvider
     */
    public function sanitizeLocalUrlAcceptsNotEncodedValidPaths($path)
    {
        $this->assertEquals($path, GeneralUtility::sanitizeLocalUrl($path));
    }

    /**
     * @test
     * @param string $path
     * @dataProvider sanitizeLocalUrlValidPathsDataProvider
     */
    public function sanitizeLocalUrlAcceptsEncodedValidPaths($path)
    {
        $this->assertEquals(rawurlencode($path), GeneralUtility::sanitizeLocalUrl(rawurlencode($path)));
    }

    /**
     * Data provider for valid sanitizeLocalUrl's
     *
     * @return array Valid url
     */
    public function sanitizeLocalUrlValidUrlsDataProvider()
    {
        $host = 'localhost';
        $subDirectory = '/cms/';

        return array(
            $subDirectory . 'typo3/alt_intro.php' => array(
                $subDirectory . 'typo3/alt_intro.php',
                $host,
                $subDirectory,
            ),
            $subDirectory . 'index.php' => array(
                $subDirectory . 'index.php',
                $host,
                $subDirectory,
            ),
            'http://' . $host . '/typo3/alt_intro.php' => array(
                'http://' . $host . '/typo3/alt_intro.php',
                $host,
                '',
            ),
            'http://' . $host . $subDirectory . 'typo3/alt_intro.php' => array(
                'http://' . $host . $subDirectory . 'typo3/alt_intro.php',
                $host,
                $subDirectory,
            ),
        );
    }

    /**
     * @test
     * @param string $url
     * @param string $host
     * @param string $subDirectory
     * @dataProvider sanitizeLocalUrlValidUrlsDataProvider
     */
    public function sanitizeLocalUrlAcceptsNotEncodedValidUrls($url, $host, $subDirectory)
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SCRIPT_NAME'] = $subDirectory . 'typo3/index.php';
        GeneralUtility::flushInternalRuntimeCaches();
        $this->assertEquals($url, GeneralUtility::sanitizeLocalUrl($url));
    }

    /**
     * @test
     * @param string $url
     * @param string $host
     * @param string $subDirectory
     * @dataProvider sanitizeLocalUrlValidUrlsDataProvider
     */
    public function sanitizeLocalUrlAcceptsEncodedValidUrls($url, $host, $subDirectory)
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SCRIPT_NAME'] = $subDirectory . 'typo3/index.php';
        GeneralUtility::flushInternalRuntimeCaches();
        $this->assertEquals(rawurlencode($url), GeneralUtility::sanitizeLocalUrl(rawurlencode($url)));
    }

    /**
     * Data provider for invalid sanitizeLocalUrl's
     *
     * @return array Valid url
     */
    public function sanitizeLocalUrlInvalidDataProvider()
    {
        return array(
            'empty string' => array(''),
            'http domain' => array('http://www.google.de/'),
            'https domain' => array('https://www.google.de/'),
            'relative path with XSS' => array('../typo3/whatever.php?argument=javascript:alert(0)'),
            'base64 encoded string' => array('data:%20text/html;base64,PHNjcmlwdD5hbGVydCgnWFNTJyk8L3NjcmlwdD4='),
        );
    }

    /**
     * @test
     * @dataProvider sanitizeLocalUrlInvalidDataProvider
     */
    public function sanitizeLocalUrlDeniesPlainInvalidUrls($url)
    {
        $this->assertEquals('', GeneralUtility::sanitizeLocalUrl($url));
    }

    /**
     * @test
     * @dataProvider sanitizeLocalUrlInvalidDataProvider
     */
    public function sanitizeLocalUrlDeniesEncodedInvalidUrls($url)
    {
        $this->assertEquals('', GeneralUtility::sanitizeLocalUrl(rawurlencode($url)));
    }

    ////////////////////////////////////////
    // Tests concerning unlink_tempfile
    ////////////////////////////////////////

    /**
     * @test
     */
    public function unlink_tempfileRemovesValidFileInTypo3temp()
    {
        $fixtureFile = __DIR__ . '/Fixtures/clear.gif';
        $testFilename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_') . '.gif';
        @copy($fixtureFile, $testFilename);
        GeneralUtility::unlink_tempfile($testFilename);
        $fileExists = file_exists($testFilename);
        $this->assertFalse($fileExists);
    }

    /**
     * @test
     */
    public function unlink_tempfileRemovesHiddenFile()
    {
        $fixtureFile = __DIR__ . '/Fixtures/clear.gif';
        $testFilename = PATH_site . 'typo3temp/' . $this->getUniqueId('.test_') . '.gif';
        @copy($fixtureFile, $testFilename);
        GeneralUtility::unlink_tempfile($testFilename);
        $fileExists = file_exists($testFilename);
        $this->assertFalse($fileExists);
    }

    /**
     * @test
     */
    public function unlink_tempfileReturnsTrueIfFileWasRemoved()
    {
        $fixtureFile = __DIR__ . '/Fixtures/clear.gif';
        $testFilename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_') . '.gif';
        @copy($fixtureFile, $testFilename);
        $returnValue = GeneralUtility::unlink_tempfile($testFilename);
        $this->assertTrue($returnValue);
    }

    /**
     * @test
     */
    public function unlink_tempfileReturnsNullIfFileDoesNotExist()
    {
        $returnValue = GeneralUtility::unlink_tempfile(PATH_site . 'typo3temp/' . $this->getUniqueId('i_do_not_exist'));
        $this->assertNull($returnValue);
    }

    /**
     * @test
     */
    public function unlink_tempfileReturnsNullIfFileIsNowWithinTypo3temp()
    {
        $returnValue = GeneralUtility::unlink_tempfile('/tmp/typo3-unit-test-unlink_tempfile');
        $this->assertNull($returnValue);
    }

    //////////////////////////////////////
    // Tests concerning tempnam
    //////////////////////////////////////

    /**
     * @test
     */
    public function tempnamReturnsPathStartingWithGivenPrefix()
    {
        $filePath = GeneralUtility::tempnam('foo');
        $fileName = basename($filePath);
        $this->assertStringStartsWith('foo', $fileName);
    }

    /**
     * @test
     */
    public function tempnamReturnsPathWithoutBackslashes()
    {
        $filePath = GeneralUtility::tempnam('foo');
        $this->assertNotContains('\\', $filePath);
    }

    /**
     * @test
     */
    public function tempnamReturnsAbsolutePathInsideDocumentRoot()
    {
        $filePath = GeneralUtility::tempnam('foo');
        $this->assertStringStartsWith(PATH_site, $filePath);
    }

    //////////////////////////////////////
    // Tests concerning addSlashesOnArray
    //////////////////////////////////////
    /**
     * @test
     */
    public function addSlashesOnArrayAddsSlashesRecursive()
    {
        $inputArray = array(
            'key1' => array(
                'key11' => 'val\'ue1',
                'key12' => 'val"ue2'
            ),
            'key2' => 'val\\ue3'
        );
        $expectedResult = array(
            'key1' => array(
                'key11' => 'val\\\'ue1',
                'key12' => 'val\\"ue2'
            ),
            'key2' => 'val\\\\ue3'
        );
        GeneralUtility::addSlashesOnArray($inputArray);
        $this->assertEquals($expectedResult, $inputArray);
    }

    //////////////////////////////////////
    // Tests concerning addSlashesOnArray
    //////////////////////////////////////
    /**
     * @test
     */
    public function stripSlashesOnArrayStripsSlashesRecursive()
    {
        $inputArray = array(
            'key1' => array(
                'key11' => 'val\\\'ue1',
                'key12' => 'val\\"ue2'
            ),
            'key2' => 'val\\\\ue3'
        );
        $expectedResult = array(
            'key1' => array(
                'key11' => 'val\'ue1',
                'key12' => 'val"ue2'
            ),
            'key2' => 'val\\ue3'
        );
        GeneralUtility::stripSlashesOnArray($inputArray);
        $this->assertEquals($expectedResult, $inputArray);
    }

    //////////////////////////////////////
    // Tests concerning removeDotsFromTS
    //////////////////////////////////////
    /**
     * @test
     */
    public function removeDotsFromTypoScriptSucceedsWithDottedArray()
    {
        $typoScript = array(
            'propertyA.' => array(
                'keyA.' => array(
                    'valueA' => 1
                ),
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $expectedResult = array(
            'propertyA' => array(
                'keyA' => array(
                    'valueA' => 1
                ),
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $this->assertEquals($expectedResult, GeneralUtility::removeDotsFromTS($typoScript));
    }

    /**
     * @test
     */
    public function removeDotsFromTypoScriptOverridesSubArray()
    {
        $typoScript = array(
            'propertyA.' => array(
                'keyA' => 'getsOverridden',
                'keyA.' => array(
                    'valueA' => 1
                ),
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $expectedResult = array(
            'propertyA' => array(
                'keyA' => array(
                    'valueA' => 1
                ),
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $this->assertEquals($expectedResult, GeneralUtility::removeDotsFromTS($typoScript));
    }

    /**
     * @test
     */
    public function removeDotsFromTypoScriptOverridesWithScalar()
    {
        $typoScript = array(
            'propertyA.' => array(
                'keyA.' => array(
                    'valueA' => 1
                ),
                'keyA' => 'willOverride',
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $expectedResult = array(
            'propertyA' => array(
                'keyA' => 'willOverride',
                'keyB' => 2
            ),
            'propertyB' => 3
        );
        $this->assertEquals($expectedResult, GeneralUtility::removeDotsFromTS($typoScript));
    }

    //////////////////////////////////////
    // Tests concerning get_dirs
    //////////////////////////////////////
    /**
     * @test
     */
    public function getDirsReturnsArrayOfDirectoriesFromGivenDirectory()
    {
        $path = PATH_typo3conf;
        $directories = GeneralUtility::get_dirs($path);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_ARRAY, $directories);
    }

    /**
     * @test
     */
    public function getDirsReturnsStringErrorOnPathFailure()
    {
        $path = 'foo';
        $result = GeneralUtility::get_dirs($path);
        $expectedResult = 'error';
        $this->assertEquals($expectedResult, $result);
    }

    //////////////////////////////////
    // Tests concerning hmac
    //////////////////////////////////
    /**
     * @test
     */
    public function hmacReturnsHashOfProperLength()
    {
        $hmac = GeneralUtility::hmac('message');
        $this->assertTrue(!empty($hmac) && is_string($hmac));
        $this->assertTrue(strlen($hmac) == 40);
    }

    /**
     * @test
     */
    public function hmacReturnsEqualHashesForEqualInput()
    {
        $msg0 = 'message';
        $msg1 = 'message';
        $this->assertEquals(GeneralUtility::hmac($msg0), GeneralUtility::hmac($msg1));
    }

    /**
     * @test
     */
    public function hmacReturnsNoEqualHashesForNonEqualInput()
    {
        $msg0 = 'message0';
        $msg1 = 'message1';
        $this->assertNotEquals(GeneralUtility::hmac($msg0), GeneralUtility::hmac($msg1));
    }

    //////////////////////////////////
    // Tests concerning quoteJSvalue
    //////////////////////////////////
    /**
     * Data provider for quoteJSvalueTest.
     *
     * @return array
     */
    public function quoteJsValueDataProvider()
    {
        return array(
            'Immune characters are returned as is' => array(
                '._,',
                '._,'
            ),
            'Alphanumerical characters are returned as is' => array(
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
            ),
            'Angle brackets and ampersand are encoded' => array(
                '<>&',
                '\\u003C\\u003E\\u0026'
            ),
            'Quotes and backslashes are encoded' => array(
                '"\'\\',
                '\\u0022\\u0027\\u005C'
            ),
            'Forward slashes are escaped' => array(
                '</script>',
                '\\u003C\\/script\\u003E'
            ),
            'Empty string stays empty' => array(
                '',
                ''
            ),
            'Exclamation mark and space are properly encoded' => array(
                'Hello World!',
                'Hello\\u0020World\\u0021'
            ),
            'Whitespaces are properly encoded' => array(
                TAB . LF . CR . ' ',
                '\\u0009\\u000A\\u000D\\u0020'
            ),
            'Null byte is properly encoded' => array(
                chr(0),
                '\\u0000'
            ),
            'Umlauts are properly encoded' => array(
                'ÜüÖöÄä',
                '\\u00dc\\u00fc\\u00d6\\u00f6\\u00c4\\u00e4'
            )
        );
    }

    /**
     * @test
     * @param string $input
     * @param string $expected
     * @dataProvider quoteJsValueDataProvider
     */
    public function quoteJsValueTest($input, $expected)
    {
        $this->assertSame('\'' . $expected . '\'', GeneralUtility::quoteJSvalue($input));
    }

    ///////////////////////////////
    // Tests concerning _GETset()
    ///////////////////////////////
    /**
     * @test
     */
    public function getSetWritesArrayToGetSystemVariable()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        $getParameters = array('foo' => 'bar');
        GeneralUtility::_GETset($getParameters);
        $this->assertSame($getParameters, $_GET);
    }

    /**
     * @test
     */
    public function getSetWritesArrayToGlobalsHttpGetVars()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        $getParameters = array('foo' => 'bar');
        GeneralUtility::_GETset($getParameters);
        $this->assertSame($getParameters, $GLOBALS['HTTP_GET_VARS']);
    }

    /**
     * @test
     */
    public function getSetForArrayDropsExistingValues()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset(array('foo' => 'bar'));
        GeneralUtility::_GETset(array('oneKey' => 'oneValue'));
        $this->assertEquals(array('oneKey' => 'oneValue'), $GLOBALS['HTTP_GET_VARS']);
    }

    /**
     * @test
     */
    public function getSetAssignsOneValueToOneKey()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset('oneValue', 'oneKey');
        $this->assertEquals('oneValue', $GLOBALS['HTTP_GET_VARS']['oneKey']);
    }

    /**
     * @test
     */
    public function getSetForOneValueDoesNotDropUnrelatedValues()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset(array('foo' => 'bar'));
        GeneralUtility::_GETset('oneValue', 'oneKey');
        $this->assertEquals(array('foo' => 'bar', 'oneKey' => 'oneValue'), $GLOBALS['HTTP_GET_VARS']);
    }

    /**
     * @test
     */
    public function getSetCanAssignsAnArrayToASpecificArrayElement()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset(array('childKey' => 'oneValue'), 'parentKey');
        $this->assertEquals(array('parentKey' => array('childKey' => 'oneValue')), $GLOBALS['HTTP_GET_VARS']);
    }

    /**
     * @test
     */
    public function getSetCanAssignAStringValueToASpecificArrayChildElement()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset('oneValue', 'parentKey|childKey');
        $this->assertEquals(array('parentKey' => array('childKey' => 'oneValue')), $GLOBALS['HTTP_GET_VARS']);
    }

    /**
     * @test
     */
    public function getSetCanAssignAnArrayToASpecificArrayChildElement()
    {
        $_GET = array();
        $GLOBALS['HTTP_GET_VARS'] = array();
        GeneralUtility::_GETset(array('key1' => 'value1', 'key2' => 'value2'), 'parentKey|childKey');
        $this->assertEquals(array(
            'parentKey' => array(
                'childKey' => array('key1' => 'value1', 'key2' => 'value2')
            )
        ), $GLOBALS['HTTP_GET_VARS']);
    }

    ///////////////////////////
    // Tests concerning minifyJavaScript
    ///////////////////////////
    /**
     * @test
     */
    public function minifyJavaScriptReturnsInputStringIfNoHookIsRegistered()
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['minifyJavaScript']);
        $testString = $this->getUniqueId('string');
        $this->assertSame($testString, GeneralUtility::minifyJavaScript($testString));
    }

    /**
     * Create an own hook callback class, register as hook, and check
     * if given string to compress is given to hook method
     *
     * @test
     */
    public function minifyJavaScriptCallsRegisteredHookWithInputString()
    {
        $hookClassName = $this->getUniqueId('tx_coretest');
        $minifyHookMock = $this->getMock('stdClass', array('minify'), array(), $hookClassName);
        $functionName = $hookClassName . '->minify';
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName] = array();
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['obj'] = $minifyHookMock;
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['method'] = 'minify';
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['minifyJavaScript'][] = $functionName;
        $minifyHookMock->expects($this->once())->method('minify')->will($this->returnCallback(array($this, 'isMinifyJavaScriptHookCalledCallback')));
        GeneralUtility::minifyJavaScript('foo');
    }

    /**
     * Callback function used in minifyJavaScriptCallsRegisteredHookWithInputString test
     *
     * @param array $params
     */
    public function isMinifyJavaScriptHookCalledCallback(array $params)
    {
        // We can not throw an exception here, because that would be caught by the
        // minifyJavaScript method under test itself. Thus, we just die if the
        // input string is not ok.
        if ($params['script'] !== 'foo') {
            die('broken');
        }
    }

    /**
     * Create a hook callback, use callback to throw an exception and check
     * if the exception is given as error parameter to the calling method.
     *
     * @test
     */
    public function minifyJavaScriptReturnsErrorStringOfHookException()
    {
        $hookClassName = $this->getUniqueId('tx_coretest');
        $minifyHookMock = $this->getMock('stdClass', array('minify'), array(), $hookClassName);
        $functionName = '&' . $hookClassName . '->minify';
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName] = array();
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['obj'] = $minifyHookMock;
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['method'] = 'minify';
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['minifyJavaScript'][] = $functionName;
        $minifyHookMock->expects($this->any())->method('minify')->will($this->returnCallback(array($this, 'minifyJavaScriptErroneousCallback')));
        $error = '';
        GeneralUtility::minifyJavaScript('string to compress', $error);
        $this->assertSame('Error minifying java script: foo', $error);
    }

    /**
     * Check if the error message that is returned by the hook callback
     * is logged to \TYPO3\CMS\Core\GeneralUtility::devLog.
     *
     * @test
     */
    public function minifyJavaScriptWritesExceptionMessageToDevLog()
    {
        $hookClassName = $this->getUniqueId('tx_coretest');
        $minifyHookMock = $this->getMock('stdClass', array('minify'), array(), $hookClassName);
        $functionName = '&' . $hookClassName . '->minify';
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName] = array();
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['obj'] = $minifyHookMock;
        $GLOBALS['T3_VAR']['callUserFunction'][$functionName]['method'] = 'minify';
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['minifyJavaScript'][] = $functionName;
        $minifyHookMock->expects($this->any())->method('minify')->will($this->returnCallback(array($this, 'minifyJavaScriptErroneousCallback')));
        $this->setExpectedException('\\RuntimeException');
        GeneralUtilityMinifyJavaScriptFixture::minifyJavaScript('string to compress');
    }

    /**
     * Callback function used in
     * minifyJavaScriptReturnsErrorStringOfHookException and
     * minifyJavaScriptWritesExceptionMessageToDevLog
     *
     * @throws \RuntimeException
     */
    public function minifyJavaScriptErroneousCallback()
    {
        throw new \RuntimeException('foo', 1344888548);
    }

    ///////////////////////////////
    // Tests concerning fixPermissions
    ///////////////////////////////
    /**
     * @test
     */
    public function fixPermissionsSetsGroup()
    {
        if (TYPO3_OS === 'WIN') {
            $this->markTestSkipped('fixPermissionsSetsGroup() tests not available on Windows');
        }
        if (!function_exists('posix_getegid')) {
            $this->markTestSkipped('Function posix_getegid() not available, fixPermissionsSetsGroup() tests skipped');
        }
        if (posix_getegid() === -1) {
            $this->markTestSkipped('The fixPermissionsSetsGroup() is not available on Mac OS because posix_getegid() always returns -1 on Mac OS.');
        }
        // Create and prepare test file
        $filename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::writeFileToTypo3tempDir($filename, '42');
        $this->testFilesToDelete[] = $filename;
        $currentGroupId = posix_getegid();
        // Set target group and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['createGroup'] = $currentGroupId;
        GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->assertEquals($currentGroupId, filegroup($filename));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsToFile()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test file
        $filename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::writeFileToTypo3tempDir($filename, '42');
        $this->testFilesToDelete[] = $filename;
        chmod($filename, 482);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0660';
        $fixPermissionsResult = GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0660', substr(decoct(fileperms($filename)), 2));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsToHiddenFile()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test file
        $filename = PATH_site . 'typo3temp/' . $this->getUniqueId('.test_');
        GeneralUtility::writeFileToTypo3tempDir($filename, '42');
        $this->testFilesToDelete[] = $filename;
        chmod($filename, 482);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0660';
        $fixPermissionsResult = GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0660', substr(decoct(fileperms($filename)), 2));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsToDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test directory
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        chmod($directory, 1551);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0770';
        $fixPermissionsResult = GeneralUtility::fixPermissions($directory);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0770', substr(decoct(fileperms($directory)), 1));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsToDirectoryWithTrailingSlash()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test directory
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        chmod($directory, 1551);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0770';
        $fixPermissionsResult = GeneralUtility::fixPermissions($directory . '/');
        // Get actual permissions and clean up
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0770', substr(decoct(fileperms($directory)), 1));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsToHiddenDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test directory
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('.test_');
        GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        chmod($directory, 1551);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0770';
        $fixPermissionsResult = GeneralUtility::fixPermissions($directory);
        // Get actual permissions and clean up
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0770', substr(decoct(fileperms($directory)), 1));
    }

    /**
     * @test
     */
    public function fixPermissionsCorrectlySetsPermissionsRecursive()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test directory and file structure
        $baseDirectory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::mkdir($baseDirectory);
        $this->testFilesToDelete[] = $baseDirectory;
        chmod($baseDirectory, 1751);
        GeneralUtility::writeFileToTypo3tempDir($baseDirectory . '/file', '42');
        chmod($baseDirectory . '/file', 482);
        GeneralUtility::mkdir($baseDirectory . '/foo');
        chmod($baseDirectory . '/foo', 1751);
        GeneralUtility::writeFileToTypo3tempDir($baseDirectory . '/foo/file', '42');
        chmod($baseDirectory . '/foo/file', 482);
        GeneralUtility::mkdir($baseDirectory . '/.bar');
        chmod($baseDirectory . '/.bar', 1751);
        // Use this if writeFileToTypo3tempDir is fixed to create hidden files in subdirectories
        // \TYPO3\CMS\Core\Utility\GeneralUtility::writeFileToTypo3tempDir($baseDirectory . '/.bar/.file', '42');
        // \TYPO3\CMS\Core\Utility\GeneralUtility::writeFileToTypo3tempDir($baseDirectory . '/.bar/..file2', '42');
        touch($baseDirectory . '/.bar/.file', '42');
        chmod($baseDirectory . '/.bar/.file', 482);
        touch($baseDirectory . '/.bar/..file2', '42');
        chmod($baseDirectory . '/.bar/..file2', 482);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0660';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0770';
        $fixPermissionsResult = GeneralUtility::fixPermissions($baseDirectory, true);
        // Get actual permissions
        clearstatcache();
        $resultBaseDirectoryPermissions = substr(decoct(fileperms($baseDirectory)), 1);
        $resultBaseFilePermissions = substr(decoct(fileperms($baseDirectory . '/file')), 2);
        $resultFooDirectoryPermissions = substr(decoct(fileperms($baseDirectory . '/foo')), 1);
        $resultFooFilePermissions = substr(decoct(fileperms($baseDirectory . '/foo/file')), 2);
        $resultBarDirectoryPermissions = substr(decoct(fileperms($baseDirectory . '/.bar')), 1);
        $resultBarFilePermissions = substr(decoct(fileperms($baseDirectory . '/.bar/.file')), 2);
        $resultBarFile2Permissions = substr(decoct(fileperms($baseDirectory . '/.bar/..file2')), 2);
        // Test if everything was ok
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0770', $resultBaseDirectoryPermissions);
        $this->assertEquals('0660', $resultBaseFilePermissions);
        $this->assertEquals('0770', $resultFooDirectoryPermissions);
        $this->assertEquals('0660', $resultFooFilePermissions);
        $this->assertEquals('0770', $resultBarDirectoryPermissions);
        $this->assertEquals('0660', $resultBarFilePermissions);
        $this->assertEquals('0660', $resultBarFile2Permissions);
    }

    /**
     * @test
     */
    public function fixPermissionsDoesNotSetPermissionsToNotAllowedPath()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        // Create and prepare test file
        $filename = PATH_site . 'typo3temp/../typo3temp/' . $this->getUniqueId('test_');
        touch($filename);
        chmod($filename, 482);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0660';
        $fixPermissionsResult = GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->testFilesToDelete[] = $filename;
        $this->assertFalse($fixPermissionsResult);
    }

    /**
     * @test
     */
    public function fixPermissionsSetsPermissionsWithRelativeFileReference()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        $filename = 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::writeFileToTypo3tempDir(PATH_site . $filename, '42');
        $this->testFilesToDelete[] = PATH_site . $filename;
        chmod(PATH_site . $filename, 482);
        // Set target permissions and run method
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0660';
        $fixPermissionsResult = GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0660', substr(decoct(fileperms(PATH_site . $filename)), 2));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsDefaultPermissionsToFile()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        $filename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::writeFileToTypo3tempDir($filename, '42');
        $this->testFilesToDelete[] = $filename;
        chmod($filename, 482);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask']);
        $fixPermissionsResult = GeneralUtility::fixPermissions($filename);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0644', substr(decoct(fileperms($filename)), 2));
    }

    /**
     * @test
     */
    public function fixPermissionsSetsDefaultPermissionsToDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('fixPermissions() tests not available on Windows');
        }
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        chmod($directory, 1551);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask']);
        $fixPermissionsResult = GeneralUtility::fixPermissions($directory);
        clearstatcache();
        $this->assertTrue($fixPermissionsResult);
        $this->assertEquals('0755', substr(decoct(fileperms($directory)), 1));
    }

    ///////////////////////////////
    // Tests concerning mkdir
    ///////////////////////////////
    /**
     * @test
     */
    public function mkdirCreatesDirectory()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        $mkdirResult = GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        clearstatcache();
        $this->assertTrue($mkdirResult);
        $this->assertTrue(is_dir($directory));
    }

    /**
     * @test
     */
    public function mkdirCreatesHiddenDirectory()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('.test_');
        $mkdirResult = GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        clearstatcache();
        $this->assertTrue($mkdirResult);
        $this->assertTrue(is_dir($directory));
    }

    /**
     * @test
     */
    public function mkdirCreatesDirectoryWithTrailingSlash()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_') . '/';
        $mkdirResult = GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        clearstatcache();
        $this->assertTrue($mkdirResult);
        $this->assertTrue(is_dir($directory));
    }

    /**
     * @test
     */
    public function mkdirSetsPermissionsOfCreatedDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('mkdirSetsPermissionsOfCreatedDirectory() test not available on Windows');
        }
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('test_');
        $oldUmask = umask(19);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0772';
        GeneralUtility::mkdir($directory);
        $this->testFilesToDelete[] = $directory;
        clearstatcache();
        $resultDirectoryPermissions = substr(decoct(fileperms($directory)), 1);
        umask($oldUmask);
        $this->assertEquals($resultDirectoryPermissions, '0772');
    }

    /**
     * @test
     */
    public function mkdirSetsGroupOwnershipOfCreatedDirectory()
    {
        if (!function_exists('posix_getegid')) {
            $this->markTestSkipped('Function posix_getegid() not available, mkdirSetsGroupOwnershipOfCreatedDirectory() tests skipped');
        }
        if (posix_getegid() === -1) {
            $this->markTestSkipped('The mkdirSetsGroupOwnershipOfCreatedDirectory() is not available on Mac OS because posix_getegid() always returns -1 on Mac OS.');
        }
        $swapGroup = $this->checkGroups(__FUNCTION__);
        if ($swapGroup !== false) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['createGroup'] = $swapGroup;
            $directory = $this->getUniqueId('mkdirtest_');
            GeneralUtility::mkdir(PATH_site . 'typo3temp/' . $directory);
            $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
            clearstatcache();
            $resultDirectoryGroupInfo = posix_getgrgid(filegroup(PATH_site . 'typo3temp/' . $directory));
            $resultDirectoryGroup = $resultDirectoryGroupInfo['name'];
            $this->assertEquals($resultDirectoryGroup, $swapGroup);
        }
    }

    ///////////////////////////////
    // Helper function for filesystem ownership tests
    ///////////////////////////////
    /**
     * Check if test on filesystem group ownership can be done in this environment
     * If so, return second group of webserver user
     *
     * @param string calling method name
     * @return mixed FALSE if test cannot be run, string name of the second group of webserver user
     */
    private function checkGroups($methodName)
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped($methodName . '() test not available on Windows.');
            return false;
        }
        if (!function_exists('posix_getgroups')) {
            $this->markTestSkipped('Function posix_getgroups() not available, ' . $methodName . '() tests skipped');
        }
        $groups = posix_getgroups();
        if (count($groups) <= 1) {
            $this->markTestSkipped($methodName . '() test cannot be done when the web server user is only member of 1 group.');
            return false;
        }
        $uname = strtolower(php_uname());
        $groupOffset = 1;
        if (strpos($uname, 'darwin') !== false) {
            // We are on OSX and it seems that the first group needs to be fetched since Mavericks
            $groupOffset = 0;
        }
        $groupInfo = posix_getgrgid($groups[$groupOffset]);
        return $groupInfo['name'];
    }

    ///////////////////////////////
    // Tests concerning mkdir_deep
    ///////////////////////////////
    /**
     * @test
     */
    public function mkdirDeepCreatesDirectory()
    {
        $directory = 'typo3temp/' . $this->getUniqueId('test_');
        GeneralUtility::mkdir_deep(PATH_site, $directory);
        $this->testFilesToDelete[] = PATH_site . $directory;
        $this->assertTrue(is_dir(PATH_site . $directory));
    }

    /**
     * @test
     */
    public function mkdirDeepCreatesSubdirectoriesRecursive()
    {
        $directory = 'typo3temp/' . $this->getUniqueId('test_');
        $subDirectory = $directory . '/foo';
        GeneralUtility::mkdir_deep(PATH_site, $subDirectory);
        $this->testFilesToDelete[] = PATH_site . $directory;
        $this->assertTrue(is_dir(PATH_site . $subDirectory));
    }

    /**
     * Data provider for mkdirDeepCreatesDirectoryWithDoubleSlashes.
     * @return array
     */
    public function mkdirDeepCreatesDirectoryWithAndWithoutDoubleSlashesDataProvider()
    {
        return array(
            'no double slash if concatenated with PATH_site' => array('fileadmin/testDir1'),
            'double slash if concatenated with PATH_site' => array('/fileadmin/testDir2'),
        );
    }

    /**
     * @test
     * @dataProvider mkdirDeepCreatesDirectoryWithAndWithoutDoubleSlashesDataProvider
     */
    public function mkdirDeepCreatesDirectoryWithDoubleSlashes($directoryToCreate)
    {
        vfsStream::setup();
        // Load fixture files and folders from disk
        FileStreamWrapper::init(PATH_site);
        FileStreamWrapper::registerOverlayPath('fileadmin', 'vfs://root/fileadmin', true);
        GeneralUtility::mkdir_deep(PATH_site, $directoryToCreate);
        $this->assertTrue(is_dir(PATH_site . $directoryToCreate));
        FileStreamWrapper::destroy();
    }

    /**
     * @test
     */
    public function mkdirDeepFixesPermissionsOfCreatedDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('mkdirDeepFixesPermissionsOfCreatedDirectory() test not available on Windows.');
        }
        $directory = $this->getUniqueId('mkdirdeeptest_');
        $oldUmask = umask(19);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0777';
        GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/', $directory);
        $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
        clearstatcache();
        umask($oldUmask);
        $this->assertEquals('777', substr(decoct(fileperms(PATH_site . 'typo3temp/' . $directory)), -3, 3));
    }

    /**
     * @test
     */
    public function mkdirDeepFixesPermissionsOnNewParentDirectory()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('mkdirDeepFixesPermissionsOnNewParentDirectory() test not available on Windows.');
        }
        $directory = $this->getUniqueId('mkdirdeeptest_');
        $subDirectory = $directory . '/bar';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask'] = '0777';
        $oldUmask = umask(19);
        GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/', $subDirectory);
        $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
        clearstatcache();
        umask($oldUmask);
        $this->assertEquals('777', substr(decoct(fileperms(PATH_site . 'typo3temp/' . $directory)), -3, 3));
    }

    /**
     * @test
     */
    public function mkdirDeepDoesNotChangePermissionsOfExistingSubDirectories()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('mkdirDeepDoesNotChangePermissionsOfExistingSubDirectories() test not available on Windows.');
        }
        $baseDirectory = PATH_site . 'typo3temp/';
        $existingDirectory = $this->getUniqueId('test_existing_') . '/';
        $newSubDirectory = $this->getUniqueId('test_new_');
        @mkdir(($baseDirectory . $existingDirectory));
        $this->testFilesToDelete[] = $baseDirectory . $existingDirectory;
        chmod($baseDirectory . $existingDirectory, 482);
        GeneralUtility::mkdir_deep($baseDirectory, $existingDirectory . $newSubDirectory);
        $this->assertEquals('0742', substr(decoct(fileperms($baseDirectory . $existingDirectory)), 2));
    }

    /**
     * @test
     */
    public function mkdirDeepSetsGroupOwnershipOfCreatedDirectory()
    {
        $swapGroup = $this->checkGroups(__FUNCTION__);
        if ($swapGroup !== false) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['createGroup'] = $swapGroup;
            $directory = $this->getUniqueId('mkdirdeeptest_');
            GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/', $directory);
            $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
            clearstatcache();
            $resultDirectoryGroupInfo = posix_getgrgid(filegroup(PATH_site . 'typo3temp/' . $directory));
            $resultDirectoryGroup = $resultDirectoryGroupInfo['name'];
            $this->assertEquals($resultDirectoryGroup, $swapGroup);
        }
    }

    /**
     * @test
     */
    public function mkdirDeepSetsGroupOwnershipOfCreatedParentDirectory()
    {
        $swapGroup = $this->checkGroups(__FUNCTION__);
        if ($swapGroup !== false) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['createGroup'] = $swapGroup;
            $directory = $this->getUniqueId('mkdirdeeptest_');
            $subDirectory = $directory . '/bar';
            GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/', $subDirectory);
            $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
            clearstatcache();
            $resultDirectoryGroupInfo = posix_getgrgid(filegroup(PATH_site . 'typo3temp/' . $directory));
            $resultDirectoryGroup = $resultDirectoryGroupInfo['name'];
            $this->assertEquals($resultDirectoryGroup, $swapGroup);
        }
    }

    /**
     * @test
     */
    public function mkdirDeepSetsGroupOwnershipOnNewSubDirectory()
    {
        $swapGroup = $this->checkGroups(__FUNCTION__);
        if ($swapGroup !== false) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['createGroup'] = $swapGroup;
            $directory = $this->getUniqueId('mkdirdeeptest_');
            $subDirectory = $directory . '/bar';
            GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/', $subDirectory);
            $this->testFilesToDelete[] = PATH_site . 'typo3temp/' . $directory;
            clearstatcache();
            $resultDirectoryGroupInfo = posix_getgrgid(filegroup(PATH_site . 'typo3temp/' . $subDirectory));
            $resultDirectoryGroup = $resultDirectoryGroupInfo['name'];
            $this->assertEquals($resultDirectoryGroup, $swapGroup);
        }
    }

    /**
     * @test
     */
    public function mkdirDeepCreatesDirectoryInVfsStream()
    {
        if (!class_exists('org\\bovigo\\vfs\\vfsStreamWrapper')) {
            $this->markTestSkipped('mkdirDeepCreatesDirectoryInVfsStream() test not available with this phpunit version.');
        }
        vfsStreamWrapper::register();
        $baseDirectory = $this->getUniqueId('test_');
        vfsStreamWrapper::setRoot(new vfsStreamDirectory($baseDirectory));
        GeneralUtility::mkdir_deep('vfs://' . $baseDirectory . '/', 'sub');
        $this->assertTrue(is_dir('vfs://' . $baseDirectory . '/sub'));
    }

    /**
     * @test
     * @expectedException \RuntimeException
     */
    public function mkdirDeepThrowsExceptionIfDirectoryCreationFails()
    {
        GeneralUtility::mkdir_deep('http://localhost');
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function mkdirDeepThrowsExceptionIfBaseDirectoryIsNotOfTypeString()
    {
        GeneralUtility::mkdir_deep(array());
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function mkdirDeepThrowsExceptionIfDeepDirectoryIsNotOfTypeString()
    {
        GeneralUtility::mkdir_deep(PATH_site . 'typo3temp/foo', array());
    }

    ///////////////////////////////
    // Tests concerning rmdir
    ///////////////////////////////

    /**
     * @test
     */
    public function rmdirRemovesFile()
    {
        $file = PATH_site . 'typo3temp/' . $this->getUniqueId('file_');
        touch($file);
        GeneralUtility::rmdir($file);
        $this->assertFalse(file_exists($file));
    }

    /**
     * @test
     */
    public function rmdirReturnTrueIfFileWasRemoved()
    {
        $file = PATH_site . 'typo3temp/' . $this->getUniqueId('file_');
        touch($file);
        $this->assertTrue(GeneralUtility::rmdir($file));
    }

    /**
     * @test
     */
    public function rmdirReturnFalseIfNoFileWasRemoved()
    {
        $file = PATH_site . 'typo3temp/' . $this->getUniqueId('file_');
        $this->assertFalse(GeneralUtility::rmdir($file));
    }

    /**
     * @test
     */
    public function rmdirRemovesDirectory()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('directory_');
        mkdir($directory);
        GeneralUtility::rmdir($directory);
        $this->assertFalse(file_exists($directory));
    }

    /**
     * @test
     */
    public function rmdirRemovesDirectoryWithTrailingSlash()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('directory_') . '/';
        mkdir($directory);
        GeneralUtility::rmdir($directory);
        $this->assertFalse(file_exists($directory));
    }

    /**
     * @test
     */
    public function rmdirDoesNotRemoveDirectoryWithFilesAndReturnsFalseIfRecursiveDeletionIsOff()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('directory_') . '/';
        mkdir($directory);
        $file = $this->getUniqueId('file_');
        touch($directory . $file);
        $this->testFilesToDelete[] = $directory;
        $return = GeneralUtility::rmdir($directory);
        $this->assertTrue(file_exists($directory));
        $this->assertTrue(file_exists($directory . $file));
        $this->assertFalse($return);
    }

    /**
     * @test
     */
    public function rmdirRemovesDirectoriesRecursiveAndReturnsTrue()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('directory_') . '/';
        mkdir($directory);
        mkdir($directory . 'sub/');
        touch($directory . 'sub/file');
        $return = GeneralUtility::rmdir($directory, true);
        $this->assertFalse(file_exists($directory));
        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function rmdirRemovesLinkToDirectory()
    {
        if (TYPO3_OS === 'WIN') {
            $this->markTestSkipped('Test not available on Windows OS.');
        }
        $existingDirectory = PATH_site . 'typo3temp/' . $this->getUniqueId('notExists_') . '/';
        mkdir($existingDirectory);
        $this->testFilesToDelete[] = $existingDirectory;
        $symlinkName = PATH_site . 'typo3temp/' . $this->getUniqueId('link_');
        symlink($existingDirectory, $symlinkName);
        GeneralUtility::rmdir($symlinkName, true);
        $this->assertFalse(is_link($symlinkName));
    }

    /**
     * @test
     */
    public function rmdirRemovesDeadLinkToDirectory()
    {
        if (TYPO3_OS === 'WIN') {
            $this->markTestSkipped('Test not available on Windows OS.');
        }
        $notExistingDirectory = PATH_site . 'typo3temp/' . $this->getUniqueId('notExists_') . '/';
        $symlinkName = PATH_site . 'typo3temp/' . $this->getUniqueId('link_');
        symlink($notExistingDirectory, $symlinkName);
        GeneralUtility::rmdir($symlinkName, true);
        $this->assertFalse(is_link($symlinkName));
    }

    /**
     * @test
     */
    public function rmdirRemovesDeadLinkToFile()
    {
        if (TYPO3_OS === 'WIN') {
            $this->markTestSkipped('Test not available on Windows OS.');
        }
        $notExistingFile = PATH_site . 'typo3temp/' . $this->getUniqueId('notExists_');
        $symlinkName = PATH_site . 'typo3temp/' . $this->getUniqueId('link_');
        symlink($notExistingFile, $symlinkName);
        GeneralUtility::rmdir($symlinkName, true);
        $this->assertFalse(is_link($symlinkName));
    }

    ///////////////////////////////////
    // Tests concerning getFilesInDir
    ///////////////////////////////////

    /**
     * Helper method to create test directory.
     *
     * @return string A unique directory name prefixed with test_.
     */
    protected function getFilesInDirCreateTestDirectory()
    {
        if (!class_exists('org\\bovigo\\vfs\\vfsStreamWrapper')) {
            $this->markTestSkipped('getFilesInDirCreateTestDirectory() helper method not available without vfsStream.');
        }
        $structure = array(
            'subDirectory' => array(
                'test.php' => 'butter',
                'other.php' => 'milk',
                'stuff.csv' => 'honey',
            ),
            'excludeMe.txt' => 'cocoa nibs',
            'testB.txt' => 'olive oil',
            'testA.txt' => 'eggs',
            'testC.txt' => 'carrots',
            'test.js' => 'oranges',
            'test.css' => 'apples',
            '.secret.txt' => 'sammon',
        );
        vfsStream::setup('test', null, $structure);
        $vfsUrl = vfsStream::url('test');

        // set random values for mtime
        foreach ($structure as $structureLevel1Key => $structureLevel1Content) {
            $newMtime = rand();
            if (is_array($structureLevel1Content)) {
                foreach ($structureLevel1Content as $structureLevel2Key => $structureLevel2Content) {
                    touch($vfsUrl . '/' . $structureLevel1Key . '/' . $structureLevel2Key, $newMtime);
                }
            } else {
                touch($vfsUrl . '/' . $structureLevel1Key, $newMtime);
            }
        }

        return $vfsUrl;
    }

    /**
     * @test
     */
    public function getFilesInDirFindsRegularFile()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl);
        $this->assertContains('testA.txt', $files);
    }

    /**
     * @test
     */
    public function getFilesInDirFindsHiddenFile()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl);
        $this->assertContains('.secret.txt', $files);
    }

    /**
     * @test
     */
    public function getFilesInDirByExtensionFindsFiles()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl, 'txt,js');
        $this->assertContains('testA.txt', $files);
        $this->assertContains('test.js', $files);
    }

    /**
     * @test
     */
    public function getFilesInDirByExtensionDoesNotFindFilesWithOtherExtensions()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl, 'txt,js');
        $this->assertContains('testA.txt', $files);
        $this->assertContains('test.js', $files);
        $this->assertNotContains('test.css', $files);
    }

    /**
     * @test
     */
    public function getFilesInDirExcludesFilesMatchingPattern()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl, '', false, '', 'excludeMe.*');
        $this->assertContains('test.js', $files);
        $this->assertNotContains('excludeMe.txt', $files);
    }

    /**
     * @test
     */
    public function getFilesInDirCanPrependPath()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $this->assertContains(
            $vfsStreamUrl . '/testA.txt',
            GeneralUtility::getFilesInDir($vfsStreamUrl, '', true)
        );
    }

    /**
     * @test
     */
    public function getFilesInDirDoesSortAlphabeticallyByDefault()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $this->assertSame(
            array_values(GeneralUtility::getFilesInDir($vfsStreamUrl, '', false)),
            array('.secret.txt', 'excludeMe.txt', 'test.css', 'test.js', 'testA.txt', 'testB.txt', 'testC.txt')
        );
    }

    /**
     * @test
     */
    public function getFilesInDirCanOrderByMtime()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = array();
        $iterator = new \DirectoryIterator($vfsStreamUrl);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $files[$fileinfo->getFilename()] = $fileinfo->getMTime();
            }
        }
        asort($files);
        $this->assertSame(
            array_values(GeneralUtility::getFilesInDir($vfsStreamUrl, '', false, 'mtime')),
            array_keys($files)
        );
    }

    /**
     * @test
     */
    public function getFilesInDirReturnsArrayWithMd5OfElementAndPathAsArrayKey()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $this->assertArrayHasKey(
            md5($vfsStreamUrl . '/testA.txt'),
            GeneralUtility::getFilesInDir($vfsStreamUrl)
        );
    }

    /**
     * @test
     */
    public function getFilesInDirDoesNotFindDirectories()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $this->assertNotContains(
            'subDirectory',
            GeneralUtility::getFilesInDir($vfsStreamUrl)
        );
    }

    /**
     * Dotfiles; current directory: '.' and parent directory: '..' must not be
     * present.
     *
     * @test
     */
    public function getFilesInDirDoesNotFindDotfiles()
    {
        $vfsStreamUrl = $this->getFilesInDirCreateTestDirectory();
        $files = GeneralUtility::getFilesInDir($vfsStreamUrl);
        $this->assertNotContains('..', $files);
        $this->assertNotContains('.', $files);
    }

    ///////////////////////////////
    // Tests concerning unQuoteFilenames
    ///////////////////////////////
    /**
     * Data provider for ImageMagick shell commands
     *
     * @see explodeAndUnquoteImageMagickCommands
     */
    public function imageMagickCommandsDataProvider()
    {
        return array(
            // Some theoretical tests first
            array(
                '',
                array(),
                array()
            ),
            array(
                'aa bb "cc" "dd"',
                array('aa', 'bb', '"cc"', '"dd"'),
                array('aa', 'bb', 'cc', 'dd')
            ),
            array(
                'aa bb "cc dd"',
                array('aa', 'bb', '"cc dd"'),
                array('aa', 'bb', 'cc dd')
            ),
            array(
                '\'aa bb\' "cc dd"',
                array('\'aa bb\'', '"cc dd"'),
                array('aa bb', 'cc dd')
            ),
            array(
                '\'aa bb\' cc "dd"',
                array('\'aa bb\'', 'cc', '"dd"'),
                array('aa bb', 'cc', 'dd')
            ),
            // Now test against some real world examples
            array(
                '/opt/local/bin/gm.exe convert +profile \'*\' -geometry 170x136!  -negate "C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]" "C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"',
                array(
                    '/opt/local/bin/gm.exe',
                    'convert',
                    '+profile',
                    '\'*\'',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '"C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]"',
                    '"C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"'
                ),
                array(
                    '/opt/local/bin/gm.exe',
                    'convert',
                    '+profile',
                    '*',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    'C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]',
                    'C:/Users/Someuser.Domain/Documents/Htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif'
                )
            ),
            array(
                'C:/opt/local/bin/gm.exe convert +profile \'*\' -geometry 170x136!  -negate "C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]" "C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"',
                array(
                    'C:/opt/local/bin/gm.exe',
                    'convert',
                    '+profile',
                    '\'*\'',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '"C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]"',
                    '"C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"'
                ),
                array(
                    'C:/opt/local/bin/gm.exe',
                    'convert',
                    '+profile',
                    '*',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    'C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]',
                    'C:/Program Files/Apache2/htdocs/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif'
                )
            ),
            array(
                '/usr/bin/gm convert +profile \'*\' -geometry 170x136!  -negate "/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]" "/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"',
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '\'*\'',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '"/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]"',
                    '"/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"'
                ),
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '*',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]',
                    '/Shared Items/Data/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif'
                )
            ),
            array(
                '/usr/bin/gm convert +profile \'*\' -geometry 170x136!  -negate "/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]" "/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"',
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '\'*\'',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '"/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]"',
                    '"/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif"'
                ),
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '*',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]',
                    '/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif'
                )
            ),
            array(
                '/usr/bin/gm convert +profile \'*\' -geometry 170x136!  -negate \'/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]\' \'/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif\'',
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '\'*\'',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '\'/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]\'',
                    '\'/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif\''
                ),
                array(
                    '/usr/bin/gm',
                    'convert',
                    '+profile',
                    '*',
                    '-geometry',
                    '170x136!',
                    '-negate',
                    '/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif[0]',
                    '/Network/Servers/server01.internal/Projects/typo3temp/temp/61401f5c16c63d58e1d92e8a2449f2fe_maskNT.gif'
                )
            )
        );
    }

    /**
     * Tests if the commands are exploded and unquoted correctly
     *
     * @dataProvider imageMagickCommandsDataProvider
     * @test
     */
    public function explodeAndUnquoteImageMagickCommands($source, $expectedQuoted, $expectedUnquoted)
    {
        $actualQuoted = GeneralUtility::unQuoteFilenames($source);
        $actualUnquoted = GeneralUtility::unQuoteFilenames($source, true);
        $this->assertEquals($expectedQuoted, $actualQuoted, 'The exploded command does not match the expected');
        $this->assertEquals($expectedUnquoted, $actualUnquoted, 'The exploded and unquoted command does not match the expected');
    }

    ///////////////////////////////
    // Tests concerning split_fileref
    ///////////////////////////////
    /**
     * @test
     */
    public function splitFileRefReturnsFileTypeNotForFolders()
    {
        $directoryName = $this->getUniqueId('test_') . '.com';
        $directoryPath = PATH_site . 'typo3temp/';
        $directory = $directoryPath . $directoryName;
        mkdir($directory, octdec($GLOBALS['TYPO3_CONF_VARS']['SYS']['folderCreateMask']));
        $fileInfo = GeneralUtility::split_fileref($directory);
        $directoryCreated = is_dir($directory);
        rmdir($directory);
        $this->assertTrue($directoryCreated);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_ARRAY, $fileInfo);
        $this->assertEquals($directoryPath, $fileInfo['path']);
        $this->assertEquals($directoryName, $fileInfo['file']);
        $this->assertEquals($directoryName, $fileInfo['filebody']);
        $this->assertEquals('', $fileInfo['fileext']);
        $this->assertArrayNotHasKey('realFileext', $fileInfo);
    }

    /**
     * @test
     */
    public function splitFileRefReturnsFileTypeForFilesWithoutPathSite()
    {
        $testFile = 'fileadmin/media/someFile.png';
        $fileInfo = GeneralUtility::split_fileref($testFile);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_ARRAY, $fileInfo);
        $this->assertEquals('fileadmin/media/', $fileInfo['path']);
        $this->assertEquals('someFile.png', $fileInfo['file']);
        $this->assertEquals('someFile', $fileInfo['filebody']);
        $this->assertEquals('png', $fileInfo['fileext']);
    }

    /////////////////////////////
    // Tests concerning dirname
    /////////////////////////////
    /**
     * @see dirnameWithDataProvider
     * @return array<array>
     */
    public function dirnameDataProvider()
    {
        return array(
            'absolute path with multiple part and file' => array('/dir1/dir2/script.php', '/dir1/dir2'),
            'absolute path with one part' => array('/dir1/', '/dir1'),
            'absolute path to file without extension' => array('/dir1/something', '/dir1'),
            'relative path with one part and file' => array('dir1/script.php', 'dir1'),
            'relative one-character path with one part and file' => array('d/script.php', 'd'),
            'absolute zero-part path with file' => array('/script.php', ''),
            'empty string' => array('', '')
        );
    }

    /**
     * @test
     * @dataProvider dirnameDataProvider
     * @param string $input the input for dirname
     * @param string $expectedValue the expected return value expected from dirname
     */
    public function dirnameWithDataProvider($input, $expectedValue)
    {
        $this->assertEquals($expectedValue, GeneralUtility::dirname($input));
    }

    /////////////////////////////////////
    // Tests concerning resolveBackPath
    /////////////////////////////////////
    /**
     * @see resolveBackPathWithDataProvider
     * @return array<array>
     */
    public function resolveBackPathDataProvider()
    {
        return array(
            'empty path' => array('', ''),
            'this directory' => array('./', './'),
            'relative directory without ..' => array('dir1/dir2/dir3/', 'dir1/dir2/dir3/'),
            'relative path without ..' => array('dir1/dir2/script.php', 'dir1/dir2/script.php'),
            'absolute directory without ..' => array('/dir1/dir2/dir3/', '/dir1/dir2/dir3/'),
            'absolute path without ..' => array('/dir1/dir2/script.php', '/dir1/dir2/script.php'),
            'only one directory upwards without trailing slash' => array('..', '..'),
            'only one directory upwards with trailing slash' => array('../', '../'),
            'one level with trailing ..' => array('dir1/..', ''),
            'one level with trailing ../' => array('dir1/../', ''),
            'two levels with trailing ..' => array('dir1/dir2/..', 'dir1'),
            'two levels with trailing ../' => array('dir1/dir2/../', 'dir1/'),
            'leading ../ without trailing /' => array('../dir1', '../dir1'),
            'leading ../ with trailing /' => array('../dir1/', '../dir1/'),
            'leading ../ and inside path' => array('../dir1/dir2/../dir3/', '../dir1/dir3/'),
            'one times ../ in relative directory' => array('dir1/../dir2/', 'dir2/'),
            'one times ../ in absolute directory' => array('/dir1/../dir2/', '/dir2/'),
            'one times ../ in relative path' => array('dir1/../dir2/script.php', 'dir2/script.php'),
            'one times ../ in absolute path' => array('/dir1/../dir2/script.php', '/dir2/script.php'),
            'consecutive ../' => array('dir1/dir2/dir3/../../../dir4', 'dir4'),
            'distrubuted ../ with trailing /' => array('dir1/../dir2/dir3/../', 'dir2/'),
            'distributed ../ without trailing /' => array('dir1/../dir2/dir3/..', 'dir2'),
            'multiple distributed and consecutive ../ together' => array('dir1/dir2/dir3/dir4/../../dir5/dir6/dir7/../dir8/', 'dir1/dir2/dir5/dir6/dir8/'),
            'dirname with leading ..' => array('dir1/..dir2/dir3/', 'dir1/..dir2/dir3/'),
            'dirname with trailing ..' => array('dir1/dir2../dir3/', 'dir1/dir2../dir3/'),
            'more times upwards than downwards in directory' => array('dir1/../../', '../'),
            'more times upwards than downwards in path' => array('dir1/../../script.php', '../script.php')
        );
    }

    /**
     * @test
     * @dataProvider resolveBackPathDataProvider
     * @param string $input the input for resolveBackPath
     * @param $expectedValue Expected return value from resolveBackPath
     */
    public function resolveBackPathWithDataProvider($input, $expectedValue)
    {
        $this->assertEquals($expectedValue, GeneralUtility::resolveBackPath($input));
    }

    /////////////////////////////////////////////////////////////////////////////////////
    // Tests concerning makeInstance, setSingletonInstance, addInstance, purgeInstances
    /////////////////////////////////////////////////////////////////////////////////////
    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithEmptyClassNameThrowsException()
    {
        GeneralUtility::makeInstance('');
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithNullClassNameThrowsException()
    {
        GeneralUtility::makeInstance(null);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithZeroStringClassNameThrowsException()
    {
        GeneralUtility::makeInstance(0);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithEmptyArrayThrowsException()
    {
        GeneralUtility::makeInstance(array());
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithNonEmptyArrayThrowsException()
    {
        GeneralUtility::makeInstance(array('foo'));
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function makeInstanceWithBeginningSlashInClassNameThrowsException()
    {
        GeneralUtility::makeInstance('\\TYPO3\\CMS\\Backend\\Controller\\BackendController');
    }

    /**
     * @test
     */
    public function makeInstanceReturnsClassInstance()
    {
        $className = get_class($this->getMock('foo'));
        $this->assertTrue(GeneralUtility::makeInstance($className) instanceof $className);
    }

    /**
     * @test
     */
    public function makeInstancePassesParametersToConstructor()
    {
        $instance = GeneralUtility::makeInstance(TwoParametersConstructorFixture::class, 'one parameter', 'another parameter');
        $this->assertEquals('one parameter', $instance->constructorParameter1, 'The first constructor parameter has not been set.');
        $this->assertEquals('another parameter', $instance->constructorParameter2, 'The second constructor parameter has not been set.');
    }

    /**
     * @test
     */
    public function makeInstanceInstanciatesConfiguredImplementation()
    {
        GeneralUtilityFixture::resetFinalClassNameCache();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][OriginalClassFixture::class] = array('className' => ReplacementClassFixture::class);
        $this->assertInstanceOf(ReplacementClassFixture::class, GeneralUtility::makeInstance(OriginalClassFixture::class));
    }

    /**
     * @test
     */
    public function makeInstanceResolvesConfiguredImplementationsRecursively()
    {
        GeneralUtilityFixture::resetFinalClassNameCache();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][OriginalClassFixture::class] = array('className' => ReplacementClassFixture::class);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][ReplacementClassFixture::class] = array('className' => OtherReplacementClassFixture::class);
        $this->assertInstanceOf(OtherReplacementClassFixture::class, GeneralUtility::makeInstance(OriginalClassFixture::class));
    }

    /**
     * @test
     */
    public function makeInstanceCalledTwoTimesForNonSingletonClassReturnsDifferentInstances()
    {
        $className = get_class($this->getMock('foo'));
        $this->assertNotSame(GeneralUtility::makeInstance($className), GeneralUtility::makeInstance($className));
    }

    /**
     * @test
     */
    public function makeInstanceCalledTwoTimesForSingletonClassReturnsSameInstance()
    {
        $className = get_class($this->getMock(\TYPO3\CMS\Core\SingletonInterface::class));
        $this->assertSame(GeneralUtility::makeInstance($className), GeneralUtility::makeInstance($className));
    }

    /**
     * @test
     */
    public function makeInstanceCalledTwoTimesForSingletonClassWithPurgeInstancesInbetweenReturnsDifferentInstances()
    {
        $className = get_class($this->getMock(\TYPO3\CMS\Core\SingletonInterface::class));
        $instance = GeneralUtility::makeInstance($className);
        GeneralUtility::purgeInstances();
        $this->assertNotSame($instance, GeneralUtility::makeInstance($className));
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function setSingletonInstanceForEmptyClassNameThrowsException()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        GeneralUtility::setSingletonInstance('', $instance);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function setSingletonInstanceForClassThatIsNoSubclassOfProvidedClassThrowsException()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class, array('foo'));
        $singletonClassName = get_class($this->getMock(\TYPO3\CMS\Core\SingletonInterface::class));
        GeneralUtility::setSingletonInstance($singletonClassName, $instance);
    }

    /**
     * @test
     */
    public function setSingletonInstanceMakesMakeInstanceReturnThatInstance()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        $singletonClassName = get_class($instance);
        GeneralUtility::setSingletonInstance($singletonClassName, $instance);
        $this->assertSame($instance, GeneralUtility::makeInstance($singletonClassName));
    }

    /**
     * @test
     */
    public function setSingletonInstanceCalledTwoTimesMakesMakeInstanceReturnLastSetInstance()
    {
        $instance1 = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        $singletonClassName = get_class($instance1);
        $instance2 = new $singletonClassName();
        GeneralUtility::setSingletonInstance($singletonClassName, $instance1);
        GeneralUtility::setSingletonInstance($singletonClassName, $instance2);
        $this->assertSame($instance2, GeneralUtility::makeInstance($singletonClassName));
    }

    /**
     * @test
     */
    public function getSingletonInstancesContainsPreviouslySetSingletonInstance()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        $instanceClassName = get_class($instance);
        GeneralUtility::setSingletonInstance($instanceClassName, $instance);
        $registeredSingletonInstances = GeneralUtility::getSingletonInstances();
        $this->assertArrayHasKey($instanceClassName, $registeredSingletonInstances);
        $this->assertSame($registeredSingletonInstances[$instanceClassName], $instance);
    }

    /**
     * @test
     */
    public function resetSingletonInstancesResetsPreviouslySetInstance()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        $instanceClassName = get_class($instance);
        GeneralUtility::setSingletonInstance($instanceClassName, $instance);
        GeneralUtility::resetSingletonInstances(array());
        $registeredSingletonInstances = GeneralUtility::getSingletonInstances();
        $this->assertArrayNotHasKey($instanceClassName, $registeredSingletonInstances);
    }

    /**
     * @test
     */
    public function resetSingletonInstancesSetsGivenInstance()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        $instanceClassName = get_class($instance);
        GeneralUtility::resetSingletonInstances(
            array($instanceClassName => $instance)
        );
        $registeredSingletonInstances = GeneralUtility::getSingletonInstances();
        $this->assertArrayHasKey($instanceClassName, $registeredSingletonInstances);
        $this->assertSame($registeredSingletonInstances[$instanceClassName], $instance);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function addInstanceForEmptyClassNameThrowsException()
    {
        $instance = $this->getMock('foo');
        GeneralUtility::addInstance('', $instance);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function addInstanceForClassThatIsNoSubclassOfProvidedClassThrowsException()
    {
        $instance = $this->getMock('foo', array('bar'));
        $singletonClassName = get_class($this->getMock('foo'));
        GeneralUtility::addInstance($singletonClassName, $instance);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function addInstanceWithSingletonInstanceThrowsException()
    {
        $instance = $this->getMock(\TYPO3\CMS\Core\SingletonInterface::class);
        GeneralUtility::addInstance(get_class($instance), $instance);
    }

    /**
     * @test
     */
    public function addInstanceMakesMakeInstanceReturnThatInstance()
    {
        $instance = $this->getMock('foo');
        $className = get_class($instance);
        GeneralUtility::addInstance($className, $instance);
        $this->assertSame($instance, GeneralUtility::makeInstance($className));
    }

    /**
     * @test
     */
    public function makeInstanceCalledTwoTimesAfterAddInstanceReturnTwoDifferentInstances()
    {
        $instance = $this->getMock('foo');
        $className = get_class($instance);
        GeneralUtility::addInstance($className, $instance);
        $this->assertNotSame(GeneralUtility::makeInstance($className), GeneralUtility::makeInstance($className));
    }

    /**
     * @test
     */
    public function addInstanceCalledTwoTimesMakesMakeInstanceReturnBothInstancesInAddingOrder()
    {
        $instance1 = $this->getMock('foo');
        $className = get_class($instance1);
        GeneralUtility::addInstance($className, $instance1);
        $instance2 = new $className();
        GeneralUtility::addInstance($className, $instance2);
        $this->assertSame($instance1, GeneralUtility::makeInstance($className), 'The first returned instance does not match the first added instance.');
        $this->assertSame($instance2, GeneralUtility::makeInstance($className), 'The second returned instance does not match the second added instance.');
    }

    /**
     * @test
     */
    public function purgeInstancesDropsAddedInstance()
    {
        $instance = $this->getMock('foo');
        $className = get_class($instance);
        GeneralUtility::addInstance($className, $instance);
        GeneralUtility::purgeInstances();
        $this->assertNotSame($instance, GeneralUtility::makeInstance($className));
    }

    /**
     * Data provider for validPathStrDetectsInvalidCharacters.
     *
     * @return array
     */
    public function validPathStrInvalidCharactersDataProvider()
    {
        return array(
            'double slash in path' => array('path//path'),
            'backslash in path' => array('path\\path'),
            'directory up in path' => array('path/../path'),
            'directory up at the beginning' => array('../path'),
            'NUL character in path' => array('path' . chr(0) . 'path'),
            'BS character in path' => array('path' . chr(8) . 'path')
        );
    }

    /**
     * Tests whether invalid characters are detected.
     *
     * @param string $path
     * @dataProvider validPathStrInvalidCharactersDataProvider
     * @test
     */
    public function validPathStrDetectsInvalidCharacters($path)
    {
        $this->assertFalse(GeneralUtility::validPathStr($path));
    }

    /**
     * Tests whether Unicode characters are recognized as valid file name characters.
     *
     * @test
     */
    public function validPathStrWorksWithUnicodeFileNames()
    {
        $this->assertTrue(GeneralUtility::validPathStr('fileadmin/templates/Ссылка (fce).xml'));
    }

    /**
     * @return array
     */
    public function deniedFilesDataProvider()
    {
        return array(
            'Nul character in file' => array('image' . chr(0) . '.gif'),
            'Nul character in file with .php' => array('image.php' . chr(0) . '.gif'),
            'Regular .php file' => array('file.php'),
            'Regular .php5 file' => array('file.php5'),
            'Regular .php3 file' => array('file.php3'),
            'Regular .phpsh file' => array('file.phpsh'),
            'Regular .phtml file' => array('file.phtml'),
            'PHP file in the middle' => array('file.php.txt'),
            '.htaccess file' => array('.htaccess'),
        );
    }

    /**
     * Tests whether verifyFilenameAgainstDenyPattern detects denied files.
     *
     * @param string $deniedFile
     * @test
     * @dataProvider deniedFilesDataProvider
     */
    public function verifyFilenameAgainstDenyPatternDetectsNotAllowedFiles($deniedFile)
    {
        $this->assertFalse(GeneralUtility::verifyFilenameAgainstDenyPattern($deniedFile));
    }


    /////////////////////////////////////////////////////////////////////////////////////
    // Tests concerning copyDirectory
    /////////////////////////////////////////////////////////////////////////////////////

    /**
     * @test
     */
    public function copyDirectoryCopiesFilesAndDirectoriesWithRelativePaths()
    {
        $sourceDirectory = 'typo3temp/' . $this->getUniqueId('test_') . '/';
        $absoluteSourceDirectory = PATH_site . $sourceDirectory;
        $this->testFilesToDelete[] = $absoluteSourceDirectory;
        GeneralUtility::mkdir($absoluteSourceDirectory);

        $targetDirectory = 'typo3temp/' . $this->getUniqueId('test_') . '/';
        $absoluteTargetDirectory = PATH_site . $targetDirectory;
        $this->testFilesToDelete[] = $absoluteTargetDirectory;
        GeneralUtility::mkdir($absoluteTargetDirectory);

        GeneralUtility::writeFileToTypo3tempDir($absoluteSourceDirectory . 'file', '42');
        GeneralUtility::mkdir($absoluteSourceDirectory . 'foo');
        GeneralUtility::writeFileToTypo3tempDir($absoluteSourceDirectory . 'foo/file', '42');

        GeneralUtility::copyDirectory($sourceDirectory, $targetDirectory);

        $this->assertFileExists($absoluteTargetDirectory . 'file');
        $this->assertFileExists($absoluteTargetDirectory . 'foo/file');
    }

    /**
     * @test
     */
    public function copyDirectoryCopiesFilesAndDirectoriesWithAbsolutePaths()
    {
        $sourceDirectory = 'typo3temp/' . $this->getUniqueId('test_') . '/';
        $absoluteSourceDirectory = PATH_site . $sourceDirectory;
        $this->testFilesToDelete[] = $absoluteSourceDirectory;
        GeneralUtility::mkdir($absoluteSourceDirectory);

        $targetDirectory = 'typo3temp/' . $this->getUniqueId('test_') . '/';
        $absoluteTargetDirectory = PATH_site . $targetDirectory;
        $this->testFilesToDelete[] = $absoluteTargetDirectory;
        GeneralUtility::mkdir($absoluteTargetDirectory);

        GeneralUtility::writeFileToTypo3tempDir($absoluteSourceDirectory . 'file', '42');
        GeneralUtility::mkdir($absoluteSourceDirectory . 'foo');
        GeneralUtility::writeFileToTypo3tempDir($absoluteSourceDirectory . 'foo/file', '42');

        GeneralUtility::copyDirectory($absoluteSourceDirectory, $absoluteTargetDirectory);

        $this->assertFileExists($absoluteTargetDirectory . 'file');
        $this->assertFileExists($absoluteTargetDirectory . 'foo/file');
    }

    /////////////////////////////////////////////////////////////////////////////////////
    // Tests concerning sysLog
    /////////////////////////////////////////////////////////////////////////////////////
    /**
     * @test
     */
    public function syslogFixesPermissionsOnFileIfUsingFileLogging()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('syslogFixesPermissionsOnFileIfUsingFileLogging() test not available on Windows.');
        }
        // Fake all required settings
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemLogLevel'] = 0;
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['systemLogInit'] = true;
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_div.php']['systemLog']);
        $testLogFilename = PATH_site . 'typo3temp/' . $this->getUniqueId('test_') . '.txt';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemLog'] = 'file,' . $testLogFilename . ',0';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0777';
        // Call method, get actual permissions and clean up
        GeneralUtility::syslog('testLog', 'test', GeneralUtility::SYSLOG_SEVERITY_NOTICE);
        $this->testFilesToDelete[] = $testLogFilename;
        clearstatcache();
        $this->assertEquals('0777', substr(decoct(fileperms($testLogFilename)), 2));
    }

    /**
     * @test
     */
    public function deprecationLogFixesPermissionsOnLogFile()
    {
        if (TYPO3_OS == 'WIN') {
            $this->markTestSkipped('deprecationLogFixesPermissionsOnLogFile() test not available on Windows.');
        }
        $filePath = PATH_site . GeneralUtilityFixture::DEPRECATION_LOG_PATH;
        @mkdir(dirname($filePath));
        $this->testFilesToDelete[] = $filePath;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['enableDeprecationLog'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fileCreateMask'] = '0777';
        GeneralUtilityFixture::deprecationLog('foo');
        clearstatcache();
        $resultFilePermissions = substr(decoct(fileperms($filePath)), 2);
        $this->assertEquals('0777', $resultFilePermissions);
    }

    ///////////////////////////////////////////////////
    // Tests concerning callUserFunction
    ///////////////////////////////////////////////////

    /**
     * @test
     * @dataProvider callUserFunctionInvalidParameterDataprovider
     */
    public function callUserFunctionWillReturnFalseForInvalidParameters($functionName)
    {
        $inputData = array('foo' => 'bar');
        // omit the debug() output
        ob_start();
        $result = GeneralUtility::callUserFunction($functionName, $inputData, $this, 'user_', 1);
        ob_end_clean();
        $this->assertFalse($result);
    }

    /**
     * @test
     * @dataProvider callUserFunctionInvalidParameterDataprovider
     * @expectedException \InvalidArgumentException
     */
    public function callUserFunctionWillThrowExceptionForInvalidParameters($functionName)
    {
        $inputData = array('foo' => 'bar');
        GeneralUtility::callUserFunction($functionName, $inputData, $this, 'user_', 2);
    }

    /**
     * Data provider for callUserFunctionInvalidParameterDataprovider and
     * callUserFunctionWillThrowExceptionForInvalidParameters.
     *
     * @return array
     */
    public function callUserFunctionInvalidParameterDataprovider()
    {
        return array(
            'Function is not prefixed' => array('t3lib_divTest->calledUserFunction'),
            'Class doesn\'t exists' => array('t3lib_divTest21345->user_calledUserFunction'),
            'No method name' => array('t3lib_divTest'),
            'No class name' => array('->user_calledUserFunction'),
            'No function name' => array('')
        );
    }

    /**
     * Above tests already showed that the prefix is checked properly,
     * therefore this test skips the prefix and enables to inline the instantly
     * created function (who's name doesn't have a prefix).
     *
     * @test
     */
    public function callUserFunctionCanCallFunction()
    {
        $inputData = array('foo' => 'bar');
        $result = GeneralUtility::callUserFunction(function () { return "Worked fine"; }, $inputData, $this, '');
        $this->assertEquals('Worked fine', $result);
    }

    /**
     * @test
     */
    public function callUserFunctionCanCallMethod()
    {
        $inputData = array('foo' => 'bar');
        $result = GeneralUtility::callUserFunction(GeneralUtilityTest::class . '->user_calledUserFunction', $inputData, $this);
        $this->assertEquals('Worked fine', $result);
    }

    /**
     * @return string
     */
    public function user_calledUserFunction()
    {
        return 'Worked fine';
    }

    /**
     * @test
     */
    public function callUserFunctionCanPrefixFuncNameWithFilePath()
    {
        $inputData = array('foo' => 'bar');
        $result = GeneralUtility::callUserFunction('typo3/sysext/core/Tests/Unit/Utility/GeneralUtilityTest.php:TYPO3\\CMS\\Core\\Tests\\Unit\\Utility\GeneralUtilityTest->user_calledUserFunction', $inputData, $this);
        $this->assertEquals('Worked fine', $result);
    }

    /**
     * @test
     */
    public function callUserFunctionCanPersistObjectsBetweenCalls()
    {
        $inputData = array('called' => array());
        GeneralUtility::callUserFunction('&TYPO3\\CMS\\Core\\Tests\\Unit\\Utility\\GeneralUtilityTest->user_calledUserFunctionCountCallers', $inputData, $this);
        GeneralUtility::callUserFunction('&TYPO3\\CMS\\Core\\Tests\\Unit\\Utility\\GeneralUtilityTest->user_calledUserFunctionCountCallers', $inputData, $this);
        $this->assertEquals(1, sizeof($inputData['called']));
    }

    /**
     * Takes the object hash and adds it to the passed array. In case
     * persisting the objects would not work we'd see two different
     * parent objects.
     *
     * @param $params
     */
    public function user_calledUserFunctionCountCallers(&$params)
    {
        $params['called'][spl_object_hash($this)]++;
    }

    /**
     * @test
     */
    public function callUserFunctionAcceptsClosures()
    {
        $inputData = array('foo' => 'bar');
        $closure = function ($parameters, $reference) use ($inputData) {
            $reference->assertEquals($inputData, $parameters, 'Passed data doesn\'t match expected output');
            return 'Worked fine';
        };
        $this->assertEquals('Worked fine', GeneralUtility::callUserFunction($closure, $inputData, $this));
    }

    ///////////////////////////////////////////////////
    // Tests concerning generateRandomBytes
    ///////////////////////////////////////////////////
    /**
     * @test
     * @dataProvider generateRandomBytesReturnsExpectedAmountOfBytesDataProvider
     * @param int $numberOfBytes Number of Bytes to generate
     */
    public function generateRandomBytesReturnsExpectedAmountOfBytes($numberOfBytes)
    {
        $this->assertEquals(strlen(GeneralUtility::generateRandomBytes($numberOfBytes)), $numberOfBytes);
    }

    public function generateRandomBytesReturnsExpectedAmountOfBytesDataProvider()
    {
        return array(
            array(1),
            array(2),
            array(3),
            array(4),
            array(7),
            array(8),
            array(31),
            array(32),
            array(100),
            array(102),
            array(4000),
            array(4095),
            array(4096),
            array(4097),
            array(8000)
        );
    }

    /**
     * @test
     * @dataProvider generateRandomBytesReturnsDifferentBytesDuringDifferentCallsDataProvider
     * @param int $numberOfBytes  Number of Bytes to generate
     */
    public function generateRandomBytesReturnsDifferentBytesDuringDifferentCalls($numberOfBytes)
    {
        $results = array();
        $numberOfTests = 5;
        // generate a few random numbers
        for ($i = 0; $i < $numberOfTests; $i++) {
            $results[$i] = GeneralUtility::generateRandomBytes($numberOfBytes);
        }
        // array_unique would filter out duplicates
        $this->assertEquals($results, array_unique($results));
    }

    public function generateRandomBytesReturnsDifferentBytesDuringDifferentCallsDataProvider()
    {
        return array(
            array(32),
            array(128),
            array(4096)
        );
    }

    ///////////////////////////////////////////////////
    // Tests concerning substUrlsInPlainText
    ///////////////////////////////////////////////////
    /**
     * @return array
     */
    public function substUrlsInPlainTextDataProvider()
    {
        $urlMatch = 'http://example.com/index.php\\?RDCT=[0-9a-z]{20}';
        return array(
            array('http://only-url.com', '|^' . $urlMatch . '$|'),
            array('https://only-secure-url.com', '|^' . $urlMatch . '$|'),
            array('A http://url in the sentence.', '|^A ' . $urlMatch . ' in the sentence\\.$|'),
            array('URL in round brackets (http://www.example.com) in the sentence.', '|^URL in round brackets \\(' . $urlMatch . '\\) in the sentence.$|'),
            array('URL in square brackets [http://www.example.com/a/b.php?c[d]=e] in the sentence.', '|^URL in square brackets \\[' . $urlMatch . '\\] in the sentence.$|'),
            array('URL in square brackets at the end of the sentence [http://www.example.com/a/b.php?c[d]=e].', '|^URL in square brackets at the end of the sentence \\[' . $urlMatch . '].$|'),
            array('Square brackets in the http://www.url.com?tt_news[uid]=1', '|^Square brackets in the ' . $urlMatch . '$|'),
            array('URL with http://dot.com.', '|^URL with ' . $urlMatch . '.$|'),
            array('URL in <a href="http://www.example.com/">a tag</a>', '|^URL in <a href="' . $urlMatch . '">a tag</a\\>$|'),
            array('URL in HTML <b>http://www.example.com</b><br />', '|^URL in HTML <b>' . $urlMatch . '</b><br />$|'),
            array('URL with http://username@example.com/', '|^URL with ' . $urlMatch . '$|'),
            array('Secret in URL http://username:secret@example.com', '|^Secret in URL ' . $urlMatch . '$|'),
            array('URL in quotation marks "http://example.com"', '|^URL in quotation marks "' . $urlMatch . '"$|'),
            array('URL with umlauts http://müller.de', '|^URL with umlauts ' . $urlMatch . '$|'),
            array('Multiline
text with a http://url.com', '|^Multiline
text with a ' . $urlMatch . '$|s'),
            array('http://www.shout.com!', '|^' . $urlMatch . '!$|'),
            array('And with two URLs http://www.two.com/abc http://urls.com/abc?x=1&y=2', '|^And with two URLs ' . $urlMatch . ' ' . $urlMatch . '$|')
        );
    }

    /**
     * @test
     * @dataProvider substUrlsInPlainTextDataProvider
     * @param string $input Text to recognise URLs from
     * @param string $expected Text with correctly detected URLs
     */
    public function substUrlsInPlainText($input, $expected)
    {
        $GLOBALS['TYPO3_DB'] = $this->getMock(\TYPO3\CMS\Core\Database\DatabaseConnection::class, array(), array(), '', false);
        $this->assertTrue(preg_match($expected, GeneralUtility::substUrlsInPlainText($input, 1, 'http://example.com/index.php')) == 1);
    }

    /**
     * @return array
     */
    public function getRedirectUrlFromHttpHeadersDataProvider()
    {
        return array(
            'Extracts redirect URL from Location header' => array("HTTP/1.0 302 Redirect\r\nServer: Apache\r\nLocation: http://example.com/\r\nX-pad: avoid browser bug\r\n\r\nLocation: test\r\n", 'http://example.com/'),
            'Returns empty string if no Location is found in header' => array("HTTP/1.0 302 Redirect\r\nServer: Apache\r\nX-pad: avoid browser bug\r\n\r\nLocation: test\r\n", ''),
        );
    }

    /**
     * @param string $httpResponse
     * @param string $expected
     * @test
     * @dataProvider getRedirectUrlFromHttpHeadersDataProvider
     */
    public function getRedirectUrlReturnsRedirectUrlFromHttpResponse($httpResponse, $expected)
    {
        $this->assertEquals($expected, GeneralUtilityFixture::getRedirectUrlFromHttpHeaders($httpResponse));
    }

    /**
     * @return array
     */
    public function getStripHttpHeadersDataProvider()
    {
        return array(
            'Simple content' => array("HTTP/1.0 302 Redirect\r\nServer: Apache\r\nX-pad: avoid browser bug\r\n\r\nHello, world!", 'Hello, world!'),
            'Content with multiple returns' => array("HTTP/1.0 302 Redirect\r\nServer: Apache\r\nX-pad: avoid browser bug\r\n\r\nHello, world!\r\n\r\nAnother hello here!", "Hello, world!\r\n\r\nAnother hello here!"),
        );
    }

    /**
     * @param string $httpResponse
     * @param string $expected
     * @test
     * @dataProvider getStripHttpHeadersDataProvider
     */
    public function stripHttpHeadersStripsHeadersFromHttpResponse($httpResponse, $expected)
    {
        $this->assertEquals($expected, GeneralUtilityFixture::stripHttpHeaders($httpResponse));
    }

    /**
     * @test
     */
    public function getAllFilesAndFoldersInPathReturnsArrayWithMd5Keys()
    {
        $directory = PATH_site . 'typo3temp/' . $this->getUniqueId('directory_');
        mkdir($directory);
        $filesAndDirectories = GeneralUtility::getAllFilesAndFoldersInPath(array(), $directory, '', true);
        $check = true;
        foreach ($filesAndDirectories as $md5 => $path) {
            if (!preg_match('/^[a-f0-9]{32}$/', $md5)) {
                $check = false;
            }
        }
        GeneralUtility::rmdir($directory);
        $this->assertTrue($check);
    }
}
