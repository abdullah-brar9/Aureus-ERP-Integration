<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Direct-method Cash Flow Statement</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { margin-bottom: 4px; }
        .meta { color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 5px; border-bottom: 1px solid #e5e7eb; }
        th { text-align: left; background: #f3f4f6; }
        .amount { text-align: right; }
        .total { font-weight: bold; }
        .warning { color: #b45309; }
    </style>
</head>
<body>
    <h1>Direct-method Cash Flow Statement</h1>
    <div class="meta">
        {{ $dateFrom }} to {{ $dateTo }} · currency mode: {{ $data['currency_mode'] }} · status: {{ $data['conversion_status'] }}<br>
        {{ $data['rate_basis'] }}
    </div>
    @foreach ($data['warnings'] as $warning)<div class="warning">{{ $warning }}</div>@endforeach
    @foreach ($data['reports'] as $currency => $report)
        <h2>{{ $currency }}</h2>
        <table>
            <thead><tr><th>Category</th><th class="amount">Amount</th></tr></thead>
            <tbody>
                @foreach ($report['categories'] as $category => $amount)
                    <tr><td>{{ $category }}</td><td class="amount">{{ number_format($amount, 2) }}</td></tr>
                @endforeach
                <tr class="total"><td>Net change in cash</td><td class="amount">{{ number_format($report['net_change'], 2) }}</td></tr>
                <tr><td>Opening cash</td><td class="amount">{{ number_format($report['opening_cash'], 2) }}</td></tr>
                <tr class="total"><td>Ending cash</td><td class="amount">{{ number_format($report['ending_cash'], 2) }}</td></tr>
                <tr><td>Posted bank ledger cash</td><td class="amount">{{ number_format($report['ledger_cash'], 2) }}</td></tr>
                <tr class="total"><td>Cash flow check</td><td class="amount">{{ number_format($report['difference'], 2) }}</td></tr>
            </tbody>
        </table>
    @endforeach
</body>
</html>
