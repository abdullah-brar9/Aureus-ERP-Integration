<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Trial Balance — {{ $company }}</title>
    <style>
        @page { margin: 80px 24px 48px 24px; }
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; }
        body { font-size: 8.5px; color: #1f2937; margin: 0; }
        .header { position: fixed; top: -60px; left: 0; right: 0; border-bottom: 2px solid #1f2937; padding-bottom: 6px; }
        .header .title { font-size: 14px; font-weight: bold; }
        .header .meta { font-size: 8px; color: #6b7280; }
        .footer { position: fixed; bottom: -32px; left: 0; right: 0; font-size: 8px; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 4px; }
        .footer .page:after { content: "Page " counter(page); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2px 4px; }
        thead th { border-bottom: 1px solid #1f2937; font-size: 8px; }
        .value { text-align: right; white-space: nowrap; }
        .caption { text-align: left; }
        tfoot td { border-top: 1.5px solid #1f2937; font-weight: bold; }
        .group { background: #f3f4f6; font-weight: bold; }
        .fail { color: #b91c1c; font-weight: bold; }
        .ok { color: #15803d; }
    </style>
</head>
<body>
    @php
        $fmt = fn ($v) => (abs((float) $v) < 0.005) ? '-' : number_format((float) $v, 2);
        $cols = ['opening_debit','opening_credit','movement_debit','movement_credit','adjustment_debit','adjustment_credit','closing_debit','closing_credit'];
        $currencyTotals = $currencyTotals ?: ['' => $totals];
    @endphp

    <div class="header">
        <div class="title">Trial Balance — {{ $company }}</div>
        <div class="meta">{{ $from }} to {{ $to }} · posted ledger lines · Generated {{ $generatedAt->format('M d, Y H:i') }}</div>
    </div>
    <div class="footer">
        <span>Trial Balance — {{ $company }}</span>
        <span style="float:right" class="page"></span>
    </div>

    <table>
        <caption>Currency mode: {{ $currencyMode }}; status: {{ $conversionStatus }}; basis: {{ $rateBasis }}</caption>
        <thead>
            <tr>
                <th class="caption" rowspan="2">Code</th>
                <th class="caption" rowspan="2">Account</th>
                <th class="caption" rowspan="2">Currency</th>
                <th class="value" colspan="2">Opening</th>
                <th class="value" colspan="2">Movement</th>
                <th class="value" colspan="2">Adjustment</th>
                <th class="value" colspan="2">Closing</th>
            </tr>
            <tr>
                @foreach (['Debit','Credit','Debit','Credit','Debit','Credit','Debit','Credit'] as $h)
                    <th class="value">{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row['is_group'] ? 'group' : '' }}">
                    <td class="caption">{{ $row['code'] }}</td>
                    <td class="caption">{{ $row['name'] }}</td>
                    <td class="caption">{{ $row['currency'] ?? '' }}</td>
                    @foreach ($cols as $c)
                        <td class="value">{{ $fmt($row[$c]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @foreach ($currencyTotals as $currency => $currencyTotal)
            @php($diff = (float) ($currencyTotal['difference'] ?? 0))
            <tr>
                <td colspan="2">Total</td>
                <td>{{ $currency }}</td>
                @foreach ($cols as $c)
                    <td class="value">{{ $fmt($currencyTotal[$c] ?? 0) }}</td>
                @endforeach
            </tr>
            @endforeach
            <tr class="{{ abs($diff) > 0.005 ? 'fail' : 'ok' }}">
                <td colspan="8">Difference (Closing Debit − Closing Credit)</td>
                <td class="value" colspan="2">{{ $fmt($diff) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
