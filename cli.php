<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

/**
 * DokuWiki Plugin acknowledge (CLI Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class cli_plugin_acknowledge extends CLIPlugin
{
    /** @var helper_plugin_acknowledge */
    protected $helper;

    /**
     * Initialize helper
     */
    public function __construct()
    {
        parent::__construct();
        $this->helper = plugin_load('helper', 'acknowledge');
    }

    /**
     * @inheritDoc
     */
    protected function setup(Options $options)
    {
        $options->setHelp('Maintenance and import tools for the acknowledge plugin');

        $options->registerCommand(
            'import-ireadit',
            'Import read records and ~~IREADIT~~ assignments from the ireadit plugin'
        );
        $options->registerOption(
            'dry-run',
            'Only report what would be imported, without writing anything',
            'd',
            false,
            'import-ireadit'
        );
    }

    /**
     * @inheritDoc
     */
    protected function main(Options $options)
    {
        $cmd = $options->getCmd();
        switch ($cmd) {
            case 'import-ireadit':
                try {
                    $this->importIreadit((bool)$options->getOpt('dry-run'));
                } catch (\Exception $e) {
                    $this->fatal($e);
                }
                break;
            default:
                $this->error('No command provided');
                echo $options->help();
                exit(1);
        }
    }

    /**
     * Import acknowledgements and assignments from the ireadit plugin
     *
     * @param bool $dryRun whether to only report instead of writing
     * @return void
     * @throws Exception
     */
    protected function importIreadit(bool $dryRun): void
    {
        /** @var helper_plugin_ireadit_db $ireaditDb */
        $ireaditDb = plugin_load('helper', 'ireadit_db');
        if ($ireaditDb === null) {
            throw new \RuntimeException(
                'The ireaditDb plugin is required but could not be loaded.'
            );
        }

        if ($dryRun) {
            $this->notice('Running in dry-run mode, no changes will be written');
        }

        // make sure the pages table is populated
        if (!$dryRun) {
            $this->helper->updatePageIndex();
        }

        [$assignmentCount, $patternCount] = $this->importAssignments($dryRun);
        $acksCount = $this->importIreaditRecords($ireaditDb, $dryRun);

        $this->success(sprintf(
            'Done: imported %d ireadit record(s), %d assignment pattern(s) (%d assignee entries)',
            $acksCount,
            $patternCount,
            $assignmentCount
        ));
    }

    /**
     * Import ireadit records into the acks table, keeping only the latest per page+user
     *
     * @param helper_plugin_ireadit_db $ireaditDb
     * @param bool $dryRun
     * @return int number of records imported (or that would be imported if on dry-run)
     * @throws Exception
     */
    protected function importIreaditRecords(\helper_plugin_ireadit_db $ireaditDb, bool $dryRun): int
    {
        $sqlite = $ireaditDb->getDB();
        $res = $sqlite->query('SELECT page, user, rev, tim§estamp FROM ireadit');
        if ($res === false) {
            throw new \RuntimeException('Failed to read records from the ireadit database');
        }
        $rows = $sqlite->res2arr($res);

        // keep only the latest acknowledgement per page+user
        $latest = [];
        foreach ($rows as $row) {
            $time = $row['timestamp'] ? strtotime($row['timestamp']) : (int)$row['rev'];
            if (!$time) continue;

            // index by page+user; the NUL byte is a safe separator because it can never
            // appear in a page id or user name, so the two parts can't collide
            $key = $row['page'] . "\0" . $row['user'];
            if (!isset($latest[$key]) || $time > $latest[$key]['time']) {
                $latest[$key] = ['page' => $row['page'], 'user' => $row['user'], 'time' => $time];
            }
        }

        $imported = 0;
        $failed = 0;
        foreach ($latest as $entry) {
            $this->info(sprintf(
                'ireadit record: %s by %s at %s',
                $entry['page'],
                $entry['user'],
                dformat($entry['time'])
            ));

            if ($dryRun) {
                $imported++;
                continue;
            }

            // a single bad record should not abort the whole import
            try {
                $this->helper->importAcknowledgement($entry['page'], $entry['user'], $entry['time']);
                $imported++;
            } catch (\Exception $e) {
                $failed++;
                $this->error(sprintf(
                    'Failed to import read record for %s by %s: %s',
                    $entry['page'],
                    $entry['user'],
                    $e->getMessage()
                ));
            }
        }

        if ($failed) {
            $this->warning(sprintf('%d read record(s) could not be imported', $failed));
        }

        return $imported;
    }

    /**
     * Create assignment patterns for every page containing ~~IREADIT...~~ syntax
     *
     * @param bool $dryRun
     * @return array{0:int,1:int} number of assignee entries and number of patterns created
     * @throws RuntimeException if the assignment patterns cannot be saved
     */
    protected function importAssignments(bool $dryRun): array
    {
        // legacy indexer, starting with Mort
        $pages = idx_getIndex('page', '');

        $newPatterns = [];
        foreach ($pages as $page) {
            $page = trim($page);
            if ($page === '') continue;

            $source = rawWiki($page);
            if (!preg_match('/~~IREADIT(.*?)~~/', $source, $match)) {
                continue;
            }

            $assignees = $this->parseIreaditAssignees($match[1]);
            $newPatterns[$page] = $assignees;
            $this->info(sprintf('Pattern: %s -> %s', $page, $assignees));
        }

        if (!$dryRun && $newPatterns) {
            // saveAssignmentPatterns() rewrites the whole assignments_patterns table, so we must merge
            $patterns = $this->helper->getAssignmentPatterns();
            foreach ($newPatterns as $newPattern => $assignees) {
                // when a page already has our pattern, append the imported assignees
                if (!empty($patterns[$newPattern])) {
                    $assignees = $patterns[$newPattern] . ',' . $assignees;
                }
                // clean up combined assignees
                $entries = array_map('trim', explode(',', $assignees));
                $entries = array_filter($entries);
                $patterns[$newPattern] = implode(',', array_unique($entries));
            }
            try {
                $this->helper->saveAssignmentPatterns($patterns);
            } catch (\Exception $e) {
                throw new \RuntimeException('Failed to save assignment patterns: ' . $e->getMessage(), 0, $e);
            }
        }

        $assigneeCount = 0;
        foreach ($newPatterns as $assignees) {
            $assigneeCount += count(array_filter(explode(',', $assignees)));
        }

        return [$assigneeCount, count($newPatterns)];
    }

    /**
     * Parse the ~~IREADIT...~~ syntax into an acknowledge assignee string
     *
     * Mirrors the parsing in syntax_plugin_ireadit_ireadit::handle(). Empty syntax means
     * "everyone" in ireadit, which is mapped to the global default group.
     *
     * @param string $inner the text between ~~IREADIT and ~~
     * @return string comma-separated list of users and @groups
     */
    protected function parseIreaditAssignees(string $inner): string
    {
        global $conf;

        $splits = preg_split('/[\s:]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);

        $users = [];
        $groups = [];
        foreach ($splits as $split) {
            if ($split[0] === '@') {
                $groups[] = $split;
            } else {
                $users[] = $split;
            }
        }

        // empty ~~IREADIT~~ means everyone, so use default group
        if (!$users && !$groups) {
            return '@' . $conf['defaultgroup'];
        }

        return implode(',', array_merge($users, $groups));
    }
}
