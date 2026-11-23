import { describe, expect, it } from 'vitest';
import { codeFromScan } from '../src/core/checkInCode';
import { megabytes, planPack } from '../src/core/offlinePack';

const code = '01jdemo0000000000000000000.60000000.0123456789abcdef0123';

describe('check-in codes', () => {
  it('reads the code from the link or on its own', () => {
    expect(codeFromScan(`https://tym.skills.co.za/c/${code}`)).toBe(code);
    expect(codeFromScan(code)).toBe(code);
  });
  it('ignores other QR codes', () => {
    expect(codeFromScan('https://evil.test/c/../settings')).toBeNull();
    expect(codeFromScan('WIFI:S:centre;T:WPA;P:secret;;')).toBeNull();
    expect(codeFromScan('')).toBeNull();
  });
});

describe('offline pack', () => {
  it('downloads only new or changed items and removes the rest', () => {
    const plan = planPack(
      [
        { id: 'a', version: 2, size: 1000, low: false },
        { id: 'b', version: 1, size: 5_000_000, low: true },
        { id: 'c', version: 1, size: 200, low: false },
      ],
      { a: { version: 1, low: false, path: 'x' }, b: { version: 1, low: true, path: 'y' }, gone: { version: 3, low: false, path: 'z' } },
    );
    expect(plan.download.map((i) => i.id)).toEqual(['a', 'c']);
    expect(plan.remove).toEqual(['gone']);
    expect(plan.bytes).toBe(1200);
    expect(megabytes(1200)).toBe('1 KB');
    expect(megabytes(5_400_000)).toBe('5.4 MB');
  });
});
