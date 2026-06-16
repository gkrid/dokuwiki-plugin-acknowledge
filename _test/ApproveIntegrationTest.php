<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;

/**
 * Tests for the approve plugin integration (helper->isBlockedByApprove)
 *
 * The test drives the approve plugin exclusively through its public helper API
 * (addMaintainer / handlePageEdit / setApprovedStatus) rather than touching its
 * database directly, so it exercises the real integration path.
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class ApproveIntegrationTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite', 'approve'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var \helper_plugin_approve_db */
    protected $approve;

    public function setUp(): void
    {
        parent::setUp();

        // setApprovedStatus() records the approving user from $INFO
        global $INFO;
        $INFO['client'] = 'someapprover';

        $this->helper = plugin_load('helper', 'acknowledge');
        $this->approve = plugin_load('helper', 'approve_db');

        // track the whole "approved:" namespace
        $this->approve->addMaintainer('approved:**', 'someapprover');
    }

    /**
     * Create a wiki page and let approve record its current revision,
     * just as the COMMON_WIKIPAGE_SAVE hook would.
     *
     * @param string $id page id
     * @return void
     */
    protected function createPage($id)
    {
        saveWikiText($id, 'content', 'test');
        $this->approve->handlePageEdit($id);
    }

    /**
     * A page outside any approve-maintained namespace is never blocked.
     */
    public function testUntrackedPageNotBlocked()
    {
        $id = 'free:page';
        $this->createPage($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }

    /**
     * A maintained page that is still a draft is blocked.
     */
    public function testDraftPageBlocked()
    {
        $id = 'approved:draft';
        $this->createPage($id);
        self::assertTrue($this->helper->isBlockedByApprove($id));
    }

    /**
     * A maintained page whose current revision is approved is not blocked.
     */
    public function testApprovedPageNotBlocked()
    {
        $id = 'approved:done';
        $this->createPage($id);
        $this->approve->setApprovedStatus($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }

    /**
     * With the integration disabled, even a maintained draft is not blocked.
     */
    public function testDisabledIntegrationNeverBlocks()
    {
        global $conf;
        $conf['plugin']['acknowledge']['approve_integration'] = 0;

        $id = 'approved:ignored';
        $this->createPage($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }
}
