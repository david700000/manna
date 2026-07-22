<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>System Logs</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #333; }
        h1 { color: #d9534f; font-size: 24px; text-align: center; margin-bottom: 20px; }
        pre { background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Manna Bridal System Error Logs</h1>
    <p>Generated on: {{ now()->format('Y-m-d H:i:s') }}</p>
    <p><em>Showing the last 500 lines of laravel.log</em></p>

    <pre>{{ $logs }}</pre>
</body>
</html>
