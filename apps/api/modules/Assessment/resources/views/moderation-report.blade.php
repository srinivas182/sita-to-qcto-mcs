<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 14mm 12mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.4pt; color: #1f2330; }
  h1 { font-size: 14pt; color: #1e1b4b; margin: 0 0 1mm; }
  h2 { font-size: 10pt; color: #1e1b4b; margin: 5mm 0 1.5mm; }
  .muted { color: #5b6275; }
  table { width: 100%; border-collapse: collapse; }
  .grid th, .grid td { border: .4pt solid #c9cdd8; padding: 1.4mm 1.2mm; text-align: left; }
  .grid th { background: #f1f2f7; font-weight: normal; }
  .over { color: #b42318; font-weight: bold; }
  .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 6.8pt; color: #5b6275; }
  p { margin: 1mm 0; }
</style>
</head>
<body>
<h1>Internal moderation report</h1>
<p class="muted">{{ $provider }} · {{ $cohort->name }} · {{ $module->code }} {{ $module->title }}</p>

@php($s = $r->summary)
<h2>Summary</h2>
<table class="grid">
  <tr><th>Decisions</th><th>Competent</th><th>Not yet competent</th><th>Sampled</th><th>Overturned</th><th>Policy sample</th><th>Meets policy</th></tr>
  <tr><td>{{ $s['decisions'] }}</td><td>{{ $s['competent'] }}</td><td>{{ $s['nyc'] }}</td><td>{{ $s['sampled'] }}</td><td>{{ $s['overturned'] }}</td><td>{{ $s['policy_pct'] }}%</td><td>{{ $s['compliant'] ? 'Yes' : 'No' }}</td></tr>
</table>

<h2>Sample</h2>
<table class="grid">
  <tr><th>Learner</th><th>Assessor</th><th>Assessed</th><th>Outcome</th><th>Why sampled</th><th>Moderation</th></tr>
  @foreach($s['samples'] as $row)
    @php($res = $results->get($row['result_id']))
    <tr>
      <td>{{ $res ? $names->get($res->user_id) : '' }}</td>
      <td>{{ $res ? $names->get($res->assessor_id) : '' }}</td>
      <td>{{ $res?->assessed_on->format('j M Y') }}</td>
      <td>{{ $res?->outcome === 'competent' ? 'Competent' : 'Not yet competent' }}</td>
      <td>{{ ['nyc' => 'Not yet competent', 'new_assessor' => 'New assessor', 'facilitator' => 'Own facilitator', 'random' => 'Random', 'appeal' => 'Appeal'][$row['reason']] ?? $row['reason'] }}</td>
      <td class="{{ $row['decision'] === 'overturn' ? 'over' : '' }}">{{ $row['decision'] === 'overturn' ? 'Overturned' : 'Agreed' }}</td>
    </tr>
  @endforeach
</table>

<h2>Findings</h2>
<p>{!! nl2br(e($r->findings)) !!}</p>
@if($r->recommendations)
<h2>Recommendations</h2>
<p>{!! nl2br(e($r->recommendations)) !!}</p>
@endif

<h2>Moderator</h2>
<p>{{ $names->get($r->moderator_id) }} · signed {{ $r->signed_at->setTimezone('Africa/Johannesburg')->format('j M Y H:i') }}</p>
<p class="muted">I confirm this report is a true account of my moderation of the decisions above.</p>

<div class="footer">Fingerprint {{ $r->hash }}</div>
</body>
</html>
