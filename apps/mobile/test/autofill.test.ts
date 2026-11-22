import { beforeEach, describe, expect, it, vi } from 'vitest';

const listeners: Array<(e: { code: string; source: 'whatsapp' | 'sms' }) => void> = [];
const native = {
  requestWhatsAppCode: vi.fn(() => true),
  startSmsRetriever: vi.fn(async () => true),
  stop: vi.fn(),
  appHash: vi.fn(() => 'FA+9qCX9VSu'),
  addListener: vi.fn((_: string, cb: (typeof listeners)[number]) => {
    listeners.push(cb);
    return { remove: vi.fn(() => listeners.splice(listeners.indexOf(cb), 1)) };
  }),
};
let os = 'android';
vi.mock('react-native', () => ({ Platform: { get OS() { return os; } } }));
vi.mock('expo', () => ({ requireOptionalNativeModule: () => native }));

async function load() {
  vi.resetModules();
  return import('../modules/code-autofill/src');
}

describe('code autofill', () => {
  beforeEach(() => {
    listeners.length = 0;
    vi.clearAllMocks();
    os = 'android';
  });

  it('shakes hands with WhatsApp and starts the SMS Retriever before the code is sent', async () => {
    const { listenForCode } = await load();
    listenForCode(() => undefined);
    expect(native.requestWhatsAppCode).toHaveBeenCalledOnce();
    expect(native.startSmsRetriever).toHaveBeenCalledOnce();
  });

  it('delivers one valid code only, then stops listening', async () => {
    const { listenForCode } = await load();
    const got = vi.fn();
    const stop = listenForCode(got);
    listeners[0]!({ code: 'abc', source: 'sms' });
    listeners[0]!({ code: '123456', source: 'whatsapp' });
    listeners[0]!({ code: '654321', source: 'sms' });
    expect(got).toHaveBeenCalledExactlyOnceWith('123456', 'whatsapp');
    stop();
    expect(native.stop).toHaveBeenCalled();
    expect(listeners).toHaveLength(0);
  });

  it('does nothing on iOS, where the keyboard offers the code', async () => {
    os = 'ios';
    const { listenForCode, appHash, autofillAvailable } = await load();
    expect(autofillAvailable).toBe(false);
    listenForCode(() => undefined)();
    expect(native.requestWhatsAppCode).not.toHaveBeenCalled();
    expect(appHash()).toBeNull();
  });
});
