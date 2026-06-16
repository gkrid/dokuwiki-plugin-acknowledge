<?php

/**
 * DokuWiki Plugin acknowledge (AJAX Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Form\Form;

class action_plugin_acknowledge_ajax extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxAcknowledge');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxAutocomplete');
    }

    /**
     * @param Event $event
     * @param $param
     */
    public function handleAjaxAcknowledge(Event $event, $param)
    {
        if ($event->data === 'plugin_acknowledge_acknowledge') {
            $event->stopPropagation();
            $event->preventDefault();

            global $INPUT;
            $id = $INPUT->str('id');

            if (page_exists($id)) {
                echo $this->html();
            }
        }
    }

    /**
     * @param Event $event
     * @return void
     */
    public function handleAjaxAutocomplete(Event $event)
    {
        if ($event->data === 'plugin_acknowledge_autocomplete') {
            if (!checkSecurityToken()) return;

            global $INPUT;

            $event->stopPropagation();
            $event->preventDefault();

            /** @var helper_plugin_acknowledge $hlp */
            $hlp = $this->loadHelper('acknowledge');

            $found = [];

            if ($INPUT->has('user')) {
                $search = $INPUT->str('user');
                $knownUsers = $hlp->getUsers();
                $found = array_filter($knownUsers, function ($user) use ($search) {
                    return (strstr(strtolower($user['label']), strtolower($search))) !== false ? $user : null;
                });
            }

            if ($INPUT->has('pg')) {
                $search = $INPUT->str('pg');
                $pages = ft_pageLookup($search, true);
                $found = array_map(function ($id, $title) {
                    return ['value' => $id, 'label' => $title ?? $id];
                }, array_keys($pages), array_values($pages));
            }

            header('Content-Type: application/json');

            echo json_encode($found);
        }
    }

    /**
     * Returns the acknowledgment form/confirmation
     *
     * @return string The HTML to display
     */
    protected function html()
    {
        global $INPUT;
        global $USERINFO;
        $id = $INPUT->str('id');
        $ackSubmitted = $INPUT->bool('ack');
        $user = $INPUT->server->str('REMOTE_USER');
        if ($id === '' || $user === '') return '';

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        // only display for users assigned to the page
        if (!$helper->isUserAssigned($id, $user, $USERINFO['grps'])) {
            return '';
        }

        // if the approve plugin is active, only show if the page is approved
        if ($helper->isBlockedByApprove($id)) {
            return '';
        }

        if ($ackSubmitted) {
            $helper->saveAcknowledgement($id, $user);
        }

        $ack = $helper->hasUserAcknowledged($id, $user);

        $html = '<div class="' . ($ack ? 'ack' : 'noack') . '">';
        $html .= inlineSVG(__DIR__ . '/../admin.svg');
        $html .= '</div>';

        if ($ack) {
            $html .= '<div>';
            $html .= '<h4>';
            $html .= $this->getLang('ackOk');
            $html .= '</h4>';
            $html .= sprintf($this->getLang('ackGranted'), dformat($ack));
            $html .= '</div>';
        } else {
            $html .= '<div>';
            $html .= '<h4>' . $this->getLang('ackRequired') . '</h4>';
            $latest = $helper->getLatestUserAcknowledgement($id, $user);
            if ($latest) {
                $html .= '<a href="'
                    . wl($id, ['do' => 'diff', 'at' => $latest], false, '&') . '">'
                    . sprintf($this->getLang('ackDiff'), dformat($latest))
                    . '</a><br>';
            }

            $form = new Form(['id' => 'ackForm']);
            $form->addCheckbox('ack', $this->getLang('ackText'))->attr('required', 'required');
            $form->addHTML(
                '<br><button type="submit" name="acksubmit" id="ack-submit">'
                . $this->getLang('ackButton')
                . '</button>'
            );

            $html .= $form->toHTML();
            $html .= '</div>';
        }

        return $html;
    }
}
