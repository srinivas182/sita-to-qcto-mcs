import { useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { Body, Button, Card, Notice, Screen, Title } from '@/components/ui';
import { codeFromScan } from '@/core/checkInCode';
import { useApp } from '@/lib/app';
import { checkIn, type CheckInResult } from '@/lib/delivery';

/** Scan the facilitator's changing code. Works without signal: the check-in is sent later. */
export default function CheckIn() {
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const [permission, request] = useCameraPermissions();
  const busy = useRef(false);
  const [result, setResult] = useState<CheckInResult | null>(null);

  const scanned = async (data: string) => {
    const code = codeFromScan(data);
    if (!code || busy.current) return;
    busy.current = true;
    const r = await checkIn(code, online);
    setResult(r);
    if (r.kind === 'done') void qc.invalidateQueries({ queryKey: ['dashboard'] });
    if (r.kind === 'saved') void syncNow();
  };

  if (result) {
    return (
      <Screen>
        <View className="flex-1 justify-center gap-4">
          {result.kind === 'done' ? (
            <Card className="gap-2">
              <Title>{result.action === 'checked_out' ? t('checkin.out') : result.action === 'checked_in' ? t('checkin.in') : t('checkin.already')}</Title>
              <Body>{result.title ?? ''}</Body>
              {result.status === 'late' ? <Notice tone="warning">{t('checkin.late', { n: result.minutesLate })}</Notice> : null}
              {result.review ? <Notice tone="info">{t('checkin.review', { reason: result.review })}</Notice> : null}
            </Card>
          ) : result.kind === 'saved' ? (
            <Card className="gap-2">
              <Title>{t('checkin.saved')}</Title>
              <Body>{t('checkin.savedBody')}</Body>
            </Card>
          ) : (
            <Notice tone="danger">{result.message}</Notice>
          )}
        </View>
        <View className="gap-3">
          {result.kind === 'refused' ? (
            <Button title={t('checkin.again')} onPress={() => { busy.current = false; setResult(null); }} />
          ) : null}
          <Button title={t('common.done')} variant={result.kind === 'refused' ? 'ghost' : 'primary'} onPress={() => router.back()} />
        </View>
      </Screen>
    );
  }

  if (!permission?.granted) {
    return (
      <Screen>
        <View className="flex-1 justify-center gap-4">
          <Title>{t('checkin.title')}</Title>
          <Body>{t('checkin.why')}</Body>
        </View>
        <View className="gap-3">
          <Button title={t('docs.allowCamera')} onPress={() => void request()} />
          <Button title={t('common.cancel')} variant="ghost" onPress={() => router.back()} />
        </View>
      </Screen>
    );
  }

  return (
    <View className="flex-1 bg-black">
      <CameraView style={{ flex: 1 }} barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={({ data }) => void scanned(data)} />
      <View className="absolute inset-x-0 bottom-12 items-center gap-4 px-8">
        <View className="h-60 w-60 rounded-3xl border-2 border-white/80" />
        <Text className="text-center font-medium text-body text-white">{t('checkin.hint')}</Text>
        <Button title={t('common.cancel')} variant="secondary" onPress={() => router.back()} />
      </View>
    </View>
  );
}
