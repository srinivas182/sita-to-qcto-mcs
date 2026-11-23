/**
 * The facilitator's screen shows a link like https://tym.example/c/<session>.<window>.<sig>.
 * The app takes the code out of it (or accepts the bare code). Anything else is ignored,
 * so a random QR code can't send the app anywhere.
 */
const CODE = /^[0-9A-Za-z]{26}\.\d{1,12}\.[0-9a-f]{20}$/;

export function codeFromScan(data: string | null | undefined): string | null {
  if (!data) return null;
  const raw = data.trim();
  if (CODE.test(raw)) return raw;
  const m = /\/c\/([^/?#\s]+)/.exec(raw);
  if (m && CODE.test(m[1]!)) return m[1]!;
  return null;
}
