<?php

use Illuminate\Support\Facades\DB;

/**
 * Copy journal configuration, never its users or submissions. The caller must
 * hold a transaction and roll it back, including this temporary journal.
 */
function groupReviewTestJournal(string $sourcePath): string
{
    if (DB::transactionLevel() < 1) {
        throw new RuntimeException('The test journal requires an active rollback transaction.');
    }
    $source = DB::table('journals')->where('path', $sourcePath)->first();
    if (!$source) {
        throw new RuntimeException('Provide an existing development journal path.');
    }
    $journal = (array) $source;
    $sourceId = (int) $journal['journal_id'];
    unset($journal['journal_id']);
    $journal['path'] = 'gr-test-' . bin2hex(random_bytes(6));
    $journal['current_issue_id'] = null;
    $journalId = (int) DB::table('journals')->insertGetId($journal, 'journal_id');
    foreach (DB::table('journal_settings')->where('journal_id', $sourceId)->get() as $row) {
        $data = (array) $row;
        unset($data['journal_setting_id']);
        $data['journal_id'] = $journalId;
        DB::table('journal_settings')->insert($data);
    }
    foreach (DB::table('user_groups')->where('context_id', $sourceId)->get() as $row) {
        $data = (array) $row;
        $oldId = (int) $data['user_group_id'];
        unset($data['user_group_id']);
        $data['context_id'] = $journalId;
        $newId = (int) DB::table('user_groups')->insertGetId($data, 'user_group_id');
        foreach (['user_group_settings' => 'user_group_setting_id', 'user_group_stage' => 'user_group_stage_id'] as $table => $primaryKey) {
            foreach (DB::table($table)->where('user_group_id', $oldId)->get() as $setting) {
                $data = (array) $setting;
                unset($data[$primaryKey]);
                $data['user_group_id'] = $newId;
                if (isset($data['context_id'])) {
                    $data['context_id'] = $journalId;
                }
                DB::table($table)->insert($data);
            }
        }
    }
    foreach (DB::table('sections')->where('journal_id', $sourceId)->get() as $row) {
        $data = (array) $row;
        $oldId = (int) $data['section_id'];
        unset($data['section_id']);
        $data['journal_id'] = $journalId;
        $data['review_form_id'] = null;
        $newId = (int) DB::table('sections')->insertGetId($data, 'section_id');
        foreach (DB::table('section_settings')->where('section_id', $oldId)->get() as $setting) {
            $data = (array) $setting;
            unset($data['section_setting_id']);
            $data['section_id'] = $newId;
            DB::table('section_settings')->insert($data);
        }
    }
    return $journal['path'];
}
