import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Text, View } from 'react-native';
import { Button } from './ui';
import { useApp } from '@/lib/app';

export function BackHeader() {
  const { t } = useApp();
  const router = useRouter();
  return (
    <View className="items-start">
      <Button title={t('common.back')} variant="ghost" icon={<Feather name="chevron-left" size={18} color="#4338ca" />} onPress={() => router.back()} />
    </View>
  );
}

/** A small status pill. */
export function Pill({ text, tone = 'neutral' }: { text: string; tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }) {
  const map = { neutral: 'bg-line-soft text-muted', success: 'bg-success-bg text-success', warning: 'bg-warning-bg text-warning', danger: 'bg-danger-bg text-danger', info: 'bg-info-bg text-info' }[tone];
  const [bg, fg] = map.split(' ');
  return (
    <View className={`self-start rounded-full px-2.5 py-1 ${bg}`}>
      <Text className={`font-medium text-caption ${fg}`}>{text}</Text>
    </View>
  );
}
