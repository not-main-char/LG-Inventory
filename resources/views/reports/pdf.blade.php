<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 16px; color: #14532d; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #166534; color: #fff; text-align: left; padding: 7px; }
        td { border-bottom: 1px solid #d1d5db; padding: 6px; }
        tr:nth-child(even) td { background: #f0fdf4; }
        .empty { padding: 18px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @if(count($rows))
        <table>
            <thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>@foreach($row as $value)<td>{{ is_numeric($value) && $value !== '' ? number_format((float) $value, 2) : $value }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No active records were found for this month.</div>
    @endif
</body>
</html>
