<?php

return [
    'title' => 'Report Template',

    'navigation' => [
        'title' => 'Report Templates',
        'group' => 'Report Administration',
    ],

    'actions' => [
        'publish' => [
            'label'     => 'Publish',
            'confirm'   => 'Publishing locks this version: layout, formulas and mappings become immutable. Manual values stay editable.',
            'published' => 'Report published.',
            'blocked'   => 'Cannot publish — :count validation error(s)',
        ],
        'new-version' => [
            'label'   => 'New Version',
            'confirm' => 'Creates an editable draft copy (next version number) with all lines, columns, formulas, mappings and manual values.',
            'created' => 'Draft version :version created.',
        ],
        'archive' => [
            'label'    => 'Archive',
            'archived' => 'Report archived.',
        ],
    ],

    'form' => [
        'immutable-notice' => 'This version is :status and cannot be edited. Use "New Version" to create an editable draft.',
        'sections'         => [
            'general' => 'Report',
        ],
        'fields' => [
            'name'             => 'Name',
            'name-help'        => 'Rendered exactly as entered — matches the workbook sheet name.',
            'code'             => 'Code',
            'layout-type'      => 'Layout',
            'layout-type-help' => 'Used when no explicit columns are defined: monthly matrix = Jan-Dec + Total, period total = one full-year column.',
            'currency-mode'    => 'Currency',
            'entity-mode'      => 'Entities',
            'status'           => 'Status',
            'company'          => 'Company',
            'company-help'     => 'Leave empty for a template available to all companies.',
            'version'          => 'Version',
            'description'      => 'Description',
        ],
    ],

    'table' => [
        'columns' => [
            'name'          => 'Name',
            'code'          => 'Code',
            'layout'        => 'Layout',
            'status'        => 'Status',
            'version'       => 'Version',
            'lines'         => 'Lines',
            'columns'       => 'Columns',
            'company'       => 'Company',
            'all-companies' => 'All companies',
            'created-by'    => 'Created By',
            'published-at'  => 'Published',
            'updated-at'    => 'Updated',
        ],
        'filters' => [
            'status'  => 'Status',
            'layout'  => 'Layout',
            'company' => 'Company',
        ],
    ],

    'pages' => [
        'list' => [
            'create' => 'New Report Template',
        ],
        'edit' => [
            'immutable' => 'This version is :status and immutable — create a new draft version to make changes.',
            'actions'   => [
                'validate' => [
                    'label'  => 'Validate',
                    'passed' => 'Template is valid — no issues found.',
                    'failed' => ':count issue(s) found',
                    'more'   => '… and :count more (see Mapping Review).',
                ],
                'preview' => 'Preview Report',
            ],
        ],
    ],

    'lines' => [
        'title' => 'Lines',

        'form' => [
            'sections' => [
                'line'         => 'Line',
                'mapping'      => 'Account Mapping',
                'mapping-help' => 'Chart-of-account accounts feeding this line (ledger lines only). A parent account includes its descendants.',
                'formula'      => 'Formula',
                'formula-help' => 'Ordered operands folded left-to-right (no operator precedence). "Consolidation Override" operands replace the value formula in consolidated columns only.',
                'inputs'       => 'Manual Values',
                'inputs-help'  => 'Dated entries for manual lines; a report period sums the entries falling inside it.',
            ],
            'fields' => [
                'line-type'                => 'Line Type',
                'caption'                  => 'Caption',
                'caption-help'             => 'Rendered verbatim. Leave empty for blank rows.',
                'code'                     => 'Code',
                'parent'                   => 'Parent Line',
                'value-source'             => 'Value Source',
                'value-source-placeholder' => 'Automatic (from line type)',
                'value-basis'              => 'Value Basis',
                'value-basis-placeholder'  => 'Report default',
                'external-provider'        => 'Provider Key',
                'company'                  => 'Company Override',
                'company-help'             => 'Compute this line against this company regardless of the column scope.',
                'sign'                     => 'Sign',
                'indent'                   => 'Indent',
                'bold'                     => 'Bold',
                'check'                    => 'Check Row',
                'check-help'               => 'Expected to evaluate to zero (e.g. balance sheet check).',
                'visible'                  => 'Visible',
                'add-account'              => 'Add Account',
                'account'                  => 'Account',
                'add-operand'              => 'Add Operand',
                'purpose'                  => 'Purpose',
                'operator'                 => 'Operator',
                'operand-type'             => 'Operand',
                'operand-line'             => 'Line',
                'operand-constant'         => 'Constant',
                'add-input'                => 'Add Value',
                'date'                     => 'Date',
                'value'                    => 'Value',
            ],
        ],

        'table' => [
            'columns' => [
                'caption'  => 'Caption',
                'blank'    => '— blank row —',
                'type'     => 'Type',
                'source'   => 'Source',
                'accounts' => 'Accounts',
                'operands' => 'Operands',
                'check'    => 'Check',
            ],
            'actions' => [
                'create' => 'Add Line',
            ],
        ],
    ],

    'columns' => [
        'title' => 'Columns',

        'form' => [
            'fields' => [
                'column-type'       => 'Column Type',
                'label'             => 'Label',
                'label-help'        => 'Rendered as the column heading (e.g. the entity name). Empty = derived from the period.',
                'start-month'       => 'Month / Range Start',
                'end-month'         => 'Range End',
                'year-offset'       => 'Year Offset',
                'year-offset-help'  => '0 = report year, -1 = prior year comparative.',
                'company'           => 'Company',
                'company-help'      => 'Scope this column to one entity. Empty = the report run scope.',
                'consolidated'      => 'Consolidated',
                'consolidated-help' => 'Sums across the full run scope; lines with a consolidation formula use it here.',
            ],
        ],

        'table' => [
            'columns' => [
                'label'        => 'Label',
                'derived'      => '— derived —',
                'type'         => 'Type',
                'start'        => 'Start',
                'end'          => 'End',
                'year-offset'  => 'Year +/-',
                'company'      => 'Company',
                'consolidated' => 'Consolidated',
            ],
            'actions' => [
                'create' => 'Add Column',
            ],
        ],
    ],
];
