<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 20mm 16mm; }
    body { font-family: 'Helvetica', Arial, sans-serif; color: #232a3b; font-size: 11.5px; }
    .header { border-bottom: 2px solid #1e3a6e; padding-bottom: 10px; margin-bottom: 18px; }
    .brand { font-size: 11px; font-weight: bold; color: #345fcf; text-transform: uppercase; letter-spacing: 1px; }
    .title { font-size: 20px; font-weight: bold; color: #1e3a6e; margin: 4px 0; }
    .subtitle { font-size: 12px; color: #4a5568; }
    .meta { font-size: 10.5px; color: #718096; margin-top: 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #1e3a6e; color: #fff; text-align: left; padding: 6px 8px; font-size: 10.5px; }
    td { padding: 5px 8px; border-bottom: 1px solid #dbe2ee; font-size: 10.5px; }
    tr:nth-child(even) td { background: #f4f7fc; }
    td.action { color: #345fcf; font-weight: bold; }
    .empty { text-align: center; color: #a0aec0; padding: 24px; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #a0aec0; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">TIPIGANAN &middot; MDC Online Repository of Special and Rare Collections</div>
        <div class="title">{{ $title }}</div>
        @if(!empty($subtitle))
            <div class="subtitle">{{ $subtitle }}</div>
        @endif
        <div class="meta">Generated {{ $generatedAt }} &middot; Range: {{ $dateRange }}</div>
    </div>

    @if(count($rows ?? []) === 0)
        <div class="empty">No audit log entries in this range.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 18%;">Timestamp</th>
                    <th style="width: 18%;">User</th>
                    <th style="width: 14%;">Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['timestamp'] }}</td>
                        <td>{{ $row['user'] }}</td>
                        <td class="action">{{ $row['action'] }}</td>
                        <td>{{ $row['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">TIPIGANAN Audit Trail &middot; Group 7</div>
</body>
</html>
