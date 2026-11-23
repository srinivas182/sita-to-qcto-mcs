import { describe, expect, it } from 'vitest';
import { luhnValid, readIdBarcode } from '../src/core/idBarcode';

describe('SA ID barcodes', () => {
  it('reads the smart ID card PDF417', () => {
    const data = 'ZULU|AYANDA PRECIOUS|F|RSA|9202204720083|20 FEB 1992|RSA|CITIZEN|01 MAR 2019|12345|';
    expect(readIdBarcode(data, 'pdf417')).toEqual({ idNumber: '9202204720083', surname: 'ZULU', names: 'AYANDA PRECIOUS', source: 'smart_card' });
  });

  it('reads the ID book Code 39', () => {
    expect(readIdBarcode('9001014800089', 'code39')).toEqual({ idNumber: '9001014800089', surname: null, names: null, source: 'id_book' });
  });

  it('ignores anything that is not a valid ID number', () => {
    expect(readIdBarcode('ZULU|AYANDA|F|RSA|9202204720084|', 'pdf417')).toBeNull();
    expect(readIdBarcode('https://example.test/9202204720', 'qr')).toBeNull();
    expect(readIdBarcode('', 'pdf417')).toBeNull();
    expect(luhnValid('1203155800088')).toBe(true);
    expect(luhnValid('12031558000')).toBe(false);
  });
});
