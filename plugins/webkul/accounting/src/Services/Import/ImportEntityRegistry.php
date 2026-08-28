<?php

namespace Webkul\Accounting\Services\Import;

final class ImportEntityRegistry
{
    /**
     * @var array<string, array{label: string, required: array<int, string>, fields: array<string, string>}>
     */
    private const DEFINITIONS = [
        'vendor' => [
            'label'    => 'Vendors',
            'required' => ['name'],
            'fields'   => [
                'reference'      => 'Vendor reference', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone',
                'mobile'         => 'Mobile', 'tax_id' => 'Tax ID', 'currency' => 'Currency code', 'payment_term' => 'Payment term',
                'classification' => 'Classification', 'sector' => 'Sector', 'category' => 'Category', 'is_active' => 'Active',
            ],
        ],
        'customer' => [
            'label'    => 'Customers',
            'required' => ['name'],
            'fields'   => [
                'reference'      => 'Customer reference', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone',
                'mobile'         => 'Mobile', 'tax_id' => 'Tax ID', 'currency' => 'Currency code', 'payment_term' => 'Payment term',
                'classification' => 'Classification', 'sector' => 'Sector', 'category' => 'Category', 'is_active' => 'Active',
            ],
        ],
        'employee' => [
            'label'    => 'Employees',
            'required' => ['name'],
            'fields'   => [
                'identification_id' => 'Employee ID', 'name' => 'Name', 'work_email' => 'Work email', 'work_phone' => 'Work phone',
                'mobile_phone'      => 'Mobile phone', 'job_title' => 'Job title', 'department' => 'Department',
                'manager_reference' => 'Manager reference', 'currency' => 'Currency code', 'is_active' => 'Active',
            ],
        ],
        'fs_tag' => [
            'label'    => 'FS Tag Registry',
            'required' => ['code', 'name'],
            'fields'   => [
                'code'               => 'FS Tag code',
                'name'               => 'FS Tag name',
                'gl_code'            => 'Linked GL code',
                'cash_flow_category' => 'Cash-flow category',
                'tax_treatment'      => 'Tax treatment',
                'is_active'          => 'Active',
            ],
        ],
        'invoice' => [
            'label'    => 'Customer invoices',
            'required' => ['reference', 'date', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code'],
            'fields'   => self::DOCUMENT_FIELDS,
        ],
        'bill' => [
            'label'    => 'Vendor bills',
            'required' => ['reference', 'date', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code'],
            'fields'   => self::DOCUMENT_FIELDS,
        ],
        'claim' => [
            'label'    => 'Claims',
            'required' => ['reference', 'date', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code'],
            'fields'   => self::DOCUMENT_FIELDS,
        ],
        'miscellaneous' => [
            'label'    => 'Miscellaneous documents',
            'required' => ['reference', 'date', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code'],
            'fields'   => self::DOCUMENT_FIELDS,
        ],
        'opening_balance' => [
            'label'    => 'Opening balances',
            'required' => ['gl_code', 'debit', 'credit', 'date', 'currency', 'journal_code'],
            'fields'   => [
                'gl_code'              => 'GL code',
                'gl_account'           => 'GL account name',
                'source_classification'=> 'Source classification',
                'debit'                => 'Opening debit',
                'credit'               => 'Opening credit',
                'note'                 => 'Note',
                'date'                 => 'Opening date',
                'currency'             => 'Currency code',
                'journal_code'         => 'General journal code',
            ],
        ],
        'journal_entry' => [
            'label'    => 'Non-bank journal entries',
            'required' => ['journal_entry_id', 'date', 'gl_code', 'debit', 'credit', 'currency', 'journal_code'],
            'fields'   => [
                'journal_entry_id'  => 'Journal entry ID',
                'date'              => 'Journal date',
                'description'       => 'Description',
                'gl_code'           => 'GL code',
                'gl_account'        => 'GL account name',
                'debit'             => 'Debit',
                'credit'            => 'Credit',
                'entity'            => 'Entity',
                'tax_treatment'     => 'Tax treatment',
                'status'            => 'Requested posting status',
                'cash_flow_category'=> 'Cash flow category',
                'fs_tag_name'       => 'FS Tag name',
                'fs_tag'            => 'FS Tag code',
                'currency'          => 'Currency code',
                'journal_code'      => 'General journal code',
            ],
        ],
        'bank_statement' => [
            'label'    => 'Bank statement transactions',
            'required' => ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'],
            'fields'   => [
                'statement_reference' => 'Statement reference', 'bank_name' => 'Bank name', 'bank_account_number' => 'Bank account number',
                'account_title'       => 'Account title', 'date' => 'Transaction date', 'value_date' => 'Value date',
                'description'         => 'Description', 'reference' => 'Transaction reference', 'debit' => 'Debit', 'credit' => 'Credit',
                'balance'             => 'Running balance', 'opening_balance' => 'Opening balance', 'closing_balance' => 'Closing balance',
                'transaction_type'    => 'Transaction type', 'counterparty' => 'Counterparty', 'offset_gl_code' => 'Offset GL code',
                'fs_tag_name'         => 'FS Tag name', 'cash_flow_category' => 'Cash flow category', 'fs_tag' => 'FS Tag code',
                'supporting_document' => 'Supporting document/reference', 'tax_treatment' => 'Tax treatment',
                'currency'            => 'Currency code', 'bank_gl_code' => 'Bank GL code', 'journal_code' => 'Bank journal code',
            ],
        ],
    ];

    private const DOCUMENT_FIELDS = [
        'reference'          => 'Document reference', 'partner_reference' => 'Party reference', 'customer_name' => 'Customer/vendor name',
        'customer_email'     => 'Customer/vendor email', 'billing_address' => 'Billing address', 'date' => 'Document date',
        'due_date'           => 'Due date', 'currency' => 'Currency code', 'amount_untaxed' => 'Untaxed amount',
        'amount_tax'         => 'Tax amount', 'amount_total' => 'Total amount', 'journal_code' => 'Journal code',
        'payment_term'       => 'Payment term', 'description' => 'Description', 'fs_tag' => 'FS Tag', 'status' => 'Workflow status',
        'debit_gl_code'      => 'Debit GL code', 'credit_gl_code' => 'Credit GL code',
        'location'           => 'Location', 'booking_id' => 'Booking ID', 'consolidated_number' => 'Consolidated number',
        'drop_off'           => 'Drop-off', 'product_service' => 'Product/service', 'quantity' => 'Quantity',
        'rate'               => 'Rate', 'tax_percent' => 'Tax percentage',
        'cash_flow_category' => 'Cash flow category', 'tax_treatment' => 'Tax treatment',
    ];

    /**
     * @return array<string, string>
     */
    public function entityOptions(): array
    {
        return collect(self::DEFINITIONS)->mapWithKeys(fn (array $definition, string $key): array => [$key => $definition['label']])->all();
    }

    /**
     * @return array<string, string>
     */
    public function fields(string $entityType): array
    {
        return self::DEFINITIONS[$entityType]['fields'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function requiredFields(string $entityType): array
    {
        return self::DEFINITIONS[$entityType]['required'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function allFields(): array
    {
        return collect(self::DEFINITIONS)
            ->pluck('fields')
            ->reduce(fn (array $fields, array $entityFields): array => $fields + $entityFields, []);
    }

    public function supports(string $entityType): bool
    {
        return array_key_exists($entityType, self::DEFINITIONS);
    }
}
