import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, View } from 'react-native';
import { Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { lookupTenant } from '@/lib/tenantLookup';

export default function TenantScreen() {
  const { t, chooseTenant } = useApp();
  const router = useRouter();
  const params = useLocalSearchParams<{ code?: string }>();
  const [code, setCode] = useState(params.code ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (value = code) => {
    setBusy(true);
    setError(null);
    try {
      const tenant = await lookupTenant(value);
      if (tenant) await chooseTenant(tenant);
      else setError(t('tenant.notFound'));
    } catch {
      setError(t('home.offline'));
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (params.code) void submit(params.code);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.code]);

  return (
    <Screen>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <View className="flex-1 justify-center gap-4">
          <Title>{t('tenant.title')}</Title>
          <Body>{t('tenant.body')}</Body>
          {error ? <Notice tone="danger">{error}</Notice> : null}
          <Field label={t('tenant.code')} value={code} onChangeText={setCode} autoCapitalize="none" autoCorrect={false} placeholder="e.g. tym" returnKeyType="go" onSubmitEditing={() => void submit()} />
        </View>
        <View className="gap-3">
          <Button title={t('tenant.continue')} loading={busy} disabled={code.trim().length < 3} onPress={() => void submit()} />
          <Button title={t('tenant.scan')} variant="secondary" onPress={() => router.push('/scan')} />
        </View>
      </KeyboardAvoidingView>
    </Screen>
  );
}
