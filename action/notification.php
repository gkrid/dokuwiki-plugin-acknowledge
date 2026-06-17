<?php

/**
 * DokuWiki Plugin acknowledge (Notification Action Component)
 *
 * Integration with the notification plugin.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\AuthPlugin;

class action_plugin_acknowledge_notification extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PLUGIN_NOTIFICATION_REGISTER_SOURCE', 'AFTER', $this, 'registerSource');
        $controller->register_hook('PLUGIN_NOTIFICATION_GATHER', 'AFTER', $this, 'gatherNotifications');
        $controller->register_hook('PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES', 'AFTER', $this, 'cacheDependencies');
    }

    /**
     * Announce acknowledge as a notification source.
     *
     * @param Event $event PLUGIN_NOTIFICATION_REGISTER_SOURCE
     * @return void
     */
    public function registerSource(Event $event)
    {
        if (!$this->getConf('notification_integration')) return;
        $event->data[] = 'acknowledge';
    }

    /**
     * Gather pending acknowledgements for the given user.
     *
     * @param Event $event PLUGIN_NOTIFICATION_GATHER
     * @return void
     */
    public function gatherNotifications(Event $event)
    {
        if (!$this->getConf('notification_integration')) return;
        if (!in_array('acknowledge', $event->data['plugins'])) return;

        /** @var AuthPlugin $auth */
        global $auth;

        // resolve the target user's groups from auth (cron runs need this)
        $user = $event->data['user'];
        $userData = $auth->getUserData($user);
        if ($userData === false) return;
        $groups = $userData['grps'] ?? [];

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $rows = $helper->getUserAcknowledgements($user, $groups, 'due');
        if (!$rows) return;

        foreach ($rows as $row) {
            $page = $row['page'];

            // don't notify about pages that cannot be acknowledged yet (approve check)
            if ($helper->isBlockedByApprove($page)) continue;

            $event->data['notifications'][] = [
                'plugin' => 'acknowledge',
                'id' => $page . ':' . $row['lastmod'], // notification is bound to id and rev
                'full' => sprintf($this->getLang('notification'), $this->buildPageLink($page)),
                'brief' => $this->buildPageLink($page),
                'timestamp' => (int) $row['lastmod'],
            ];
        }
    }

    /**
     * @param Event $event PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES
     * @return void
     */
    public function cacheDependencies(Event $event)
    {
        if (!$this->getConf('notification_integration')) return;
        if (!in_array('acknowledge', $event->data['plugins'])) return;
        $event->data['_nocache'] = true;
    }

    /**
     * Build the wiki link
     *
     * @param string $page Page ID
     * @return string HTML anchor
     */
    protected function buildPageLink($page)
    {
        if (useHeading('content')) {
            $heading = p_get_first_heading($page);
            $title = blank($heading) ? noNSorNS($page) : $heading;
        } else {
            $title = noNSorNS($page);
        }

        return '<a class="wikilink1" href="' . wl($page, '', true) . '">' . hsc($title) . '</a>';
    }
}
