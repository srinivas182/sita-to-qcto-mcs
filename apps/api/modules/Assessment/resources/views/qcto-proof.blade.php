<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 18mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2330; }
  h1 { font-size: 14pt; color: #1e1b4b; margin: 0 0 2mm; }
  .muted { color: #5b6275; }
  table { width: 100%; border-collapse: collapse; }
  .grid th, .grid td { border: .4pt solid #c9cdd8; padding: 1.6mm; text-align: left; }
  .grid th { background: #f1f2f7; font-weight: normal; }
</style>
</head>
<body>
<h1>Proof of submission to the QCTO (LEISA)</h1>
<p class="muted">{{ $provider }} · {{ $cohort }}</p>
<p>{{ $learner }} ({{ $idNumber }}) was included in these Learner Enrolment and Readiness for EISA files sent to the QCTO:</p>
<table class="grid">
  <tr><th>Sent</th><th>Learners in the file</th><th>Note</th><th>QCTO reply</th></tr>
  @forelse($submissions as $s)
  <tr><td>{{ $s['sent_on'] }}</td><td>{{ $s['rows'] }}</td><td>{{ $s['note'] }}</td><td>{{ $s['reply'] }}</td></tr>
  @empty
  <tr><td colspan="4">Not yet sent.</td></tr>
  @endforelse
</table>
<p class="muted" style="margin-top:4mm">Taken from the provider's submission log on {{ now()->setTimezone('Africa/Johannesburg')->format('j F Y') }}.</p>
</body>
</html>
