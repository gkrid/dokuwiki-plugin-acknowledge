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
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxUserList');
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
     * Returns the full user list for a report section (loaded on demand)
     *
     * @param Event $event
     * @return void
     */
    public function handleAjaxUserList(Event $event)
    {
        if ($event->data !== 'plugin_acknowledge_userlist') return;

        $event->stopPropagation();
        $event->preventDefault();

        if (!auth_ismanager()) return;

        global $INPUT;
        $id = $INPUT->str('id');
        $status = $INPUT->str('status');

        if (!page_exists($id)) return;
        if (!in_array($status, ['current', 'due'], true)) return;

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        if (!$helper->getPageAssignees($id)) return;

        echo $this->userListHtml($helper->getPageAcknowledgements($id, '', $status));
    }

    /**
     * Returns the acknowledgment form/confirmation and optionally management report
     *
     * @return string The HTML to display
     */
    protected function html()
    {
        global $INPUT;
        $id = $INPUT->str('id');
        $user = $INPUT->server->str('REMOTE_USER');
        if ($id === '' || $user === '') return '';

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        return $this->bannerHtml($id, $user, $helper) . $this->reportHtml($id, $helper);
    }

    /**
     * Returns the personal acknowledgement banner
     *
     * @param string $id
     * @param string $user
     * @param helper_plugin_acknowledge $helper
     * @return string
     */
    protected function bannerHtml($id, $user, helper_plugin_acknowledge $helper)
    {
        global $INPUT;
        global $USERINFO;

        // only display for users assigned to the page
        if (!$helper->isUserAssigned($id, $user, $USERINFO['grps'])) {
            return '';
        }

        // if the approve plugin is active, only show if the page is approved
        if ($helper->isBlockedByApprove($id)) {
            return '';
        }

        if ($INPUT->bool('ack')) {
            $helper->saveAcknowledgement($id, $user);
        }

        $ack = $helper->hasUserAcknowledged($id, $user);

        $html = '<div class="plugin-acknowledge-box ack' . ($ack ? ' done' : '') . '">';
        $html .= '<div class="ack-icon">';
        $html .= inlineSVG(__DIR__ . '/../admin.svg');
        $html .= '</div>';

        $html .= '<div class="content">';
        if ($ack) {
            $html .= '<h4>';
            $html .= $this->getLang('ackOk');
            $html .= '</h4>';
            $html .= sprintf($this->getLang('ackGranted'), dformat($ack));
        } else {
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
        }
        $html .= '</div>'; // content
        $html .= '</div>'; // box

        return $html;
    }

    /**
     * Returns the manager/admin report box
     *
     * @param string $id
     * @param helper_plugin_acknowledge $helper
     * @return string
     */
    protected function reportHtml($id, helper_plugin_acknowledge $helper)
    {
        $mode = $this->getConf('onpage_report');
        if ($mode === 'off') return '';

        if (!auth_ismanager()) return '';

        if (!$helper->getPageAssignees($id)) return '';

        $html = '<div class="plugin-acknowledge-box report">';

        $html .= '<div class="ack-icon">';
        $html .= inlineSVG(__DIR__ . '/../admin.svg');
        $html .= '</div>';

        $html .= '<div class="content">';
        $html .= '<h3>' . $this->getLang('reportTitle') . '</h3>';

        // resolve group membership once, derive both counts arithmetically
        $counts = $helper->getPageAcknowledgementCounts($id);

        if ($mode === 'acknowledged' || $mode === 'both') {
            $html .= '<p>' . $this->getLang('reportAcknowledgedTitle') . '</p>';
            $html .= $this->userCountHtml($id, 'current', $counts['current']);
        }

        if ($mode === 'pending' || $mode === 'both') {
            $html .= '<p>' . $this->getLang('reportPendingTitle') . '</p>';
            $html .= $this->userCountHtml($id, 'due', $counts['due']);
        }

        $html .= '</div>'; // content
        $html .= '</div>'; // box

        return $html;
    }

    /**
     * Renders a clickable user count that loads the full user list on demand
     *
     * @param string $id
     * @param string $status acknowledgement status ('current' or 'due')
     * @param int $count
     * @return string
     */
    protected function userCountHtml($id, $status, $count)
    {
        if (!$count) {
            return '<p>' . $this->getLang('reportNobody') . '</p>';
        }

        return '<p><a href="#" class="plugin-acknowledge-loadusers"'
            . ' data-id="' . hsc($id) . '" data-status="' . hsc($status) . '">'
            . sprintf($this->getLang('reportUserCount'), $count)
            . '</a></p>';
    }

    /**
     * Renders a list of users from acknowledgement records.
     *
     * @param array $rows
     * @return string
     */
    protected function userListHtml($rows)
    {
        if (!$rows) {
            return '<p>' . $this->getLang('reportNobody') . '</p>';
        }

        $html = '<ul>';
        foreach ($rows as $row) {
            $html .= '<li>';
            $html .= userlink($row['user']);

            if (!empty($row['ack'])) {
                $html .= ' ' . $this->getLang('reportAckedOn') . ' ' . hsc(dformat($row['ack']));
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
