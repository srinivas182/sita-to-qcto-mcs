/**
 * The fingerprint of a logbook entry, made on the phone when the entry is written (even
 * offline) and checked by the server. It must match the server's EntryHash (PHP) exactly;
 * the shared test vector lives in both test suites.
 */
export interface EntryFields {
  prev: string | null;
  clientId: string;
  moduleId: string;
  activity: string;
  date: string; // YYYY-MM-DD
  minutes: number;
  note: string | null;
  photoHashes: string[];
  deviceTime: string; // exactly as sent, e.g. 2027-02-03T14:05:00+02:00
}

/** PHP's trim(): spaces, tabs, new lines, NUL and vertical tab only. */
function phpTrim(s: string): string {
  return s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}

export function entryHashInput(f: EntryFields): string {
  const photos = f.photoHashes.map((h) => h.toLowerCase()).sort();
  const text = phpTrim(f.note ?? '').replace(/\r\n|\r/g, '\n').replace(/\|/g, '/');
  return ['v1', f.prev ?? '', f.clientId.toLowerCase(), f.moduleId.toLowerCase(), f.activity, f.date, String(Math.trunc(f.minutes)), text, photos.join(','), f.deviceTime].join('|');
}

export async function entryHash(f: EntryFields, sha256Hex: (input: string) => Promise<string> | string): Promise<string> {
  return (await sha256Hex(entryHashInput(f))).toLowerCase();
}

/** A local time with its UTC offset and no milliseconds: 2027-02-03T14:05:00+02:00. */
export function localIso(d: Date): string {
  const pad = (n: number) => String(Math.abs(n)).padStart(2, '0');
  const off = -d.getTimezoneOffset();
  const sign = off >= 0 ? '+' : '-';
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}${sign}${pad(Math.floor(Math.abs(off) / 60))}:${pad(Math.abs(off) % 60)}`;
}

/** The local calendar date, YYYY-MM-DD. */
export function localDate(d: Date): string {
  return localIso(d).slice(0, 10);
}
