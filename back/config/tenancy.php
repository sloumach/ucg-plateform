<?php

return [
    'cache_store' => env('TENANCY_CACHE_STORE', env('CACHE_STORE', 'database')),
    // Technical defaults, not commercial plans. Operator-only overrides use immutable tenant UUIDs.
    'limits' => [
        'requests_per_minute' => filter_var(env('TENANCY_REQUESTS_PER_MINUTE', 600), FILTER_VALIDATE_INT),
        'artifact_bytes' => filter_var(env('TENANCY_ARTIFACT_BYTES', 26214400), FILTER_VALIDATE_INT),
        'storage_bytes' => filter_var(env('TENANCY_STORAGE_BYTES', 1073741824), FILTER_VALIDATE_INT),
        'import_bytes' => filter_var(env('TENANCY_IMPORT_BYTES', 10485760), FILTER_VALIDATE_INT),
        'import_rows' => filter_var(env('TENANCY_IMPORT_ROWS', 10000), FILTER_VALIDATE_INT),
        'export_rows' => filter_var(env('TENANCY_EXPORT_ROWS', 50000), FILTER_VALIDATE_INT),
    ],
    'limit_overrides' => [],
    'test_redis' => env('TENANCY_TEST_REDIS', false),
    // An operator may disable concurrent memberships after reviewing existing conflicts.
    'allow_multiple_organizations' => env('TENANCY_ALLOW_MULTIPLE_ORGANIZATIONS', true),
    'invitation_lifetime_days' => 7,
    // Exact, lowercase hosts approved by an operator => immutable organization UUID.
    // Empty by default; never populate this mapping from a request or an unverified domain.
    'approved_domains' => [],
    // Only languages with an installed translation catalogue are selectable.
    'languages' => ['fr'],
    'country_codes' => 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW',
];
