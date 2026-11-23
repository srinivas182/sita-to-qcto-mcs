<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 12mm 10mm 14mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6pt; color: #1f2330; }
  h1 { font-size: 13pt; color: #1e1b4b; margin: 0 0 1mm; }
  .muted { color: #5b6275; }
  table { width: 100%; border-collapse: collapse; }
  .grid th, .grid td { border: .4pt solid #c9cdd8; padding: 1.2mm .8mm; text-align: center; }
  .grid th.name, .grid td.name { text-align: left; white-space: nowrap; }
  .grid th { background: #f1f2f7; font-weight: normal; font-size: 6.6pt; }
  .A { color: #b42318; font-weight: bold; } .E { color: #5b6275; } .L { color: #8a5a0b; }
  .sig td { border-bottom: .4pt solid #e3e6ee; padding: 1.6mm 1mm; vertical-align: top; }
  .footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 6.6pt; color: #5b6275; }
  .key { margin-top: 2mm; }
</style>
</head>
<body>
<div class="footer">{{ $provider }} · attendance register {{ $month }} · {{ $cohort->name }} · snapshot {{ substr($register->grid_sha256, 0, 16) }}</div>
<h1>Attendance register: {{ $month }}</h1>
<div class="muted">{{ $provider }}@if($centre) · {{ $centre->name }}@if($centre->accreditation_number) ({{ $centre->accreditation_number }})@endif @endif · {{ $cohort->name }}@if($qualification) · {{ $qualification->title }}@if($qualification->saqa_id) (SAQA {{ $qualification->saqa_id }})@endif @endif</div>
<br>
<table class="grid">
  <tr>
    <th class="name">Learner</th>
    @foreach($register->grid['sessions'] as $s)
      <th>{{ \Carbon\CarbonImmutable::parse($s['date'])->format('d M') }}<br>{{ $s['start'] }}<br>{{ $s['module'] ?? ($s['kind'] === 'practical' ? 'PR' : 'KN') }}</th>
    @endforeach
    <th>Held</th><th>Att.</th><th>Exc.</th><th>%</th><th>Learner signature</th>
  </tr>
  @php $sigs = collect($data['signatures'])->where('role', 'learner')->keyBy('user_id'); @endphp
  @foreach($register->grid['learners'] as $l)
  <tr>
    <td class="name">{{ $l['name'] }}<br><span class="muted">{{ $l['id_number'] }}</span></td>
    @foreach($register->grid['sessions'] as $s)
      @php $m = $l['marks'][$s['id']] ?? null; @endphp
      <td class="{{ $m === 'absent' ? 'A' : ($m === 'excused' ? 'E' : ($m === 'late' || $m === 'left_early' ? 'L' : '')) }}">{{ $m === null ? '–' : ['present' => 'P', 'late' => 'L', 'left_early' => 'LE', 'absent' => 'A', 'excused' => 'E'][$m] }}</td>
    @endforeach
    <td>{{ $l['held'] }}</td><td>{{ $l['attended'] }}</td><td>{{ $l['excused'] }}</td><td>{{ $l['pct'] ?? '–' }}</td>
    @php $sg = $sigs->get($l['user_id']); @endphp
    <td style="text-align:left">
      @if($sg && $sg['status'] === 'signed') Signed in the app {{ \Carbon\CarbonImmutable::parse($sg['signed_at'])->timezone('Africa/Johannesburg')->format('j M Y H:i') }}
      @elseif($sg && $sg['status'] === 'disputed') <span class="A">Disputed:</span> {{ $sg['dispute'] }}
      @else <span class="muted">Not signed</span> @endif
    </td>
  </tr>
  @endforeach
</table>
<div class="key muted">P present · L late · LE left early · A absent · E excused (left out of the percentage) · – not enrolled then. KN knowledge, PR practical. Check-ins were by a changing QR code at the centre or marked by the facilitator with a reason.</div>
<br>
<table class="sig">
  @foreach(collect($data['signatures'])->where('role', 'facilitator') as $f)
  <tr><td style="width:30%"><strong>{{ $f['name'] }}</strong><br><span class="muted">Facilitator</span></td>
  <td>@if($f['status'] === 'signed') Signed in the app {{ \Carbon\CarbonImmutable::parse($f['signed_at'])->timezone('Africa/Johannesburg')->format('j F Y, H:i') }} SAST @else <span class="muted">Not signed yet</span> @endif</td></tr>
  @endforeach
</table>
</body>
</html>
