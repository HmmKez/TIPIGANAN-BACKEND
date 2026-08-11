<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 34px 32px 42px 32px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #2d3748; line-height: 1.5; }
  .cover { text-align: center; padding-top: 150px; }
  .cover h1 { font-size: 30px; color: #345FCF; margin: 0 0 4px; letter-spacing: -0.5px; }
  .cover .sub { font-size: 13px; color: #4a5568; margin-bottom: 26px; }
  .cover .meta { font-size: 11px; color: #718096; }
  .cover .rule { width: 90px; height: 3px; background: #345FCF; margin: 20px auto; }
  h1.section { font-size: 17px; color: #345FCF; border-bottom: 2px solid #345FCF; padding-bottom: 5px; margin: 0 0 12px; }
  h2.tbl { font-size: 13px; color: #1a202c; margin: 16px 0 2px; }
  h2.tbl code { background: #EDF2F7; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; }
  .purpose { font-size: 10px; color: #4a5568; margin: 0 0 6px; }
  .rowcount { font-size: 9.5px; color: #718096; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  th { background: #345FCF; color: #fff; text-align: left; padding: 4px 7px; font-size: 9.5px; }
  td { padding: 4px 7px; border-bottom: 1px solid #E2E8F0; font-size: 9.5px; vertical-align: top; }
  td.f { font-family: DejaVu Sans Mono, monospace; color: #1a202c; width: 24%; }
  td.t { font-family: DejaVu Sans Mono, monospace; color: #4a5568; width: 24%; }
  td.n { color: #4a5568; }
  .page-break { page-break-after: always; }
  .note { background: #F7FAFC; border-left: 3px solid #345FCF; padding: 8px 11px; margin: 10px 0; font-size: 10px; }
  .warn { background: #FDF3E3; border-left: 3px solid #E0912F; padding: 8px 11px; margin: 10px 0; font-size: 10px; }
</style>
</head>
<body>

<div class="cover">
  <h1>TIPIGANAN</h1>
  <div class="sub">MDC Online Repository of Special and Rare Collections</div>
  <div class="rule"></div>
  <div class="sub"><b>SYSTEM BLUEPRINT — DATABASE SCHEMA</b></div>
  <div class="meta">
    Group 7 · Capstone Project Documentation<br>
    Concha · Esto · Mendez · Miano<br><br>
    Generated {{ $generatedAt }}
  </div>
</div>

<div class="page-break"></div>

<h1 class="section">About This Document</h1>

<div class="note">
  <b>This document is generated from the live database, not written by hand.</b>
  Every table, column, type, key and foreign-key rule below is read directly from
  the running schema at the moment it was produced. Re-create it at any time with
  <code>php artisan docs:blueprint</code>.
</div>

<p>The previous edition of this blueprint was a standalone PDF with no source file, and it had drifted badly from the system it described — it still showed nested categories that were removed, a permissions pivot the application never used, and none of the tables added since. Because there was no source, nobody could correct it. Generating it from <code>information_schema</code> removes that failure mode entirely: the document is now a <i>report on</i> the database rather than a <i>description of</i> it, so it cannot disagree with the code.</p>

<div class="warn">
  <b>Accounts are identified by a 5-digit school ID number.</b> <code>users.id_number</code>
  is the login credential and the key the school's API uses to return a person's real
  name and details. That is why <code>users.name</code> is nullable — it is not collected
  at registration. Anywhere a name would be displayed, including the identity stamped
  on every page of every PDF, falls back to the ID number.
</div>

<p><b>Not listed here:</b> Laravel's own infrastructure tables ({{ implode(', ', $infrastructure) }}), which carry no design decisions of ours; and the permission tables provided by the <code>spatie/laravel-permission</code> package ({{ implode(', ', $permissionTables) }}), which hold the role and permission assignments described in the team guide.</p>

<div class="page-break"></div>

<h1 class="section">Database Schema — {{ count($schema) }} Tables</h1>

@foreach ($schema as $table)
  <h2 class="tbl"><code>{{ $table['name'] }}</code> <span class="rowcount">— {{ number_format($table['rows']) }} rows at generation</span></h2>
  @if ($table['purpose'])
    <p class="purpose">{{ $table['purpose'] }}</p>
  @endif
  <table>
    <tr><th style="width:24%">Column</th><th style="width:24%">Type</th><th>Notes</th></tr>
    @foreach ($table['columns'] as $col)
      <tr>
        <td class="f">{{ $col['field'] }}</td>
        <td class="t">{{ $col['type'] }}</td>
        <td class="n">{{ $col['notes'] ?: '—' }}</td>
      </tr>
    @endforeach
  </table>
@endforeach

</body>
</html>
