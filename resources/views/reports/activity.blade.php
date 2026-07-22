<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Activity Logs</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; }
        h1 { color: #1A1A2E; font-size: 24px; text-align: center; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
        th { background-color: #f9f9f9; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Manna Bridal System Activity Logs</h1>
    <p>Generated on: {{ now()->format('Y-m-d H:i:s') }}</p>

    <table>
        <thead>
            <tr>
                <th style="width: 15%">Time</th>
                <th style="width: 20%">User</th>
                <th style="width: 10%">Role</th>
                <th style="width: 15%">Action</th>
                <th style="width: 25%">Description</th>
                <th style="width: 15%">IP</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                <td>{{ $log->user?->name ?? 'System/Guest' }}</td>
                <td>{{ $log->user?->role ?? 'N/A' }}</td>
                <td>{{ $log->action }}</td>
                <td>{{ $log->description }}</td>
                <td>{{ $log->ip_address }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
