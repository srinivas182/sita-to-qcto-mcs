import Feather from '@expo/vector-icons/Feather';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { AccountStatus } from '@/components/AccountStatus';
import { Brand, Button, Card, Screen } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyAgreements } from '@/lib/agreements';
import { useMe } from '@/lib/me';
import { useMentoring } from '@/lib/mentoring';
import { outbox } from '@/lib/outbox';

export default function Home() {
  const { t, tenant, online, pending, syncNow } = useApp();
  const me = useMe();
  const router = useRouter();
  const agreements = useMyAgreements(online);
  const mentoring = useMentoring(online);
  const toSign = (agreements.data ?? []).filter((a) => a.can_sign).length;
  const parked = useQuery({ queryKey: ['outbox', 'parked', pending], queryFn: () => outbox.conflicts() });

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-8 pt-4" refreshControl={<RefreshControl refreshing={me.isRefetching} onRefresh={() => void Promise.all([me.refetch(), syncNow(), agreements.refetch(), mentoring.refetch()])} />}>
        {tenant ? <Brand name={tenant.name} primary={tenant.branding.primary} /> : null}

        <View className="mt-2">
          <Text className="font-sans text-small text-muted dark:text-night-muted">{me.data?.roles[0]?.label ?? ''}</Text>
          <Text className="font-semibold text-display text-ink dark:text-night-ink">{t('home.greeting', { name: me.data?.user.first_name ?? '' })}</Text>
        </View>

        <AccountStatus me={me.data} />

        <Card className="flex-row items-center gap-3">
          <View className={`h-10 w-10 items-center justify-center rounded-xl ${!online ? 'bg-warning-bg' : pending ? 'bg-info-bg' : 'bg-success-bg'}`}>
            <Feather name={!online ? 'wifi-off' : pending ? 'refresh-cw' : 'check'} size={18} color={!online ? '#8a5a0b' : pending ? '#2d68a8' : '#166c52'} />
          </View>
          <Text className="flex-1 font-medium text-body text-ink dark:text-night-ink">
            {!online ? t('home.offline') : pending ? t('home.pending', { n: pending }) : t('home.synced')}
          </Text>
        </Card>

        {parked.data?.length ? (
          <Card className="gap-3">
            {parked.data.map((item) => (
              <View key={item.id} className="gap-2">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{item.lastError}</Text>
                <View className="flex-row gap-2">
                  <Button title={t('common.retry')} variant="secondary" onPress={() => void outbox.retry(item.id).then(syncNow)} />
                  <Button title={t('common.cancel')} variant="ghost" onPress={() => void outbox.discard(item.id).then(() => parked.refetch())} />
                </View>
              </View>
            ))}
          </Card>
        ) : null}

        {toSign ? (
          <Pressable accessibilityRole="button" onPress={() => router.push('/agreements' as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="h-10 w-10 items-center justify-center rounded-xl bg-warning-bg">
                <Feather name="edit-3" size={18} color="#8a5a0b" />
              </View>
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('home.toSign')}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{t('home.toSignBody', { n: toSign })}</Text>
              </View>
              <Feather name="chevron-right" size={18} color="#6b7186" />
            </Card>
          </Pressable>
        ) : null}

        {mentoring.data?.is_mentor ? (
          <Pressable accessibilityRole="button" onPress={() => router.push('/mentoring' as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="h-10 w-10 items-center justify-center rounded-xl bg-info-bg">
                <Feather name="users" size={18} color="#2d68a8" />
              </View>
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('home.mentoring')}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{t('home.mentoringBody')}</Text>
              </View>
              <Feather name="chevron-right" size={18} color="#6b7186" />
            </Card>
          </Pressable>
        ) : null}

        <Card className="gap-2">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('tabs.learn')}</Text>
          <Text className="font-sans text-body text-muted dark:text-night-muted">{t('learn.soon')}</Text>
        </Card>
      </ScrollView>
    </Screen>
  );
}
