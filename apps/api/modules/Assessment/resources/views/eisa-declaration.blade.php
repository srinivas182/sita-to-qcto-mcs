<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 18mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2330; }
  h1 { font-size: 15pt; color: #1e1b4b; margin: 0 0 2mm; }
  .muted { color: #5b6275; }
  .kv td { padding: 1mm 0; } .kv td.k { color: #5b6275; width: 40%; }
  .box { border: .6pt solid #c9cdd8; padding: 3mm; margin: 5mm 0; }
</style>
</head>
<body>
<h1>EISA registration and learner declaration</h1>
<p class="muted">{{ $provider }}</p>
<table class="kv">
  <tr><td class="k">Learner</td><td>{{ $learner }} ({{ $idNumber }})</td></tr>
  <tr><td class="k">Qualification</td><td>{{ $qualification }} · SAQA ID {{ $saqa }}</td></tr>
  <tr><td class="k">Statement of Results</td><td>{{ $sor }}</td></tr>
  <tr><td class="k">EISA date</td><td>{{ $r->eisa_on->format('j F Y') }}</td></tr>
  <tr><td class="k">Assessment centre</td><td>{{ $r->assessment_centre_name }} ({{ $r->assessment_centre }})</td></tr>
  <tr><td class="k">Foundational learning (FLC)</td><td>{{ $flc }}@if($r->flc_number) · {{ $r->flc_number }}@endif</td></tr>
</table>
<div class="box">
  <p><strong>Declaration ({{ $r->declaration_version }})</strong></p>
  <p>{{ $declaration }}</p>
  <p>Signed electronically by <strong>{{ $r->declared_name }}</strong> on {{ $r->declared_at?->setTimezone('Africa/Johannesburg')->format('j F Y H:i') }}.</p>
</div>
<p class="muted">Registered by the provider on {{ $r->registered_at?->setTimezone('Africa/Johannesburg')->format('j F Y') }}.</p>
</body>
</html>
