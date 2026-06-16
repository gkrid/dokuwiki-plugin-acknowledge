<?php

/**
 * DokuWiki Plugin acknowledge (SQLite Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_acknowledge_sqlite extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_DATABASE_UPGRADE', 'AFTER', $this, 'handleUpgrade');
    }

    /**
     * Handle Migration events
     *
     * @param Event $event
     * @param $param
     * @return void
     */
    public function handleUpgrade(Event $event, $param)
    {
        if ($event->data['sqlite']->getAdapter()->getDbname() !== 'acknowledgement') {
            return;
        }
        $to = $event->data['to'];
        if ($to !== 3) return; // only handle upgrade to version 3

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $helper->updatePageIndex();
    }
}
