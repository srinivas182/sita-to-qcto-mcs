import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { Body, Button, Card, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyAgreements } from '@/lib/agreements';

export default function MyAgreements() {
  const { t } = useApp();
  const router = useRouter();
  const list = useMyAgreements();
  const rows = list.data ?? [];

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" refreshControl={<RefreshControl refreshing={list.isRefetching} onRefresh={() => void list.refetch()} />}>
        <View className="items-start">
          <Button title={t('common.back')} variant="ghost" icon={<Feather name="chevron-left" size={18} color="#4338ca" />} onPress={() => router.back()} />
        </View>
        <Title>{t('sign.title')}</Title>
        {list.isSuccess && !rows.length ? <Body>{t('sign.none')}</Body> : null}
        {rows.map((a) => {
          const state = a.can_sign ? t('sign.waiting') : a.status === 'signed' ? t('sign.signed') : t('sign.closed');
          return (
            <Pressable key={a.id} accessibilityRole="button" accessibilityLabel={`${a.title}, ${state}`} onPress={() => router.push(`/agreements/${a.id}` as never)}>
              <Card className="flex-row items-center gap-3">
                <View className={`h-10 w-10 items-center justify-center rounded-xl ${a.can_sign ? 'bg-warning-bg' : a.status === 'signed' ? 'bg-success-bg' : 'bg-line-soft'}`}>
                  <Feather name={a.can_sign ? 'edit-3' : a.status === 'signed' ? 'check' : 'minus'} size={18} color={a.can_sign ? '#8a5a0b' : a.status === 'signed' ? '#166c52' : '#6b7186'} />
                </View>
                <View className="flex-1">
                  <Text className="font-medium text-body text-ink dark:text-night-ink">{a.title}</Text>
                  <Text className="font-sans text-caption text-muted dark:text-night-muted">{state}</Text>
                </View>
                <Feather name="chevron-right" size={18} color="#6b7186" />
              </Card>
            </Pressable>
          );
        })}
      </ScrollView>
    </Screen>
  );
}
