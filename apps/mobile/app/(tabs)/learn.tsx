import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { Pill } from '@/components/BackHeader';
import { Body, Button, Card, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyCourses } from '@/lib/enrolment';

/** My course: only what the person is enrolled on. Learning content arrives in Sprint 5. */
export default function Learn() {
  const { t, online } = useApp();
  const router = useRouter();
  const q = useMyCourses(online);
  const courses = q.data ?? [];

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <Title>{t('learn.myCourse')}</Title>
        {courses.length ? courses.map((c) => (
          <Pressable key={c.enrolment_id} accessibilityRole="button" disabled={c.status !== 'active'} onPress={() => router.push({ pathname: '/course/[id]', params: { id: c.enrolment_id } } as never)}>
          <Card className="gap-2">
            <View className="h-12 w-12 items-center justify-center rounded-2xl bg-primary-50">
              <Feather name="book-open" size={22} color="#4338ca" />
            </View>
            <Text className="font-semibold text-title text-ink dark:text-night-ink">{c.qualification?.title ?? c.cohort}</Text>
            <Body>{[c.cohort, c.starts_on && c.ends_on ? `${c.starts_on} – ${c.ends_on}` : null].filter(Boolean).join(' · ')}</Body>
            <Pill text={c.status === 'active' ? t('learn.enrolled') : c.status} tone={c.status === 'active' ? 'success' : 'neutral'} />
            {c.status === 'active' ? <Text className="font-semibold text-body text-primary-700">{t('learn.open')}</Text> : null}
          </Card>
          </Pressable>
        )) : null}
        {courses.length ? (
          <Pressable accessibilityRole="button" onPress={() => router.push('/results' as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="h-12 w-12 items-center justify-center rounded-2xl bg-success-bg">
                <Feather name="award" size={22} color="#166c52" />
              </View>
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('learn.results')}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{t('learn.resultsBody')}</Text>
              </View>
              <Feather name="chevron-right" size={18} color="#64748b" />
            </Card>
          </Pressable>
        ) : null}
        {courses.some((c) => c.status === 'active') ? (
          <Pressable accessibilityRole="button" onPress={() => router.push('/logbook' as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="h-12 w-12 items-center justify-center rounded-2xl bg-warning-bg">
                <Feather name="clipboard" size={22} color="#b45309" />
              </View>
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('learn.logbook')}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{t('learn.logbookBody')}</Text>
              </View>
              <Feather name="chevron-right" size={18} color="#64748b" />
            </Card>
          </Pressable>
        ) : null}
        {courses.length ? null : (
          <View className="items-center gap-3 pt-10">
            <View className="h-14 w-14 items-center justify-center rounded-2xl bg-primary-50">
              <Feather name="book-open" size={24} color="#4338ca" />
            </View>
            <View className="max-w-[300px]"><Body>{t('learn.none')}</Body></View>
            <Button title={t('apply.title')} variant="secondary" onPress={() => router.push('/apply' as never)} />
          </View>
        )}
      </ScrollView>
    </Screen>
  );
}
