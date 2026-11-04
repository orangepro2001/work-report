<?php

/*
 * Domain vocabulary lifted verbatim from the reference workbook
 * (docs/source-rev02.xlsx, sheet 목록). The Excel exporter and the board UI both
 * read these lists, so changing a status here changes the dropdown, the chips and
 * the exported 검증 lists in one place.
 */

return [
    // Mount point of the app behind the reverse proxy ("" when served at the root).
    'base_path' => env('WR_BASE_PATH', '/report'),

    'timezone' => env('WR_TZ', 'Asia/Seoul'),

    'department' => env('WR_DEPARTMENT', '검사 기술'),

    /*
     * The SSO this application signs in against (docs/auth-seam.md). Issuer,
     * endpoints and the client registration all live at 521985.xyz/sso; the three
     * paths below are fixed by that service's DESIGN.md (its discovery document
     * publishes the same values).
     */
    'sso' => [
        'issuer' => rtrim((string) env('SSO_ISSUER', 'https://521985.xyz/sso'), '/'),
        'client_id' => env('SSO_CLIENT_ID'),
        'client_secret' => env('SSO_CLIENT_SECRET'),
        // Absolute, because the redirect_uri is registered verbatim on the SSO and
        // url() would answer with whatever Host the request came in on.
        'redirect_uri' => env('SSO_REDIRECT_URI'),
        // The SSO's scope list is fixed (its config/sso.php): no `email` scope
        // exists, because an account has no address to release.
        'scope' => 'openid profile',
    ],

    // Report title prefix, mirrored in B2 of the workbook: "DMT_ 검사 기술 일일업무 보고".
    'title_prefix' => env('WR_TITLE_PREFIX', 'DMT_ 검사 기술 일일업무 보고'),

    /*
     * Workflow states. `key` is stored in the database, `label` is what the
     * workbook prints in 상태, `tone` drives the badge colour in the UI.
     */
    'statuses' => [
        ['key' => 'planned', 'label' => '예정', 'tone' => 'slate', 'done' => false],
        ['key' => 'in_progress', 'label' => '진행 중', 'tone' => 'blue', 'done' => false],
        ['key' => 'done', 'label' => '완료', 'tone' => 'green', 'done' => true],
        ['key' => 'on_hold', 'label' => '보류', 'tone' => 'amber', 'done' => false],
    ],

    'priorities' => [
        ['key' => 'high', 'label' => 'High', 'tone' => 'red'],
        ['key' => 'medium', 'label' => 'Medium', 'tone' => 'amber'],
        ['key' => 'low', 'label' => 'Low', 'tone' => 'slate'],
    ],

    /*
     * Summary buckets of the 업무 현황 요약 block (rows 7-10 of the workbook).
     * `match` is the exact 구분 value a project carries; the exporter counts rows
     * per bucket so the totals always agree with the detail table.
     */
    'summary_buckets' => [
        ['label' => '3D AFVI', 'abbr' => '3D'],
        ['label' => '2D AFVI', 'abbr' => '2D'],
        ['label' => '기타', 'abbr' => 'ETC'],
        ['label' => '목표', 'abbr' => 'GOAL'],
    ],

    // Free-text remark presets (비고 column); the workbook uses these repeatedly.
    'note_presets' => ['교육', '발표 자료', '검사원 지원', '품질팀 요청', '대만 DMT'],

    'locales' => ['ko' => '한국어', 'en' => 'English', 'ja' => '日本語'],

    'excel' => [
        // Cell layout of the reference workbook. Every offset the writer uses is
        // declared here so the golden test and the writer cannot drift apart.
        'sheet_report' => '업무일보',
        'sheet_members' => '담당자 요약',
        'sheet_lists' => '목록',
        'header_row' => 15,
        'first_data_row' => 16,
        // The template reserves rows 16..76 (61 slots).
        'max_data_rows' => 61,
        'last_data_row' => 76,
        'total_row' => 11,
        'summary_first_row' => 7,
        'summary_last_row' => 10,
    ],
];
