<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 28mm 18mm 22mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2330; }
  h1 { font-size: 20pt; margin: 0 0 2mm; color: #1e1b4b; letter-spacing: .5pt; }
  .muted { color: #5b6275; }
  .small { font-size: 8pt; }
  table { width: 100%; border-collapse: collapse; }
  .parties td { vertical-align: top; width: 50%; padding: 0 6mm 0 0; }
  .lines { margin-top: 8mm; }
  .lines th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #5b6275; border-bottom: 1.2pt solid #1e1b4b; padding: 2mm 1.5mm; }
  .lines td { border-bottom: .5pt solid #e3e6ee; padding: 2.2mm 1.5mm; }
  .num { text-align: right; white-space: nowrap; }
  .totals { width: 45%; margin-left: 55%; margin-top: 4mm; }
  .totals td { padding: 1.5mm; }
  .totals .grand td { font-weight: bold; font-size: 11pt; border-top: 1.2pt solid #1e1b4b; }
  .meta td { padding: .6mm 0; }
  .box { margin-top: 8mm; padding: 3mm 4mm; background: #f4f4fb; border-radius: 2mm; }
  .label { font-size: 7.5pt; text-transform: uppercase; color: #5b6275; letter-spacing: .4pt; }
  .stamp { display: inline-block; padding: 1mm 3mm; border: 1pt solid #15803d; color: #15803d; font-weight: bold; border-radius: 1.5mm; }
</style>
</head>
<body>
@yield('content')
</body>
</html>
