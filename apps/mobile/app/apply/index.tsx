import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Card, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { APPLICATION_STATE, useMyApplications, useOpenIntakes } from '@/lib/enrolment';

/** Courses open for applications at this provider, and the person's applications. */
export default function Apply() {
  const { t, online } = useApp();
  const router = useRouter();
  const intakes = useOpenIntakes(online);
  const mine = useMyApplications(online);
  const applied = new Set((mine.data ?? []).map((a) => a.intake_id));

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={intakes.isRefetching} onRefresh={() => void Promise.all([intakes.refetch(), mine.refetch()])} />}>
        <BackHeader />
        <Title>{t('apply.title')}</Title>
        {!online ? <Notice tone="warning">{t('apply.offline')}</Notice> : null}

        {(mine.data ?? []).length ? (
          <View className="gap-2">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('apply.mine')}</Text>
            {(mine.data ?? []).map((a) => (
              <Card key={a.id} className="gap-1">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{a.intake}</Text>
                <Text className="font-sans text-small text-muted">{a.reference}</Text>
                <Pill text={APPLICATION_STATE[a.status] ?? a.status} tone={a.status === 'enrolled' || a.status === 'offered' ? 'success' : a.status === 'declined' ? 'neutral' : 'info'} />
                {a.reason ? <Body>{a.reason}</Body> : null}
              </Card>
            ))}
          </View>
        ) : null}

        <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('apply.open')}</Text>
        {intakes.data && !intakes.data.length ? <Body>{t('apply.none')}</Body> : null}
        {(intakes.data ?? []).map((i) => (
          <Pressable key={i.id} accessibilityRole="button" accessibilityLabel={i.title} disabled={applied.has(i.id)} onPress={() => router.push({ pathname: '/apply/[id]', params: { id: i.id } } as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="flex-1 gap-0.5">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{i.title}</Text>
                <Text className="font-sans text-small text-muted">{[i.centre?.name, t('apply.closes', { date: i.closes_on })].filter(Boolean).join(' · ')}</Text>
                {applied.has(i.id) ? <Pill text={t('apply.applied')} tone="success" /> : null}
              </View>
              {applied.has(i.id) ? null : <Feather name="chevron-right" size={18} color="#6b7186" />}
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </Screen>
  );
}
