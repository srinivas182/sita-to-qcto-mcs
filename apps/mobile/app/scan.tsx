import { CameraView, useCameraPermissions } from 'expo-camera';
import { useRouter } from 'expo-router';
import { useRef } from 'react';
import { Text, View } from 'react-native';
import { Body, Button, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { codeFromQr } from '@/lib/tenantLookup';

export default function Scan() {
  const { t } = useApp();
  const router = useRouter();
  const [permission, request] = useCameraPermissions();
  const done = useRef(false);

  if (!permission?.granted) {
    return (
      <Screen>
        <View className="flex-1 justify-center gap-4">
          <Title>{t('tenant.scan')}</Title>
          <Body>{t('tenant.body')}</Body>
        </View>
        <View className="gap-3">
          <Button title={t('tenant.scan')} onPress={() => void request()} />
          <Button title={t('common.cancel')} variant="ghost" onPress={() => router.back()} />
        </View>
      </Screen>
    );
  }

  return (
    <View className="flex-1 bg-black">
      <CameraView
        style={{ flex: 1 }}
        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
        onBarcodeScanned={({ data }) => {
          const code = codeFromQr(data);
          if (!code || done.current) return;
          done.current = true;
          router.replace({ pathname: '/tenant', params: { code } });
        }}
      />
      <View className="absolute inset-x-0 bottom-12 items-center gap-4 px-8">
        <View className="h-56 w-56 rounded-3xl border-2 border-white/80" />
        <Text className="text-center font-medium text-body text-white">{t('tenant.body')}</Text>
        <Button title={t('common.cancel')} variant="secondary" onPress={() => router.back()} />
      </View>
    </View>
  );
}
