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
import { useMyCourses, useMyDocuments, useMyOffers } from '@/lib/enrolment';
import { useDashboard } from '@/lib/delivery';
import { outbox } from '@/lib/outbox';

export default function Home() {
  const { t, tenant, online, pending, syncNow } = useApp();
  const me = useMe();
  const router = useRouter();
  const agreements = useMyAgreements(online);
  const mentoring = useMentoring(online);
  const toSign = (agreements.data ?? []).filter((a) => a.can_sign).length;
  const offers = useMyOffers(online);
  const documents = useMyDocuments(online);
  const courses = useMyCourses(online);
  const openOffers = (offers.data ?? []).filter((o) => o.status === 'open').length;
  const docsNeeded = (documents.data?.needed ?? []).flatMap((n) => n.items).filter((i) => ['missing', 'rejected', 'expired'].includes(i.state)).length;
  const learner = (courses.data ?? []).length > 0;
  const assessor = (me.data?.roles ?? []).some((r) => r.role === 'assessor' || r.role === 'moderator');
  const dash = useDashboard(online && learner);
  const card = (icon: 'gift' | 'file-plus' | 'search' | 'edit' | 'calendar' | 'check-square', tone: string, color: string, title: string, body: string, to: string) => (
    <Pressable accessibilityRole="button" onPress={() => router.push(to as never)}>
      <Card className="flex-row items-center gap-3">
        <View className={`h-10 w-10 items-center justify-center rounded-xl ${tone}`}>
          <Feather name={icon} size={18} color={color} />
        </View>
        <View className="flex-1">
          <Text className="font-semibold text-body text-ink dark:text-night-ink">{title}</Text>
          <Text className="font-sans text-small text-muted dark:text-night-muted">{body}</Text>
        </View>
        <Feather name="chevron-right" size={18} color="#6b7186" />
      </Card>
    </Pressable>
  );
  const parked = useQuery({ queryKey: ['outbox', 'parked', pending], queryFn: () => outbox.conflicts() });

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-8 pt-4" refreshControl={<RefreshControl refreshing={me.isRefetching} onRefresh={() => void Promise.all([me.refetch(), syncNow(), agreements.refetch(), mentoring.refetch(), offers.refetch(), documents.refetch(), courses.refetch(), dash.refetch()])} />}>
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

        {(dash.data?.today ?? []).map((s) => (
          <Card key={s.id} className="gap-2">
            <Text className="font-sans text-small text-muted dark:text-night-muted">{t('home.today')}</Text>
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{s.starts_at.slice(11, 16)}–{s.ends_at.slice(11, 16)} · {s.title ?? t('timetable.class')}</Text>
            <Text className="font-sans text-small text-muted">{[s.room?.name ?? s.venue, s.facilitator?.name].filter(Boolean).join(' · ')}</Text>
            {s.status === 'cancelled' ? <Text className="font-medium text-small text-danger">{t('timetable.cancelled')}{s.cancel_reason ? `: ${s.cancel_reason}` : ''}</Text> : null}
            {s.status === 'scheduled' && !s.teaching ? <Button title={t('home.checkIn')} icon={<Feather name="maximize" size={18} color="#fff" />} onPress={() => router.push('/check-in' as never)} /> : null}
          </Card>
        ))}
        {(dash.data?.courses ?? []).map((c) => (
          <Pressable key={c.enrolment_id} accessibilityRole="button" onPress={() => router.push({ pathname: '/course/[id]', params: { id: c.enrolment_id } } as never)}>
            <Card className="gap-2">
              <Text className="font-semibold text-body text-ink dark:text-night-ink">{c.title ?? c.cohort}</Text>
              <View className="flex-row gap-3">
                <View className="flex-1"><Text className={`font-bold text-title ${c.attendance.at_risk ? 'text-danger' : 'text-ink dark:text-night-ink'}`}>{c.attendance.pct ?? '–'}{c.attendance.pct !== null ? '%' : ''}</Text><Text className="font-sans text-caption text-muted">{t('home.attendance')}</Text></View>
                <View className="flex-1"><Text className="font-bold text-title text-ink dark:text-night-ink">{c.materials.done}/{c.materials.total}</Text><Text className="font-sans text-caption text-muted">{t('home.materials')}</Text></View>
                <View className="flex-1"><Text className="font-bold text-title text-ink dark:text-night-ink">{c.tasks.passed}/{c.tasks.total}</Text><Text className="font-sans text-caption text-muted">{t('home.tasks')}</Text></View>
              </View>
              {c.attendance.at_risk ? <Text className="font-sans text-small text-danger">{t('home.atRisk')}</Text> : null}
            </Card>
          </Pressable>
        ))}
        {dash.data?.registers_to_sign ? card('edit', 'bg-warning-bg', '#8a5a0b', t('home.registers'), t('home.registersBody', { n: dash.data.registers_to_sign }), '/registers') : null}
        {learner ? card('calendar', 'bg-info-bg', '#2d68a8', t('timetable.title'), t('home.timetableBody'), '/timetable') : null}
        {assessor ? card('check-square', 'bg-primary-50', '#4338ca', t('home.assess'), t('home.assessBody'), '/assess') : null}
        {openOffers ? card('gift', 'bg-success-bg', '#166c52', t('home.offers'), t('home.offersBody', { n: openOffers }), '/offers') : null}
        {docsNeeded ? card('file-plus', 'bg-warning-bg', '#8a5a0b', t('home.documents'), t('home.documentsBody', { n: docsNeeded }), '/documents') : null}

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

        {!learner && !mentoring.data?.is_mentor ? card('search', 'bg-primary-50', '#4338ca', t('home.apply'), t('home.applyBody'), '/apply') : null}

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
