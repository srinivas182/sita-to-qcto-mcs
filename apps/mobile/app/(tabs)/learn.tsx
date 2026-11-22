import Feather from '@expo/vector-icons/Feather';
import { View } from 'react-native';
import { Body, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';

export default function Learn() {
  const { t } = useApp();
  return (
    <Screen>
      <View className="flex-1 items-center justify-center gap-3">
        <View className="h-14 w-14 items-center justify-center rounded-2xl bg-primary-50">
          <Feather name="book-open" size={24} color="#4338ca" />
        </View>
        <Title>{t('tabs.learn')}</Title>
        <View className="max-w-[280px]"><Body>{t('learn.soon')}</Body></View>
      </View>
    </Screen>
  );
}
