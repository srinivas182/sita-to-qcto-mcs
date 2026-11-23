<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 16mm 14mm 18mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.8pt; color: #1f2330; }
  h1 { font-size: 16pt; color: #1e1b4b; margin: 0 0 1mm; }
  h2 { font-size: 10.5pt; color: #1e1b4b; margin: 6mm 0 2mm; }
  .muted { color: #5b6275; }
  table { width: 100%; border-collapse: collapse; }
  .grid th, .grid td { border: .4pt solid #c9cdd8; padding: 1.6mm 1.4mm; text-align: left; vertical-align: top; }
  .grid th { background: #f1f2f7; font-weight: normal; }
  .kv td { padding: .8mm 0; } .kv td.k { color: #5b6275; width: 38%; }
  .withdrawn { color: #b42318; font-size: 14pt; font-weight: bold; border: 1.2pt solid #b42318; padding: 2mm; text-align: center; margin: 4mm 0; }
  .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 6.8pt; color: #5b6275; }
</style>
</head>
<body>
<h1>Statement of Results</h1>
<p class="muted">{{ $snap['provider']['name'] }}@if($snap['provider']['accreditation']) · SDP {{ $snap['provider']['accreditation'] }}@endif · {{ $snap['provider']['centre'] }}</p>

@if($s->status === 'withdrawn')
<div class="withdrawn">WITHDRAWN {{ $s->withdrawn_at?->format('j M Y') }}: {{ $s->withdrawn_reason }}</div>
@endif

<table class="kv">
  <tr><td class="k">Number</td><td><strong>{{ $s->number }}</strong></td></tr>
  <tr><td class="k">Learner</td><td>{{ $snap['learner']['name'] }} ({{ $snap['learner']['id_number'] }})</td></tr>
  <tr><td class="k">Qualification</td><td>{{ $snap['qualification']['title'] }} · SAQA ID {{ $snap['qualification']['saqa_id'] }}@if($snap['qualification']['nqf_level']) · NQF {{ $snap['qualification']['nqf_level'] }}@endif</td></tr>
  <tr><td class="k">Programme</td><td>{{ $snap['cohort']['name'] }}@if($snap['cohort']['started']), from {{ \Carbon\Carbon::parse($snap['cohort']['started'])->format('j M Y') }}@endif</td></tr>
  <tr><td class="k">Issued</td><td>{{ $s->issued_at->setTimezone('Africa/Johannesburg')->format('j F Y') }}</td></tr>
</table>

<h2>Internal summative assessment results</h2>
<table class="grid">
  <tr><th>Module</th><th>Component</th><th>Credits</th><th>Result</th><th>Date</th></tr>
  @foreach($snap['modules'] as $m)
  <tr>
    <td>{{ $m['code'] }} {{ $m['title'] }}</td>
    <td>{{ ['KM' => 'Knowledge', 'PM' => 'Practical skills', 'WM' => 'Work experience'][$m['kind']] ?? $m['kind'] }}</td>
    <td>{{ $m['credits'] }}</td>
    <td>{{ $m['outcome'] }}@if($m['hours'] !== null) ({{ $m['hours'] }} h signed)@endif</td>
    <td>{{ $m['date'] ? \Carbon\Carbon::parse($m['date'])->format('j M Y') : '' }}</td>
  </tr>
  @endforeach
</table>

@if(count($snap['work_experience']))
<h2>Statement of Work Experience</h2>
<table class="grid">
  <tr><th>Employer and workplace</th><th>Approval</th><th>Mentor</th><th>Period</th></tr>
  @foreach($snap['work_experience'] as $w)
  <tr>
    <td>{{ $w['employer'] }} · {{ $w['site'] }}</td><td>{{ $w['approval_number'] }}</td><td>{{ $w['mentor'] }}</td>
    <td>{{ \Carbon\Carbon::parse($w['from'])->format('j M Y') }} – {{ $w['to'] ? \Carbon\Carbon::parse($w['to'])->format('j M Y') : 'ongoing' }}</td>
  </tr>
  @endforeach
</table>
@endif

<p style="margin-top:6mm">The learner has been found competent in all the modules above through internal summative assessment and the provider's moderation. This statement makes the learner eligible to register for the External Integrated Summative Assessment.</p>
<p class="muted">Check this statement at {{ $verifyUrl }}</p>

<div class="footer">{{ $s->number }} · fingerprint {{ $s->hash }}</div>
</body>
</html>
