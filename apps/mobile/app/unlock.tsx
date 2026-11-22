import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { PinPad } from '@/components/PinPad';
import { Brand, Button, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { checkPin, promptBiometric } from '@/lib/lock';
import { secure } from '@/lib/secure';

export default function Unlock() {
  const { t, tenant, unlocked, signOut } = useApp();
  const [method, setMethod] = useState<'biometric' | 'pin' | null>(null);
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const biometric = useCallback(async () => {
    if (await promptBiometric(t('unlock.title'))) unlocked();
  }, [t, unlocked]);

  useEffect(() => {
    void secure.get('lock_method').then((m) => {
      const value = m === 'biometric' ? 'biometric' : 'pin';
      setMethod(value);
      if (value === 'biometric') void biometric();
    });
  }, [biometric]);

  const onPin = async (value: string) => {
    setPin(value);
    if (value.length < 6) return;
    setBusy(true);
    const result = await checkPin(value);
    setBusy(false);
    setPin('');
    if (result === 'ok') return unlocked();
    if (result === 0) return signOut();
    setError(t('unlock.wrong', { n: result }));
  };

  return (
    <Screen>
      <View className="pt-4">{tenant ? <Brand name={tenant.name} primary={tenant.branding.primary} /> : null}</View>
      <View className="flex-1 justify-center gap-4">
        <Title>{t('unlock.title')}</Title>
        {error ? <Notice tone="danger">{error}</Notice> : null}
      </View>
      {method === 'biometric' ? <Button title={t('unlock.biometric')} onPress={() => void biometric()} /> : null}
      {method === 'pin' ? <PinPad value={pin} onChange={(v) => void onPin(v)} disabled={busy} /> : null}
    </Screen>
  );
}
