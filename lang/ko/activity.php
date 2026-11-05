<?php

/*
 * Timeline sentences. Keys match issue_activities.verb; placeholders come from the
 * `changes` jsonb column. Keeping these in lang/ means the timeline reads naturally
 * in each locale instead of leaking raw field names.
 */
return [
    'created' => '이슈를 만들었습니다',
    'updated' => '이슈를 수정했습니다',
    'status_changed' => ':from 에서 :to 로 상태를 바꿨습니다',
    'percent_changed' => '진행률을 :to%로 바꿨습니다',
    'assigned' => '담당자를 바꿨습니다',
    'reported_by_changed' => '보고자를 바꿨습니다',
    'commented' => '댓글을 남겼습니다',
    'due_changed' => '예정일을 바꿨습니다',
    'priority_changed' => '우선순위를 바꿨습니다',
    'attached' => '파일을 첨부했습니다',
];
