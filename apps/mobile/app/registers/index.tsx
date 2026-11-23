import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Card, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyRegisters } from '@/lib/delivery';

const monthName = (ym: string) => new Date(`${ym}-15T12:00:00Z`).toLocaleDateString('en-ZA', { month: 'long', year: 'numeric', timeZone: 'UTC' });

export default function Registers() {
  const { t, online } = useApp();
  const router = useRouter();
  const q = useMyRegisters(online);
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <BackHeader />
        <Title>{t('registers.title')}</Title>
        <Body>{t('registers.body')}</Body>
        {(q.data ?? []).map((r) => (
          <Pressable key={r.id} accessibilityRole="button" onPress={() => router.push({ pathname: '/registers/[id]', params: { id: r.id } } as never)}>
            <Card className="gap-1">
              <Text className="font-semibold text-body text-ink dark:text-night-ink">{monthName(r.month)} · {r.cohort}</Text>
              {r.pct !== null ? <Text className="font-sans text-small text-muted">{t('registers.line', { attended: r.attended ?? 0, held: r.held ?? 0, pct: r.pct })}</Text> : null}
              <Pill text={t(`registers.${r.my_status}` as never)} tone={r.my_status === 'pending' ? 'warning' : r.my_status === 'signed' ? 'success' : 'danger'} />
            </Card>
          </Pressable>
        ))}
        {q.data && !q.data.length ? <Body>{t('registers.none')}</Body> : null}
      </ScrollView>
    </Screen>
  );
}
