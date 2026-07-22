<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Finance Report</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        h1 { color: #F47B20; font-size: 24px; text-align: center; margin-bottom: 30px; }
        h2 { color: #555; border-bottom: 2px solid #F47B20; padding-bottom: 5px; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f9f9f9; font-weight: bold; }
        .text-right { text-align: right; }
        .summary-box { background-color: #FFF4EC; padding: 15px; border-left: 4px solid #F47B20; margin-bottom: 30px; }
        .summary-box p { font-size: 18px; margin: 0; font-weight: bold; color: #F47B20; }
        .summary-box small { color: #888; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Manna Bridal Finance Report</h1>
    <p>Generated on: {{ now()->format('Y-m-d H:i:s') }}</p>

    <div class="summary-box">
        <small>Total Income (All Paid Orders)</small>
        <p>₦{{ number_format($totalIncome, 2) }}</p>
    </div>

    <h2>Monthly Sales Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th class="text-right">Revenue (₦)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($monthlySales->sortKeysDesc() as $month => $total)
            <tr>
                <td>{{ $month }}</td>
                <td class="text-right">{{ number_format($total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Daily Sales Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th class="text-right">Revenue (₦)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dailySales->sortKeysDesc() as $day => $total)
            <tr>
                <td>{{ $day }}</td>
                <td class="text-right">{{ number_format($total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
