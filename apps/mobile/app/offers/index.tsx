import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Card, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyOffers } from '@/lib/enrolment';

export const OFFER_STATE = {
  open: { label: 'Waiting for your reply', tone: 'info' },
  accepted: { label: 'Accepted', tone: 'success' },
  declined: { label: 'Declined', tone: 'neutral' },
  lapsed: { label: 'Lapsed', tone: 'neutral' },
  withdrawn: { label: 'Withdrawn', tone: 'neutral' },
} as const;

export default function Offers() {
  const { t, online } = useApp();
  const router = useRouter();
  const q = useMyOffers(online);
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <BackHeader />
        <Title>{t('offers.title')}</Title>
        {q.data && !q.data.length ? <Body>{t('offers.none')}</Body> : null}
        {(q.data ?? []).map((o) => (
          <Pressable key={o.id} accessibilityRole="button" accessibilityLabel={o.course.title} onPress={() => router.push({ pathname: '/offers/[id]', params: { id: o.id } } as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="flex-1 gap-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{o.course.title}</Text>
                <Text className="font-sans text-small text-muted">{o.status === 'open' ? t('offers.replyBy', { date: o.expires_at.slice(0, 10) }) : o.reference}</Text>
                <Pill text={OFFER_STATE[o.status].label} tone={OFFER_STATE[o.status].tone} />
              </View>
              <Feather name="chevron-right" size={18} color="#6b7186" />
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </Screen>
  );
}
