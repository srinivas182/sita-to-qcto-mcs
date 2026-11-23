import { useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Image, Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { readIdBarcode, type IdBarcode } from '@/core/idBarcode';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { uploadPhoto } from '@/lib/enrolment';

interface Added {
  status: string;
  checks: { quality?: { ok: boolean; problems?: string[] }; certification?: { ok: boolean; problem: string | null }; id_match?: boolean | null } | null;
}

/**
 * Photograph a document. For an ID the camera also reads the barcode (smart ID card PDF417
 * or ID book Code 39) so the number can be matched. The server checks sharpness, glare
 * and the certification date, and says at once if the photo should be retaken.
 */
export default function Capture() {
  const { type, label } = useLocalSearchParams<{ type: string; label: string }>();
  const { t } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const [permission, request] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [photo, setPhoto] = useState<string | null>(null);
  const [barcode, setBarcode] = useState<IdBarcode | null>(null);
  const [form, setForm] = useState({ certified: true, certified_on: '', certified_by: '' });
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [result, setResult] = useState<Added | null>(null);
  const isId = type === 'id_document';

  const send = async () => {
    if (!photo) return;
    setBusy(true);
    setErrors({});
    try {
      const fileId = await uploadPhoto('learner_document', photo, `${type}.jpg`);
      const r = await json<{ data: Added }>(api.POST('/me/documents' as never, { body: {
        type, file_id: fileId, certified: form.certified, certified_on: form.certified ? form.certified_on || null : null,
        certified_by: form.certified ? form.certified_by || null : null, barcode_id_number: barcode?.idNumber ?? null,
      } } as never));
      setResult(r.data);
      void qc.invalidateQueries({ queryKey: ['my-documents'] });
    } catch (e) {
      setErrors(e instanceof ApiError ? e.fields : { status: [errorMessage(e)] });
    } finally {
      setBusy(false);
    }
  };

  if (!permission?.granted) {
    return (
      <Screen>
        <View className="flex-1 justify-center gap-4">
          <Title>{label}</Title>
          <Body>{t('docs.cameraWhy')}</Body>
        </View>
        <View className="gap-3">
          <Button title={t('docs.allowCamera')} onPress={() => void request()} />
          <Button title={t('common.cancel')} variant="ghost" onPress={() => router.back()} />
        </View>
      </Screen>
    );
  }

  if (!photo) {
    return (
      <View className="flex-1 bg-black">
        <CameraView ref={camera} style={{ flex: 1 }}
          barcodeScannerSettings={isId ? { barcodeTypes: ['pdf417', 'code39'] } : undefined}
          onBarcodeScanned={isId ? ({ data, type: kind }) => { const b = readIdBarcode(data, kind); if (b) setBarcode(b); } : undefined} />
        <View className="absolute inset-x-0 bottom-10 items-center gap-3 px-6">
          <Text className="text-center font-medium text-body text-white">{isId ? (barcode ? t('docs.barcodeRead') : t('docs.idHint')) : t('docs.hint')}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t('docs.take')} onPress={() => void camera.current?.takePictureAsync({ quality: 0.85 }).then((p) => p && setPhoto(p.uri))}
            className="h-20 w-20 items-center justify-center rounded-full border-4 border-white bg-white/20" />
          <Button title={t('common.cancel')} variant="secondary" onPress={() => router.back()} />
        </View>
      </View>
    );
  }

  const quality = result?.checks?.quality;
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        <Title>{label}</Title>
        <Image source={{ uri: photo }} accessibilityLabel={label} style={{ width: '100%', aspectRatio: 1.5, borderRadius: 16 }} resizeMode="cover" />
        {barcode ? <Notice tone="success">{t('docs.barcodeMatched', { id: `${barcode.idNumber.slice(0, 6)}•••••••` })}</Notice> : null}
        {result ? (
          <Card className="gap-2">
            {quality && !quality.ok ? <Notice tone="warning">{(quality.problems ?? []).join(' ')} {t('docs.retake')}</Notice> : <Notice tone="success">{t('docs.added')}</Notice>}
            {result.checks?.certification && !result.checks.certification.ok ? <Notice tone="warning">{result.checks.certification.problem}</Notice> : null}
            <View className="flex-row gap-2">
              {quality && !quality.ok ? <Button title={t('docs.again')} variant="secondary" onPress={() => { setPhoto(null); setResult(null); }} /> : null}
              <Button title={t('common.done')} onPress={() => router.back()} />
            </View>
          </Card>
        ) : (
          <>
            <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: form.certified }} onPress={() => setForm({ ...form, certified: !form.certified })} className="flex-row items-center gap-3">
              <View className={`h-6 w-6 items-center justify-center rounded-md border-2 ${form.certified ? 'border-primary-700 bg-primary-700' : 'border-line'}`}>
                {form.certified ? <Text className="font-bold text-white">✓</Text> : null}
              </View>
              <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('docs.certified')}</Text>
            </Pressable>
            {form.certified ? (
              <>
                <Field label={t('docs.certifiedOn')} placeholder="2026-11-20" value={form.certified_on} onChangeText={(v) => setForm({ ...form, certified_on: v })} error={errors.certified_on?.[0]} />
                <Field label={t('docs.certifiedBy')} value={form.certified_by} onChangeText={(v) => setForm({ ...form, certified_by: v })} />
              </>
            ) : null}
            {errors.status ?? errors.file_id ?? errors.type ? <Notice tone="danger">{(errors.status ?? errors.file_id ?? errors.type)?.[0]}</Notice> : null}
            <View className="flex-row gap-2">
              <Button title={t('docs.again')} variant="secondary" onPress={() => setPhoto(null)} />
              <View className="flex-1"><Button title={t('docs.send')} loading={busy} onPress={() => void send()} /></View>
            </View>
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
