/** Rand from cents, the South African way: R1 500,00. */
export function rand(cents: number): string {
  const r = (cents / 100).toFixed(2).split('.');
  const whole = (r[0] ?? '0').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return `R${whole},${r[1] ?? '00'}`;
}

/** An account number as typed: digits only, 6 to 16. */
export function validAccount(raw: string): string | null {
  const digits = raw.replace(/\s/g, '');
  return /^\d{6,16}$/.test(digits) ? digits : null;
}
