<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 20mm 16mm; }
    body { font-family: 'Helvetica', Arial, sans-serif; color: #232a3b; font-size: 12px; }
    .header { border-bottom: 2px solid #1e3a6e; padding-bottom: 10px; margin-bottom: 18px; }
    .brand { font-size: 11px; font-weight: bold; color: #345fcf; text-transform: uppercase; letter-spacing: 1px; }
    .title { font-size: 20px; font-weight: bold; color: #1e3a6e; margin: 4px 0; }
    .subtitle { font-size: 12px; color: #4a5568; }
    .meta { font-size: 10.5px; color: #718096; margin-top: 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #1e3a6e; color: #fff; text-align: left; padding: 7px 9px; font-size: 11px; }
    td { padding: 6px 9px; border-bottom: 1px solid #dbe2ee; font-size: 11px; }
    tr:nth-child(even) td { background: #f4f7fc; }
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
        <div class="empty">No data available for this report.</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">TIPIGANAN Reports &amp; Analytics &middot; Group 7</div>
</body>
</html>
