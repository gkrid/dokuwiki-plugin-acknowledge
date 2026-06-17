<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;
use dokuwiki\Extension\Event;

/**
 * Tests for the notification plugin integration (action/notification.php).
 *
 * The notification plugin is pull-based: it fires PLUGIN_NOTIFICATION_GATHER per user and
 * deduplicates the returned notifications by (plugin, id, user) permanently. These tests drive
 * the gather handler directly and assert on the produced notification ids, which encode the
 * "$page:$lastmod" semantics that define when a user is (re-)notified.
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class NotificationIntegrationTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite', 'approve'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var \dokuwiki\plugin\sqlite\SQLiteDB */
    protected $db;

    /** @var \helper_plugin_approve_db */
    protected $approve;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        /** @var \auth_plugin_authplain $auth */
        global $auth;
        $auth->createUser('alice', 'none', 'alice', 'alice@example.com', ['staff']);
    }

    public function setUp(): void
    {
        parent::setUp();

        // setApprovedStatus() records the approving user from $INFO
        global $INFO;
        $INFO['client'] = 'someapprover';

        $this->helper = plugin_load('helper', 'acknowledge');
        $this->db = $this->helper->getDB();
        /** @var \helper_plugin_approve_db approve */
        $this->approve = plugin_load('helper', 'approve_db');
        $this->approve->addMaintainer('approved:**', 'someapprover');

        // due (never acked), current (acked >= lastmod), outdated (acked < lastmod)
        $pages = "REPLACE INTO pages(page,lastmod)
            VALUES ('wiki:due_new', 1000),
            ('wiki:done', 1000),
            ('wiki:outdated', 2000)";
        $this->db->exec($pages);

        $assignments = "REPLACE INTO assignments(page,pageassignees)
            VALUES ('wiki:due_new', '@staff'),
            ('wiki:done', '@staff'),
            ('wiki:outdated', '@staff')";
        $this->db->exec($assignments);

        $acks = "REPLACE INTO acks(page,user,ack)
            VALUES ('wiki:done', 'alice', 2000),
            ('wiki:outdated', 'alice', 1000)";
        $this->db->exec($acks);
    }

    /**
     * Invoke the real gather handler for a user and return the produced notifications.
     *
     * @param string $user
     * @return array
     */
    protected function gather($user)
    {
        $data = [
            'plugins' => ['acknowledge'],
            'user' => $user,
            'notifications' => [],
        ];
        $event = new Event('PLUGIN_NOTIFICATION_GATHER', $data);

        /** @var \action_plugin_acknowledge_notification $action */
        $action = plugin_load('action', 'acknowledge_notification');
        $action->gatherNotifications($event);

        return $data['notifications'];
    }

    /**
     * Due and outdated pages are notified; up-to-date pages are not. The id is "$page:$lastmod".
     */
    public function testDueAndOutdatedNotifiedCurrentNot()
    {
        $ids = array_column($this->gather('alice'), 'id');
        sort($ids);

        $this->assertEquals(['wiki:due_new:1000', 'wiki:outdated:2000'], $ids);
    }

    /**
     * The notification carries the assigned plugin name and a rendered link.
     */
    public function testNotificationShape()
    {
        $notifications = $this->gather('alice');
        $this->assertNotEmpty($notifications);

        $notification = $notifications[0];
        $this->assertEquals('acknowledge', $notification['plugin']);
        $this->assertStringContainsString('href', $notification['full']);
        $this->assertIsInt($notification['timestamp']);
    }

    /**
     * A page edit (new lastmod) yields a new id, so the user is re-notified after re-ack falls due.
     */
    public function testPageChangeProducesNewId()
    {
        $before = array_column($this->gather('alice'), 'id');
        $this->assertContains('wiki:due_new:1000', $before);

        // simulate a page edit bumping the stored modification date
        $this->db->exec("UPDATE pages SET lastmod = 1500 WHERE page = 'wiki:due_new'");

        $after = array_column($this->gather('alice'), 'id');
        $this->assertContains('wiki:due_new:1500', $after);
        $this->assertNotContains('wiki:due_new:1000', $after);
    }

    /**
     * Changing assignment rules (without a page edit) keeps the same id, so dedup prevents re-notify.
     */
    public function testAssignmentChurnKeepsSameId()
    {
        $before = array_column($this->gather('alice'), 'id');
        $this->assertContains('wiki:due_new:1000', $before);

        // rule churn: widen the assignees but leave lastmod untouched
        $this->db->exec(
            "REPLACE INTO assignments(page,pageassignees) VALUES ('wiki:due_new', '@staff,@other')"
        );

        $after = array_column($this->gather('alice'), 'id');
        $this->assertContains('wiki:due_new:1000', $after);
    }

    /**
     * With the integration disabled, the gather handler produces nothing.
     */
    public function testDisabledIntegrationProducesNothing()
    {
        global $conf;
        $conf['plugin']['acknowledge']['notification_integration'] = 0;

        $this->assertEquals([], $this->gather('alice'));
    }

    /**
     * A page blocked by approve is not notified until it is approved.
     */
    public function testApproveBlockedPageNotNotifiedUntilApproved()
    {
        $id = 'approved:doc';
        saveWikiText($id, 'content', 'test');
        $this->approve->handlePageEdit($id);
        $lastmod = (int) @filemtime(wikiFN($id));

        $this->db->exec("REPLACE INTO pages(page,lastmod) VALUES (?, ?)", [$id, $lastmod]);
        $this->db->exec("REPLACE INTO assignments(page,pageassignees) VALUES (?, '@staff')", [$id]);

        // still a draft -> blocked -> not notified
        $ids = array_column($this->gather('alice'), 'id');
        $this->assertNotContains($id . ':' . $lastmod, $ids);

        // once approved -> notified
        $this->approve->setApprovedStatus($id);
        $ids = array_column($this->gather('alice'), 'id');
        $this->assertContains($id . ':' . $lastmod, $ids);
    }
}
