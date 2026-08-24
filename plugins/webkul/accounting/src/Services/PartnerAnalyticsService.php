<?php

namespace Webkul\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;

final class PartnerAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(int $companyId, string $partyType, string $dateFrom, string $dateTo): array
    {
        if (! in_array($partyType, ['customer', 'vendor'], true)) {
            throw new InvalidArgumentException('Party analytics type must be customer or vendor.');
        }

        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $to = CarbonImmutable::parse($dateTo)->endOfDay();
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('Analytics start date must not be after the end date.');
        }

        [$documentType, $refundType] = $partyType === 'customer'
            ? [MoveType::OUT_INVOICE->value, MoveType::OUT_REFUND->value]
            : [MoveType::IN_INVOICE->value, MoveType::IN_REFUND->value];
        $base = $this->documentQuery($companyId, $from->toDateString(), $to->toDateString(), [$documentType, $refundType]);
        $totals = (clone $base)->selectRaw(
            'COUNT(*) AS document_count,
             COUNT(DISTINCT partner_id) AS party_count,
             COALESCE(SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END), 0) AS document_value,
             COALESCE(SUM(CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END), 0) AS outstanding,
             COALESCE(SUM(CASE WHEN invoice_date_due < ? THEN CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END ELSE 0 END), 0) AS overdue',
            [$refundType, $refundType, $to->toDateString(), $refundType],
        )->first();
        $top = (clone $base)
            ->join('partners_partners as partners', 'partners.id', '=', 'accounts_account_moves.partner_id')
            ->selectRaw(
                'accounts_account_moves.partner_id, partners.name,
                 COUNT(*) AS document_count,
                 SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END) AS document_value,
                 SUM(CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END) AS outstanding',
                [$refundType, $refundType],
            )
            ->groupBy('accounts_account_moves.partner_id', 'partners.name')
            ->orderByDesc('document_value')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'partner_id'     => (int) $row->partner_id,
                'name'           => (string) $row->name,
                'document_count' => (int) $row->document_count,
                'document_value' => (float) $row->document_value,
                'outstanding'    => (float) $row->outstanding,
            ])
            ->all();
        $trends = (clone $base)
            ->selectRaw(
                "DATE_FORMAT(invoice_date, '%Y-%m') AS period,
                 COUNT(*) AS document_count,
                 SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END) AS document_value",
                [$refundType],
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row): array => [
                'period'         => (string) $row->period,
                'document_count' => (int) $row->document_count,
                'document_value' => (float) $row->document_value,
            ])
            ->all();
        $payments = DB::table('accounting_bank_transaction_mappings as mappings')
            ->join('accounts_bank_statement_lines as bank_lines', 'bank_lines.id', '=', 'mappings.statement_line_id')
            ->join('accounts_account_moves as documents', 'documents.id', '=', 'mappings.matched_move_id')
            ->where('mappings.company_id', $companyId)
            ->where('mappings.posting_status', 'posted')
            ->whereIn('documents.move_type', [$documentType, $refundType])
            ->whereBetween('bank_lines.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS payment_count, COALESCE(SUM(ABS(bank_lines.company_signed_amount)), 0) AS payment_value, MAX(bank_lines.transaction_date) AS last_payment_date')
            ->first();

        $documentValue = (float) ($totals->document_value ?? 0);
        $outstanding = (float) ($totals->outstanding ?? 0);
        $overdue = (float) ($totals->overdue ?? 0);
        $topValue = (float) ($top[0]['document_value'] ?? 0);

        return [
            'party_type'        => $partyType,
            'date_from'         => $from->toDateString(),
            'date_to'           => $to->toDateString(),
            'party_count'       => (int) ($totals->party_count ?? 0),
            'document_count'    => (int) ($totals->document_count ?? 0),
            'document_value'    => $documentValue,
            'outstanding'       => $outstanding,
            'overdue'           => $overdue,
            'overdue_rate'      => $outstanding > 0 ? round(($overdue / $outstanding) * 100, 2) : 0.0,
            'payment_count'     => (int) ($payments->payment_count ?? 0),
            'payment_value'     => (float) ($payments->payment_value ?? 0),
            'last_payment_date' => $payments->last_payment_date ?? null,
            'top_concentration' => $documentValue > 0 ? round(($topValue / $documentValue) * 100, 2) : 0.0,
            'top_parties'       => $top,
            'trends'            => $trends,
        ];
    }

    /** @param array<int, string> $moveTypes */
    private function documentQuery(int $companyId, string $dateFrom, string $dateTo, array $moveTypes): Builder
    {
        return Move::query()
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('state', MoveState::POSTED)
            ->whereIn('move_type', $moveTypes)
            ->whereNotNull('partner_id')
            ->whereBetween('invoice_date', [$dateFrom, $dateTo]);
    }
}
