@php use Modules\Billing\Support\Money; @endphp
@extends('billing::layout')
@section('content')
<table>
  <tr>
    <td><h1>CREDIT NOTE</h1><span class="muted">Tax credit note under section 21 of the VAT Act</span></td>
    <td class="num">
      <table class="meta" style="width:auto; margin-left:auto">
        <tr><td class="label">Credit note no.&nbsp;</td><td class="num"><strong>{{ $doc->number }}</strong></td></tr>
        <tr><td class="label">Date&nbsp;</td><td class="num">{{ $doc->issued_at->timezone('Africa/Johannesburg')->format('j F Y') }}</td></tr>
        <tr><td class="label">Against invoice&nbsp;</td><td class="num">{{ $invoice?->number }}</td></tr>
      </table>
    </td>
  </tr>
</table>
<div style="height:8mm"></div>
@include('billing::parties', ['supplier' => $doc->supplier, 'recipient' => $doc->recipient])
<table class="lines">
  <thead><tr><th>Reason</th><th class="num">Amount (excl. VAT)</th></tr></thead>
  <tbody><tr><td>{{ $doc->reason }}</td><td class="num">{{ Money::format($doc->subtotal_cents) }}</td></tr></tbody>
</table>
<table class="totals">
  <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ Money::format($doc->subtotal_cents) }}</td></tr>
  <tr><td>VAT</td><td class="num">{{ Money::format($doc->vat_cents) }}</td></tr>
  <tr class="grand"><td>Total credited</td><td class="num">{{ Money::format($doc->total_cents) }}</td></tr>
</table>
@endsection
