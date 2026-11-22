@php
  $line = fn ($p) => collect([$p['address_line1'] ?? null, $p['address_line2'] ?? null, trim(($p['city'] ?? '').' '.($p['postal_code'] ?? '')), $p['province'] ?? null])->filter()->implode("\n");
@endphp
<table class="parties">
  <tr>
    <td>
      <div class="label">From</div>
      <strong>{{ $supplier['registered_name'] ?? '' }}</strong>@if(!empty($supplier['trading_name'])) <span class="muted">t/a {{ $supplier['trading_name'] }}</span>@endif<br>
      {!! nl2br(e($line($supplier))) !!}<br>
      @if(!empty($supplier['registration_number']))Reg. no. {{ $supplier['registration_number'] }}<br>@endif
      @if(!empty($supplier['vat_number']))VAT no. {{ $supplier['vat_number'] }}<br>@endif
      <span class="muted">{{ $supplier['email'] ?? '' }} @if(!empty($supplier['phone'])) · {{ $supplier['phone'] }} @endif</span>
    </td>
    <td>
      <div class="label">To</div>
      <strong>{{ $recipient['legal_name'] ?? '' }}</strong><br>
      {!! nl2br(e($line($recipient))) !!}<br>
      @if(!empty($recipient['registration_number']))Reg. no. {{ $recipient['registration_number'] }}<br>@endif
      @if(!empty($recipient['vat_number']))VAT no. {{ $recipient['vat_number'] }}<br>@endif
      @if(!empty($recipient['purchase_order']))Order no. {{ $recipient['purchase_order'] }}<br>@endif
    </td>
  </tr>
</table>
