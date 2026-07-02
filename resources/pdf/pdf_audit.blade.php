<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Audit Report' }}</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #1f2937;
            font-size: 12px;
            margin: 24px;
        }

        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .title {
            font-size: 22px;
            font-weight: bold;
            color: #111827;
            margin: 0;
        }

        .subtitle {
            margin-top: 6px;
            color: #4b5563;
        }

        .meta {
            margin-top: 12px;
            font-size: 11px;
            color: #6b7280;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 10px;
        }

        th {
            background: #eff6ff;
            color: #1d4ed8;
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #bfdbfe;
        }

        td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .muted {
            color: #6b7280;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .footer {
            margin-top: 24px;
            font-size: 10px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $title ?? 'Audit Report' }}</div>
        <div class="subtitle">{{ $subtitle ?? 'System activity report' }}</div>
        <div class="meta">
            Generated: {{ $generatedAt ?? now()->format('Y-m-d H:i:s') }}
            @if (!empty($dateRange))
                | Range: {{ $dateRange }}
            @endif
        </div>
    </div>

    @if (!empty($rows) && count($rows) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 14%;">Timestamp</th>
                    <th style="width: 14%;">User</th>
                    <th style="width: 12%;">Action</th>
                    <th style="width: 60%;">Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['timestamp'] ?? '-' }}</td>
                        <td>{{ $row['user'] ?? 'System' }}</td>
                        <td><span class="badge">{{ $row['action'] ?? '-' }}</span></td>
                        <td>{{ $row['description'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="muted">No audit log entries were found for the selected filters.</div>
    @endif

    <div class="footer">
        Report generated from the TIPIGANAN audit log export feature.
    </div>
</body>
</html>
