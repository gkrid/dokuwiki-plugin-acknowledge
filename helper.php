<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\ChangeLog\PageChangeLog;
use dokuwiki\ErrorHandler;
use dokuwiki\Extension\AuthPlugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

/**
 * DokuWiki Plugin acknowledge (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class helper_plugin_acknowledge extends Plugin
{
    protected $db;

    // region Database Management

    /**
     * Constructor
     *
     * @return void
     * @throws Exception
     */
    public function __construct()
    {
        if ($this->db === null) {
            try {
                $this->db = new SQLiteDB('acknowledgement', __DIR__ . '/db');

                // register our custom functions
                $this->db->getPdo()->sqliteCreateFunction('AUTH_ISMEMBER', [$this, 'auth_isMember'], -1);
                $this->db->getPdo()->sqliteCreateFunction('MATCHES_PAGE_PATTERN', [$this, 'matchPagePattern'], 2);
            } catch (\Exception $exception) {
                if (defined('DOKU_UNITTEST')) throw new \RuntimeException('Could not load SQLite', 0, $exception);
                ErrorHandler::logException($exception);
                msg($this->getLang('error sqlite plugin missing'), -1);
                throw $exception;
            }
        }
    }

    /**
     * Wrapper for test DB access
     *
     * @return SQLiteDB
     */
    public function getDB()
    {
        return $this->db;
    }

    /**
     * Wrapper function for auth_isMember which accepts groups as string
     *
     * @param string $memberList
     * @param string $user
     * @param string $groups
     *
     * @return bool
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function auth_isMember($memberList, $user, $groups)
    {
        return auth_isMember($memberList, $user, explode('///', $groups));
    }

    /**
     * Fills the page index with all unknown pages from the fulltext index
     * @return void
     */
    public function updatePageIndex()
    {
        $pages = idx_getIndex('page', '');
        $sql = "INSERT OR IGNORE INTO pages (page, lastmod) VALUES (?,?)";

        $this->db->getPdo()->beginTransaction();
        foreach ($pages as $page) {
            $page = trim($page);
            $lastmod = @filemtime(wikiFN($page));
            if ($lastmod) {
                try {
                    $this->db->exec($sql, [$page, $lastmod]);
                } catch (\Exception $exception) {
                    $this->db->getPdo()->rollBack();
                    throw $exception;
                }
            }
        }
        $this->db->getPdo()->commit();
    }

    /**
     * Check if the given pattern matches the given page
     *
     * @param string $pattern the pattern to check against
     * @param string $page the cleaned pageid to check
     * @return bool
     */
    public function matchPagePattern($pattern, $page)
    {
        if (trim($pattern, ':') == '**') return true; // match all

        // regex patterns
        if ($pattern[0] == '/') {
            return (bool)preg_match($pattern, ":$page");
        }

        $pns = ':' . getNS($page) . ':';

        $ans = ':' . cleanID($pattern) . ':';
        if (substr($pattern, -2) == '**') {
            // upper namespaces match
            if (strpos($pns, $ans) === 0) {
                return true;
            }
        } elseif (substr($pattern, -1) == '*') {
            // namespaces match exact
            if ($ans === $pns) {
                return true;
            }
        } elseif (cleanID($pattern) == $page) {
            // exact match
            return true;
        }

        return false;
    }

    /**
     * Returns all users, formatted for autocomplete
     *
     * @return array
     */
    public function getUsers()
    {
        /** @var AuthPlugin $auth */
        global $auth;

        if (!$auth->canDo('getUsers')) {
            return [];
        }

        $cb = function ($k, $v) {
            return [
              'value' => $k,
              'label' => $k  . ' (' . $v['name'] . ')'
            ];
        };
        $users = $auth->retrieveUsers();
        $users = array_map($cb, array_keys($users), array_values($users));

        return $users;
    }

    // endregion
    // region Page Data

    /**
     * Delete a page
     *
     * Cascades to delete all assigned data, etc.
     *
     * @param string $page Page ID
     */
    public function removePage($page)
    {
        $sql = "DELETE FROM pages WHERE page = ?";
        $this->db->exec($sql, $page);
    }

    /**
     * Update last modified date of page if content has changed
     *
     * @param string $page Page ID
     * @param int $lastmod timestamp of last non-minor change
     */
    public function storePageDate($page, $lastmod, $newContent)
    {
        $changelog = new PageChangeLog($page);
        $revs = $changelog->getRevisions(0, 1);

        // compare content
        if (!empty($revs)) {
            $oldContent = str_replace(NL, '', io_readFile(wikiFN($page, $revs[0])));
            $newContent = str_replace(NL, '', $newContent);
            if ($oldContent === $newContent) return;
        }

        $sql = "REPLACE INTO pages (page, lastmod) VALUES (?,?)";
        $this->db->exec($sql, [$page, $lastmod]);
    }

    // endregion
    // region Assignments

    /**
     * Clears direct assignments for a page
     *
     * @param string $page Page ID
     */
    public function clearPageAssignments($page)
    {
        $sql = "UPDATE assignments SET pageassignees = '' WHERE page = ?";
        $this->db->exec($sql, $page);
    }

    /**
     * Set assignees for a given page as manually specified
     *
     * @param string $page Page ID
     * @param string $assignees
     * @return void
     */
    public function setPageAssignees($page, $assignees)
    {
        $assignees = implode(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));

        $sql = "REPLACE INTO assignments ('page', 'pageassignees') VALUES (?,?)";
        $this->db->exec($sql, [$page, $assignees]);
    }

    /**
     * Set assignees for a given page from the patterns
     * @param string $page Page ID
     */
    public function setAutoAssignees($page)
    {
        $patterns = $this->getAssignmentPatterns();

        // given assignees
        $assignees = '';

        // find all patterns that match the page and add the configured assignees
        foreach ($patterns as $pattern => $assign) {
            if ($this->matchPagePattern($pattern, $page)) {
                $assignees .= ',' . $assign;
            }
        }

        // remove duplicates and empty entries
        $assignees = implode(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));

        // store the assignees
        $sql = "REPLACE INTO assignments ('page', 'autoassignees') VALUES (?,?)";
        $this->db->exec($sql, [$page, $assignees]);
    }

    /**
     * Is the given user one of the assignees for this page
     *
     * @param string $page Page ID
     * @param string $user user name to check
     * @param string[] $groups groups this user is in
     * @return bool
     */
    public function isUserAssigned($page, $user, $groups)
    {
        $sql = "SELECT pageassignees,autoassignees FROM assignments WHERE page = ?";
        $record = $this->db->queryRecord($sql, $page);
        if (!$record) return false;
        $assignees = $record['pageassignees'] . ',' . $record['autoassignees'];
        return auth_isMember($assignees, $user, $groups);
    }

    /**
     * Fetch all assignments for a given user, with additional page information,
     * by default filtering already granted acknowledgements.
     * Filter can be switched off via $includeDone
     *
     * @param string $user
     * @param array $groups
     * @param bool $includeDone
     *
     * @return array|bool
     */
    public function getUserAssignments($user, $groups, $includeDone = false)
    {
        $sql = "SELECT A.page, A.pageassignees, A.autoassignees, B.lastmod, C.user, C.ack FROM assignments A
                JOIN pages B
                ON A.page = B.page
                LEFT JOIN acks C
                ON A.page = C.page AND ( (C.user = ? AND C.ack > B.lastmod) )
                WHERE AUTH_ISMEMBER(A.pageassignees || ',' || A.autoassignees , ? , ?)";

        if (!$includeDone) {
            $sql .= ' AND ack IS NULL';
        }

        return $this->db->queryAll($sql, $user, $user, implode('///', $groups));
    }

    /**
     * Resolve names of users assigned to a given page
     *
     * This can be slow on huge user bases!
     *
     * @param string $page
     * @return array|false
     */
    public function getPageAssignees($page)
    {
        /** @var AuthPlugin $auth */
        global $auth;

        $sql = "SELECT pageassignees || ',' || autoassignees AS 'assignments'
                  FROM assignments
                 WHERE page = ?";
        $assignments = $this->db->queryValue($sql, $page);

        $users = [];
        foreach (explode(',', $assignments) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            if ($item[0] == '@') {
                $users = array_merge(
                    $users,
                    array_keys($auth->retrieveUsers(0, 0, ['grps' => substr($item, 1)]))
                );
            } else {
                $users[] = $item;
            }
        }

        return array_unique($users);
    }

    // endregion
    // region Assignment Patterns

    /**
     * Get all the assignment patterns
     * @return array (pattern => assignees)
     */
    public function getAssignmentPatterns()
    {
        $sql = "SELECT pattern, assignees FROM assignments_patterns";
        return $this->db->queryKeyValueList($sql);
    }

    /**
     * Save new assignment patterns
     *
     * This resaves all patterns and reapplies them
     *
     * @param array $patterns (pattern => assignees)
     */
    public function saveAssignmentPatterns($patterns)
    {
        $this->db->getPdo()->beginTransaction();
        try {

            /** @noinspection SqlWithoutWhere Remove all assignments */
            $sql = "UPDATE assignments SET autoassignees = ''";
            $this->db->exec($sql);

            /** @noinspection SqlWithoutWhere Remove all patterns */
            $sql = "DELETE FROM assignments_patterns";
            $this->db->exec($sql);

            // insert new patterns and gather affected pages
            $pages = [];

            $sql = "REPLACE INTO assignments_patterns (pattern, assignees) VALUES (?,?)";
            foreach ($patterns as $pattern => $assignees) {
                $pattern = trim($pattern);
                $assignees = trim($assignees);
                if (!$pattern || !$assignees) continue;
                $this->db->exec($sql, [$pattern, $assignees]);

                // patterns may overlap, so we need to gather all affected pages first
                $affectedPages = $this->getPagesMatchingPattern($pattern);
                foreach ($affectedPages as $page) {
                    if (isset($pages[$page])) {
                        $pages[$page] .= ',' . $assignees;
                    } else {
                        $pages[$page] = $assignees;
                    }
                }
            }

            $sql = "INSERT INTO assignments (page, autoassignees) VALUES (?, ?)
                ON CONFLICT(page)
                DO UPDATE SET autoassignees = ?";
            foreach ($pages as $page => $assignees) {
                // remove duplicates and empty entries
                $assignees = implode(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));
                $this->db->exec($sql, [$page, $assignees, $assignees]);
            }
        } catch (Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }
        $this->db->getPdo()->commit();
    }

    /**
     * Get all known pages that match the given pattern
     *
     * @param $pattern
     * @return string[]
     */
    public function getPagesMatchingPattern($pattern)
    {
        $sql = "SELECT page FROM pages WHERE MATCHES_PAGE_PATTERN(?, page)";
        $pages = $this->db->queryAll($sql, $pattern);

        return array_column($pages, 'page');
    }

    // endregion
    // region Acknowledgements

    /**
     * Has the given user acknowledged the given page?
     *
     * @param string $page
     * @param string $user
     * @return bool|int timestamp of acknowledgement or false
     */
    public function hasUserAcknowledged($page, $user)
    {
        $sql = "SELECT ack
                  FROM acks A, pages B
                 WHERE A.page = B.page
                   AND A.page = ?
                   AND A.user = ?
                   AND A.ack >= B.lastmod";

        $acktime = $this->db->queryValue($sql, $page, $user);

        return $acktime ? (int)$acktime : false;
    }

    /**
     * Timestamp of the latest acknowledgment of the given page
     * by the given user
     *
     * @param string $page
     * @param string $user
     * @return bool|string
     */
    public function getLatestUserAcknowledgement($page, $user)
    {
        $sql = "SELECT MAX(ack)
                  FROM acks
                 WHERE page = ?
                   AND user = ?";

        return $this->db->queryValue($sql, [$page, $user]);
    }

    /**
     * Save user's acknowledgement for a given page
     *
     * @param string $page
     * @param string $user
     * @return bool
     */
    public function saveAcknowledgement($page, $user)
    {
        $sql = "INSERT INTO acks (page, user, ack) VALUES (?,?, strftime('%s','now'))";

        $this->db->exec($sql, $page, $user);
        return true;
    }

    /**
     * Save an acknowledgement with an explicit timestamp.
     * Useful for imports and tests. Ignores duplicates.
     *
     * @param string $page
     * @param string $user
     * @param int $time
     * @return void
     */
    public function importAcknowledgement($page, $user, $time)
    {
        $sql = "INSERT OR IGNORE INTO acks (page, user, ack) VALUES (?,?,?)";
        $this->db->exec($sql, [$page, $user, $time]);
    }

    /**
     * Get all pages that a user needs to acknowledge and/or the last acknowledgement infos
     * depending on the (optional) filter based on status of the acknowledgements.
     *
     * @param string $user
     * @param array $groups
     * @param string $status Optional status filter, can be all (default), current or due
     *
     * @return array|bool
     */
    public function getUserAcknowledgements($user, $groups, $status = '')
    {
        $filterClause = $this->getFilterClause($status, 'B');

        // query
        $sql = "SELECT A.page, A.pageassignees, A.autoassignees, B.lastmod, C.user, MAX(C.ack) AS ack
                  FROM assignments A
                  JOIN pages B
                    ON A.page = B.page
             LEFT JOIN acks C
                    ON A.page = C.page AND C.user = ?
                 WHERE AUTH_ISMEMBER(A.pageassignees || ',' || A.autoassignees, ? , ?)
              GROUP BY A.page";
        $sql .= $filterClause;
        $sql .= "
              ORDER BY A.page";

        return $this->db->queryAll($sql, [$user, $user, implode('///', $groups)]);
    }

    /**
     * Get ack status for all assigned users of a given page
     *
     * This can be slow!
     *
     * @param string $page
     * @param string $user
     * @param string $status
     * @param int $max
     *
     * @return array
     */
    public function getPageAcknowledgements($page, $user = '', $status = '', $max = 0)
    {
        $userClause = '';
        $filterClause = '';
        $params[] = $page;

        // filtering for user from input or using saved assignees?
        if ($user) {
            $users = [$user];
            $userClause = ' AND (B.user = ? OR B.user IS NULL) ';
            $params[] = $user;
        } else {
            $users = $this->getPageAssignees($page);
            if (!$users) return [];
        }

        if ($status === 'current') {
            $filterClause = ' AND ACK >= A.lastmod ';
        }

        $ulist = implode(',', array_map([$this->db->getPdo(), 'quote'], $users));
        $sql = "SELECT A.page, A.lastmod, B.user, MAX(B.ack) AS ack
                  FROM pages A
             LEFT JOIN acks B
                    ON A.page = B.page
                   AND B.user IN ($ulist)
                WHERE  A.page = ? $userClause $filterClause";
        $sql .= " GROUP BY A.page, B.user ";
        if ($max) $sql .= " LIMIT $max";

        $acknowledgements = $this->db->queryAll($sql, $params);

        if ($status === 'current') {
            return $acknowledgements;
        }

        // there should be at least one result, unless the page is unknown
        if (!count($acknowledgements)) return $acknowledgements;

        $baseinfo = [
            'page' => $acknowledgements[0]['page'],
            'lastmod' => $acknowledgements[0]['lastmod'],
            'user' => null,
            'ack' => null,
        ];

        // fill up the result with all users that never acknowledged the page
        $combined = [];
        foreach ($acknowledgements as $ack) {
            if ($ack['user'] !== null) {
                $combined[$ack['user']] = $ack;
            }
        }
        foreach ($users as $user) {
            if (!isset($combined[$user])) {
                $combined[$user] = array_merge($baseinfo, ['user' => $user]);
            }
        }

        // finally remove current acknowledgements if filter is used
        // this cannot be done in SQL without loss of data,
        // filtering must happen last, otherwise removed current acks will be re-added as due
        if ($status === 'due') {
            $combined = array_filter($combined, function ($info) {
                return $info['ack'] < $info['lastmod'];
            });
        }

        ksort($combined);
        return array_values($combined);
    }

    /**
     * Count up-to-date and due acknowledgements for a given page
     *
     * Uses potentially slow getPageAssignees()
     *
     * @param string $page
     * @return array{required:int, current:int, due:int}
     */
    public function getPageAcknowledgementCounts($page)
    {
        $users = $this->getPageAssignees($page);
        if (!$users) {
            return ['required' => 0, 'current' => 0, 'due' => 0];
        }

        $ulist = implode(',', array_map([$this->db->getPdo(), 'quote'], $users));
        $sql = "SELECT COUNT(*) FROM (
                    SELECT B.user
                      FROM pages A
                 LEFT JOIN acks B
                        ON A.page = B.page
                       AND B.user IN ($ulist)
                     WHERE A.page = ?
                  GROUP BY B.user
                    HAVING MAX(B.ack) >= A.lastmod
                )";
        $current = (int)$this->db->queryValue($sql, $page);

        $required = count($users);
        return [
            'required' => $required,
            'current' => $current,
            'due' => $required - $current,
        ];
    }

    /**
     * Returns all acknowledgements
     *
     * @param int $limit maximum number of results
     * @return array
     */
    public function getAcknowledgements($limit = 100)
    {
        $sql = '
            SELECT A.page, A.user, B.lastmod, max(A.ack) AS ack
              FROM acks A, pages B
             WHERE A.page = B.page
          GROUP BY A.user, A.page
          ORDER BY ack DESC
             LIMIT ?
              ';
        return $this->db->queryAll($sql, $limit);
    }

    /**
     * Aggregate acknowledgement statistics as a drill-down into a namespace, plus a wiki-wide
     * total.
     *
     * @param string $ns namespace to drill into ('' = wiki root / top level)
     * @return array {
     *     namespaces: array<string, array{required:int, acked:int, pages:int, haschildren:bool}>,
     *         keyed by the immediate child namespace within $ns ('' = root pages),
     *     total: array{required:int, acked:int, pages:int} wiki-wide total
     * }
     */
    public function getStatistics($ns = '')
    {
        $depth = $ns === '' ? 0 : count(explode(':', $ns));

        $sql = "SELECT page FROM assignments WHERE TRIM(pageassignees || autoassignees) != ''";
        $pages = $this->db->queryAll($sql);

        $namespaces = [];
        $total = ['required' => 0, 'acked' => 0, 'pages' => 0];

        foreach ($pages as $row) {
            $page = $row['page'];

            $acknowledgements = $this->getPageAcknowledgements($page);
            $required = count($acknowledgements);
            if (!$required) continue;

            $acked = 0;
            foreach ($acknowledgements as $ack) {
                if ($ack['ack'] !== null && $ack['ack'] >= $ack['lastmod']) {
                    $acked++;
                }
            }

            // total is always wiki-wide
            $total['required'] += $required;
            $total['acked'] += $acked;
            $total['pages'] += 1;

            $pageNS = getNS($page);
            $pageNS = ($pageNS === false) ? '' : $pageNS;

            // not in a namespace, or in current ns or it's subnamespaces
            $inScope = $ns === '' || $pageNS === $ns || str_starts_with($pageNS . ':', $ns . ':');
            if (!$inScope) continue;

            // group namespaces
            $segments = $pageNS === '' ? [] : explode(':', $pageNS);
            $key = implode(':', array_slice($segments, 0, $depth + 1));

            if (!isset($namespaces[$key])) {
                $namespaces[$key] = ['required' => 0, 'acked' => 0, 'pages' => 0, 'haschildren' => false];
            }
            $namespaces[$key]['required'] += $required;
            $namespaces[$key]['acked'] += $acked;
            $namespaces[$key]['pages'] += 1;
            if (count($segments) > $depth + 1) {
                $namespaces[$key]['haschildren'] = true;
            }
        }

        ksort($namespaces);

        return ['namespaces' => $namespaces, 'total' => $total];
    }

    /**
     * Returns a filter clause for acknowledgement queries depending on wanted status.
     *
     * @param string $status
     * @param string $alias Table alias used in the SQL query
     * @return string
     */
    protected function getFilterClause($status, $alias)
    {
        switch ($status) {
            case 'current':
                $filterClause = " HAVING ack >= $alias.lastmod ";
                break;
            case 'due':
                $filterClause = " HAVING (ack IS NULL) OR (ack < $alias.lastmod) ";
                break;
            case 'outdated':
                $filterClause = " HAVING ack < $alias.lastmod ";
                break;
            case 'all':
            default:
                $filterClause = '';
                break;
        }
        return $filterClause;
    }

    // endregion
    // region Plugin Integrations

    /**
     * Check status of the approve plugin (if installed and integration enabled in config)
     *
     * @param string $page page id
     * @return bool true only if page is tracked by approve plugin but not in 'approved' status
     */
    public function isBlockedByApprove($page)
    {
        if (!$this->getConf('approve_integration')) return false;

        /** @var helper_plugin_approve_db $approve */
        $approve = plugin_load('helper', 'approve_db');
        if (!$approve) return false;

        // page not handled by approve
        if ($approve->getPageMetadata($page) === null) return false;

        // check if current revision is approved
        $currentRev = (int)@filemtime(wikiFN($page));
        $approveRev = $approve->getPageRevision($page, $currentRev);

        return $approveRev['status'] !== 'approved';
    }

    // endregion
}
