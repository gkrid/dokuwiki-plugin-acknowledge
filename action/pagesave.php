<?php

/**
 * DokuWiki Plugin acknowledge (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_acknowledge_pagesave extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handlePageSave');
    }

    /**
     * Manage page meta data
     *
     * Store page last modified date
     * Handle page deletions
     * Handle page creations
     *
     * @param Event $event
     * @param $param
     */
    public function handlePageSave(Event $event, $param)
    {
        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        if ($event->data['changeType'] === DOKU_CHANGE_TYPE_DELETE) {
            $helper->removePage($event->data['id']); // this cascades to assignments
        } elseif ($event->data['changeType'] !== DOKU_CHANGE_TYPE_MINOR_EDIT) {
            $helper->storePageDate($event->data['id'], $event->data['newRevision'], $event->data['newContent']);
        }

        // Remove page assignees here because the syntax might have been removed
        // they are readded on metadata rendering if still there
        $helper->clearPageAssignments($event->data['id']);

        if ($event->data['changeType'] === DOKU_CHANGE_TYPE_CREATE) {
            // new pages need to have their auto assignments updated based on the existing patterns
            $helper->setAutoAssignees($event->data['id']);
        }
    }
}
