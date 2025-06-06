<?php

namespace Roundcube\Tests\Actions\Contacts;

use Roundcube\Tests\ActionTestCase;
use Roundcube\Tests\OutputJsonMock;

/**
 * Test class to test rcmail_action_contacts_qrcode
 */
class QrcodeTest extends ActionTestCase
{
    /**
     * Test run() method
     */
    public function test_run()
    {
        $action = new \rcmail_action_contacts_qrcode();
        $output = $this->initOutput(\rcmail_action::MODE_HTTP, 'contacts', 'qrcode');

        $this->assertInstanceOf(\rcmail_action::class, $action);
        $this->assertTrue($action->checks());

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        $this->assertContains('HTTP/1.0 404 Contact not found', $output->headers);
        $this->assertSame('', $result);

        $type = $action->check_support();

        if (!$type) {
            $this->markTestSkipped();
        }

        $db = \rcmail::get_instance()->get_dbh();
        $query = $db->query('SELECT `contact_id` FROM `contacts` WHERE `user_id` = 1 AND `name` = \'Jon Snow\'');
        $contact = $db->fetch_assoc($query);

        $_GET = ['_cid' => $contact['contact_id'], '_source' => '0'];

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        if ($type == 'image/png') {
            $this->assertContains('Content-Type: image/png', $output->headers);
            $this->assertMatchesRegularExpression('/^\x89\x50\x4E\x47/', $result);
        } else {
            $this->assertContains('Content-Type: image/svg+xml', $output->headers);
            $this->assertMatchesRegularExpression('/^<\?xml/', $result);
            $this->assertMatchesRegularExpression('/<svg /', $result);
            $this->assertMatchesRegularExpression('/<rect /', $result);
        }
    }
}
