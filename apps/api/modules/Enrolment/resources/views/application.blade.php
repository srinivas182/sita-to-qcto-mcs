<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $reference }}</title>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1d2030; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  .muted { color: #6b7186; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  td { padding: 6px 8px; border-bottom: 1px solid #e4e6ee; vertical-align: top; }
  td.k { width: 38%; color: #6b7186; }
  h2 { font-size: 13px; margin: 18px 0 0; }
</style>
</head>
<body>
  <p class="muted">{{ $provider }}</p>
  <h1>Application {{ $reference }}</h1>
  <p class="muted">{{ $intake }} · received {{ $submitted }} · by {{ $source }}</p>
  <h2>Applicant</h2>
  <table>
    <tr><td class="k">Name</td><td>{{ $name }}</td></tr>
    <tr><td class="k">Identity number</td><td>{{ $id_number }}</td></tr>
    <tr><td class="k">Date of birth</td><td>{{ $dob }}</td></tr>
    <tr><td class="k">Mobile</td><td>{{ $phone }}</td></tr>
    <tr><td class="k">Email</td><td>{{ $email }}</td></tr>
  </table>
  @if (count($answers))
  <h2>Answers</h2>
  <table>
    @foreach ($answers as $a)
    <tr><td class="k">{{ $a['label'] }}</td><td>{{ $a['value'] }}</td></tr>
    @endforeach
  </table>
  @endif
  <p class="muted" style="margin-top:24px">Kept on the applicant's record as received. The identity number is shown in part.</p>
</body>
</html>
