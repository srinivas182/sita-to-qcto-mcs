import { useLocalSearchParams, useRouter } from 'expo-router';
import { ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Screen, Title } from '@/components/ui';
import { useLearnerDetail } from '@/lib/assess';
import { useApp } from '@/lib/app';

const tone = { competent: 'success', nyc: 'danger', in_progress: 'warning', not_assessed: 'neutral', no_assessment: 'neutral' } as const;

/** One learner's modules and assessments; tap Assess to observe or record a test. */
export default function LearnerAssessScreen() {
  const { cohort, enrolment } = useLocalSearchParams<{ cohort: string; enrolment: string }>();
  const { t, online } = useApp();
  const router = useRouter();
  const q = useLearnerDetail(cohort, enrolment, online);
  const d = q.data;
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-3 pb-10 pt-4">
        <BackHeader />
        <Title>{d?.name ?? t('assess.title')}</Title>
        {!d ? <Body>{online ? t('common.loading') : t('home.offline')}</Body> : null}
        {d?.modules.map((m) => (
          <Card key={m.module_id} className="gap-2">
            <View className="flex-row items-center justify-between gap-2">
              <Text className="flex-1 font-semibold text-body text-ink dark:text-night-ink">{m.code} {m.title}</Text>
              <Pill text={t(`state.${m.state}` as never)} tone={tone[m.state]} />
            </View>
            {m.instruments.map((i) => {
              const done = i.latest?.outcome === 'competent';
              const out = i.attempts >= d.max_attempts && !done;
              return (
                <View key={i.instrument_id} className="gap-1 rounded-xl bg-line-soft p-3 dark:bg-night-canvas">
                  <Text className="font-sans text-small text-ink dark:text-night-ink">{i.title}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">
                    {i.latest ? t('assess.attempt', { n: i.latest.attempt, outcome: t(`outcome.${i.latest.outcome}` as never) }) : t('assess.notAssessed')}
                  </Text>
                  {out ? <Pill text={t('assess.allUsed')} tone="danger" /> : !done && i.method !== 'logbook' ? (
                    <View className="items-start">
                      <Button title={i.attempts ? t('assess.reassess') : t('assess.assess')}
                        onPress={() => router.push({ pathname: '/assess/decide', params: { cohort, enrolment, instrument: i.instrument_id } } as never)} />
                    </View>
                  ) : null}
                </View>
              );
            })}
          </Card>
        ))}
      </ScrollView>
    </Screen>
  );
}
