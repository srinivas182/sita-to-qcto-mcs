@php use Modules\Billing\Support\Money; @endphp
@extends('billing::layout')
@section('content')
<table>
  <tr>
    <td><h1>TAX INVOICE</h1><span class="muted">{{ $doc->supplier['trading_name'] ?? $doc->supplier['registered_name'] ?? '' }}</span></td>
    <td class="num">
      <table class="meta" style="width:auto; margin-left:auto">
        <tr><td class="label">Invoice no.&nbsp;</td><td class="num"><strong>{{ $doc->number }}</strong></td></tr>
        <tr><td class="label">Date&nbsp;</td><td class="num">{{ $doc->issued_at->timezone('Africa/Johannesburg')->format('j F Y') }}</td></tr>
        <tr><td class="label">Due&nbsp;</td><td class="num">{{ $doc->due_at->timezone('Africa/Johannesburg')->format('j F Y') }}</td></tr>
        @if($doc->status === 'paid')<tr><td></td><td class="num"><span class="stamp">PAID</span></td></tr>@endif
      </table>
    </td>
  </tr>
</table>
<div style="height:8mm"></div>
@include('billing::parties', ['supplier' => $doc->supplier, 'recipient' => $doc->recipient])
<table class="lines">
  <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount (excl. VAT)</th></tr></thead>
  <tbody>
  @foreach($doc->lines as $line)
    <tr><td>{{ $line->description }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ Money::format($line->unit_cents) }}</td><td class="num">{{ Money::format($line->amount_cents) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="totals">
  <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ Money::format($doc->subtotal_cents) }}</td></tr>
  <tr><td>VAT at {{ rtrim(rtrim(number_format((float) $doc->vat_rate, 2), '0'), '.') }}%</td><td class="num">{{ Money::format($doc->vat_cents) }}</td></tr>
  <tr class="grand"><td>Total (incl. VAT)</td><td class="num">{{ Money::format($doc->total_cents) }}</td></tr>
  @if($doc->amount_credited_cents > 0)<tr><td>Credited</td><td class="num">-{{ Money::format($doc->amount_credited_cents) }}</td></tr>@endif
  @if($doc->amount_paid_cents > 0)<tr><td>Paid</td><td class="num">-{{ Money::format($doc->amount_paid_cents) }}</td></tr>@endif
  <tr><td><strong>Amount due</strong></td><td class="num"><strong>{{ Money::format($doc->amountDue()) }}</strong></td></tr>
</table>
@if($doc->status === 'open')
<div class="box">
  <div class="label">How to pay</div>
  Pay online from <strong>Billing</strong> in your console, or by EFT using reference <strong>{{ $doc->eft_reference }}</strong>.
  @if(!empty($doc->supplier['bank_details']))<br>{!! nl2br(e($doc->supplier['bank_details'])) !!}@endif
</div>
@endif
@if(!empty($doc->supplier['invoice_footer']))<p class="small muted" style="margin-top:8mm">{{ $doc->supplier['invoice_footer'] }}</p>@endif
@endsection
