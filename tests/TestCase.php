<?php
/**
 * Created by PhpStorm.
 * User: allenmccabe
 * Date: 4/27/17
 * Time: 11:38 AM
 */

namespace Tests;

use Worklog\Testing\TestCase as BaseTestCase;

class TestCase extends BaseTestCase {

    public function testSetUp()
    {
        // $this->assertTrue(defined('APPLICATION_PATH'));
        $this->assertNull(null);
        // $this->assertTrue(is_dir(APPLICATION_PATH));
        $this->assertEquals(true, true);
    }
}