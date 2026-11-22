<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Spendwise financial report</title>
    <style>
        body { font: 15px system-ui, sans-serif; color: #22172a; max-width: 58rem; margin: 2rem auto; padding: 0 1rem; }
        h1 { letter-spacing: -.04em; }
        table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
        th, td { padding: .5rem; text-align: left; border-bottom: 1px solid #ded8e1; overflow-wrap: anywhere; }
        th:last-child, td:last-child { text-align: right; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        @media print { button, .navigation { display: none; } body { margin: 0; padding: 0; } }
    </style>
</head>
<body>
    <p class="navigation"><a href="/reports">Back to reports</a></p>
    <h1>Spendwise financial report</h1>
    <p>Generated {{ now()->toDateString() }}. Each amount is shown in its original currency.</p>
    <p class="navigation"><button type="button" onclick="window.print()">Print or save as PDF</button></p>
    <table>
        <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Account</th><th>Amount</th></tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr><td>{{ $row->occurred_on }}</td><td>{{ $row->description }}</td><td>{{ $row->type }}</td><td>{{ $row->account }}</td><td>{{ $row->currency }} {{ $row->amount }}</td></tr>
            @empty
                <tr><td colspan="5">No transactions match the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
