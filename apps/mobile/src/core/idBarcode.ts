/**
 * Reads the barcode on a South African identity document, on the phone, so the ID number
 * can be compared with the one the person gave. Nothing is sent but the number.
 *
 * - Smart ID card (back): PDF417 with fields separated by "|":
 *   surname | names | sex | nationality | ID number | date of birth | country of birth | status | date issued | …
 * - Green ID book: a Code 39 barcode holding the 13-digit number.
 *
 * Anything that is not a valid 13-digit SA ID number (Luhn check) is ignored.
 */
export interface IdBarcode {
  idNumber: string;
  surname: string | null;
  names: string | null;
  source: 'smart_card' | 'id_book';
}

export function luhnValid(digits: string): boolean {
  if (!/^\d{13}$/.test(digits)) return false;
  let sum = 0;
  for (let i = 0; i < 13; i++) {
    let d = Number(digits[12 - i]);
    if (i % 2 === 1) {
      d *= 2;
      if (d > 9) d -= 9;
    }
    sum += d;
  }
  return sum % 10 === 0;
}

const clean = (s: string | undefined): string | null => {
  const v = (s ?? '').replace(/\s+/g, ' ').trim();
  return v === '' ? null : v;
};

export function readIdBarcode(data: string | null | undefined, type?: string): IdBarcode | null {
  if (!data) return null;
  const parts = data.split('|');
  if (parts.length >= 5) {
    const idNumber = (parts[4] ?? '').replace(/\D/g, '');
    if (luhnValid(idNumber)) {
      return { idNumber, surname: clean(parts[0]), names: clean(parts[1]), source: 'smart_card' };
    }
  }
  // An ID book, or a format we do not know: take the first valid 13-digit number.
  for (const m of data.matchAll(/\d{13}/g)) {
    if (luhnValid(m[0])) {
      return { idNumber: m[0], surname: null, names: null, source: type === 'pdf417' ? 'smart_card' : 'id_book' };
    }
  }
  return null;
}
