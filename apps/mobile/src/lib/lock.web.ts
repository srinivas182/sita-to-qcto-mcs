/** Web preview only: no biometrics in the browser, app PIN works as on phones. */
import { hashPin, PIN_ATTEMPTS, verifyPin } from '@/core/lock';
import { randomHex } from './random';
import { secure } from './secure';

export async function biometricKind(): Promise<string | null> {
  return null;
}

export async function promptBiometric(): Promise<boolean> {
  return false;
}

export async function setPin(pin: string): Promise<void> {
  const salt = randomHex(16);
  await secure.set('pin_salt', salt);
  await secure.set('pin_hash', hashPin(pin, salt, 1000));
  await secure.set('pin_attempts', '0');
}

export async function checkPin(pin: string): Promise<'ok' | number> {
  const [salt, hash, attempts] = await Promise.all([secure.get('pin_salt'), secure.get('pin_hash'), secure.get('pin_attempts')]);
  if (!salt || !hash) return 0;
  if (verifyPin(pin, salt, hash, 1000)) return 'ok';
  const used = Number(attempts ?? '0') + 1;
  await secure.set('pin_attempts', String(used));
  return Math.max(0, PIN_ATTEMPTS - used);
}
