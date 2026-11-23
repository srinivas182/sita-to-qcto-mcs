<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 24mm 20mm 22mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.8pt; color: #1f2330; line-height: 1.45; }
  h2 { font-size: 16pt; color: #1e1b4b; margin: 0 0 4mm; }
  h3 { font-size: 11pt; color: #1e1b4b; margin: 6mm 0 2mm; }
  p { margin: 0 0 2.5mm; }
  .clause { padding-left: 2mm; }
  .muted { color: #5b6275; }
  .small { font-size: 8pt; }
  .head { border-bottom: 1pt solid #e3e6ee; padding-bottom: 3mm; margin-bottom: 6mm; }
  .evidence { page-break-before: always; }
  table { width: 100%; border-collapse: collapse; }
  .signers td { border-bottom: .5pt solid #e3e6ee; padding: 2.4mm 1.5mm; vertical-align: top; }
  .label { font-size: 7.5pt; text-transform: uppercase; color: #5b6275; letter-spacing: .4pt; }
  .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.6pt; word-break: break-all; }
  .box { margin-top: 6mm; padding: 3mm 4mm; background: #f4f4fb; border-radius: 2mm; }
  .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #5b6275; }
</style>
</head>
<body>
<div class="footer">{{ $provider }} · {{ $agreement->title }} · Verify at {{ $verifyUrl }}</div>
<div class="head">
  <div class="label">{{ $provider }}</div>
  <div class="small muted">Reference {{ strtoupper($agreement->verify_code) }} · sent {{ $agreement->sent_at?->timezone('Africa/Johannesburg')->format('j F Y') }}</div>
</div>
{!! $bodyHtml !!}

<div class="evidence">
  <h2>Signatures and evidence</h2>
  <p class="muted">Each person below confirmed their identity with a one-time PIN sent to them, typed their full name and agreed to sign. Every signature was made against the same text, identified by the fingerprint below.</p>
  <table class="signers">
    @foreach($signers as $s)
    <tr>
      <td style="width:34%"><strong>{{ $s->name }}</strong><br><span class="muted">{{ $roles[$s->role] ?? $s->role }}</span></td>
      <td>
        Signed {{ $s->signed_at?->timezone('Africa/Johannesburg')->format('j F Y, H:i:s') }} SAST<br>
        <span class="small muted">Typed name: {{ $s->signed_name }} · PIN sent by {{ ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'email'][$s->evidence['channel'] ?? ''] ?? ($s->evidence['channel'] ?? '—') }} to {{ $s->evidence['destination'] ?? '—' }}<br>
        IP {{ $s->ip }} · {{ \Illuminate\Support\Str::limit((string) $s->user_agent, 90) }}</span>
      </td>
    </tr>
    @endforeach
  </table>
  <div class="box">
    <table>
      <tr>
        <td style="vertical-align:top">
          <div class="label">Text fingerprint (SHA-256)</div>
          <div class="hash">{{ $agreement->body_sha256 }}</div>
          <div style="height:3mm"></div>
          <div class="label">Check this document</div>
          <div class="small">Scan the code or open <strong>{{ $verifyUrl }}</strong>. The page shows who signed and when, and checks that a copy of this file has not been changed.</div>
        </td>
        <td style="width:34mm; text-align:right"><img src="{{ $qr }}" style="width:30mm; height:30mm" alt=""></td>
      </tr>
    </table>
  </div>
  <p class="small muted" style="margin-top:5mm">Electronic signatures under section 13 of the Electronic Communications and Transactions Act, 2002. Signed with {{ config('app.name') }}; evidence kept by {{ $provider }}.</p>
</div>
</body>
</html>
