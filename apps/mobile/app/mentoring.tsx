import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { Body, Button, Card, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMentoring } from '@/lib/mentoring';

/** A workplace mentor's view: where they mentor and the cohorts coming. Logbooks arrive later. */
export default function MentoringScreen() {
  const { t } = useApp();
  const router = useRouter();
  const q = useMentoring();
  const d = (iso: string) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <View className="items-start">
          <Button title={t('common.back')} variant="ghost" icon={<Feather name="chevron-left" size={18} color="#4338ca" />} onPress={() => router.back()} />
        </View>
        <Title>{t('mentor.title')}</Title>
        {q.data && !q.data.is_mentor ? <Body>{t('mentor.none')}</Body> : null}
        {q.data?.workplaces.map((w) => (
          <Card key={w.id} className="gap-3">
            <View>
              <Text className="font-semibold text-section text-ink dark:text-night-ink">{w.name}</Text>
              <Text className="font-sans text-small text-muted dark:text-night-muted">{[w.employer, w.city].filter(Boolean).join(' · ')}</Text>
            </View>
            {w.cohorts.length ? (
              w.cohorts.map((c) => (
                <View key={c.name} className="rounded-xl bg-line-soft p-3 dark:bg-night-canvas">
                  <Text className="font-medium text-body text-ink dark:text-night-ink">{c.name}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">{c.qualification}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">{t('mentor.cohorts', { n: c.learners, from: d(c.intake_on), to: d(c.planned_end_on) })}</Text>
                </View>
              ))
            ) : (
              <Body>{t('mentor.noCohorts')}</Body>
            )}
          </Card>
        ))}
        {q.data?.is_mentor ? <Notice tone="info">{t('mentor.soon')}</Notice> : null}
      </ScrollView>
    </Screen>
  );
}
