import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { PinPad } from '@/components/PinPad';
import { Body, Button, Notice, Screen, Title } from '@/components/ui';
import { isValidPin } from '@/core/lock';
import { useApp } from '@/lib/app';
import { biometricKind, promptBiometric, setPin } from '@/lib/lock';
import { secure } from '@/lib/secure';

export default function SetupLock() {
  const { t, lockConfigured } = useApp();
  const [kind, setKind] = useState<string | null>(null);
  const [mode, setMode] = useState<'choose' | 'pin' | 'confirm'>('choose');
  const [first, setFirst] = useState('');
  const [pin, setPinValue] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void biometricKind().then((k) => {
      setKind(k);
      if (!k) setMode('pin');
    });
  }, []);

  const useBiometric = async () => {
    if (await promptBiometric(t('lock.title'))) {
      await secure.set('lock_method', 'biometric');
      lockConfigured();
    }
  };

  const onPin = async (value: string) => {
    setPinValue(value);
    if (value.length < 6) return;
    if (mode === 'pin') {
      if (!isValidPin(value)) {
        setError(t('lock.weak'));
        setPinValue('');
        return;
      }
      setError(null);
      setFirst(value);
      setPinValue('');
      setMode('confirm');
    } else if (value !== first) {
      setError(t('lock.mismatch'));
      setPinValue('');
      setMode('pin');
    } else {
      await setPin(value);
      await secure.set('lock_method', 'pin');
      lockConfigured();
    }
  };

  return (
    <Screen>
      <View className="flex-1 justify-center gap-4">
        <Title>{mode === 'choose' ? t('lock.title') : mode === 'pin' ? t('lock.choosePin') : t('lock.confirmPin')}</Title>
        <Body>{mode === 'choose' ? t('lock.body') : t('lock.pinHint')}</Body>
        {error ? <Notice tone="danger">{error}</Notice> : null}
      </View>
      {mode === 'choose' ? (
        <View className="gap-3">
          {kind ? <Button title={t('lock.biometric', { kind })} onPress={() => void useBiometric()} /> : null}
          <Button title={t('lock.pin')} variant="secondary" onPress={() => setMode('pin')} />
        </View>
      ) : (
        <PinPad value={pin} onChange={(v) => void onPin(v)} />
      )}
    </Screen>
  );
}
